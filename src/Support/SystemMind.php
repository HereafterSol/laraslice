<?php

declare(strict_types=1);

namespace LaraSlice\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LaraSlice\Core\Discovery\SliceManager;
use Symfony\Component\Yaml\Yaml;

/**
 * The Mind (system.yaml): the system's intent — purpose, actors,
 * roles, journeys, operations. It never executes; the Studio's
 * Plan mode reads and edits it, and ports draft it (R6) so a
 * human reviews intent before bodies are tuned. The Heart
 * (slice.yaml) stays the only runtime truth.
 */
class SystemMind
{
    public static function path(): ?string
    {
        foreach ([base_path('porting/system.yaml'), base_path('system.yaml')] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public static function defaultPath(): string
    {
        return base_path('porting/system.yaml');
    }

    /** @return array<string, mixed>|null */
    public static function load(): ?array
    {
        $path = self::path();
        if ($path === null) {
            return null;
        }
        $parsed = Yaml::parseFile($path);

        return is_array($parsed) ? $parsed : null;
    }

    public static function render(array $mind): string
    {
        return Yaml::dump($mind, 4, 2, Yaml::DUMP_NULL_AS_TILDE);
    }

    /**
     * Flatten the Mind into the Plan form's fields (strings the
     * panel edits as plain language).
     *
     * @return array{purpose: string, roles: string, journeys: string, operations: string}
     */
    public static function toForm(array $mind): array
    {
        $roles = [];
        foreach ($mind['roles']['clubs'] ?? [] as $club) {
            if (! is_array($club)) {
                continue;
            }
            $roles[] = ($club['name'] ?? 'Role').' | '.implode(', ', (array) ($club['permissions'] ?? []));
        }

        $journeys = [];
        foreach ($mind['journeys'] ?? [] as $journey) {
            if (! is_array($journey)) {
                continue;
            }
            $journeys[] = ($journey['name'] ?? 'Journey').' | '.($journey['actor'] ?? '').' | '.implode(' > ', (array) ($journey['steps'] ?? [])).' | '.($journey['outcome'] ?? '');
        }

        $ops = [];
        foreach ((array) ($mind['operations'] ?? []) as $key => $value) {
            $ops[] = $key.': '.(is_array($value) ? (string) json_encode($value) : (string) $value);
        }

        return [
            'purpose' => (string) ($mind['system']['purpose'] ?? ''),
            'roles' => implode("\n", $roles),
            'journeys' => implode("\n", $journeys),
            'operations' => implode("\n", $ops),
        ];
    }

    /**
     * Apply the Plan form back onto a Mind array, preserving every
     * section the form does not touch.
     *
     * @param  array{name: string, actor: string, steps: string, outcome: string}  $form
     */
    public static function applyForm(array $mind, array $form): array
    {
        $mind['system']['purpose'] = trim((string) ($form['purpose'] ?? ''));

        $clubs = [];
        foreach (preg_split('/\r?\n/', (string) ($form['roles'] ?? '')) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            [$name, $perms] = array_pad(array_map('trim', explode('|', $line, 2)), 2, '');
            $clubs[] = [
                'name' => $name,
                'permissions' => array_values(array_filter(array_map('trim', explode(',', $perms)))),
            ];
        }
        $mind['roles']['clubs'] = $clubs;
        $mind['roles']['model'] = $mind['roles']['model'] ?? 'role-clubs-with-user-overrides';

        $journeys = [];
        foreach (preg_split('/\r?\n/', (string) ($form['journeys'] ?? '')) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            $journeys[] = [
                'name' => $parts[0] ?? 'Journey',
                'actor' => $parts[1] ?? '',
                'steps' => array_values(array_filter(array_map('trim', explode('>', $parts[2] ?? '')))),
                'outcome' => $parts[3] ?? '',
            ];
        }
        $mind['journeys'] = $journeys;

        $ops = [];
        foreach (preg_split('/\r?\n/', (string) ($form['operations'] ?? '')) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode(':', $line, 2));
            $ops[$key] = $value;
        }
        if ($ops !== []) {
            $mind['operations'] = $ops;
        }

        return $mind;
    }

    /** Compact unified line diff (LCS) for diff-before-save. */
    public static function diff(string $before, string $after): string
    {
        if ($before === $after) {
            return '';
        }
        $a = explode("\n", $before);
        $b = explode("\n", $after);
        $n = count($a);
        $m = count($b);
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $a[$i] === $b[$j] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }
        $out = ['--- Mind (current)', '+++ Mind (proposed)'];
        $i = 0;
        $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $out[] = '  '.$a[$i];
                $i++;
                $j++;
            } elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                $out[] = '- '.$a[$i];
                $i++;
            } else {
                $out[] = '+ '.$b[$j];
                $j++;
            }
        }
        while ($i < $n) {
            $out[] = '- '.$a[$i++];
        }
        while ($j < $m) {
            $out[] = '+ '.$b[$j++];
        }

        return implode("\n", $out);
    }

    /**
     * R6: draft a Mind + Body set from the discovered slices.
     * Everything drafted is marked [draft]; a human reviews it in
     * Plan mode. Existing files are never overwritten.
     *
     * @return array{mind: bool, bodies: int}
     */
    public static function draftFromApp(): array
    {
        $manager = app(SliceManager::class);
        $manager->discover();

        $result = ['mind' => false, 'bodies' => 0];
        $dir = base_path('porting/ui-spec');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $modules = [];
        foreach ($manager->discoveredSlices() as $slice) {
            // The full Heart lives in the manifest's raw array;
            // toArray() is only the identity summary.
            $manifest = is_array($slice->raw ?? null) && $slice->raw !== [] ? $slice->raw : $slice->toArray();
            $name = (string) ($manifest['name'] ?? 'Slice');
            $modules[] = (string) ($manifest['title'] ?? $name);
            $file = $dir.'/'.strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', (string) $name)).'.ui.yaml';
            if (is_file($file)) {
                continue;
            }
            $body = self::draftBodyV2($manifest, $name);
            file_put_contents($file, $body);
            $result['bodies']++;
        }

        $path = self::defaultPath();
        if (! is_file($path)) {
            $clubs = [];
            if (Schema::hasTable('roles')) {
                // Stock installs carry `name` only; `display_name`
                // exists solely on source-shaped schemas. Pluck
                // whichever label column the table actually has —
                // an unguarded display_name query fails the whole
                // Mind draft on a stock slice:install database.
                $labelColumn = Schema::hasColumn('roles', 'display_name') ? 'display_name' : 'name';
                foreach (DB::table('roles')->pluck($labelColumn) as $role) {
                    $clubs[] = ['name' => (string) $role, 'permissions' => ['[draft]']];
                }
            }
            $mind = [
                'system' => [
                    'name' => '[draft] '.(config('app.name') ?: 'System'),
                    'purpose' => '[draft] Describe what this system is for, in one or two sentences.',
                    'owner' => '[draft]',
                ],
                'actors' => [
                    ['name' => 'Super Admin', 'kind' => 'internal', 'description' => '[draft] Owns the whole system.'],
                ],
                'roles' => ['model' => 'role-clubs-with-user-overrides', 'clubs' => $clubs],
                'journeys' => [
                    ['name' => '[draft] Core journey', 'actor' => 'Super Admin', 'steps' => ['[draft]'], 'outcome' => '[draft]'],
                ],
                'modules' => $modules,
                'operations' => ['backups' => '[draft] daily', 'updates' => '[draft] monthly'],
            ];
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0775, true);
            }
            file_put_contents($path, "# Mind — drafted by the porter (R6). Review in Plan mode.\n".self::render($mind));
            $result['mind'] = true;
        }

        return $result;
    }

    /**
     * Draft a Body in the v2 schema (docs/MIND_BODY_HEART.md):
     * list facts from the manifest's directory declarations,
     * a form section classified from the manifest's fields
     * (system/audit columns separated, relations as pickers,
     * workflow state wired to its initial), and an access
     * skeleton marked [draft] for review in Plan mode.
     *
     * @param array<string, mixed> $manifest
     */
    public static function draftBodyV2(array $manifest, string $name): string
    {
        $title = (string) ($manifest['title'] ?? $name);
        $slug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $name));
        $lines = [];
        $lines[] = "# Body v2 (draft) — {$name} · drafted by slice:plan:draft, awaiting review";
        $lines[] = "slice: {$slug}";
        $lines[] = "labels: {singular: '[draft]', plural: {$title}}";
        $lines[] = 'pages:';

        // List page from the directory declarations.
        $lines[] = '  list:';
        $columns = $manifest['ui']['directory']['columns'] ?? [];
        if ($columns === []) {
            $lines[] = '    columns: []  # [draft] none declared';
        } else {
            $lines[] = '    columns:';
            foreach ($columns as $col) {
                if (is_array($col) && ! empty($col['label'])) {
                    $lines[] = "      - {item: '".str_replace("'", '', (string) $col['label'])."', decision: undecided}";
                }
            }
        }

        // Form page: classify the manifest's fields.
        $systemPatterns = '/(_by|_at|_ip|_id$|^id$)/';
        $systemNames = ['added_by', 'last_updated_by', 'created_by', 'updated_by', 'approved_by', 'approved_at', 'event_id', 'created_at', 'updated_at', 'deleted_at'];
        $workflow = $manifest['workflow'] ?? null;
        $stateField = is_array($workflow) ? ($workflow['state_field'] ?? null) : null;
        $formFields = [];
        $systemFields = [];
        foreach (($manifest['fields'] ?? []) as $fieldName => $field) {
            $fieldName = (string) $fieldName;
            if (in_array($fieldName, $systemNames, true) || preg_match('/_(by|at)$/', $fieldName)) {
                $systemFields[] = $fieldName;
                continue;
            }
            if ($stateField !== null && $fieldName === $stateField) {
                $formFields[] = "      {$fieldName}: {input: workflow-state, default: workflow-initial, editable: false}";
                continue;
            }
            $type = is_array($field) ? (string) ($field['type'] ?? 'string') : 'string';
            if (str_ends_with($fieldName, '_id')) {
                $relation = (string) ($field['relation'] ?? $field['target'] ?? str_replace('_id', '', $fieldName));
                $formFields[] = "      {$fieldName}: {input: picker, relation: {$relation}}";
            } elseif (in_array($type, ['date'], true)) {
                $formFields[] = "      {$fieldName}: {input: date}";
            } elseif (in_array($type, ['datetime', 'timestamp'], true) && preg_match('/(_date|_on)$/', $fieldName)) {
                $formFields[] = "      {$fieldName}: {input: date}  # [draft] storage is datetime; the domain reads it as a date — confirm";
            } elseif ($type === 'boolean') {
                $formFields[] = "      {$fieldName}: {input: checkbox}";
            } elseif ($type === 'text') {
                $formFields[] = "      {$fieldName}: {input: textarea}";
            } else {
                $formFields[] = "      {$fieldName}: {input: text}";
            }
        }
        $lines[] = '  form:';
        $lines[] = '    fields:';
        foreach ($formFields as $line) {
            $lines[] = $line;
        }
        if ($systemFields !== []) {
            $lines[] = '    system_fields: ['.implode(', ', $systemFields).']';
        }

        // Access skeleton + workflow line.
        $lines[] = 'access:';
        $lines[] = "  Super Admin: {actions: [view, create, edit, delete], scope: all}";
        $lines[] = "  Standard User: {actions: [view], scope: '[draft]'}  # [draft] confirm per club in Plan mode";
        if (is_array($workflow) && ! empty($workflow['initial_state'])) {
            $lines[] = "workflow: {state_field: {$stateField}, initial: {$workflow['initial_state']}, on_create: apply-initial}";
        }

        return implode("\n", $lines)."\n";
    }
}
