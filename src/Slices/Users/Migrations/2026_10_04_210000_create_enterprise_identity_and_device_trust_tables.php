<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. user_attempts: Security audit log for all authentication, lockscreen, and passkey attempts
        if (! Schema::hasTable('user_attempts')) {
            Schema::create('user_attempts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('identifier_attempted', 191)->nullable();
                $table->string('channel', 50)->default('Web');
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->string('browser', 100)->nullable();
                $table->string('os', 100)->nullable();
                $table->string('device_type', 50)->nullable();
                $table->string('location_label', 191)->nullable();
                $table->string('reason', 191)->default('invalid_password');
                $table->timestamp('created_at')->useCurrent();

                $table->index('user_id');
                $table->index('identifier_attempted');
                $table->index('created_at');
            });
        }

        // 2. user_devices: Registered workstations, browsers, and trusted device fingerprints
        if (! Schema::hasTable('user_devices')) {
            Schema::create('user_devices', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('session_id', 191)->nullable();
                $table->string('device_token', 191)->nullable();
                $table->string('device_label', 191)->nullable();
                $table->string('platform', 50)->default('Web');
                $table->string('device_name', 191)->nullable();
                $table->string('browser', 100)->nullable();
                $table->string('os', 100)->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->string('location_label', 191)->nullable();
                $table->boolean('is_current')->default(false);
                $table->boolean('is_trusted')->default(false);
                $table->timestamp('last_active_at')->nullable();
                $table->timestamps();

                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->index(['user_id', 'device_name']);
            });
        }

        // 3. user_connect: Single-use device pairing & enrollment codes (DEV-XXXXXX parity)
        if (! Schema::hasTable('user_connect')) {
            Schema::create('user_connect', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->string('code_hash', 191);
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('used_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->string('used_ip', 45)->nullable();
                $table->text('used_ua')->nullable();
                $table->timestamps();

                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->index('code_hash');
            });
        }

        // 4. user_creds: FIDO2 / WebAuthn passkey public keys and signatures
        if (! Schema::hasTable('user_creds')) {
            Schema::create('user_creds', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('credential_id', 255)->unique();
                $table->text('public_key');
                $table->unsignedInteger('sign_count')->default(0);
                $table->string('transports', 191)->nullable();
                $table->string('aaguid', 64)->nullable();
                $table->string('label', 191)->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();

                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }

        // 5. user_factors: Multi-factor authentication factors (RFC-6238 TOTP secrets)
        if (! Schema::hasTable('user_factors')) {
            Schema::create('user_factors', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->text('secret_enc');
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();

                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }

        // 6. user_codes: Emergency MFA offline backup/recovery codes
        if (! Schema::hasTable('user_codes')) {
            Schema::create('user_codes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('code_hash', 191);
                $table->timestamp('used_at')->nullable();
                $table->timestamps();

                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->index(['user_id', 'code_hash']);
            });
        }

        // 7. user_checks: Ephemeral challenges, step-up verifications & MFA pending tickets
        if (! Schema::hasTable('user_checks')) {
            Schema::create('user_checks', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('purpose', 100);
                $table->json('payload')->nullable();
                $table->string('token_hash', 191);
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('consumed_at')->nullable();
                $table->string('ip', 45)->nullable();
                $table->boolean('remember')->default(false);
                $table->timestamps();

                $table->index('token_hash');
            });
        }

        // 8. user_push_devices: FCM push notification targets for MFA approvals
        if (! Schema::hasTable('user_push_devices')) {
            Schema::create('user_push_devices', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->text('fcm_token');
                $table->string('platform', 50)->default('Android');
                $table->string('device_label', 191)->nullable();
                $table->timestamps();

                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }

        // 9. user_resets: Self-service & admin password reset requests
        if (! Schema::hasTable('user_resets')) {
            Schema::create('user_resets', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('email', 191);
                $table->string('token_hash', 191);
                $table->string('channel', 50)->default('email');
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->timestamp('requested_at')->nullable();
                $table->timestamp('expired_at')->nullable();
                $table->timestamp('used_at')->nullable();
                $table->string('status', 50)->default('pending');
                $table->timestamps();

                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->index('token_hash');
            });
        }

        // 10. user_tokens: Persistent login tokens & remember-me device authenticators
        if (! Schema::hasTable('user_tokens')) {
            Schema::create('user_tokens', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('selector', 64)->unique();
                $table->string('verifier_hash', 191);
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->text('user_agent')->nullable();
                $table->string('ip', 45)->nullable();
                $table->timestamps();

                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->index('selector');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_tokens');
        Schema::dropIfExists('user_resets');
        Schema::dropIfExists('user_push_devices');
        Schema::dropIfExists('user_checks');
        Schema::dropIfExists('user_codes');
        Schema::dropIfExists('user_factors');
        Schema::dropIfExists('user_creds');
        Schema::dropIfExists('user_connect');
        Schema::dropIfExists('user_devices');
        Schema::dropIfExists('user_attempts');
    }
};
