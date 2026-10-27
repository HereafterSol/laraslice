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

            return redirect()
                ->route('settings.hub', ['section' => $section])
                ->with('success', $target['title'].' settings saved.');
        }
        foreach ($target['fields'] as $field) {
            $value = $request->input($field['handle']);
            if ($field['type'] === 'toggle') {
                $value = $request->boolean($field['handle']) ? '1' : '0';
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
                        $values[$column] = in_array($record->{$column}, [1, '1', 'yes', 'true', true], true);
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
                    $value = in_array($value, [1, '1', 'yes', 'true', true], true) ? '1' : '0';
                }
                $section['fields'][$i]['value'] = $value;
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
     * Blank secret fields keep their current value.
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
                    $payload[$column] = match (true) {
                        $current === 'yes' || $current === 'no' => $on ? 'yes' : 'no',
                        is_int($current) => $on ? 1 : 0,
                        default => $on ? '1' : '0',
                    };
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
                $value = match (true) {
                    $current === 'yes' || $current === 'no' => $on ? 'yes' : 'no',
                    is_int($current) => $on ? 1 : 0,
                    default => $on ? '1' : '0',
                };
            }
            if ($field['secret'] && ($value === null || $value === '')) {
                continue;
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
