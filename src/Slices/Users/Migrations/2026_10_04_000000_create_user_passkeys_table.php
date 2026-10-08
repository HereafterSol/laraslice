<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_passkeys')) {
            Schema::create('user_passkeys', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('credential_id', 512)->unique();
                $table->text('public_key');
                $table->unsignedInteger('sign_count')->default(0);
                $table->string('aaguid', 64)->nullable();
                $table->string('transports', 100)->nullable(); // 'usb,nfc,ble,internal'
                $table->string('label', 100)->default('Passkey'); // e.g. 'Touch ID', 'Windows Hello'
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();

                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->index(['user_id', 'revoked_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_passkeys');
    }
};
