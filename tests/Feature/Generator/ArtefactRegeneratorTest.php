<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Feature\Generator;

use LaraSlice\Core\Discovery\SliceManifest;
use LaraSlice\Generator\ArtefactRegenerator;
use LaraSlice\Generator\SliceGenerator;
use LaraSlice\Tests\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Artefact regeneration (Comparative Fix Programme
 * tooling gap): the service class, primary model and show
 * blade bake manifest data in at generation time, and
 * slice:regen-form cannot re-sync them. The regenerator
 * stages the whole slice from its (corrected) Heart and
 * transplants only those three artefacts; its diff must
 * name anything the Heart cannot re-derive, a transplant
 * that would destroy a detected loss must be refused by
 * default, and --write must back the originals up.
 */
final class ArtefactRegeneratorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/ls-artefacts-'.uniqid();
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->removeTree($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function generateWidget(): string
    {
        // Tiered like every real slice (app + domain
        // folders), so the manifest carries a real namespace
        // the regenerator must reproduce faithfully.
        return (new SliceGenerator($this->root, 'App\\Slices'))->generate('ArtefactWidget', [
            ['name' => 'title', 'type' => 'string', 'nullable' => false],
            ['name' => 'starts_on', 'type' => 'date', 'nullable' => true],
            ['name' => 'owner_id', 'type' => 'integer', 'nullable' => true],
            ['name' => 'notes', 'type' => 'text', 'nullable' => true],
        ], false, ['app' => 'Sandbox', 'domain' => 'Testing']);
    }

    /** @return array<string, string> artefact key => absolute path */
    private function artefactPaths(string $sliceDir): array
    {
        return [
            'service' => $sliceDir.'/Services/ArtefactWidgetSliceService.php',
            'model' => $sliceDir.'/Models/ArtefactWidget.php',
            'show' => $sliceDir.'/Resources/views/show.blade.php',
        ];
    }

    private function rowFor(array $report, string $key): array
    {
        $row = collect($report)->firstWhere('artefact', $key);
        $this->assertNotNull($row, "diff report has no row for {$key}");

        return $row;
    }

    public function test_untouched_slice_artefacts_are_identical(): void
    {
        $sliceDir = $this->generateWidget();
        $manifest = new SliceManifest($sliceDir.'/slice.yaml');
        foreach ($this->artefactPaths($sliceDir) as $path) {
            $this->assertFileExists($path);
        }

        $regenerator = new ArtefactRegenerator;
        $staged = $regenerator->stage($manifest);
        try {
            $report = $regenerator->diff($manifest, $staged);
            foreach (ArtefactRegenerator::ARTEFACTS as $key) {
                $this->assertSame('identical', $this->rowFor($report, $key)['status'], "{$key} should stage byte-identical");
            }
            $split = $regenerator->writableRows($report, false);
            $this->assertSame([], $split['writable']);
            $this->assertSame([], $split['blocked']);
        } finally {
            $regenerator->discardStaging($staged['staging']);
        }
    }

    public function test_stale_artefacts_are_resynced_to_a_corrected_manifest(): void
    {
        $sliceDir = $this->generateWidget();
        $paths = $this->artefactPaths($sliceDir);

        // The Heart is corrected (a field the artefacts were
        // baked without); service/model/show are now stale.
        $yamlPath = $sliceDir.'/slice.yaml';
        $data = Yaml::parseFile($yamlPath);
        $data['fields']['priority'] = [
            'name' => 'priority', 'label' => 'Priority', 'type' => 'string',
            'nullable' => true, 'default' => null, 'options' => [],
        ];
        file_put_contents($yamlPath, Yaml::dump($data, 6, 2));
        $manifest = new SliceManifest($yamlPath);

        $before = [];
        foreach ($paths as $key => $path) {
            $before[$key] = md5_file($path);
        }

        $regenerator = new ArtefactRegenerator;
        $staged = $regenerator->stage($manifest);
        try {
            $report = $regenerator->diff($manifest, $staged);
            $this->assertSame('changed', $this->rowFor($report, 'model')['status']);
            $this->assertSame('changed', $this->rowFor($report, 'service')['status']);
            $this->assertContains($this->rowFor($report, 'show')['status'], ['changed', 'identical']);

            // Dry-run (stage + diff) must not write anything.
            foreach ($paths as $key => $path) {
                $this->assertSame($before[$key], md5_file($path), "{$key} changed during a dry-run");
            }

            $split = $regenerator->writableRows($report, false);
            $this->assertSame([], $split['blocked'], 'a pure manifest addition must not be blocked');
            $result = $regenerator->apply($manifest, $staged, $split['writable'], $this->root.'/backups');

            // Backups hold the stale originals.
            foreach ($split['writable'] as $row) {
                $backupPath = $result['backupDir'].'/'.$row['file'];
                $this->assertFileExists($backupPath);
                $this->assertSame($before[$row['artefact']], md5_file($backupPath), "backup of {$row['file']} is not the stale original");
            }

            // Artefacts now match the corrected Heart.
            $this->assertStringContainsString("'priority'", (string) file_get_contents($paths['model']));
            $this->assertStringContainsString("'priority'", (string) file_get_contents($paths['service']));

            // Idempotent: a second pass reports no drift.
            $after = $regenerator->diff($manifest, $staged);
            foreach (ArtefactRegenerator::ARTEFACTS as $key) {
                $this->assertSame('identical', $this->rowFor($after, $key)['status'], "{$key} still drifted after --write");
            }
        } finally {
            $regenerator->discardStaging($staged['staging']);
        }
    }

    public function test_hand_added_artefact_content_is_a_loss_blocked_by_default(): void
    {
        $sliceDir = $this->generateWidget();
        $paths = $this->artefactPaths($sliceDir);

        // Hand knowledge the Heart cannot re-derive.
        $model = (string) file_get_contents($paths['model']);
        $model = str_replace("'notes'", "'notes',\n  99 => 'legacy_code'", $model);
        $model = rtrim($model);
        $model = substr($model, 0, -1).<<<'PHP'

    public function legacyOwner(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'owner_id');
    }
}
PHP;
        file_put_contents($paths['model'], $model);

        $service = (string) file_get_contents($paths['service']);
        $service = rtrim($service);
        $service = substr($service, 0, -1).<<<'PHP'

    public function customTotals(): array
    {
        return ['hand' => true];
    }
}
PHP;
        file_put_contents($paths['service'], $service);

        file_put_contents($paths['show'], file_get_contents($paths['show'])."\n@include('hand.partial')\n");

        $manifest = new SliceManifest($sliceDir.'/slice.yaml');
        $regenerator = new ArtefactRegenerator;
        $staged = $regenerator->stage($manifest);
        try {
            $report = $regenerator->diff($manifest, $staged);

            $modelRow = $this->rowFor($report, 'model');
            $this->assertSame('changed', $modelRow['status']);
            $this->assertContains('method:legacyOwner', $modelRow['lost']);
            $this->assertContains('fillable:legacy_code', $modelRow['lost']);

            $serviceRow = $this->rowFor($report, 'service');
            $this->assertContains('method:customTotals', $serviceRow['lost']);

            $showRow = $this->rowFor($report, 'show');
            $this->assertContains('include:hand.partial', $showRow['lost']);

            // Default behaviour: every lossy artefact is
            // blocked, nothing is writable, nothing is lost.
            $split = $regenerator->writableRows($report, false);
            $this->assertSame([], $split['writable']);
            $this->assertCount(3, $split['blocked']);
            $regenerator->apply($manifest, $staged, $split['writable'], $this->root.'/backups');
            $this->assertStringContainsString('legacyOwner', (string) file_get_contents($paths['model']));
            $this->assertStringContainsString('customTotals', (string) file_get_contents($paths['service']));

            // --force knowingly overrides, with backups taken.
            $forced = $regenerator->writableRows($report, true);
            $this->assertSame([], $forced['blocked']);
            $this->assertCount(3, $forced['writable']);
            $result = $regenerator->apply($manifest, $staged, $forced['writable'], $this->root.'/backups');
            $this->assertStringNotContainsString('legacyOwner', (string) file_get_contents($paths['model']));
            $backupModel = $result['backupDir'].'/Models/ArtefactWidget.php';
            $this->assertFileExists($backupModel);
            $this->assertStringContainsString('legacyOwner', (string) file_get_contents($backupModel));
        } finally {
            $regenerator->discardStaging($staged['staging']);
        }
    }

    private function generateTenantWidget(): string
    {
        // Same slice as generateWidget(), but generated the
        // way the porter generates a tenant-scoped source:
        // the multitenant option makes the generator emit
        // the HasTenant trait AND persist the declaration
        // into the Heart it writes.
        return (new SliceGenerator($this->root, 'App\\Slices'))->generate('ArtefactWidget', [
            ['name' => 'title', 'type' => 'string', 'nullable' => false],
            ['name' => 'starts_on', 'type' => 'date', 'nullable' => true],
            ['name' => 'owner_id', 'type' => 'integer', 'nullable' => true],
            ['name' => 'notes', 'type' => 'text', 'nullable' => true],
        ], false, ['app' => 'Sandbox', 'domain' => 'Testing', 'multitenant' => true, 'tenant_key' => 'company_id']);
    }

    public function test_tenancy_declared_slice_regenerates_model_without_trait_loss(): void
    {
        $sliceDir = $this->generateTenantWidget();
        $paths = $this->artefactPaths($sliceDir);
        $yamlPath = $sliceDir.'/slice.yaml';

        // The declaration lives in the Heart and the
        // manifest exposes it; the generated model carries
        // the trait (the ported slices' exact situation).
        $data = Yaml::parseFile($yamlPath);
        $this->assertTrue($data['multitenant'] ?? false, 'Heart does not declare multitenant');
        $manifest = new SliceManifest($yamlPath);
        $this->assertTrue($manifest->multitenant);
        $this->assertStringContainsString('HasTenant', (string) file_get_contents($paths['model']));

        $regenerator = new ArtefactRegenerator;
        $staged = $regenerator->stage($manifest);
        try {
            // Staging reads the declaration: the staged
            // model carries the trait too, so the diff
            // reports NO trait loss.
            $this->assertStringContainsString('HasTenant', (string) file_get_contents($staged['artefacts']['model']));
            $report = $regenerator->diff($manifest, $staged);
            $modelRow = $this->rowFor($report, 'model');
            $this->assertSame([], $modelRow['lost']);
            $this->assertSame('identical', $modelRow['status']);

            // Correct the Heart afterwards (a field the
            // model was baked without): the now-stale model
            // is re-syncable WITHOUT --force, and the trait
            // survives the transplant.
            $data['fields']['priority'] = [
                'name' => 'priority', 'label' => 'Priority', 'type' => 'string',
                'nullable' => true, 'default' => null, 'options' => [],
            ];
            file_put_contents($yamlPath, Yaml::dump($data, 6, 2));
            $manifest = new SliceManifest($yamlPath);
            $staged2 = $regenerator->stage($manifest);
            try {
                $report = $regenerator->diff($manifest, $staged2);
                $modelRow = $this->rowFor($report, 'model');
                $this->assertSame('changed', $modelRow['status']);
                $this->assertNotContains('trait:HasTenant', $modelRow['lost']);
                $this->assertSame([], $modelRow['lost']);

                $split = $regenerator->writableRows($report, false);
                $this->assertContains('model', array_column($split['writable'], 'artefact'), 'tenant model still blocked without --force');
                $regenerator->apply($manifest, $staged2, $split['writable'], $this->root.'/backups');

                $written = (string) file_get_contents($paths['model']);
                $this->assertStringContainsString('HasTenant', $written);
                $this->assertStringContainsString("'priority'", $written);
            } finally {
                $regenerator->discardStaging($staged2['staging']);
            }
        } finally {
            $regenerator->discardStaging($staged['staging']);
        }
    }

    public function test_slice_without_tenancy_declaration_stages_model_without_trait(): void
    {
        $sliceDir = $this->generateWidget();
        $paths = $this->artefactPaths($sliceDir);
        $yamlPath = $sliceDir.'/slice.yaml';

        $data = Yaml::parseFile($yamlPath);
        $this->assertEmpty($data['multitenant'] ?? false, 'undeclared slice unexpectedly declares multitenant');
        $manifest = new SliceManifest($yamlPath);
        $this->assertFalse($manifest->multitenant);
        $this->assertStringNotContainsString('HasTenant', (string) file_get_contents($paths['model']));

        $regenerator = new ArtefactRegenerator;
        $staged = $regenerator->stage($manifest);
        try {
            $this->assertStringNotContainsString('HasTenant', (string) file_get_contents($staged['artefacts']['model']));
            $report = $regenerator->diff($manifest, $staged);
            $modelRow = $this->rowFor($report, 'model');
            $this->assertSame('identical', $modelRow['status']);
            $this->assertSame([], $modelRow['lost']);
        } finally {
            $regenerator->discardStaging($staged['staging']);
        }
    }

    public function test_relation_target_drift_is_a_loss_blocked_by_default(): void
    {
        $sliceDir = $this->generateWidget();
        $paths = $this->artefactPaths($sliceDir);

        // The lived model binds owner() to a different
        // target than the FK evidence chain derives (the
        // EmployeeDetails department→Team shape): staging
        // keeps the method NAME, so the name-keyed loss
        // diff is blind — the drift must surface as a loss
        // and block the transplant by default.
        $model = (string) file_get_contents($paths['model']);
        $this->assertStringContainsString("modelClass('Owner')", $model);
        file_put_contents($paths['model'], str_replace("modelClass('Owner')", "modelClass('User')", $model));

        $manifest = new SliceManifest($sliceDir.'/slice.yaml');
        $regenerator = new ArtefactRegenerator;
        $staged = $regenerator->stage($manifest);
        try {
            $report = $regenerator->diff($manifest, $staged);
            $modelRow = $this->rowFor($report, 'model');
            $this->assertContains('relation-target:owner', $modelRow['lost']);

            $split = $regenerator->writableRows($report, false);
            $this->assertContains('model', array_column($split['blocked'], 'artefact'));
        } finally {
            $regenerator->discardStaging($staged['staging']);
        }
    }

    public function test_staging_honours_the_model_entrys_declared_field_relation(): void
    {
        $sliceDir = $this->generateWidget();

        // The porter's dialect: the root model entry's own
        // field list declares the FK target the top-level
        // field list omits (employee_details.department_id
        // -> team). Staging must honour it instead of
        // deriving the target from the column stem, which
        // names a model that does not exist.
        $heart = $sliceDir.'/slice.yaml';
        $raw = Yaml::parseFile($heart);
        foreach ($raw['models'][0]['fields'] as &$modelField) {
            if (($modelField['handle'] ?? null) === 'owner_id') {
                $modelField['relation'] = ['model' => 'user'];
            }
        }
        unset($modelField);
        file_put_contents($heart, Yaml::dump($raw, 10, 2));

        $manifest = new SliceManifest($sliceDir.'/slice.yaml');
        $regenerator = new ArtefactRegenerator;
        $staged = $regenerator->stage($manifest);
        try {
            $stagedModel = (string) file_get_contents($staged['artefacts']['model']);
            $this->assertStringContainsString("modelClass('User')", $stagedModel);
            $this->assertStringNotContainsString("modelClass('Owner')", $stagedModel);

            // And the drift against the still-'Owner' lived
            // model is REPORTED as a loss, not passed over.
            $report = $regenerator->diff($manifest, $staged);
            $modelRow = $this->rowFor($report, 'model');
            $this->assertContains('relation-target:owner', $modelRow['lost']);
        } finally {
            $regenerator->discardStaging($staged['staging']);
        }
    }

    public function test_namespace_mismatch_is_never_writable(): void
    {
        $sliceDir = $this->generateWidget();
        $paths = $this->artefactPaths($sliceDir);

        // The current model lives in a namespace staging
        // cannot reproduce from the Heart (hand-moved class):
        // transplanting the staged model would break its
        // autoloading, so it must be refused outright.
        $model = (string) file_get_contents($paths['model']);
        $model = str_replace(
            'namespace App\\Slices\\Sandbox\\Testing\\ArtefactWidgets\\Models;',
            'namespace App\\Slices\\Elsewhere\\Models;',
            $model
        );
        file_put_contents($paths['model'], $model);

        $manifest = new SliceManifest($sliceDir.'/slice.yaml');
        $regenerator = new ArtefactRegenerator;
        $staged = $regenerator->stage($manifest);
        try {
            $report = $regenerator->diff($manifest, $staged);
            $this->assertSame('namespace-mismatch', $this->rowFor($report, 'model')['status']);

            // Not writable by default, and --force does not
            // override a staging-fidelity failure.
            foreach ([false, true] as $force) {
                $split = $regenerator->writableRows($report, $force);
                $keys = array_column($split['writable'], 'artefact');
                $this->assertNotContains('model', $keys, 'namespace-mismatched model became writable');
                $regenerator->apply($manifest, $staged, $split['writable'], $this->root.'/backups');
                $this->assertStringContainsString('App\\Slices\\Elsewhere\\Models', (string) file_get_contents($paths['model']));
            }
        } finally {
            $regenerator->discardStaging($staged['staging']);
        }
    }
}
