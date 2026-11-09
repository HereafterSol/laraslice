<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Feature\Generator;

use LaraSlice\Core\Discovery\SliceManifest;
use LaraSlice\Generator\ArtefactRegenerator;
use LaraSlice\Generator\SliceGenerator;
use LaraSlice\Tests\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * F12 (Day-2 drill, 8 Nov 2026): slice:regen-artefacts
 * regenerated the service, model and show blade from a
 * corrected Heart, but never the CONTRACTS — so a field
 * added to the Heart rendered on the form, validated in
 * the service, and then silently did NOT persist, because
 * the Form business object (whose property list is the
 * request-binding contract: BaseFormBusinessObject::
 * fromArray() only assigns keys that exist as properties)
 * had no property for it. The regenerator now stages,
 * diffs and transplants the Form / Listing / Filter
 * business objects under the same loss discipline.
 *
 * SliceAbsorber's artefact set is deliberately unchanged:
 * the default diff() still covers exactly service, model
 * and show, so absorb's fleet sweep cannot change as a
 * side effect of this fix (pinned by the first test).
 */
final class ArtefactRegeneratorContractsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/ls-contracts-'.uniqid();
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
        return (new SliceGenerator($this->root, 'App\\Slices'))->generate('ContractWidget', [
            ['name' => 'title', 'type' => 'string', 'nullable' => false],
            ['name' => 'starts_on', 'type' => 'date', 'nullable' => true],
            ['name' => 'owner_id', 'type' => 'integer', 'nullable' => true],
            ['name' => 'notes', 'type' => 'text', 'nullable' => true],
        ], false, ['app' => 'Sandbox', 'domain' => 'Testing']);
    }

    /** @return array<string, string> artefact key => absolute path */
    private function contractPaths(string $sliceDir): array
    {
        return [
            'form-bo' => $sliceDir.'/Contracts/ContractWidgetFormBusinessObject.php',
            'listing-bo' => $sliceDir.'/Contracts/ContractWidgetListingBusinessObject.php',
            'filter-bo' => $sliceDir.'/Contracts/ContractWidgetFilterBusinessObject.php',
        ];
    }

    private function rowFor(array $report, string $key): ?array
    {
        return collect($report)->firstWhere('artefact', $key);
    }

    public function test_contracts_stage_identical_and_default_diff_set_is_unchanged(): void
    {
        $sliceDir = $this->generateWidget();
        foreach ($this->contractPaths($sliceDir) as $path) {
            $this->assertFileExists($path);
        }
        $manifest = new SliceManifest($sliceDir.'/slice.yaml');

        $regenerator = new ArtefactRegenerator;
        $staged = $regenerator->stage($manifest);
        try {
            // The Contracts are staged and diffed when the
            // caller asks for the full artefact set.
            $report = $regenerator->diff($manifest, $staged, ArtefactRegenerator::allArtefacts());
            foreach (ArtefactRegenerator::CONTRACT_ARTEFACTS as $key) {
                $row = $this->rowFor($report, $key);
                $this->assertNotNull($row, "full diff has no row for {$key}");
                $this->assertSame('identical', $row['status'], "{$key} should stage byte-identical");
                $this->assertSame([], $row['lost']);
            }

            // The DEFAULT diff set is still exactly the
            // original three — SliceAbsorber's analysis (and
            // its fleet sweep) must not change as a side
            // effect of the F12 fix.
            $defaultReport = $regenerator->diff($manifest, $staged);
            $this->assertSame(
                ArtefactRegenerator::ARTEFACTS,
                array_column($defaultReport, 'artefact'),
                'default diff set changed — absorb classifications are keyed to it'
            );
        } finally {
            $regenerator->discardStaging($staged['staging']);
        }
    }

    public function test_field_added_to_heart_regenerates_the_business_objects(): void
    {
        $sliceDir = $this->generateWidget();
        $paths = $this->contractPaths($sliceDir);

        // Precondition (the drill's exact state): the Form
        // BO has no property for the field the Heart is
        // about to gain, so its value could never bind.
        $this->assertStringNotContainsString('$priority', (string) file_get_contents($paths['form-bo']));

        // The Heart gains a field.
        $yamlPath = $sliceDir.'/slice.yaml';
        $data = Yaml::parseFile($yamlPath);
        $data['fields']['priority'] = [
            'name' => 'priority', 'label' => 'Priority', 'type' => 'integer',
            'nullable' => true, 'default' => null, 'options' => [],
        ];
        file_put_contents($yamlPath, Yaml::dump($data, 6, 2));
        $manifest = new SliceManifest($yamlPath);

        $regenerator = new ArtefactRegenerator;
        $staged = $regenerator->stage($manifest);
        try {
            $report = $regenerator->diff($manifest, $staged, ArtefactRegenerator::allArtefacts());

            $formRow = $this->rowFor($report, 'form-bo');
            $this->assertNotNull($formRow, 'regen does not cover the Form business object (F12)');
            $this->assertSame('changed', $formRow['status']);
            $this->assertSame([], $formRow['lost'], 'a pure manifest addition must not be blocked');

            $split = $regenerator->writableRows($report, false);
            $this->assertContains('form-bo', array_column($split['writable'], 'artefact'));
            $result = $regenerator->apply($manifest, $staged, $split['writable'], $this->root.'/backups');

            // The binding contract now carries the field —
            // this is the property whose absence made the
            // drill's edit silently not persist.
            $formBo = (string) file_get_contents($paths['form-bo']);
            $this->assertStringContainsString('public ?int $priority', $formBo);
            $this->assertFileExists($result['backupDir'].'/Contracts/ContractWidgetFormBusinessObject.php');

            // Listing + Filter BOs follow the same Heart.
            $this->assertStringContainsString('$priority', (string) file_get_contents($paths['listing-bo']));
            $this->assertStringContainsString("'priority'", (string) file_get_contents($paths['filter-bo']));

            // Idempotent: a second full pass is clean.
            $after = $regenerator->diff($manifest, $staged, ArtefactRegenerator::allArtefacts());
            foreach (ArtefactRegenerator::allArtefacts() as $key) {
                $this->assertSame('identical', $this->rowFor($after, $key)['status'], "{$key} still drifted after --write");
            }
        } finally {
            $regenerator->discardStaging($staged['staging']);
        }
    }

    public function test_hand_owned_business_object_members_are_losses_blocked_by_default(): void
    {
        $sliceDir = $this->generateWidget();
        $paths = $this->contractPaths($sliceDir);

        // Hand knowledge the Heart cannot re-derive: an
        // extra property + method on the Form BO, an extra
        // sortable column on the Filter BO.
        $formBo = (string) file_get_contents($paths['form-bo']);
        $formBo = rtrim($formBo);
        $formBo = substr($formBo, 0, -1).<<<'PHP'

    public ?string $legacy_code = NULL;

    public function legacyLabel(): string
    {
        return 'hand';
    }
}
PHP;
        file_put_contents($paths['form-bo'], $formBo);

        $filterBo = (string) file_get_contents($paths['filter-bo']);
        $filterBo = str_replace("'notes'", "'notes', 'legacy_code'", $filterBo);
        file_put_contents($paths['filter-bo'], $filterBo);

        $manifest = new SliceManifest($sliceDir.'/slice.yaml');
        $regenerator = new ArtefactRegenerator;
        $staged = $regenerator->stage($manifest);
        try {
            $report = $regenerator->diff($manifest, $staged, ArtefactRegenerator::allArtefacts());

            $formRow = $this->rowFor($report, 'form-bo');
            $this->assertContains('property:legacy_code', $formRow['lost']);
            $this->assertContains('method:legacyLabel', $formRow['lost']);

            $filterRow = $this->rowFor($report, 'filter-bo');
            $this->assertContains('sortable:legacy_code', $filterRow['lost']);

            // Blocked by default: nothing hand-owned is
            // silently overwritten.
            $split = $regenerator->writableRows($report, false);
            $blockedKeys = array_column($split['blocked'], 'artefact');
            $this->assertContains('form-bo', $blockedKeys);
            $this->assertContains('filter-bo', $blockedKeys);
            $regenerator->apply($manifest, $staged, $split['writable'], $this->root.'/backups');
            $this->assertStringContainsString('legacyLabel', (string) file_get_contents($paths['form-bo']));

            // --force transplants knowingly, with backups.
            $forced = $regenerator->writableRows($report, true);
            $result = $regenerator->apply($manifest, $staged, $forced['writable'], $this->root.'/backups');
            $this->assertStringNotContainsString('legacyLabel', (string) file_get_contents($paths['form-bo']));
            $this->assertStringContainsString(
                'legacyLabel',
                (string) file_get_contents($result['backupDir'].'/Contracts/ContractWidgetFormBusinessObject.php')
            );
        } finally {
            $regenerator->discardStaging($staged['staging']);
        }
    }
}
