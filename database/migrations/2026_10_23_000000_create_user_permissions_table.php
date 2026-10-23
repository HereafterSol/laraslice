<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-person permission overrides (the Mind's second role
 * layer): individual grants and revocations layered on top of
 * a person's role clubs. effect = grant | revoke; a revoke
 * wins over a role grant for the same ability.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_permissions')) {
            return;
        }
        Schema::create('user_permissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('permission', 150);
            $table->string('effect', 10)->default('grant');
            $table->unsignedBigInteger('granted_by')->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'permission', 'effect']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_permissions');
    }
};
