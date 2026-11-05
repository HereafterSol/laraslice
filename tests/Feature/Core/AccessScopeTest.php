<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Feature\Core;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LaraSlice\Core\Security\Access;
use LaraSlice\Tests\TestCase;

/**
 * The scope layer (Option B): Access::scopeFor resolves a
 * grant's permission type BY NAME — widest wins across
 * roles, added + owned merge to both, a typeless legacy
 * grant counts as 'all', and a 'none' grant confers
 * nothing. Pins the semantics the source systems enforce
 * (and the by-id inversion the binary carry once made).
 */
class AccessScopeTest extends TestCase
{
    private object $user;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('permission_types');
        Schema::dropIfExists('roles');

        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug')->unique();
        });
        Schema::create('permissions', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug')->unique();
        });
        Schema::create('permission_types', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
        });
        Schema::create('role_user', function (Blueprint $t) {
            $t->unsignedBigInteger('role_id');
            $t->unsignedBigInteger('user_id');
        });
        Schema::create('permission_role', function (Blueprint $t) {
            $t->unsignedBigInteger('permission_id');
            $t->unsignedBigInteger('role_id');
            $t->unsignedBigInteger('permission_type_id')->nullable();
        });

        DB::table('permission_types')->insert([
            ['id' => 1, 'name' => 'added'],
            ['id' => 2, 'name' => 'owned'],
            ['id' => 3, 'name' => 'both'],
            ['id' => 4, 'name' => 'all'],
            ['id' => 5, 'name' => 'none'],
        ]);
        DB::table('roles')->insert([
            ['id' => 1, 'name' => 'Staff', 'slug' => 'staff'],
            ['id' => 2, 'name' => 'Helper', 'slug' => 'helper'],
        ]);
        DB::table('permissions')->insert([
            ['id' => 1, 'name' => 'View Projects', 'slug' => 'project.view'],
            ['id' => 2, 'name' => 'View Invoices', 'slug' => 'invoice.view'],
        ]);
        DB::table('role_user')->insert([
            ['role_id' => 1, 'user_id' => 7],
            ['role_id' => 2, 'user_id' => 7],
        ]);

        $this->user = new class
        {
            public int $id = 7;

            public function hasRole(string $slug): bool
            {
                return false;
            }
        };
    }

    private function grant(int $roleId, int $permissionId, ?int $typeId): void
    {
        DB::table('permission_role')->insert([
            'role_id' => $roleId,
            'permission_id' => $permissionId,
            'permission_type_id' => $typeId,
        ]);
    }

    public function test_owned_grant_resolves_to_owned(): void
    {
        $this->grant(1, 1, 2);
        $this->assertSame('owned', Access::scopeFor($this->user, 'project.view'));
    }

    public function test_added_and_owned_across_roles_merge_to_both(): void
    {
        $this->grant(1, 1, 1);
        $this->grant(2, 1, 2);
        $this->assertSame('both', Access::scopeFor($this->user, 'project.view'));
    }

    public function test_none_grant_confers_nothing_and_all_wins(): void
    {
        $this->grant(1, 1, 5);
        $this->assertSame('none', Access::scopeFor($this->user, 'project.view'));
        $this->grant(2, 1, 4);
        $this->assertSame('all', Access::scopeFor($this->user, 'project.view'));
    }

    public function test_legacy_typeless_grant_counts_as_all(): void
    {
        $this->grant(1, 2, null);
        $this->assertSame('all', Access::scopeFor($this->user, 'invoice.view'));
    }

    public function test_no_grant_is_none(): void
    {
        $this->assertSame('none', Access::scopeFor($this->user, 'invoice.view'));
    }

    public function test_wider_scope_ranks(): void
    {
        $this->assertSame('both', Access::widerScope('added', 'owned'));
        $this->assertSame('all', Access::widerScope('both', 'all'));
        $this->assertSame('owned', Access::widerScope('none', 'owned'));
        $this->assertSame('added', Access::widerScope('added', 'added'));
    }
}
