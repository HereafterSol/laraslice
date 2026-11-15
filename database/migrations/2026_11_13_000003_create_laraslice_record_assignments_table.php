<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seat 2 (record workplace), assignment fallback: slices
 * whose records have no owner-shaped column (and no
 * declared ui.workplace.assignment_field) still get the
 * rail's Assign To card — the assignment lives in this
 * generic store, keyed by slice name + record id, the
 * way the source lets every DocType be assigned whether
 * or not the DocType itself carries an owner field.
 * Field-seated slices keep their record column; this
 * table only serves the seat-less ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('laraslice_record_assignments')) {
            Schema::create('laraslice_record_assignments', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('slice', 100);
                $table->string('record_id', 100);
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->timestamps();
                $table->unique(['slice', 'record_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('laraslice_record_assignments');
    }
};
