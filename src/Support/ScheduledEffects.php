<?php

namespace LaraSlice\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LaraSlice\Core\Base\BaseSliceService;
use LaraSlice\Core\Base\Traits\AppliesFieldBehaviour;
use LaraSlice\Core\Discovery\ManifestRepository;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Core\Discovery\SliceManifest;

/**
 * Declared scheduled effects (Phase 8, DIFFERENCES D-079).
 *
 * A setting that nothing consumes is a dead value: Desk's
 * CRM Settings close_opportunity_after_days is read by a
 * DAILY scheduler job (auto_close_opportunity), and its
 * Contract / Email Campaign statuses are refreshed by
 * daily hooks as dates pass. The port stored the setting
 * and nothing read it — there was no scheduler seat at
 * all. This is that seat: a slice's Heart declares its
 * periodic effects as DATA under the top-level `effects`
 * key, and one framework engine executes them (the
 * `slice:run-effects` command, registered with the
 * Laravel scheduler daily by the service provider):
 *
 *   "effects": [
 *     {"name": "auto_close_opportunity", "schedule": "daily",
 *      "type": "status_sweep", "slice": "Opportunities",
 *      "when": {"status": "replied"},
 *      "age": {"field": "updated_at",
 *              "older_than_setting": {"slice": "CrmSettings",
 *              "field": "close_opportunity_after_days"},
 *              "default_days": 15},
 *      "set": {"status": "closed"}},
 *     {"name": "refresh_contract_statuses", "schedule": "daily",
 *      "type": "recompute", "slice": "Contracts",
 *      "when": {"is_signed": 1}}
 *   ]
 *
 * Two effect types cover the source's daily jobs:
 *  - status_sweep: a bulk status transition over rows
 *    matching equality `when` clauses whose age field is
 *    older than a threshold — a literal `days`, or a
 *    SETTING read live from another slice's single record
 *    (older_than_setting, with default_days when the
 *    setting is unset — Desk: `or 15`). Swept rows get
 *    the `set` map plus a fresh updated_at, exactly as
 *    the source's db_set bumps modified.
 *  - recompute: re-run the target slice's field
 *    behaviour over its records (optionally `when`-
 *    filtered), persisting any scalar the derivations
 *    change — the port's form of Desk's daily status
 *    refresh hooks (update_status_for_contracts,
 *    set_email_campaign_status). A record whose
 *    derivations refuse (a validation error) is skipped
 *    and counted, never fatal to the run.
 *
 * Cadence is the scheduler's business: the engine runs
 * every declared effect when invoked; `schedule` in the
 * declaration is documentation of the source's cadence.
 */
final class ScheduledEffects
{
    /**
     * Every declared effect across discovered slices,
     * each with its declaring slice's name attached.
     *
     * @return list<array<string, mixed>>
     */
    public static function declared(): array
    {
        $manager = app(SliceManager::class);
        $manager->discover();
        $out = [];
        foreach ($manager->discoveredSlices() as $manifest) {
            $raw = ManifestRepository::readFromSliceDir($manifest->path);
            foreach ((array) ($raw['effects'] ?? []) as $effect) {
                if (! is_array($effect) || empty($effect['name']) || empty($effect['type']) || empty($effect['slice'])) {
                    continue;
                }
                $effect['declared_by'] = $manifest->name;
                $out[] = $effect;
            }
        }

        return $out;
    }

    /**
     * Run every declared effect once.
     *
     * @return list<array{effect: string, type: string, slice: string, affected: int, skipped: int, detail: string}>
     */
    public static function runAll(bool $dryRun = false): array
    {
        $results = [];
        foreach (self::declared() as $effect) {
            $results[] = self::run($effect, $dryRun);
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $effect
     * @return array{effect: string, type: string, slice: string, affected: int, skipped: int, detail: string}
     */
    public static function run(array $effect, bool $dryRun = false): array
    {
        $base = [
            'effect' => (string) $effect['name'],
            'type' => (string) $effect['type'],
            'slice' => (string) $effect['slice'],
            'affected' => 0,
            'skipped' => 0,
            'detail' => '',
        ];
        try {
            return match ((string) $effect['type']) {
                'status_sweep' => array_merge($base, self::runStatusSweep($effect, $dryRun)),
                'recompute' => array_merge($base, self::runRecompute($effect, $dryRun)),
                default => array_merge($base, ['detail' => 'unknown effect type']),
            };
        } catch (\Throwable $e) {
            return array_merge($base, ['detail' => 'error: '.$e->getMessage()]);
        }
    }

    /**
     * @param  array<string, mixed>  $effect
     * @return array{affected: int, skipped: int, detail: string}
     */
    private static function runStatusSweep(array $effect, bool $dryRun): array
    {
        $slice = self::slice((string) $effect['slice']);
        $table = $slice?->primaryTable();
        if ($slice === null || $table === null) {
            return ['affected' => 0, 'skipped' => 0, 'detail' => 'target slice or table unresolvable'];
        }
        $age = is_array($effect['age'] ?? null) ? $effect['age'] : [];
        $ageField = (string) ($age['field'] ?? 'updated_at');
        $days = self::thresholdDays($age);
        if ($days === null) {
            return ['affected' => 0, 'skipped' => 0, 'detail' => 'no threshold (days / older_than_setting) declared'];
        }
        $cutoff = now()->subDays($days)->format('Y-m-d H:i:s');
        $query = DB::table($table)->where($ageField, '<', $cutoff);
        foreach ((array) ($effect['when'] ?? []) as $field => $value) {
            $query->where((string) $field, $value);
        }
        $ids = $query->pluck('id')->all();
        if ($dryRun || $ids === []) {
            return ['affected' => count($ids), 'skipped' => 0, 'detail' => ($dryRun ? 'dry-run: ' : '').count($ids)." row(s) older than {$days} day(s)"];
        }
        $set = [];
        foreach ((array) ($effect['set'] ?? []) as $field => $value) {
            $set[(string) $field] = $value;
        }
        $set['updated_at'] = now()->format('Y-m-d H:i:s');
        $affected = DB::table($table)->whereIn('id', $ids)->update($set);

        return ['affected' => (int) $affected, 'skipped' => 0, 'detail' => count($ids)." row(s) older than {$days} day(s)"];
    }

    /**
     * The sweep threshold: a literal `days`, else the live
     * setting value (the settings slice's single record),
     * else the declared default — Desk's `or 15`.
     *
     * @param  array<string, mixed>  $age
     */
    private static function thresholdDays(array $age): ?int
    {
        if (isset($age['days']) && is_numeric($age['days'])) {
            return (int) $age['days'];
        }
        $setting = is_array($age['older_than_setting'] ?? null) ? $age['older_than_setting'] : null;
        if ($setting !== null) {
            $settingsSlice = self::slice((string) ($setting['slice'] ?? ''));
            $table = $settingsSlice?->primaryTable();
            $field = (string) ($setting['field'] ?? '');
            if ($table !== null && $field !== '') {
                $value = DB::table($table)->orderBy('id')->value($field);
                if (is_numeric($value) && (int) $value > 0) {
                    return (int) $value;
                }
            }

            return isset($age['default_days']) && is_numeric($age['default_days']) ? (int) $age['default_days'] : null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $effect
     * @return array{affected: int, skipped: int, detail: string}
     */
    private static function runRecompute(array $effect, bool $dryRun): array
    {
        $slice = self::slice((string) $effect['slice']);
        $table = $slice?->primaryTable();
        if ($slice === null || $table === null) {
            return ['affected' => 0, 'skipped' => 0, 'detail' => 'target slice or table unresolvable'];
        }
        $service = self::serviceFor($slice);
        if ($service === null) {
            return ['affected' => 0, 'skipped' => 0, 'detail' => 'target slice service unresolvable'];
        }
        $raw = ManifestRepository::readFromSliceDir($slice->path);
        $fields = SliceRecordOptions::manifestFieldsOf($raw);
        $query = DB::table($table);
        foreach ((array) ($effect['when'] ?? []) as $field => $value) {
            $query->where((string) $field, $value);
        }
        $ids = $query->pluck('id')->all();
        $host = new ScheduledEffectBehaviourHost($raw);
        $affected = 0;
        $skipped = 0;
        foreach ($ids as $id) {
            try {
                $form = $service->getItemById($id);
                if ($form === null) {
                    $skipped++;
                    continue;
                }
                $host->apply($form);
                $changes = [];
                $row = DB::table($table)->where('id', $id)->first();
                foreach ($fields as $name => $definition) {
                    if (! is_array($definition) || in_array((string) ($definition['type'] ?? ''), ['multiselect', 'repeater'], true)) {
                        continue;
                    }
                    $new = $form->{$name} ?? null;
                    $old = $row?->{$name} ?? null;
                    if (is_bool($new)) {
                        $new = $new ? 1 : 0;
                    }
                    if ((string) ($new ?? '') !== (string) ($old ?? '')) {
                        $changes[$name] = $new;
                    }
                }
                if ($changes !== []) {
                    $affected++;
                    if (! $dryRun) {
                        $changes['updated_at'] = now()->format('Y-m-d H:i:s');
                        DB::table($table)->where('id', $id)->update($changes);
                    }
                }
            } catch (\Throwable) {
                // A record whose derivations refuse (e.g. an
                // Email Campaign whose campaign lost its
                // schedule) is skipped, never fatal.
                $skipped++;
            }
        }

        return ['affected' => $affected, 'skipped' => $skipped, 'detail' => count($ids).' record(s) examined'];
    }

    private static function slice(string $name): ?SliceManifest
    {
        if ($name === '') {
            return null;
        }
        $manager = app(SliceManager::class);
        $manager->discover();

        return $manager->getSlice($name);
    }

    private static function serviceFor(SliceManifest $manifest): ?BaseSliceService
    {
        $namespace = $manifest->getNamespace();
        $singular = Str::studly(Str::singular($manifest->name));
        $candidates = ["{$namespace}\\Services\\{$singular}SliceService"];
        $servicesPath = $manifest->getServicesPath();
        if ($servicesPath !== null) {
            foreach (glob($servicesPath.'/*SliceService.php') ?: [] as $file) {
                $candidates[] = $namespace.'\\Services\\'.basename($file, '.php');
            }
        }
        foreach (array_unique($candidates) as $class) {
            if (class_exists($class)) {
                $service = app($class);
                if ($service instanceof BaseSliceService) {
                    return $service;
                }
            }
        }

        return null;
    }
}

/**
 * The recompute host: applies one slice's declared field
 * behaviour to a loaded record, outside any controller.
 * The manifest is injected (a host method takes
 * precedence over the trait's file-location default).
 */
final class ScheduledEffectBehaviourHost
{
    use AppliesFieldBehaviour;

    /** @param array<string, mixed> $manifest */
    public function __construct(private readonly array $manifest) {}

    protected function ownManifest(): array
    {
        return $this->manifest;
    }

    public function apply(object $form): void
    {
        $this->applyFieldBehaviour(new Request, $form, $form, false);
    }
}
