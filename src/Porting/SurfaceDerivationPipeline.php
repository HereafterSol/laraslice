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

            // 3b. Quick Filters (facets above the table)
            $quickFilters = [];
            foreach ($listAnatomy['facets'] ?? [] as $facet) {
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
