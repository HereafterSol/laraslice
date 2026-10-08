<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "domain" was previously added with an ALTER TABLE during a web request.
 * "source" marks permissions synced from slice manifests ("laraslice"), which are
 * the only ones LaraSlice may prune; permissions created by the application stay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            if (! Schema::hasColumn('permissions', 'domain')) {
                $table->string('domain')->nullable();
            }
            if (! Schema::hasColumn('permissions', 'source')) {
                $table->string('source', 32)->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            if (Schema::hasColumn('permissions', 'source')) {
                $table->dropIndex(['source']);
                $table->dropColumn('source');
            }
        });
    }
};
