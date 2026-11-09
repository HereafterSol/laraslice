<?php

namespace LaraSlice\Tests\Unit\Support;

use LaraSlice\Support\DirectoryUi;
use PHPUnit\Framework\TestCase;

/**
 * Companion-account declaration parsing (Comparative Fix
 * Programme Phase C): a details slice declares
 * account_creation: {foreign_key, role} and the create form +
 * store grow a one-step "create the login account" path.
 */
class AccountCreationDeclarationTest extends TestCase
{
    public function test_full_declaration_is_read_with_its_foreign_key(): void
    {
        $decl = DirectoryUi::accountCreationFromManifest([
            'account_creation' => ['foreign_key' => 'user_id', 'role' => 'employee'],
        ]);

        $this->assertSame(['foreign_key' => 'user_id', 'role' => 'employee'], $decl);
    }

    public function test_foreign_key_defaults_to_user_id(): void
    {
        $decl = DirectoryUi::accountCreationFromManifest([
            'account_creation' => ['role' => 'client'],
        ]);

        $this->assertSame('user_id', $decl['foreign_key']);
        $this->assertSame('client', $decl['role']);
    }

    public function test_a_missing_role_disables_the_declaration(): void
    {
        $this->assertNull(DirectoryUi::accountCreationFromManifest([
            'account_creation' => ['foreign_key' => 'user_id'],
        ]));
        $this->assertNull(DirectoryUi::accountCreationFromManifest([]));
        $this->assertNull(DirectoryUi::accountCreationFromManifest([
            'account_creation' => 'employee',
        ]));
    }
}
