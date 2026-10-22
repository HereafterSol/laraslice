<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Unit\Porting;

use LaraSlice\Porting\SlicePortService;
use PHPUnit\Framework\TestCase;

/**
 * The opt-in port table prefix naming rule: physical target
 * tables become {prefix}{table} so a port can coexist with
 * tables an earlier port (or the host app) already owns.
 * Slice/model names never change, and framework auth/merge
 * tables are exempt — they always land in the host's tables.
 */
final class TablePrefixNamingTest extends TestCase
{
    public function test_no_prefix_returns_the_table_unchanged(): void
    {
        $this->assertSame('invoices', SlicePortService::targetTableName('invoices', ''));
    }

    public function test_prefix_is_prepended_to_domain_tables(): void
    {
        $this->assertSame('ws_invoices', SlicePortService::targetTableName('invoices', 'ws_'));
        $this->assertSame('ws_invoice_items', SlicePortService::targetTableName('invoice_items', 'ws_'));
        $this->assertSame('ws_task_users', SlicePortService::targetTableName('task_users', 'ws_'));
    }

    public function test_framework_auth_tables_are_never_prefixed(): void
    {
        foreach (['users', 'roles', 'permissions', 'permission_role', 'role_user'] as $table) {
            $this->assertSame($table, SlicePortService::targetTableName($table, 'ws_'), $table.' must stay unprefixed');
        }
    }

    public function test_already_prefixed_names_are_not_double_prefixed(): void
    {
        $this->assertSame('ws_invoices', SlicePortService::targetTableName('ws_invoices', 'ws_'));
    }
}
