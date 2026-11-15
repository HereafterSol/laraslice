<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seat 1 (Stage D): counters behind naming-series
 * generation. One row per (slice, series prefix); the
 * value increments atomically inside the generating
 * transaction so concurrent creates never mint the
 * same document number.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('laraslice_counters')) {
            Schema::create('laraslice_counters', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->unsignedBigInteger('value')->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('laraslice_counters');
    }
};
