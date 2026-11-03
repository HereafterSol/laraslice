<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The scope layer (Option B, mirroring the source systems
 * LaraSlice ports): every grant carries a scope —
 * added / owned / both / all / none — stored as
 * permission_role.permission_type_id → permission_types.
 *
 * Ids are seeded in the source's canonical order so code
 * that defaults a fresh grant to type 4 keeps meaning
 * 'all'. A NULL type on an existing grant row means a
 * legacy (pre-scope) grant and resolves to 'all', so
 * existing installs behave exactly as before until their
 * grants are re-saved or re-carried with scopes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permission_types')) {
            Schema::create('permission_types', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('permission_types')) {
            foreach ([1 => 'added', 2 => 'owned', 3 => 'both', 4 => 'all', 5 => 'none'] as $id => $name) {
                $exists = DB::table('permission_types')->where('name', $name)->exists()
                    || DB::table('permission_types')->where('id', $id)->exists();
                if (! $exists) {
                    DB::table('permission_types')->insert([
                        'id' => $id,
                        'name' => $name,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        if (Schema::hasTable('permission_role') && ! Schema::hasColumn('permission_role', 'permission_type_id')) {
            Schema::table('permission_role', function (Blueprint $table) {
                $table->unsignedBigInteger('permission_type_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('permission_role') && Schema::hasColumn('permission_role', 'permission_type_id')) {
            Schema::table('permission_role', function (Blueprint $table) {
                $table->dropColumn('permission_type_id');
            });
        }
        Schema::dropIfExists('permission_types');
    }
};
