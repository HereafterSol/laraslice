<?php

namespace LaraSlice\Slices\Settings\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use LaraSlice\Core\Ai\AiChatController;
use LaraSlice\Core\Security\Access;
use LaraSlice\Slices\Settings\Services\SettingSliceService;

class SettingWebController extends Controller
{
    protected SettingSliceService $settingService;

    public function __construct(SettingSliceService $service)
    {
        $this->settingService = $service;
    }

    /**
     * The settings hub (S-UI 5, pattern P9): every slice's
     * declared `settings:` sections in one rail + search shell,
     * alongside the built-in bespoke pages (SMTP, Theme, AI).
     */
    public function hub(Request $request): View
    {
        $this->authorizeSettings(['settings.view', 'settings.smtp.view']);

        $sections = $this->hubSections();
        // The hub opens on the company/global section when one
        // is declared (the source hub's landing), not on
        // whichever slice discovery happens to visit first.
        $default = collect($sections)->first(
            fn ($s) => str_ends_with((string) ($s['id'] ?? ''), ':global')
        ) ?? ($sections[0] ?? null);
        $activeId = (string) $request->query('section', $default['id'] ?? '');
        $active = collect($sections)->firstWhere('id', $activeId) ?? $default;

        return view('laraslice::settings-hub', [
            'sections' => $sections,
            'active' => $active,
            'builtin' => [
                ['title' => 'SMTP', 'url' => route('settings.smtp'), 'icon' => 'mail'],
                ['title' => 'Theme', 'url' => route('settings.theme'), 'icon' => 'palette'],
                ['title' => 'AI', 'url' => route('settings.ai'), 'icon' => 'sparkles'],
            ],
        ]);
    }

    /**
     * Save one declared section. Only the section's declared
     * fields are written (the declaration is the allowlist);
     * blank secret fields keep their current value.
     */
    public function saveHubSection(Request $request, string $section): RedirectResponse
    {
        $this->authorizeSettings(['settings.view', 'settings.smtp.edit']);

        $target = collect($this->hubSections())->firstWhere('id', $section);
        if ($target === null) {
            abort(404);
        }
        if (! empty($target['bind']['table'])) {
            $this->saveBoundSection($request, $target);
            // A bound save may have changed a consumed table
            // (company, currency format, theme, storage):
            // drop the read cache so the same request never
            // renders the pre-save values (SettingsUi readers).
            \LaraSlice\Support\SettingsUi::forgetBoundSettings();

            return redirect()
                ->route('settings.hub', ['section' => $section])
                ->with('success', $target['title'].' settings saved.');
        }
        foreach ($target['fields'] as $field) {
            $value = $request->input($field['handle']);
            if ($field['type'] === 'toggle') {
                $value = $request->boolean($field['handle']) ? '1' : '0';
            }
            if ($field['type'] === 'time') {
                $value = \LaraSlice\Support\SettingsUi::normalizeTimeValue($value);
            }
            if ($field['type'] === 'roles') {
                $ids = array_values(array_filter(
                    array_map('intval', (array) $value),
                    static fn (int $v) => $v > 0
                ));
                $value = json_encode($ids);
            }
            if ($field['secret'] && ($value === null || $value === '')) {
                continue;
            }
            \LaraSlice\Slices\Settings\Models\Setting::set(
                $field['key'],
                $value === null ? '' : (string) $value,
                $target['slice'],
                (bool) $field['secret'],
                $target['title'].' — '.$field['label']
            );
        }

        return redirect()
            ->route('settings.hub', ['section' => $section])
            ->with('success', $target['title'].' settings saved.');
    }

    /**
     * Aggregate declared sections from every active slice.
     * Iterates ALL discovered manifests, not the name index:
     * a name-shadowed twin that declares sections must not
     * lose them to the twin that won the name (the A2/A4
     * registry-blindness class). Duplicate section ids keep
     * the first declaration.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function hubSections(): array
    {
        $manager = app(\LaraSlice\Core\Discovery\SliceManager::class);
        $entries = [];
        foreach ($manager->discoveredSlices() as $slice) {
            if ($slice->active && ! empty($slice->raw['settings'] ?? [])) {
                $entries[] = ['slice' => (string) $slice->name, 'manifest' => $slice->raw];
            }
        }

        $sections = \LaraSlice\Support\SettingsUi::collectSections(
            $entries,
            fn (string $key, mixed $default) => \LaraSlice\Slices\Settings\Models\Setting::get($key, $default)
        );

        $seen = [];
        $seenTitles = [];
        $resolved = [];
        foreach ($sections as $section) {
            if (isset($seen[$section['id']])) {
                continue;
            }
            // One rail entry per title: when two slices declare
            // the same settings section (a key-value demo of a
            // section that also exists in bound, ported-system
            // form), the first declaration wins and the rail
            // never shows the label twice.
            $titleKey = strtolower(trim((string) ($section['title'] ?? '')));
            if ($titleKey !== '' && isset($seenTitles[$titleKey])) {
                continue;
            }
            $seen[$section['id']] = true;
            $seenTitles[$titleKey] = true;
            if (! empty($section['bind']['table'])) {
                $section = $this->resolveBoundSection($section);
                if ($section === null) {
                    continue; // backing table not installed — never 500 the hub
                }
            }
            $resolved[] = $section;
        }

        return $resolved;
    }

    /**
     * Fill a table-bound section's field values from the
     * bound table's single row (backlog D1). Returns null
     * when the table does not exist. Columns the table does
     * not have keep their declared defaults; toggle values
     * normalize to '1'/'0' whatever the table's convention
     * ('yes'/'no', 1/0, '1'/'0').
     */
    protected function resolveBoundSection(array $section): ?array
    {
        $table = (string) $section['bind']['table'];
        if (! \Illuminate\Support\Facades\Schema::hasTable($table)) {
            return null;
        }
        if (! empty($section['bind']['rows_key'])) {
            // Keyed-rows binding (L5): one editable row per
            // record, toggle columns only — the shape of
            // per-event notification settings.
            $keyColumn = (string) $section['bind']['rows_key'];
            $labelColumn = (string) ($section['bind']['rows_label'] ?? $keyColumn);
            $toggles = (array) ($section['bind']['toggles'] ?? []);
            if (! \Illuminate\Support\Facades\Schema::hasColumn($table, $keyColumn)) {
                return null;
            }
            $rows = [];
            foreach (\Illuminate\Support\Facades\DB::table($table)->orderBy('id')->get() as $record) {
                $values = [];
                foreach ($toggles as $column) {
                    if (\Illuminate\Support\Facades\Schema::hasColumn($table, $column)) {
                        $values[$column] = \LaraSlice\Support\SettingsUi::toggleIsOn($record->{$column});
                    }
                }
                $rows[] = [
                    'key' => (string) $record->{$keyColumn},
                    'label' => (string) ($record->{$labelColumn} ?? $record->{$keyColumn}),
                    'toggles' => $values,
                ];
            }
            $section['rows'] = $rows;
            $section['toggle_columns'] = $toggles;

            return $section;
        }
        $row = \Illuminate\Support\Facades\DB::table($table)->orderBy('id')->first();
        foreach ($section['fields'] as $i => $field) {
            $column = $field['handle'];
            if ($row !== null && \Illuminate\Support\Facades\Schema::hasColumn($table, $column)) {
                $value = $row->{$column};
                if ($field['type'] === 'toggle') {
                    $value = \LaraSlice\Support\SettingsUi::toggleIsOn($value) ? '1' : '0';
                }
                $section['fields'][$i]['value'] = $value;
            }
            if (($field['type'] ?? '') === 'roles') {
                // The stored value is a JSON list of role ids
                // (the source's time-log daily_report_roles);
                // the hub renders checkboxes over the role set.
                $stored = $section['fields'][$i]['value'] ?? null;
                $decoded = is_string($stored) ? json_decode($stored, true) : null;
                $section['fields'][$i]['value'] = is_array($decoded)
                    ? array_values(array_map('intval', $decoded))
                    : [];
                $section['fields'][$i]['role_options'] = \LaraSlice\Support\SettingsUi::roleOptions();
            }
            if (($field['type'] ?? '') === 'select' && ! empty($field['options_source'])) {
                // Options resolve live from their source (e.g.
                // enabled languages) — the picker offers what
                // the source system offers. The stored value
                // stays selectable even when its row is
                // currently disabled: the picker narrows what
                // can be newly chosen, it never strands a value.
                $options = \LaraSlice\Support\SettingsUi::optionsForSource((string) $field['options_source']);
                $current = $section['fields'][$i]['value'] ?? null;
                if ($current !== null && $current !== '' && ! array_key_exists((string) $current, $options)) {
                    $options[(string) $current] = (string) $current;
                }
                $section['fields'][$i]['options'] = $options;
            }
        }

        return $section;
    }

    /**
     * Write a table-bound section back to its settings
     * table: only declared fields, only columns the table
     * actually has; the single row is updated, or inserted
     * when the table is still empty. Toggle values are
     * written in the convention the existing row already
     * uses ('yes'/'no' stays 'yes'/'no', ints stay ints).
     * Blank secret fields keep their current value. A blank
     * field never writes null into a NOT NULL column (that
     * 500s the save under strict SQL modes): on update the
     * stored value is kept, on insert the column's zero
     * value is used.
     */
    protected function saveBoundSection(Request $request, array $target): void
    {
        $table = (string) $target['bind']['table'];
        if (! \Illuminate\Support\Facades\Schema::hasTable($table)) {
            abort(404);
        }
        if (! empty($target['bind']['rows_key'])) {
            // Keyed-rows save: each record's toggle columns
            // are rewritten in the record's own convention
            // (yes/no vs 1/0), keyed by the declared key.
            $keyColumn = (string) $target['bind']['rows_key'];
            $toggles = (array) ($target['bind']['toggles'] ?? []);
            $submitted = (array) $request->input('rows', []);
            foreach (\Illuminate\Support\Facades\DB::table($table)->get() as $record) {
                $key = (string) $record->{$keyColumn};
                $payload = [];
                foreach ($toggles as $column) {
                    if (! \Illuminate\Support\Facades\Schema::hasColumn($table, $column)) {
                        continue;
                    }
                    $on = isset($submitted[$key][$column]) && (string) $submitted[$key][$column] === '1';
                    $current = $record->{$column};
                    $payload[$column] = \LaraSlice\Support\SettingsUi::toggleValueFor($current, $on);
                }
                if ($payload !== []) {
                    if (\Illuminate\Support\Facades\Schema::hasColumn($table, 'updated_at')) {
                        $payload['updated_at'] = now();
                    }
                    \Illuminate\Support\Facades\DB::table($table)->where($keyColumn, $record->{$keyColumn})->update($payload);
                }
            }

            return;
        }
        $existing = \Illuminate\Support\Facades\DB::table($table)->orderBy('id')->first();
        $columnMeta = [];
        foreach (\Illuminate\Support\Facades\Schema::getColumns($table) as $info) {
            $columnMeta[(string) $info['name']] = $info;
        }
        $payload = [];
        foreach ($target['fields'] as $field) {
            $column = $field['handle'];
            if (! \Illuminate\Support\Facades\Schema::hasColumn($table, $column)) {
                continue;
            }
            $value = $request->input($column);
            if ($field['type'] === 'toggle') {
                $on = $request->boolean($column);
                $current = $existing?->{$column};
                $value = \LaraSlice\Support\SettingsUi::toggleValueFor($current, $on);
                if (($columnMeta[$column]['type_name'] ?? '') === 'enum') {
                    // An enum column declares the toggle's exact
                    // vocabulary; when it holds a known pair, the
                    // pair wins over the current-value convention
                    // ('active' alone cannot say whether off is
                    // 'deactive' or 'inactive' — guessing wrong
                    // is rejected by the column).
                    $pair = \LaraSlice\Support\SettingsUi::togglePairFor(
                        \LaraSlice\Support\SettingsUi::enumValues((string) ($columnMeta[$column]['type'] ?? ''))
                    );
                    if ($pair !== null) {
                        $value = $on ? $pair[0] : $pair[1];
                    }
                }
            }
            if ($field['secret'] && ($value === null || $value === '')) {
                continue;
            }
            if ($field['type'] === 'time') {
                $value = \LaraSlice\Support\SettingsUi::normalizeTimeValue($value);
            }
            if ($field['type'] === 'roles') {
                // Checkboxes submit the chosen role ids; the
                // column stores them as a JSON list of ints —
                // the source's daily_report_roles shape.
                $ids = array_values(array_filter(
                    array_map('intval', (array) $value),
                    static fn (int $v) => $v > 0
                ));
                $value = json_encode($ids);
            }
            if ($value === null && isset($columnMeta[$column]) && ! ($columnMeta[$column]['nullable'] ?? true)) {
                // A blank field arrives as null (the
                // ConvertEmptyStringsToNull middleware), but a
                // NOT NULL column can never hold it — writing
                // it 500s the whole save (the ported
                // payroll_settings.tds_salary shape: NOT NULL
                // varchar holding ''). Like blank secrets, a
                // blank field keeps the stored value on update;
                // on insert it takes the column's zero value.
                $value = $existing !== null
                    ? $existing->{$column}
                    : \LaraSlice\Support\SettingsUi::zeroValueFor($columnMeta[$column]);
                if ($value === null) {
                    continue;
                }
            }
            $payload[$column] = $value;
        }
        if ($payload === []) {
            return;
        }
        if (\Illuminate\Support\Facades\Schema::hasColumn($table, 'updated_at')) {
            $payload['updated_at'] = now();
        }
        if ($existing !== null) {
            \Illuminate\Support\Facades\DB::table($table)->where('id', $existing->id)->update($payload);
        } else {
            if (\Illuminate\Support\Facades\Schema::hasColumn($table, 'created_at')) {
                $payload['created_at'] = now();
            }
            \Illuminate\Support\Facades\DB::table($table)->insert($payload);
        }
    }

    /**
     * Display SMTP configuration page.
     */
    public function smtp(): View
    {
        $this->authorizeSettings(['settings.smtp.view', 'settings.smtp.edit', 'settings.view']);

        $settings = $this->settingService->getSmtpSettings();

        return view('settings::smtp', compact('settings'));
    }

    /**
     * Save SMTP settings.
     */
    public function saveSmtp(Request $request): RedirectResponse
    {
        $this->authorizeSettings(['settings.smtp.edit', 'settings.edit']);

        $validated = $request->validate([
            'mail_host' => 'required|string',
            'mail_port' => 'required|integer',
            'mail_username' => 'nullable|string',
            'mail_password' => 'nullable|string',
            'mail_encryption' => 'required|string|in:tls,ssl,none',
            'mail_from_address' => 'required|email',
            'mail_from_name' => 'required|string',
        ]);

        $this->settingService->saveSmtpSettings($validated);

        return redirect()->route('settings.smtp')->with('success', 'SMTP settings saved successfully!');
    }

    /**
     * Send test email.
     */
    public function testSmtp(Request $request): RedirectResponse
    {
        $this->authorizeSettings(['settings.smtp.test', 'settings.smtp.edit']);

        $request->validate(['recipient' => 'required|email']);

        $res = $this->settingService->testSmtpConnection($request->input('recipient'));

        if ($res['success']) {
            return back()->with('success', $res['message']);
        }

        return back()->with('error', $res['message']);
    }

    /**
     * Display AI Copilot & Model configuration.
     */
    public function ai(): View
    {
        return app(AiChatController::class)->settings();
    }

    /**
     * Save AI Copilot configuration.
     */
    public function saveAi(Request $request): RedirectResponse
    {
        return app(AiChatController::class)->updateSettings($request);
    }

    /**
     * Abort unless the signed-in user is a super-admin or holds one of the abilities.
     *
     * @param  array<int, string>  $abilities
     */
    private function authorizeSettings(array $abilities): void
    {
        $user = auth()->user();

        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        if (! Access::allows($user, $abilities)) {
            abort(403, 'Access Denied: you do not have permission to manage system settings.');
        }
    }
}
