<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seat 3 — saved list views: a user's named directory
 * states (filters + sort + facets) per slice, applied
 * from the List View switcher the way the source's
 * list view dropdown recalls a view.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('laraslice_list_views')) {
            Schema::create('laraslice_list_views', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('user_id')->index();
                $table->string('slice', 100)->index();
                $table->string('name', 120);
                $table->json('state');
                $table->timestamps();
                $table->unique(['user_id', 'slice', 'name']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('laraslice_list_views');
    }
};
