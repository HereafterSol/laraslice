<?php

namespace LaraSlice\Tests\Unit\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LaraSlice\Support\ModuleSettings;
use LaraSlice\Support\SchemaCache;
use LaraSlice\Tests\TestCase;

/**
 * module_settings switches are behaviour: a module deactivated
 * for a user type hides that type's slices. Super-class users
 * are never bound by the switches.
 */
class ModuleSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('module_settings', function (Blueprint $table) {
            $table->increments('id');
            $table->string('module_name');
            $table->string('status');
            $table->string('type');
        });
        DB::table('module_settings')->insert([
            ['module_name' => 'clients', 'status' => 'deactive', 'type' => 'employee'],
            ['module_name' => 'projects', 'status' => 'active', 'type' => 'employee'],
            ['module_name' => 'invoices', 'status' => 'deactive', 'type' => 'client'],
        ]);
        SchemaCache::flush();
        ModuleSettings::flush();
    }

    protected function tearDown(): void
    {
        ModuleSettings::flush();
        Schema::dropIfExists('module_settings');
        SchemaCache::flush();
        parent::tearDown();
    }

    private function userWithRole(string $roleName): object
    {
        $role = new class($roleName)
        {
            public function __construct(public string $name) {}
        };

        return new class($role)
        {
            public array $roles;

            public function __construct($role)
            {
                $this->roles = [$role];
            }

            /** Relation-method shape, as an Eloquent model exposes. */
            public function roles(): array
            {
                return $this->roles;
            }

            public function getAuthIdentifier(): int
            {
                return 42;
            }
        };
    }

    public function test_type_resolution_and_super_exemption(): void
    {
        $this->assertSame('employee', ModuleSettings::typeForUser($this->userWithRole('employee')));
        $this->assertSame('client', ModuleSettings::typeForUser($this->userWithRole('client')));
        $this->assertSame('admin', ModuleSettings::typeForUser($this->userWithRole('admin')));
        $this->assertNull(ModuleSettings::typeForUser($this->userWithRole('Super Administrator')));
        $this->assertNull(ModuleSettings::typeForUser($this->userWithRole('Platform Developer')));
        $this->assertNull(ModuleSettings::typeForUser($this->userWithRole('umair')));
        $this->assertNull(ModuleSettings::typeForUser(null));
    }

    public function test_hidden_modules_come_from_the_table_per_type(): void
    {
        $employee = $this->userWithRole('employee');
        $this->assertSame(['clients'], ModuleSettings::hiddenModules($employee));
        $this->assertTrue(ModuleSettings::isHiddenFor($employee, 'clients'));
        $this->assertFalse(ModuleSettings::isHiddenFor($employee, 'projects'));
        $this->assertFalse(ModuleSettings::isHiddenFor($employee, null));

        $client = $this->userWithRole('client');
        $this->assertTrue(ModuleSettings::isHiddenFor($client, 'invoices'));
        $this->assertFalse(ModuleSettings::isHiddenFor($client, 'clients'));

        $super = $this->userWithRole('Super Administrator');
        $this->assertSame([], ModuleSettings::hiddenModules($super));
        $this->assertFalse(ModuleSettings::isHiddenFor($super, 'clients'));
    }
}
