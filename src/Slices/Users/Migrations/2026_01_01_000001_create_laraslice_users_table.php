<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                // Integer keys: every related table (role_user, user_devices, ...) uses unsignedBigInteger user_id
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->timestamp('email_verified_at')->nullable();
                $table->string('password');
                $table->string('status')->default('active'); // active, suspended, pending
                $table->string('avatar_url')->nullable();
                $table->rememberToken();
                $table->timestamps();
            });
        } else {
            Schema::table('users', function (Blueprint $table) {
                if (! Schema::hasColumn('users', 'status')) {
                    $table->string('status')->default('active')->after('password');
                }
                if (! Schema::hasColumn('users', 'avatar_url')) {
                    $table->string('avatar_url')->nullable()->after('status');
                }
            });
        }
    }

    public function down(): void
    {
        // The users table usually belongs to the host application; only remove what this migration adds
        Schema::table('users', function (Blueprint $table) {
            foreach (['avatar_url', 'status'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
