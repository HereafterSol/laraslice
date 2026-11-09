<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Feature\Porting;

use LaraSlice\Porting\PortScorecard;
use LaraSlice\Tests\TestCase;

/**
 * Porter fidelity scorecard (Proposal #5): the porter run
 * scores its own output in the port matrix's established
 * vocabulary (must 3 / should 2 / could 1, yes 1 / partial
 * ½ / no 0, skips excluded, the matrix verdict rule), from
 * the run's inspection and results — captured-or-flagged,
 * never silently dropped; unmeasured dimensions are named,
 * never scored as zero.
 *
 * Pre-Phase-D the class did not exist: every test fails by
 * absence.
 */
final class PortScorecardTest extends TestCase
{
    /**
     * A clean two-table source: books belongs to authors via
     * an explicit FK, an enum status, a tenant column on
     * both tables.
     *
     * @return array<string, mixed>
     */
    private function cleanInspection(): array
    {
        return [
            'domain' => 'Adjudication',
            'tenancy' => ['enabled' => true, 'tenant_key' => 'company_id'],
            'tables' => [
                'authors' => [
                    'columns' => [
                        ['name' => 'id', 'type' => 'integer'],
                        ['name' => 'name', 'type' => 'string'],
                        ['name' => 'company_id', 'type' => 'integer'],
                    ],
                    'foreign_keys' => [],
                    'foreign_key_targets' => [],
                ],
                'books' => [
                    'columns' => [
                        ['name' => 'id', 'type' => 'integer'],
                        ['name' => 'author_id', 'type' => 'integer'],
                        ['name' => 'title', 'type' => 'string'],
                        ['name' => 'status', 'type' => 'enum', 'raw_type' => "enum('draft','published','archived')"],
                        ['name' => 'company_id', 'type' => 'integer'],
                    ],
                    'foreign_keys' => ['author_id'],
                    'foreign_key_targets' => ['author_id' => 'authors'],
                ],
            ],
            'classified' => ['system' => []],
            'unmapped_pivots' => [],
            'studio_slices' => [
                [
                    'name' => 'Authors',
                    'allRelations' => [],
                    'blueprint' => [
                        'models' => [[
                            'handle' => 'author',
                            'table' => 'authors',
                            'root' => true,
                            'multitenant' => true,
                            'fields' => [
                                ['handle' => 'name', 'type' => 'string'],
                                ['handle' => 'company_id', 'type' => 'integer'],
                            ],
                            'relations' => [[
                                'name' => 'books', 'type' => 'hasMany', 'model' => 'book',
                                'foreign_key' => 'author_id', 'table' => 'books',
                            ]],
                        ]],
                    ],
                ],
                [
                    'name' => 'Books',
                    'allRelations' => [],
                    'blueprint' => [
                        'models' => [[
                            'handle' => 'book',
                            'table' => 'books',
                            'root' => true,
                            'multitenant' => true,
                            'fields' => [
                                ['handle' => 'author_id', 'type' => 'integer'],
                                ['handle' => 'title', 'type' => 'string'],
                                ['handle' => 'status', 'type' => 'string', 'options' => ['draft' => 'Draft', 'published' => 'Published', 'archived' => 'Archived']],
                                ['handle' => 'company_id', 'type' => 'integer'],
                            ],
                            'relations' => [],
                        ]],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, array<string, string>> rows keyed by feature */
    private function rowsByFeature(array $card): array
    {
        $byFeature = [];
        foreach ($card['rows'] as $row) {
            $byFeature[$row['feature']] = $row;
        }

        return $byFeature;
    }

    public function test_a_clean_port_scores_full_marks_and_shippable(): void
    {
        $card = PortScorecard::fromInspectionRun($this->cleanInspection(), ['data_import' => null]);

        $this->assertSame(100.0, $card['score']);
        $this->assertSame('SHIPPABLE', $card['verdict']);
        $this->assertSame($card['must_haves']['total'], $card['must_haves']['done']);
        // No data import ran: the dimension is NOT MEASURED,
        // named — not scored zero.
        $dimensions = array_column($card['not_measured'], 'dimension');
        $this->assertContains('Data', $dimensions);
        $this->assertContains('Gate evidence', $dimensions);
    }

    public function test_a_dropped_column_relation_and_enum_score_visibly_worse(): void
    {
        $degraded = $this->cleanInspection();
        // Drop the title field, the status options and the
        // hasMany relation from the produced declarations.
        $degraded['studio_slices'][1]['blueprint']['models'][0]['fields'] = array_values(array_filter(
            $degraded['studio_slices'][1]['blueprint']['models'][0]['fields'],
            static fn ($f) => $f['handle'] !== 'title'
        ));
        foreach ($degraded['studio_slices'][1]['blueprint']['models'][0]['fields'] as &$field) {
            if ($field['handle'] === 'status') {
                $field['options'] = [];
            }
        }
        unset($field);
        $degraded['studio_slices'][0]['blueprint']['models'][0]['relations'] = [];

        $clean = PortScorecard::fromInspectionRun($this->cleanInspection(), ['data_import' => null]);
        $card = PortScorecard::fromInspectionRun($degraded, ['data_import' => null]);
        $rows = $this->rowsByFeature($card);

        $this->assertLessThan($clean['score'], $card['score']);
        $this->assertSame('no', $rows['Column books.title declared']['port']);
        $this->assertSame('no', $rows['Allowable values books.status captured']['port']);
        // The FK still survives as a field (name-derived
        // target agrees), so Relations stays honest at yes.
        $this->assertSame('yes', $rows['Foreign key books.author_id → authors']['port']);
    }

    public function test_an_fk_whose_real_target_differs_from_its_name_is_flagged_partial(): void
    {
        $inspection = $this->cleanInspection();
        // department_id → teams (the F-programme decoy shape):
        // the field is named for departments, the schema
        // points at teams, and no relation override exists.
        $inspection['tables']['books']['columns'][] = ['name' => 'department_id', 'type' => 'integer'];
        $inspection['tables']['books']['foreign_keys'][] = 'department_id';
        $inspection['tables']['books']['foreign_key_targets']['department_id'] = 'teams';
        $inspection['studio_slices'][1]['blueprint']['models'][0]['fields'][] = ['handle' => 'department_id', 'type' => 'integer'];

        $card = PortScorecard::fromInspectionRun($inspection, ['data_import' => null]);
        $rows = $this->rowsByFeature($card);

        $this->assertSame('partial', $rows['Foreign key books.department_id → teams']['port']);
        $this->assertStringContainsString('wrong model', $rows['Foreign key books.department_id → teams']['notes']);
    }

    public function test_an_unmapped_pivot_and_a_missing_target_table_are_flagged_not_hidden(): void
    {
        $inspection = $this->cleanInspection();
        $inspection['tables']['book_tags'] = [
            'columns' => [['name' => 'id', 'type' => 'integer'], ['name' => 'book_id', 'type' => 'integer'], ['name' => 'tag_id', 'type' => 'integer']],
            'foreign_keys' => ['book_id', 'tag_id'],
            'foreign_key_targets' => [],
        ];
        $inspection['unmapped_pivots'] = ['book_tags'];

        $card = PortScorecard::fromInspectionRun($inspection, [
            'data_import' => [
                'results' => ['books' => ['status' => 'not_in_target', 'target_table' => 'books', 'rows' => 0]],
                'missing_target_tables' => ['books'],
                'skipped_tables' => [],
            ],
        ]);
        $rows = $this->rowsByFeature($card);

        $this->assertSame('no', $rows['Table book_tags declared as a slice model']['port']);
        $this->assertStringContainsString('Unmapped pivot', $rows['Table book_tags declared as a slice model']['notes']);
        $this->assertSame('no', $rows['Data for table books imported']['port']);
        $this->assertSame('NOT SHIPPABLE', $card['verdict']);
    }

    public function test_a_column_listed_twice_by_the_extractor_earns_one_row(): void
    {
        $inspection = $this->cleanInspection();
        // The migration extractor lists author_id twice when
        // the migration also declares its foreign key.
        $inspection['tables']['books']['columns'][] = ['name' => 'author_id', 'type' => 'foreign'];

        $card = PortScorecard::fromInspectionRun($inspection, ['data_import' => null]);
        $count = 0;
        foreach ($card['rows'] as $row) {
            if ($row['feature'] === 'Column books.author_id declared') {
                $count++;
            }
        }

        $this->assertSame(1, $count);
    }

    public function test_an_enum_column_without_retained_values_is_flagged_not_skipped(): void
    {
        $inspection = $this->cleanInspection();
        // The extraction saw an enum column but retained no
        // value list (the pre-fix migration-extraction shape).
        foreach ($inspection['tables']['books']['columns'] as &$column) {
            if ($column['name'] === 'status') {
                unset($column['raw_type']);
                $column['type'] = 'enum';
            }
        }
        unset($column);
        foreach ($inspection['studio_slices'][1]['blueprint']['models'][0]['fields'] as &$field) {
            if ($field['handle'] === 'status') {
                $field['options'] = [];
            }
        }
        unset($field);

        $card = PortScorecard::fromInspectionRun($inspection, ['data_import' => null]);
        $rows = $this->rowsByFeature($card);

        $this->assertSame('no', $rows['Allowable values books.status captured']['port']);
        $this->assertStringContainsString('retained no value list', $rows['Allowable values books.status captured']['notes']);
    }

    public function test_system_tables_are_skips_with_a_reason_never_scored(): void
    {
        $inspection = $this->cleanInspection();
        $inspection['tables']['sessions'] = [
            'columns' => [['name' => 'id', 'type' => 'string'], ['name' => 'payload', 'type' => 'text']],
            'foreign_keys' => [],
            'foreign_key_targets' => [],
        ];
        $inspection['classified']['system'] = ['sessions' => $inspection['tables']['sessions']];

        $card = PortScorecard::fromInspectionRun($inspection, ['data_import' => null]);
        $rows = $this->rowsByFeature($card);

        $this->assertSame('skip', $rows['Table sessions carried']['port']);
        $this->assertSame(100.0, $card['score']); // the skip does not dent the score
    }

    public function test_the_matrix_scoring_rule_itself(): void
    {
        $mk = static fn (string $priority, string $port) => [
            'feature' => 'x', 'area' => 'A', 'priority' => $priority, 'source' => 'yes',
            'port' => $port, 'evidence' => '', 'notes' => '',
        ];

        // must 3 × yes + should 2 × partial(½) + could 1 × no
        // = (3 + 1) / 6 = 66.7; a must is fully done but the
        // score is under 80 → NOT SHIPPABLE.
        $scored = PortScorecard::score([$mk('must', 'yes'), $mk('should', 'partial'), $mk('could', 'no')]);
        $this->assertSame(66.7, $scored['score']);
        $this->assertSame('NOT SHIPPABLE', $scored['verdict']);
        $this->assertSame(['done' => 1, 'total' => 1], $scored['must_haves']);

        // A partial must is NOT done at any score.
        $scored = PortScorecard::score([$mk('must', 'partial'), $mk('should', 'yes'), $mk('should', 'yes'), $mk('should', 'yes')]);
        $this->assertSame('NOT SHIPPABLE', $scored['verdict']);

        // Skips are excluded entirely.
        $scored = PortScorecard::score([$mk('must', 'yes'), $mk('must', 'skip')]);
        $this->assertSame(100.0, $scored['score']);
        $this->assertSame('SHIPPABLE', $scored['verdict']);
        $this->assertSame(['done' => 1, 'total' => 1], $scored['must_haves']);
    }

    public function test_emit_writes_the_durable_artefacts(): void
    {
        $dir = sys_get_temp_dir().'/laraslice-scorecard-'.uniqid();
        try {
            $card = PortScorecard::fromInspectionRun($this->cleanInspection(), ['data_import' => null]);
            $files = PortScorecard::emit($card, $dir);

            $this->assertFileExists($files['json']);
            $this->assertFileExists($files['markdown']);
            $decoded = json_decode((string) file_get_contents($files['json']), true);
            $this->assertSame('porter-fidelity-scorecard', $decoded['kind']);
            $markdown = (string) file_get_contents($files['markdown']);
            $this->assertStringContainsString('Fidelity score', $markdown);
            // The machine artefact carries the NOT MEASURED
            // list itself (goldfish round: this line previously
            // compared a literal to itself and could not fail).
            $this->assertNotEmpty($decoded['not_measured'] ?? null);
            $this->assertStringContainsString('Not measured by this run', $markdown);
            // The gate disclaimer rides on the artefact's face.
            $this->assertStringContainsString('never upgrades a gate verdict', $markdown);
        } finally {
            if (is_dir($dir)) {
                array_map('unlink', glob($dir.'/*') ?: []);
                rmdir($dir);
            }
        }
    }

    public function test_plan_mode_scores_created_slices_and_names_unmeasured_dimensions(): void
    {
        $plan = [
            'domain' => 'ERP',
            'modules' => [[
                'name' => 'Work',
                'slices' => [
                    ['name' => 'Tasks', 'root_table' => 'tasks', 'child_tables' => ['task_users']],
                    ['name' => 'Projects', 'root_table' => 'projects', 'child_tables' => []],
                ],
            ]],
        ];
        $run = [
            'slices_created' => ['Tasks'],
            'child_tables_created' => [],
            'errors' => ['Projects' => 'generator exploded'],
            'collisions' => [],
            'data_import' => null,
        ];

        $card = PortScorecard::fromPlanRun($plan, $run);
        $rows = $this->rowsByFeature($card);

        $this->assertSame('yes', $rows['Slice Tasks scaffolded']['port']);
        $this->assertSame('no', $rows['Slice Projects scaffolded']['port']);
        $this->assertSame('no', $rows['Child table task_users of Tasks scaffolded']['port']);
        $this->assertSame('NOT SHIPPABLE', $card['verdict']);
        $dimensions = array_column($card['not_measured'], 'dimension');
        $this->assertContains('Relations', $dimensions);
        $this->assertContains('Allowable values', $dimensions);
    }
}
