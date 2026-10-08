<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->text('description')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('permissions')) {
            Schema::create('permissions', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->string('group')->default('general');
                $table->text('description')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('role_user')) {
            Schema::create('role_user', function (Blueprint $table) {
                $table->unsignedBigInteger('role_id');
                $table->unsignedBigInteger('user_id');
                $table->primary(['role_id', 'user_id']);
            });
        }

        if (! Schema::hasTable('permission_role')) {
            Schema::create('permission_role', function (Blueprint $table) {
                $table->unsignedBigInteger('permission_id');
                $table->unsignedBigInteger('role_id');
                $table->primary(['permission_id', 'role_id']);
            });
        }

        // Seed Default Super Admin and Standard User roles if empty
        if (Schema::hasTable('roles') && DB::table('roles')->count() === 0) {
            DB::table('roles')->insert([
                [
                    'name' => 'Super Administrator',
                    'slug' => 'super-admin',
                    'description' => 'Full unrestricted system-wide access to all slices and settings',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'name' => 'Manager',
                    'slug' => 'manager',
                    'description' => 'Can manage operational records, approvals, and reports',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'name' => 'Standard User',
                    'slug' => 'user',
                    'description' => 'Standard member with basic read/create permissions',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
