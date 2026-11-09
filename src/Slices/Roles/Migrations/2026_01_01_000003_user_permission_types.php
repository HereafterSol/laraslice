<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user permission types (the scope layer's per-person
 * dimension): the store behind the employee hub's Permissions
 * tab, plus the per-permission allowed type set on the
 * permissions table (the source systems record which types a
 * permission may take; the port's schema never carried it).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('permissions') && ! Schema::hasColumn('permissions', 'allowed_permissions')) {
            Schema::table('permissions', function (Blueprint $table) {
                $table->text('allowed_permissions')->nullable();
            });
        }

        if (! Schema::hasTable('user_permission_types')) {
            Schema::create('user_permission_types', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('permission_id');
                $table->unsignedBigInteger('permission_type_id');
                $table->timestamps();
                $table->unique(['user_id', 'permission_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_permission_types');

        if (Schema::hasTable('permissions') && Schema::hasColumn('permissions', 'allowed_permissions')) {
            Schema::table('permissions', function (Blueprint $table) {
                $table->dropColumn('allowed_permissions');
            });
        }
    }
};
