<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 1. Enhance users table with enterprise, government & security fields
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'cnic')) {
                $table->string('cnic', 20)->nullable()->after('avatar_url');
            } else {
                $table->string('cnic', 20)->nullable()->change();
            }
            if (!Schema::hasColumn('users', 'gender')) {
                $table->string('gender', 20)->nullable()->after('email'); // 'male', 'female', 'other'
            }
            if (!Schema::hasColumn('users', 'phone')) {
                $table->string('phone', 30)->nullable()->after('gender');
            }
            if (!Schema::hasColumn('users', 'dob')) {
                $table->date('dob')->nullable()->after('phone');
            }
            if (!Schema::hasColumn('users', 'employee_id')) {
                $table->string('employee_id', 50)->nullable()->after('dob');
            }
            if (!Schema::hasColumn('users', 'department')) {
                $table->string('department', 100)->nullable()->after('employee_id');
            }
            if (!Schema::hasColumn('users', 'designation')) {
                $table->string('designation', 100)->nullable()->after('department');
            }

            if (!Schema::hasColumn('users', 'customised_permissions')) {
                $table->boolean('customised_permissions')->default(false)->after('bps_scale');
            }
            if (!Schema::hasColumn('users', 'mfa_channel')) {
                $table->string('mfa_channel', 20)->default('none')->after('customised_permissions'); // 'none', 'totp', 'webauthn'
            }
            if (!Schema::hasColumn('users', 'mfa_secret')) {
                $table->text('mfa_secret')->nullable()->after('mfa_channel');
            }
            if (!Schema::hasColumn('users', 'mfa_confirmed_at')) {
                $table->timestamp('mfa_confirmed_at')->nullable()->after('mfa_secret');
            }
            if (!Schema::hasColumn('users', 'last_login_at')) {
                $table->timestamp('last_login_at')->nullable()->after('mfa_confirmed_at');
            }
            if (!Schema::hasColumn('users', 'last_login_ip')) {
                $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
            }
            if (!Schema::hasColumn('users', 'failed_attempts')) {
                $table->integer('failed_attempts')->default(0)->after('last_login_ip');
            }
            if (!Schema::hasColumn('users', 'locked_until')) {
                $table->timestamp('locked_until')->nullable()->after('failed_attempts');
            }
        });

        // 2. Multi-device & mobile push token tracking table
        if (!Schema::hasTable('user_devices')) {
            Schema::create('user_devices', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->text('device_token')->nullable(); // FCM / OneSignal / APNS token
                $table->string('platform', 20)->default('Web'); // 'Web', 'Android', 'iOS', 'Desktop'
                $table->string('device_name', 100); // e.g. 'iPhone 15 Pro', 'Chrome on Windows'
                $table->string('browser', 50)->nullable();
                $table->string('os', 50)->nullable();
                $table->string('ip_address', 45);
                $table->string('location_label', 100)->nullable();
                $table->boolean('is_current')->default(false);
                $table->timestamp('last_active_at')->useCurrent();
                $table->timestamps();

                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->index(['user_id', 'last_active_at']);
            });
        }

        // 3. Emergency single-use recovery codes
        if (!Schema::hasTable('user_recovery_codes')) {
            Schema::create('user_recovery_codes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('code_hash', 255);
                $table->timestamp('used_at')->nullable();
                $table->timestamps();

                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->index(['user_id', 'used_at']);
            });
        }

        // 4. Forensic security activity and login attempt logs
        if (!Schema::hasTable('user_security_logs')) {
            Schema::create('user_security_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('identifier_attempted', 100); // Email or CNIC
                $table->string('event_type', 50); // 'login_success', 'login_failed', 'locked_out', 'mfa_challenge', 'session_revoked'
                $table->string('ip_address', 45);
                $table->text('user_agent')->nullable();
                $table->json('payload')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['user_id', 'created_at']);
                $table->index(['identifier_attempted', 'event_type']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_security_logs');
        Schema::dropIfExists('user_recovery_codes');
        Schema::dropIfExists('user_devices');

        Schema::table('users', function (Blueprint $table) {
            $cols = [
                'gender', 'phone', 'dob', 'employee_id', 'department', 'designation',
                'customised_permissions', 'mfa_channel', 'mfa_secret',
                'mfa_confirmed_at', 'last_login_at', 'last_login_ip', 'failed_attempts', 'locked_until'
            ];
            foreach ($cols as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
