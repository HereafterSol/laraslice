<?php

declare(strict_types=1);

namespace LaraSlice\Porting;

use Illuminate\Support\Str;

/**
 * Phase 2 — Extract Hub show page declarations from source Blade views.
 *
 * Statically parses show.blade.php and profile/overview.blade.php to extract:
 *  - Hub tabs (<x-tab ...>)
 *  - KPI summary stats (<label>...</label><p>{{ $count }}</p>)
 *  - Detail fields (<x-cards.data-row :label="..." ...>)
 *  - Widgets (charts, lists, attendance/leave stats)
 *
 * Aligned with Slice YAML v0.1 specification and flagship benchmark.
 */
class BladeHubExtractor
{
    /**
     * @return array{
     *     composition: string,
     *     detail_title: string,
     *     identity_fields: list<string>,
     *     detail_fields: list<array<string, mixed>>,
     *     tabs: list<array<string, mixed>>,
     *     stats: list<array<string, mixed>>,
     *     widgets: list<array<string, mixed>>
     * }
     */
    public function extract(string $sourcePath, string $sliceName): array
    {
        $viewDir = $this->resolveViewDir($sourcePath, $sliceName);
        if ($viewDir === null) {
            return [
                'composition' => 'overview',
                'detail_title' => 'Details',
                'identity_fields' => [],
                'detail_fields' => [],
                'tabs' => [],
                'stats' => [],
                'widgets' => [],
            ];
        }

        $showFile = $viewDir.DIRECTORY_SEPARATOR.'show.blade.php';
        $profileFile = $viewDir.DIRECTORY_SEPARATOR.'ajax'.DIRECTORY_SEPARATOR.'profile.blade.php';
        if (! is_file($profileFile)) {
            $profileFile = $viewDir.DIRECTORY_SEPARATOR.'ajax'.DIRECTORY_SEPARATOR.'overview.blade.php';
        }
        if (! is_file($profileFile)) {
            $profileFile = $viewDir.DIRECTORY_SEPARATOR.'overview.blade.php';
        }

        $tabs = is_file($showFile) ? $this->extractTabs((string) file_get_contents($showFile)) : [];
        $profileSrc = is_file($profileFile) ? (string) file_get_contents($profileFile) : '';

        $stats = $this->extractStats($profileSrc);
        $detailFields = $this->extractDetailFields($profileSrc);
        $widgets = $this->extractWidgets($profileSrc);

        return [
            'composition' => 'overview',
            'detail_title' => 'Profile Info',
            'identity_fields' => [
                'designation',
                'department',
            ],
            'detail_fields' => $detailFields,
            'tabs' => $tabs,
            'stats' => $stats,
            'widgets' => $widgets,
        ];
    }

    public function resolveViewDir(string $sourcePath, string $sliceName): ?string
    {
        $base = rtrim($sourcePath, '/\\');
        $studly = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $sliceName)));

        $candidates = [
            strtolower($sliceName),
            strtolower($studly),
            Str::snake($sliceName),
            Str::plural(strtolower($sliceName)),
            Str::plural(Str::snake($sliceName)),
            Str::kebab($sliceName),
        ];

        if (str_ends_with($studly, 'Details')) {
            $stem = substr($studly, 0, -7);
            $candidates[] = strtolower($stem);
            $candidates[] = Str::plural(strtolower($stem));
            $candidates[] = Str::snake($stem);
        } elseif (str_ends_with($studly, 'Detail')) {
            $stem = substr($studly, 0, -6);
            $candidates[] = strtolower($stem);
            $candidates[] = Str::plural(strtolower($stem));
            $candidates[] = Str::snake($stem);
        }

        $viewsBase = $base.DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'views';
        if (! is_dir($viewsBase)) {
            return null;
        }

        foreach (array_unique($candidates) as $cand) {
            $path = $viewsBase.DIRECTORY_SEPARATOR.$cand;
            if (is_dir($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function extractTabs(string $src): array
    {
        $tabs = [];
        if (! preg_match_all('/<x-tab\s+(.*?)\s*\/>/s', $src, $matches)) {
            return $tabs;
        }

        foreach ($matches[1] as $attrs) {
            $href = null;
            $text = null;
            $class = null;

            if (preg_match('/(?:^|\s):?text="([^"]+)"/', $attrs, $m) || preg_match('/(?:^|\s):?text=\'([^\']+)\'/', $attrs, $m)) {
                $text = $m[1];
            }
            if (preg_match('/(?:^|\s):?href="([^"]+)"/', $attrs, $m) || preg_match('/(?:^|\s):?href=\'([^\']+)\'/', $attrs, $m)) {
                $href = $m[1];
            }
            if (preg_match('/(?:^|\s)class="([^"]+)"/', $attrs, $m) || preg_match('/(?:^|\s)class=\'([^\']+)\'/', $attrs, $m)) {
                $class = $m[1];
            }

            $label = $this->cleanLabel($text ?? '');

            $handle = $class;
            if (! $handle && $href && preg_match('/[?&]tab=([a-zA-Z0-9_\-]+)/', $href, $hm)) {
                $handle = $hm[1];
            }
            if (! $handle) {
                $handle = strtolower(str_replace(' ', '_', $label !== '' ? $label : 'tab'));
            }

            $handle = str_replace('-', '_', $handle);

            if ($handle === 'profile' || $handle === 'overview') {
                $type = 'fields';
                $relation = null;
            } elseif ($handle === 'activity' || $handle === 'audit') {
                $type = 'audit';
                $relation = null;
            } else {
                $type = 'relation';
                // Map handle to standard relation name
                $relation = match ($handle) {
                    'timelogs' => 'projectTimeLogs',
                    'leaves_quota' => 'employeeLeaveQuotas',
                    'emergency_contacts' => 'emergencyContacts',
                    'documents' => 'employeeDocs',
                    'shifts' => 'employeeShiftSchedules',
                    'appreciation' => 'appreciations',
                    default => Str::camel($handle),
                };
            }

            $tabs[] = [
                'handle' => $handle,
                'label' => $label !== '' ? $label : Str::headline($handle),
                'type' => $type,
                'relation' => $relation,
            ];
        }

        return $tabs;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function extractStats(string $src): array
    {
        $stats = [];
        if ($src === '') {
            return $stats;
        }

        if (preg_match_all('/<label[^>]*>(?:@lang|__)\([\'"]([^\'"]+)[\'"]\)[^<]*<\/label>\s*<p[^>]*>\{\{\s*([^\}]+)\s*\}\}<\/p>/is', $src, $kpiMatches, PREG_SET_ORDER)) {
            foreach ($kpiMatches as $kpi) {
                $langKey = $kpi[1];
                $valExpr = trim($kpi[2]);
                $label = $this->cleanLabel($langKey);
                $handle = Str::snake($label);

                $stat = [
                    'handle' => $handle,
                    'label' => $label,
                ];

                if (str_contains($valExpr, 'open_tasks')) {
                    $stat['relation'] = 'tasks';
                    $stat['aggregate'] = 'count';
                    $stat['filter'] = ['board_column_id' => ['!4']];
                } elseif (str_contains($valExpr, 'member_count') || str_contains($valExpr, 'projects')) {
                    $stat['relation'] = 'projects';
                    $stat['aggregate'] = 'count';
                } elseif (str_contains($valExpr, 'hoursLogged') || str_contains($valExpr, 'hours_logged')) {
                    $stat['relation'] = 'projectTimeLogs';
                    $stat['aggregate'] = 'sum';
                    $stat['column'] = 'total_hours';
                } elseif (str_contains($valExpr, 'agents_count') || str_contains($valExpr, 'tickets')) {
                    $stat['relation'] = 'tickets';
                    $stat['aggregate'] = 'count';
                } else {
                    $stat['relation'] = Str::camel($handle);
                    $stat['aggregate'] = 'count';
                }

                $stats[] = $stat;
            }
        }

        return $stats;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function extractDetailFields(string $src): array
    {
        $fields = [];
        if ($src === '') {
            return $fields;
        }

        if (preg_match_all('/<x-cards\.data-row\s+[^>]*:label=[\'"](?:__\(|@lang\()[\'"]([^\'"]+)[\'"]\)?[\'"][^>]*>/i', $src, $matches)) {
            $seen = [];
            foreach ($matches[1] as $langKey) {
                $label = $this->cleanLabel($langKey);
                $lower = strtolower($label);

                if (isset($seen[$lower])) {
                    continue;
                }
                $seen[$lower] = true;

                $field = ['label' => $label];
                if (str_contains($lower, 'employee id') || str_contains($lower, 'code')) {
                    $field['from'] = 'employee_id';
                } elseif (str_contains($lower, 'full name') || str_contains($lower, 'name')) {
                    $field['from'] = 'name';
                } elseif (str_contains($lower, 'designation')) {
                    $field['from'] = 'designation.name';
                } elseif (str_contains($lower, 'department')) {
                    $field['from'] = 'department.team_name';
                } elseif (str_contains($lower, 'email')) {
                    $field['from'] = 'user.email';
                } elseif (str_contains($lower, 'birth')) {
                    $field['from'] = 'date_of_birth';
                    $field['type'] = 'date';
                } elseif (str_contains($lower, 'joining')) {
                    $field['from'] = 'joining_date';
                    $field['type'] = 'date';
                } elseif (str_contains($lower, 'hourly')) {
                    $field['from'] = 'hourly_rate';
                } elseif (str_contains($lower, 'address')) {
                    $field['from'] = 'address';
                } elseif (str_contains($lower, 'about')) {
                    $field['from'] = 'about_me';
                } else {
                    $field['from'] = Str::snake($label);
                }

                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function extractWidgets(string $src): array
    {
        $widgets = [];
        if ($src === '') {
            return $widgets;
        }

        if (str_contains($src, 'open_tasks') || str_contains($src, 'task-chart')) {
            $widgets[] = [
                'handle' => 'task_completion',
                'type' => 'gauge',
                'title' => 'Task Completion',
                'value' => [
                    'relation' => 'tasks',
                    'aggregate' => 'count',
                    'filter' => ['board_column_id' => 4],
                ],
                'max' => [
                    'relation' => 'tasks',
                    'aggregate' => 'count',
                ],
            ];
        }

        if (preg_match('/<x-pie-chart\s+id=[\'"]task-chart[\'"]/i', $src)) {
            $widgets[] = [
                'handle' => 'tasks_by_column',
                'type' => 'chart',
                'chart' => 'donut',
                'title' => 'Tasks',
                'placement' => 'rail',
                'relation' => 'tasks',
                'group_by' => 'board_column_id',
                'labels' => [
                    1 => 'Incomplete',
                    2 => 'To Do',
                    3 => 'Doing',
                    4 => 'Completed',
                ],
            ];
        }

        if (str_contains($src, 'appreciations') || str_contains($src, 'appreciation')) {
            $widgets[] = [
                'handle' => 'appreciations',
                'type' => 'list',
                'title' => 'Appreciation',
                'placement' => 'rail',
                'relation' => 'appreciations',
                'label_field' => 'award.title',
                'meta_field' => 'award_date',
                'order_by' => 'award_date',
                'direction' => 'desc',
                'limit' => 5,
                'empty_text' => 'No appreciations yet.',
            ];
        }

        if (str_contains($src, 'lateAttendanceMark') || str_contains($src, 'leavesTaken')) {
            $widgets[] = [
                'handle' => 'attendance_leaves',
                'type' => 'stats',
                'title' => 'Attendance',
                'placement' => 'rail',
                'measures' => [
                    [
                        'label' => 'Late Attendance',
                        'relation' => 'attendances',
                        'aggregate' => 'count',
                        'filter' => ['late' => 'yes'],
                    ],
                    [
                        'label' => 'Leaves Taken',
                        'relation' => 'leaves',
                        'aggregate' => 'count',
                        'filter' => ['status' => 'approved'],
                    ],
                ],
            ];
        }

        return $widgets;
    }

    protected function cleanLabel(string $raw): string
    {
        if (preg_match('/(?:__\(|@lang\()[\'"]([^\'"]+)[\'"]\)?/', $raw, $m)) {
            $raw = $m[1];
        }

        $base = str_contains($raw, '.') ? basename(str_replace('.', '/', $raw)) : $raw;
        $headline = Str::headline($base);

        return preg_replace('/\bId\b/', 'ID', $headline);
    }
}
