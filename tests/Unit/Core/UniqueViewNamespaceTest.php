<?php

namespace LaraSlice\Tests\Unit\Core;

use LaraSlice\Core\Discovery\SliceManager;
use PHPUnit\Framework\TestCase;

/**
 * G6: every slice also gets a view namespace derived from its own
 * directory path, so same-named slices (Demo/Tasks, ERP/Tasks,
 * ERP/ERP/Work/Tasks) can never shadow each other's templates when
 * the base controller resolves its own views.
 */
class UniqueViewNamespaceTest extends TestCase
{
    public function test_nested_slice_path_becomes_path_derived_namespace(): void
    {
        $this->assertSame(
            'ls_erp_erp_work_tasks',
            SliceManager::viewNamespaceForPath('/app/Slices/ERP/ERP/Work/Tasks', '/app/Slices')
        );
    }

    public function test_root_slice_uses_its_name(): void
    {
        $this->assertSame(
            'ls_permits',
            SliceManager::viewNamespaceForPath('/app/Slices/Permits', '/app/Slices')
        );
    }

    public function test_same_named_slices_get_different_namespaces(): void
    {
        $a = SliceManager::viewNamespaceForPath('/app/Slices/ERP/Tasks', '/app/Slices');
        $b = SliceManager::viewNamespaceForPath('/app/Slices/ERP/ERP/Work/Tasks', '/app/Slices');
        $c = SliceManager::viewNamespaceForPath('/app/Slices/Demo/Tasks', '/app/Slices');
        $this->assertSame('ls_erp_tasks', $a);
        $this->assertSame('ls_erp_erp_work_tasks', $b);
        $this->assertSame('ls_demo_tasks', $c);
        $this->assertSame(3, count(array_unique([$a, $b, $c])));
    }

    public function test_trailing_slashes_and_out_of_base_paths_are_safe(): void
    {
        $this->assertSame(
            'ls_hr_leaves',
            SliceManager::viewNamespaceForPath('/app/Slices/HR/Leaves/', '/app/Slices/')
        );
        // Outside the base: falls back to the directory name — still
        // deterministic, never empty.
        $this->assertSame(
            'ls_orders',
            SliceManager::viewNamespaceForPath('/somewhere/else/Orders', '/app/Slices')
        );
    }
}
