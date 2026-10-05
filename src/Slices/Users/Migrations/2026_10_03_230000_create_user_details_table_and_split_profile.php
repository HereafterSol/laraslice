<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // 1. Create dedicated user_details table for extended enterprise profile details
        if (!Schema::hasTable('user_details')) {
            Schema::create('user_details', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->unique();
                $table->string('employee_id', 50)->nullable();
                $table->string('department', 100)->nullable();
                $table->string('designation', 100)->nullable();
                $table->string('cnic', 20)->nullable();
                $table->date('dob')->nullable();
                $table->text('address')->nullable();
                $table->timestamps();

                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->index('employee_id');
                $table->index('cnic');
            });
        }

        // 2. Data Migration: Copy existing employee/profile info from users to user_details
        $existingUsers = DB::table('users')->get();
        foreach ($existingUsers as $u) {
            $hasDetail = DB::table('user_details')->where('user_id', $u->id)->exists();
            if (!$hasDetail) {
                DB::table('user_details')->insert([
                    'user_id'     => $u->id,
                    'employee_id' => $u->employee_id ?? null,
                    'department'  => $u->department ?? null,
                    'designation' => $u->designation ?? null,
                    'cnic'        => $u->cnic ?? null,
                    'dob'         => $u->dob ?? null,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            }
        }

        // 3. Drop moved profile columns from core users table so users table remains pure and lean
        Schema::table('users', function (Blueprint $table) {
            $cols = ['employee_id', 'department', 'designation', 'cnic', 'dob'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }

    public function down(): void
    {
        // Re-add columns to users table
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'employee_id')) {
                $table->string('employee_id', 50)->nullable();
            }
            if (!Schema::hasColumn('users', 'department')) {
                $table->string('department', 100)->nullable();
            }
            if (!Schema::hasColumn('users', 'designation')) {
                $table->string('designation', 100)->nullable();
            }
            if (!Schema::hasColumn('users', 'cnic')) {
                $table->string('cnic', 20)->nullable();
            }
            if (!Schema::hasColumn('users', 'dob')) {
                $table->date('dob')->nullable();
            }
        });

        // Copy back data
        if (Schema::hasTable('user_details')) {
            $details = DB::table('user_details')->get();
            foreach ($details as $d) {
                DB::table('users')->where('id', $d->user_id)->update([
                    'employee_id' => $d->employee_id,
                    'department'  => $d->department,
                    'designation' => $d->designation,
                    'cnic'        => $d->cnic,
                    'dob'         => $d->dob,
                ]);
            }
            Schema::dropIfExists('user_details');
        }
    }
};
