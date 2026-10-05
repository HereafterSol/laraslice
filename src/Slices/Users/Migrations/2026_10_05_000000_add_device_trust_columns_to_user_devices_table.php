<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // user_devices is first created by 2026_10_03_220000 without the device trust columns.
        // 2026_10_04_210000 then skips its own create because the table already exists,
        // so backfill the columns the UserDevice model writes to.
        if (!Schema::hasTable('user_devices')) {
            return;
        }

        Schema::table('user_devices', function (Blueprint $table) {
            if (!Schema::hasColumn('user_devices', 'session_id')) {
                $table->string('session_id', 191)->nullable()->after('user_id');
            }
            if (!Schema::hasColumn('user_devices', 'device_label')) {
                $table->string('device_label', 191)->nullable()->after('device_token');
            }
            if (!Schema::hasColumn('user_devices', 'is_trusted')) {
                $table->boolean('is_trusted')->default(false)->after('is_current');
            }
        });
    }

    public function down(): void
    {
        // No-op: on fresh installs these columns may belong to 2026_10_04_210000,
        // and that migration's down() drops the whole table.
    }
};
