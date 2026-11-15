<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seat 2 (record workplace): the generic per-record
 * stores behind the record page's rail and tabs —
 * notes, attachments, tags and shares for ANY slice's
 * records, keyed by slice name + record id. One home
 * for every slice keeps the workplace identical across
 * the whole system, the way the source gives every
 * DocType the same record furniture for free.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('laraslice_record_notes')) {
            Schema::create('laraslice_record_notes', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('slice', 100)->index();
                $table->string('record_id', 100)->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->text('body');
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('laraslice_record_attachments')) {
            Schema::create('laraslice_record_attachments', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('slice', 100)->index();
                $table->string('record_id', 100)->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('disk', 50)->default('public');
                $table->string('path');
                $table->string('name');
                $table->unsignedBigInteger('size')->default(0);
                $table->string('mime', 100)->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('laraslice_record_tags')) {
            Schema::create('laraslice_record_tags', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('slice', 100)->index();
                $table->string('record_id', 100)->index();
                $table->string('tag', 80);
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->timestamps();
                $table->unique(['slice', 'record_id', 'tag']);
            });
        }
        if (! Schema::hasTable('laraslice_record_shares')) {
            Schema::create('laraslice_record_shares', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('slice', 100)->index();
                $table->string('record_id', 100)->index();
                $table->unsignedBigInteger('user_id')->index();
                $table->unsignedBigInteger('shared_by')->nullable();
                $table->timestamps();
                $table->unique(['slice', 'record_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('laraslice_record_shares');
        Schema::dropIfExists('laraslice_record_tags');
        Schema::dropIfExists('laraslice_record_attachments');
        Schema::dropIfExists('laraslice_record_notes');
    }
};
