<?php

namespace LaraSlice\Tests\Unit\Security;

use Illuminate\Support\Collection;
use LaraSlice\Core\Security\Traits\HasSlicePermissions;
use PHPUnit\Framework\TestCase;

class DummyUser
{
    use HasSlicePermissions;

    public int $id = 1;

    public Collection $roles;

    public function __construct(array $roles = [])
    {
        $this->roles = collect($roles);
    }
}

class DummyRole
{
    public string $slug;

    public Collection $permissions;

    public function __construct(string $slug, array $permissions = [])
    {
        $this->slug = $slug;
        $this->permissions = collect($permissions);
    }
}

class DummyPermission
{
    public int $id;

    public string $slug;

    public string $name;

    public function __construct(int $id, string $slug, string $name = '')
    {
        $this->id = $id;
        $this->slug = $slug;
        $this->name = $name ?: $slug;
    }
}

class SliceRbacTest extends TestCase
{
    public function test_user_has_role(): void
    {
        $user = new DummyUser([
            new DummyRole('manager'),
            new DummyRole('editor'),
        ]);

        $this->assertTrue($user->hasRole('manager'));
        $this->assertTrue($user->hasRole('editor'));
        $this->assertFalse($user->hasRole('admin'));
    }

    public function test_user_has_permission_via_roles(): void
    {
        $user = new DummyUser([
            new DummyRole('manager', [
                new DummyPermission(1, 'shop_product.view'),
                new DummyPermission(2, 'shop_product.create'),
            ]),
        ]);

        $this->assertTrue($user->hasPermission('shop_product.view'));
        $this->assertTrue($user->hasPermission('shop_product.create'));
        $this->assertFalse($user->hasPermission('shop_product.delete'));
        $this->assertFalse($user->hasPermission('users.view'));
    }

    public function test_user_wildcard_permission(): void
    {
        $user = new DummyUser([
            new DummyRole('admin', [
                new DummyPermission(1, 'shop_product.*'),
            ]),
        ]);

        $this->assertTrue($user->hasPermission('shop_product.view'));
        $this->assertTrue($user->hasPermission('shop_product.delete'));
        $this->assertFalse($user->hasPermission('users.view'));
    }

    public function test_user_with_empty_permissions_has_no_access(): void
    {
        $user = new DummyUser([
            new DummyRole('editor', []), // Non-super-admin with empty permissions!
        ]);

        $this->assertFalse($user->hasPermission('shop_product.view'));
        $this->assertFalse($user->hasPermission('shop_order.view'));
    }

    public function test_super_admin_has_all_permissions_by_default(): void
    {
        $superAdmin = new DummyUser([
            new DummyRole('super-admin', []),
        ]);

        $this->assertTrue($superAdmin->hasPermission('shop_product.view'));
        $this->assertTrue($superAdmin->hasPermission('users.view'));
        $this->assertTrue($superAdmin->hasPermission('any.custom.capability'));
    }
}
