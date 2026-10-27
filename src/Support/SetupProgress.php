<?php

namespace LaraSlice\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use LaraSlice\Core\Discovery\ManifestRepository;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Core\Security\Access;

/**
 * Setup Progress (G1 residue): the source topbar's admin
 * checklist widget — "Setup Progress n/total" with a thin
 * bar, shown only while the setup is incomplete, linking
 * to a checklist page. The steps mirror the source's own
 * probes against the ported settings data:
 *
 *   Installation   — the app is running (always done)
 *   Account Setup  — at least one user account exists
 *   Email Setup    — the SMTP from-address left its
 *                    install placeholder ('from@email.com')
 *   Cron Setup     — global_settings.last_cron_run is set
 *   Company Logo   — global_settings.logo is set
 *   Favicon        — global_settings.favicon is set
 *
 * Gating mirrors the source: admins only (here: whoever
 * may open the settings hub), and the widget hides itself
 * at 100% — the checklist page stays reachable.
 */
class SetupProgress
{
    /**
     * Pure step evaluation over probe values, so the rules
     * are unit-testable without a database.
     *
     * @param  array{users?: int, smtp_from?: ?string, cron?: mixed, logo?: ?string, favicon?: ?string}  $probes
     * @return array{steps: array<int, array{key: string, label: string, info: string, done: bool, section: ?string}>, completed: int, total: int, percent: int}
     */
    public static function evaluate(array $probes): array
    {
        $smtpFrom = $probes['smtp_from'] ?? null;
        $steps = [
            ['key' => 'installation', 'label' => 'Installation', 'info' => 'Upload files on the server and configure the database.', 'done' => true, 'section' => null],
            ['key' => 'account_setup', 'label' => 'Account Setup', 'info' => 'Setup admin account details.', 'done' => (int) ($probes['users'] ?? 0) > 0, 'section' => null],
            ['key' => 'email_setup', 'label' => 'Configure Email Settings', 'info' => 'Set the outgoing mail server and from-address.', 'done' => is_string($smtpFrom) && $smtpFrom !== '' && $smtpFrom !== 'from@email.com', 'section' => 'smtp'],
            ['key' => 'cron_setup', 'label' => 'Cron Setup', 'info' => 'Scheduled jobs (reminders, backups) run once the cron has fired.', 'done' => ! empty($probes['cron'] ?? null), 'section' => 'global'],
            ['key' => 'company_logo', 'label' => 'Add Company Logo', 'info' => 'This logo will show on Login, Register, and Sidebar menu.', 'done' => ! empty($probes['logo'] ?? null), 'section' => 'global'],
            ['key' => 'favicon', 'label' => 'Add Favicon', 'info' => 'This icon shows near the page title on the browser.', 'done' => ! empty($probes['favicon'] ?? null), 'section' => 'global'],
        ];
        $completed = count(array_filter($steps, static fn (array $s): bool => $s['done']));
        $total = count($steps);

        return [
            'steps' => $steps,
            'completed' => $completed,
            'total' => $total,
            'percent' => $total > 0 ? (int) floor($completed / $total * 100) : 100,
        ];
    }

    /**
     * The evaluated checklist for a user, or null when the
     * user may not see it (not a settings admin).
     *
     * @return array{steps: array<int, array<string, mixed>>, completed: int, total: int, percent: int, hub_url: ?string}|null
     */
    public static function forUser(mixed $user): ?array
    {
        if ($user === null || ! Access::allows($user, 'settings.view')) {
            return null;
        }

        $probes = ['users' => 0, 'smtp_from' => null, 'cron' => null, 'logo' => null, 'favicon' => null];
        try {
            if (Schema::hasTable('users')) {
                $probes['users'] = (int) DB::table('users')->count();
            }
            if (Schema::hasTable('smtp_settings') && Schema::hasColumn('smtp_settings', 'mail_from_email')) {
                $probes['smtp_from'] = DB::table('smtp_settings')->value('mail_from_email');
            }
            if (Schema::hasTable('global_settings')) {
                $row = DB::table('global_settings')->first();
                if ($row !== null) {
                    $probes['cron'] = $row->last_cron_run ?? null;
                    $probes['logo'] = $row->logo ?? null;
                    $probes['favicon'] = $row->favicon ?? null;
                }
            }
        } catch (\Throwable) {
            // Probes are best-effort: a missing table reads
            // as "not set up yet", never as an error page.
        }

        $result = self::evaluate($probes);
        $result['hub_url'] = self::hubUrl();

        return $result;
    }

    /**
     * The topbar summary: the checklist, but only while
     * incomplete (the source hides the widget at 100%).
     */
    public static function summary(mixed $user): ?array
    {
        $result = self::forUser($user);

        return ($result === null || $result['completed'] >= $result['total']) ? null : $result;
    }

    /**
     * The settings hub's index URL: the first slice whose
     * Heart declares bound settings sections (the D1 hub),
     * resolved from its own routes file.
     */
    public static function hubUrl(): ?string
    {
        try {
            $manager = app(SliceManager::class);
            $manager->discover();
            foreach ($manager->discoveredSlices() as $manifest) {
                $raw = ManifestRepository::readFromSliceDir($manifest->path);
                if (! is_array($raw) || empty($raw['settings']) || ! is_array($raw['settings'])) {
                    continue;
                }
                $webFile = $manifest->path.'/Routes/web.php';
                if (! is_file($webFile)) {
                    continue;
                }
                $routes = (string) file_get_contents($webFile);
                if (preg_match("/->name\('([a-z0-9_.]+)\.'\)/", $routes, $m)
                    && Route::has($m[1].'.index')) {
                    return route($m[1].'.index');
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }
}
