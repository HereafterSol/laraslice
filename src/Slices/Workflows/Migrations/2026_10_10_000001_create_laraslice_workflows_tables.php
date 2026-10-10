<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('laraslice_workflows')) {
            Schema::create('laraslice_workflows', function (Blueprint $table) {
                $table->id();
                $table->string('slug', 100)->unique();
                $table->string('name', 150);
                $table->string('domain', 100)->nullable();
                $table->string('entity_model', 255)->nullable();
                $table->string('slice_name', 100)->nullable();
                $table->json('trigger_conditions')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('laraslice_workflow_states')) {
            Schema::create('laraslice_workflow_states', function (Blueprint $table) {
                $table->id();
                $table->foreignId('workflow_id')->constrained('laraslice_workflows')->cascadeOnDelete();
                $table->string('slug', 100);
                $table->string('label', 150);
                $table->string('badge_color', 50)->default('slate');
                $table->string('icon', 50)->nullable();
                $table->boolean('is_initial')->default(false);
                $table->boolean('is_terminal')->default(false);
                $table->integer('sla_hours')->nullable();
                $table->integer('position')->default(0);
                $table->timestamps();

                $table->unique(['workflow_id', 'slug']);
            });
        }

        if (! Schema::hasTable('laraslice_workflow_transitions')) {
            Schema::create('laraslice_workflow_transitions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('workflow_id')->constrained('laraslice_workflows')->cascadeOnDelete();
                $table->string('slug', 100);
                $table->string('label', 150);
                $table->string('from_state_slug', 100);
                $table->string('to_state_slug', 100);
                $table->string('permission_gate', 150)->nullable();
                $table->string('button_color', 50)->default('primary');
                $table->string('button_icon', 50)->nullable();
                $table->boolean('requires_remarks')->default(false);
                $table->boolean('requires_attachment')->default(false);
                $table->boolean('confirmation_dialog')->default(false);
                $table->json('guard_rules')->nullable();
                $table->json('actions')->nullable();
                $table->integer('position')->default(0);
                $table->timestamps();

                $table->index(['workflow_id', 'slug']);
            });
        }

        if (! Schema::hasTable('laraslice_workflow_routing_rules')) {
            Schema::create('laraslice_workflow_routing_rules', function (Blueprint $table) {
                $table->id();
                $table->foreignId('transition_id')->constrained('laraslice_workflow_transitions')->cascadeOnDelete();
                $table->string('routing_type', 50)->default('role'); // role, department, user, hierarchy_manager, dynamic_field, round_robin
                $table->string('target_role', 100)->nullable();
                $table->string('target_department', 100)->nullable();
                $table->unsignedBigInteger('target_user_id')->nullable();
                $table->string('user_field', 100)->nullable();
                $table->boolean('notify_parties')->default(true);
                $table->boolean('assign_current_user')->default(false);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('laraslice_workflow_logs')) {
            Schema::create('laraslice_workflow_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('workflow_id')->nullable()->index();
                $table->string('entity_type', 255)->index();
                $table->unsignedBigInteger('entity_id')->index();
                $table->string('transition_slug', 100)->nullable();
                $table->string('from_state', 100)->nullable();
                $table->string('to_state', 100);
                $table->unsignedBigInteger('performed_by_id')->nullable()->index();
                $table->unsignedBigInteger('assigned_to_user_id')->nullable()->index();
                $table->string('assigned_to_role', 100)->nullable();
                $table->string('assigned_to_department', 100)->nullable();
                $table->text('remarks')->nullable();
                $table->json('attachments')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['entity_type', 'entity_id']);
                $table->index('created_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('laraslice_workflow_logs');
        Schema::dropIfExists('laraslice_workflow_routing_rules');
        Schema::dropIfExists('laraslice_workflow_transitions');
        Schema::dropIfExists('laraslice_workflow_states');
        Schema::dropIfExists('laraslice_workflows');
    }
};