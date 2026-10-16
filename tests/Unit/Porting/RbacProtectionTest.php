<?php

namespace LaraSlice\Tests\Unit\Porting;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LaraSlice\Core\Security\Access;
use LaraSlice\Porting\SliceDataPortService;
use LaraSlice\Slices\Roles\Models\Role;
use LaraSlice\Tests\TestCase;
use PDO;

/**
 * RBAC protection during legacy data ports.
 *
 * Regression test for the Worksuite ERP lockout: the ETL wipe+replaced the
 * `roles`/`permissions` tables with legacy rows (no slug column), destroying
 * the super-admin role and every studio.* permission, locking all developers
 * out of Slice Studio with a 403.
 *
 * Guarantees:
 *  1. `super-dev` is a whole-system developer master key (Access::allows bypass).
 *  2. ETL never wipe+replaces framework auth tables by default.
 *  3. `users` merges by email — existing accounts are never deleted.
 *  4. Post-port reconciliation is idempotent and repairs wiped RBAC.
 */
final class RbacProtectionTest extends TestCase
{
    private function makeDevUser(): object
    {
        $user = $this->makeUser(['email' => 'dev@example.test']);
        $role = Role::firstOrCreate(
            ['slug' => Access::SUPER_DEV_ROLE],
            ['name' => 'Platform Developer', 'description' => 'test']
        );
        DB::table('role_user')->updateOrInsert(
            ['role_id' => $role->id, 'user_id' => $user->id],
            ['role_id' => $role->id, 'user_id' => $user->id]
        );

        return $user->fresh();
    }

    private function makeAdminUser(): object
    {
        $user = $this->makeUser(['email' => 'admin@example.test']);
        $role = Role::firstOrCreate(
            ['slug' => Access::SUPER_ADMIN_ROLE],
            ['name' => 'Super Administrator', 'description' => 'test']
        );
        DB::table('role_user')->updateOrInsert(
            ['role_id' => $role->id, 'user_id' => $user->id],
            ['role_id' => $role->id, 'user_id' => $user->id]
        );

        return $user->fresh();
    }

        public function test_super_dev_is_detected_by_slug(): void
    {
        $dev = $this->makeDevUser();
        $plain = $this->makeUser();

        $this->assertTrue(Access::isSuperDev($dev));
        $this->assertFalse(Access::isSuperDev($plain));
        // super-dev is distinct from super-admin
        $this->assertFalse(Access::isSuperAdmin($dev));
    }

        public function test_super_dev_bypasses_all_permission_checks(): void
    {
        $dev = $this->makeDevUser();

        $this->assertTrue(Access::allows($dev, 'studio.access'));
        $this->assertTrue(Access::allows($dev, ['studio.wipe', 'domain.delete']));
        $this->assertTrue(Access::allows($dev, 'some.nonexistent.permission'));
    }

        public function test_super_admin_behavior_is_unchanged(): void
    {
        $admin = $this->makeAdminUser();

        $this->assertTrue(Access::isSuperAdmin($admin));
        $this->assertTrue(Access::allows($admin, 'studio.access'));
        $this->assertFalse(Access::isSuperDev($admin));
    }

        public function test_etl_skips_system_auth_tables_by_default(): void
    {
        $service = new SliceDataPortService;

        $sourcePdo = new PDO('sqlite::memory:');
        $sourcePdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT)');
        $sourcePdo->exec("INSERT INTO roles (id, name) VALUES (1, 'admin')");

        foreach (['roles', 'permissions', 'permission_role', 'role_user'] as $table) {
            $method = new \ReflectionMethod($service, 'importSingleTable');
            $method->setAccessible(true);
            $result = $method->invoke($service, $table, $sourcePdo, 'sqlite', null);

            $this->assertSame(
                'skipped_system_auth',
                $result['status'],
                "Table {$table} must be protected from ETL overwrite"
            );
            $this->assertSame(0, $result['rows']);
        }
    }

        public function test_etl_merges_users_by_email_without_deleting_existing(): void
    {
        // Existing developer account must survive the port
        $existing = $this->makeUser(['email' => 'dev@laraslice.test']);

        $sourcePdo = new PDO('sqlite::memory:');
        $sourcePdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT, password TEXT)');
        $sourcePdo->exec("INSERT INTO users (id, name, email, password) VALUES (1, 'Legacy Admin', 'admin@example.com', 'hashed')");
        $sourcePdo->exec("INSERT INTO users (id, name, email, password) VALUES (2, 'Dev Clone', 'dev@laraslice.test', 'hashed')");

        // Target users table needs email column for the merge key
        $service = new SliceDataPortService;
        $method = new \ReflectionMethod($service, 'importSingleTable');
        $method->setAccessible(true);
        $result = $method->invoke($service, 'users', $sourcePdo, 'sqlite', null);
        $this->assertSame('merged', $result['status']);
        // Only the genuinely new user is inserted; the existing dev account is untouched
        $this->assertSame(1, $result['rows']);
        $this->assertSame(1, $result['skipped_existing']);
        $this->assertTrue(
            DB::table('users')->where('email', 'dev@laraslice.test')->where('id', $existing->id)->exists(),
            'Existing developer account must be preserved with its original id'
        );
        $this->assertTrue(DB::table('users')->where('email', 'admin@example.com')->exists());
    }

        public function test_import_auth_opt_in_normalizes_legacy_roles_without_touching_framework_roles(): void
    {
        // Framework roles exist before the port
        Role::firstOrCreate(['slug' => 'super-admin'], ['name' => 'Super Administrator']);
        Role::firstOrCreate(['slug' => 'super-dev'], ['name' => 'Platform Developer']);
        $frameworkCount = DB::table('roles')->count();

        $sourcePdo = new PDO('sqlite::memory:');
        $sourcePdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT, display_name TEXT)');
        // Worksuite-shaped legacy role: no slug column, name 'admin'
        $sourcePdo->exec("INSERT INTO roles (id, name, display_name) VALUES (1, 'admin', 'App Administrator')");
        $sourcePdo->exec("INSERT INTO roles (id, name, display_name) VALUES (2, 'employee', 'Employee')");

        $service = (new SliceDataPortService)->setImportAuth(true);
        $method = new \ReflectionMethod($service, 'importSingleTable');
        $method->setAccessible(true);
        $result = $method->invoke($service, 'roles', $sourcePdo, 'sqlite', null);

        $this->assertSame('normalized_merge', $result['status']);
        $this->assertSame(2, $result['rows']);

        // Framework roles untouched
        $this->assertSame($frameworkCount + 2, DB::table('roles')->count());
        $this->assertTrue(DB::table('roles')->where('slug', 'super-admin')->exists());
        $this->assertTrue(DB::table('roles')->where('slug', 'super-dev')->exists());
        // Legacy 'admin' did NOT become super-admin — it got its own slug
        $this->assertTrue(DB::table('roles')->where('slug', 'admin')->exists());
        $this->assertSame(1, DB::table('roles')->where('slug', 'super-admin')->count());
    }

        public function test_reconciliation_repairs_wiped_rbac_and_is_idempotent(): void
    {
        // Simulate the lockout: legacy import wiped everything
        DB::table('permission_role')->delete();
        DB::table('role_user')->delete();
        DB::table('permissions')->delete();
        DB::table('roles')->delete();

        $service = new SliceDataPortService;

        $first = $service->reconcileAuthAfterImport();
        $this->assertContains('super-admin (recreated)', $first['roles_ensured']);
        $this->assertContains('super-dev (recreated)', $first['roles_ensured']);
        $this->assertTrue(DB::table('roles')->where('slug', 'super-admin')->exists());
        $this->assertTrue(DB::table('roles')->where('slug', 'super-dev')->exists());

        // Second run: idempotent, no duplicates
        $second = $service->reconcileAuthAfterImport();
        $this->assertContains('super-admin (exists)', $second['roles_ensured']);
        $this->assertContains('super-dev (exists)', $second['roles_ensured']);
        $this->assertSame(1, DB::table('roles')->where('slug', 'super-admin')->count());
        $this->assertSame(1, DB::table('roles')->where('slug', 'super-dev')->count());
    }

        public function test_reserved_slugs_can_never_be_minted_from_legacy_data(): void
    {
        $sourcePdo = new PDO('sqlite::memory:');
        $sourcePdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT)');
        // A hostile or confused legacy DB trying to claim the framework slugs
        $sourcePdo->exec("INSERT INTO roles (id, name) VALUES (1, 'super-admin')");
        $sourcePdo->exec("INSERT INTO roles (id, name) VALUES (2, 'super-dev')");

        $service = (new SliceDataPortService)->setImportAuth(true);
        $method = new \ReflectionMethod($service, 'importSingleTable');
        $method->setAccessible(true);
        $method->invoke($service, 'roles', $sourcePdo, 'sqlite', null);

        $this->assertTrue(DB::table('roles')->where('slug', 'legacy-super-admin')->exists());
        $this->assertTrue(DB::table('roles')->where('slug', 'legacy-super-dev')->exists());
        // Genuine framework rows (if any) are exactly one each
        $this->assertLessThanOrEqual(1, DB::table('roles')->where('slug', 'super-admin')->count());
    }
}
