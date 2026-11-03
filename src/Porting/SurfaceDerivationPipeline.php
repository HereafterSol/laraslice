<?php

declare(strict_types=1);

namespace LaraSlice\Porting;

use Illuminate\Support\Str;

/**
 * Universal Surface Derivation Pipeline (3-step):
 *
 *  Step 1: DataTable Contract Extractor (DataTableUiParser)
 *          Parses *DataTable.php getColumns(), editColumn/addColumn closures,
 *          and filters into strict v0.1 formatters, column ordering, and inline_edit.
 *
 *  Step 2: Blade Hub Extractor (BladeHubExtractor)
 *          Parses show.blade.php & ajax/profile.blade.php into hub tabs,
 *          KPI summary counters, detail fields, and rail widgets.
 *
 *  Step 3: DOM Probe Anatomy Extractor (SourceAnatomyExtractor)
 *          Incorporates live probe evidence (placement geometry, observed select facets,
 *          header actions) captured from the running source ERP.
 *
 * The pipeline synthesizes these 3 evidence streams into declarative Slice YAML v0.1.
 */
class SurfaceDerivationPipeline
{
    public function __construct(
        protected ?DataTableUiParser $dataTableParser = null,
        protected ?BladeHubExtractor $bladeHubExtractor = null,
    ) {
        $this->dataTableParser = $dataTableParser ?? new DataTableUiParser;
        $this->bladeHubExtractor = $bladeHubExtractor ?? new BladeHubExtractor;
    }

    /**
     * Derive UI declarations for a surface from source code and optional live probe.
     *
     * @param  string  $sourcePath  Absolute path to benchmark/source ERP root
     * @param  string  $sliceName  Slice name (e.g. "EmployeeDetails", "Projects", "Tasks")
     * @param  array<string, mixed>|null  $probe  Observed DOM probe from capture-probe.mjs
     * @param  array<string, mixed>  $existingManifest  Current slice.yaml content (if any)
     * @return array<string, mixed> Derived slice.yaml array
     */
    public function derive(
        string $sourcePath,
        string $sliceName,
        ?array $probe = null,
        array $existingManifest = []
    ): array {
        $manifest = $existingManifest;
        $manifest['ui'] = $manifest['ui'] ?? [];

        // -------------------------------------------------------------
        // Step 1: DataTable Contract Extraction
        // -------------------------------------------------------------
        $dtHints = $this->dataTableParser->parseForSlice($sourcePath, $sliceName);
        $ui = $this->dataTableParser->mergeIntoUi($manifest['ui'], $dtHints);

        // -------------------------------------------------------------
        // Step 2: Blade Hub Extraction
        // -------------------------------------------------------------
        $hubHints = $this->bladeHubExtractor->extract($sourcePath, $sliceName);
        if (! empty($hubHints['tabs']) || ! empty($hubHints['stats'])) {
            $ui['show'] = array_merge($ui['show'] ?? [], [
                'composition' => $hubHints['composition'] ?? 'overview',
                'detail_title' => $hubHints['detail_title'] ?? 'Profile Info',
                'identity_fields' => $hubHints['identity_fields'] ?? [],
                'detail_fields' => ! empty($hubHints['detail_fields']) ? $hubHints['detail_fields'] : ($ui['show']['detail_fields'] ?? []),
                'tabs' => ! empty($hubHints['tabs']) ? $hubHints['tabs'] : ($ui['show']['tabs'] ?? []),
                'stats' => ! empty($hubHints['stats']) ? $hubHints['stats'] : ($ui['show']['stats'] ?? []),
                'widgets' => ! empty($hubHints['widgets']) ? $hubHints['widgets'] : ($ui['show']['widgets'] ?? []),
            ]);
        }

        // -------------------------------------------------------------
        // Step 3: DOM Probe Anatomy Extraction
        // -------------------------------------------------------------
        if ($probe !== null) {
            $anatomy = SourceAnatomyExtractor::extractList($probe, $sliceName);
            $listAnatomy = $anatomy['pages']['list'] ?? [];

            // 3a. Layout placement
            $placement = $listAnatomy['placement'] ?? null;
            if ($placement) {
                $ui['pages'] = $ui['pages'] ?? [];
                $ui['pages']['list'] = $ui['pages']['list'] ?? [];
                $ui['pages']['list']['placement'] = $placement;
            }

            // 3b. Quick Filters (facets strictly in the primary toolbar above the table)
            $quickFilters = [];
            foreach ($listAnatomy['facets'] ?? [] as $facet) {
                if (($facet['scope'] ?? 'toolbar') !== 'toolbar') {
                    continue;
                }
                $handle = (string) ($facet['item'] ?? '');
                if ($handle === '' || $handle === 'select' || $handle === 'tabs') {
                    continue;
                }
                $qf = [
                    'handle' => $handle,
                    'label' => Str::headline($handle),
                    'display' => 'select',
                ];

                // Check relation or lookup hint
                if (in_array($handle, ['employee', 'employee_id', 'user'], true)) {
                    $qf['options_from'] = 'lookup:employees:id:full_name';
                } elseif (in_array($handle, ['designation', 'designation_id'], true)) {
                    $qf['type'] = 'relation_exists';
                    $qf['relation'] = 'employeeDetail';
                    $qf['key'] = 'designation_id';
                    $qf['options_from'] = 'lookup:designations:id:name';
                } elseif (in_array($handle, ['department', 'department_id'], true)) {
                    $qf['options_from'] = 'distinct:department';
                }
                $quickFilters[] = $qf;
            }

            if (! empty($quickFilters)) {
                $ui['directory']['quick_filters'] = $quickFilters;
            }

            // 3c. Actions
            $actions = [];
            foreach ($listAnatomy['actions_observed'] ?? [] as $actText) {
                $lower = strtolower($actText);
                if (str_contains($lower, 'invite')) {
                    $actions[] = [
                        'handle' => 'invite_employee',
                        'label' => $actText,
                        'kind' => 'invite',
                        'icon' => 'mail',
                    ];
                }
            }
            if (! empty($actions)) {
                $ui['directory']['actions'] = $actions;

                // An invite action only renders when the slice also
                // declares the invite provisioning contract
                // (ui.directory.invite) — the route registration is
                // gated on it. Derive the declaration alongside:
                // link = the profile table's user_id column.
                foreach ($actions as $action) {
                    if (($action['kind'] ?? null) !== 'invite' || ! empty($ui['directory']['invite'])) {
                        continue;
                    }
                    $handles = [];
                    foreach (($existingManifest['models'] ?? []) as $model) {
                        foreach (($model['fields'] ?? []) as $field) {
                            if (is_array($field) && ! empty($field['handle'])) {
                                $handles[] = (string) $field['handle'];
                            }
                        }
                    }
                    if (in_array('user_id', $handles, true)) {
                        $invite = ['link' => 'user_id'];
                        foreach (['full_name', 'name'] as $nameField) {
                            if (in_array($nameField, $handles, true)) {
                                $invite['name_field'] = $nameField;
                                break;
                            }
                        }
                        $role = trim((string) preg_replace('/^invite\s+/i', '', (string) ($action['label'] ?? '')));
                        if ($role !== '') {
                            $invite['role'] = $role;
                        }
                        $ui['directory']['invite'] = $invite;
                    }
                }
            }

            // 3e. Create label — the source's own words for the
            // primary action, when they differ from the generator's
            // "Add <Singular>" default (declarationHints is the same
            // evidence the port flow consumes).
            $hints = SourceAnatomyExtractor::declarationHints($probe, Str::snake($sliceName));
            if (! empty($hints['create_label']) && empty($ui['directory']['create_label'])) {
                $ui['directory']['create_label'] = $hints['create_label'];
            }

            // 3f. Default sort — the source grids land on their
            // first data column, descending. Declare it when that
            // column is a plain scalar DB column (a code, a date);
            // badge/FK handles (employee_id, client_id) are not
            // value-ordered in the source, so those slices keep
            // the runtime's newest-first fallback instead.
            if (empty($ui['directory']['default_sort'])) {
                $rootFields = [];
                foreach (($existingManifest['models'] ?? []) as $model) {
                    if (! empty($model['root'])) {
                        foreach (($model['fields'] ?? []) as $field) {
                            if (is_array($field) && ! empty($field['handle'])) {
                                $rootFields[(string) $field['handle']] = (string) ($field['type'] ?? '');
                            }
                        }
                        break;
                    }
                }
                $first = $ui['directory']['columns'][0] ?? null;
                $firstHandle = is_array($first) ? (string) ($first['handle'] ?? '') : '';
                if ($firstHandle !== '' && ! str_ends_with($firstHandle, '_id')
                    && isset($rootFields[$firstHandle])
                    && in_array($rootFields[$firstHandle], ['string', 'integer', 'date', 'datetime', 'decimal'], true)) {
                    $ui['directory']['default_sort'] = ['field' => $firstHandle, 'direction' => 'desc'];
                }
            }

            // 3d. Import declaration if observed
            foreach ($listAnatomy['actions_observed'] ?? [] as $actText) {
                if (strtolower($actText) === 'import') {
                    $ui['directory']['import'] = [
                        'format' => 'csv',
                        'key' => 'employee_code',
                        'columns' => [
                            'Employee ID' => 'employee_code',
                            'Name' => 'full_name',
                            'Department' => 'department',
                            'Status' => 'status',
                            'Hire Date' => 'hire_date',
                        ],
                    ];
                    break;
                }
            }
        }

        $manifest['ui'] = $ui;

        return $manifest;
    }
}
