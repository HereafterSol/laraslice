<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Unit\Support;

use LaraSlice\Support\DirectoryUi;
use PHPUnit\Framework\TestCase;

/**
 * Fidelity stage 3 — the declared patterns that close the last
 * foundation gaps: P13 import, P14 invite, P15 reference sets,
 * P16 cross-slice actions, strip ranges + date_filter_on, and
 * the list timer cell.
 */
class DeclaredPatternsTest extends TestCase
{
    public function test_import_declaration_is_read_and_validated(): void
    {
        $decl = DirectoryUi::importFromManifest(['ui' => ['directory' => ['import' => [
            'format' => 'csv',
            'key' => 'employee_code',
            'columns' => ['Employee ID' => 'employee_code', 'Name' => 'full_name'],
        ]]]]);
        $this->assertSame('employee_code', $decl['key']);
        $this->assertSame(['Employee ID' => 'employee_code', 'Name' => 'full_name'], $decl['columns']);

        $this->assertNull(DirectoryUi::importFromManifest(['ui' => ['directory' => []]]));
        $this->assertNull(DirectoryUi::importFromManifest(['ui' => ['directory' => ['import' => ['columns' => []]]]]));
    }

    public function test_invite_declaration_defaults(): void
    {
        $decl = DirectoryUi::inviteFromManifest(['ui' => ['directory' => ['invite' => ['link' => 'user_id']]]]);
        $this->assertSame('user_id', $decl['link']);
        $this->assertSame('name', $decl['name_field']);
        $this->assertNull($decl['role']);
        $this->assertNull(DirectoryUi::inviteFromManifest(['ui' => ['directory' => []]]));
    }

    public function test_stage_three_machinery_markers(): void
    {
        $root = dirname(__DIR__, 3);
        $ctl = file_get_contents($root.'/src/Core/Base/BaseSliceWebController.php');
        foreach (['importForm', 'importStore', 'seedAction', 'inviteForm', 'inviteStore', 'date_filter_on translation', 'params_from_filter', 'importUrl', 'Declared static options', 'where map'] as $marker) {
            $this->assertStringContainsString($marker, $ctl, "controller marker: {$marker}");
        }

        $mgr = file_get_contents($root.'/src/Core/Discovery/SliceManager.php');
        foreach (["'.import'", "'.seed_action'", "'.invite'", '(l) P13', '(m) The declared-filters drawer', '(n) Strip-placed', '(o) Fidelity', '(p) Summary-card', '(q) Show-page identity'] as $marker) {
            $this->assertStringContainsString($marker, $mgr, "manager marker: {$marker}");
        }

        $rows = file_get_contents($root.'/src/Support/DataTableRows.php');
        $this->assertStringContainsString('resolveTimer', $rows);
        $ui = file_get_contents($root.'/src/Support/DirectoryUi.php');
        $this->assertStringContainsString("'timer'", $ui);

        foreach (['import', 'invite', 'actions', 'filters-drawer', 'strip-filters'] as $view) {
            $this->assertFileExists($root."/src/Wizard/views/directory/{$view}.blade.php", "directory view: {$view}");
        }

        $gen = file_get_contents($root.'/src/Generator/SliceGenerator.php');
        $this->assertStringContainsString('filters-drawer', $gen);
        $this->assertStringContainsString('strip-filters', $gen);
        $this->assertStringContainsString('$importUrl', $gen);
    }
}
