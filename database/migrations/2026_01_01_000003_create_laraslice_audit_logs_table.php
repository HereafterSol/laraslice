<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('laraslice_audit_logs')) {
            Schema::create('laraslice_audit_logs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('slice', 100)->index()->default('global');
                $table->string('action', 50)->index();
                $table->unsignedBigInteger('actor_id')->nullable()->index();
                $table->string('actor_type')->nullable();
                $table->string('actor_email')->nullable()->index();
                $table->string('entity_type')->nullable()->index();
                $table->string('entity_id', 100)->nullable()->index();
                $table->json('old_values')->nullable();
                $table->json('new_values')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->string('user_agent', 255)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('laraslice_audit_logs');
    }
};
