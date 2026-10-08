<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->text('value')->nullable();
                $table->string('group')->default('general')->index();
                $table->boolean('is_secret')->default(false);
                $table->string('description')->nullable();
                $table->timestamps();
            });

            // Seed default SMTP & system defaults
            $defaults = [
                ['key' => 'app_name', 'value' => 'LaraSlice Enterprise', 'group' => 'general', 'is_secret' => false, 'description' => 'System Application Name'],
                ['key' => 'mail_host', 'value' => 'smtp.mailtrap.io', 'group' => 'mail', 'is_secret' => false, 'description' => 'SMTP Host Address'],
                ['key' => 'mail_port', 'value' => '2525', 'group' => 'mail', 'is_secret' => false, 'description' => 'SMTP Host Port'],
                ['key' => 'mail_username', 'value' => '', 'group' => 'mail', 'is_secret' => false, 'description' => 'SMTP Username'],
                ['key' => 'mail_password', 'value' => '', 'group' => 'mail', 'is_secret' => true, 'description' => 'SMTP Password'],
                ['key' => 'mail_encryption', 'value' => 'tls', 'group' => 'mail', 'is_secret' => false, 'description' => 'Encryption (tls, ssl, null)'],
                ['key' => 'mail_from_address', 'value' => 'admin@laraslice.com', 'group' => 'mail', 'is_secret' => false, 'description' => 'Default Mail From Address'],
                ['key' => 'mail_from_name', 'value' => 'LaraSlice System', 'group' => 'mail', 'is_secret' => false, 'description' => 'Default Mail From Name'],
            ];

            foreach ($defaults as $row) {
                $row['created_at'] = now();
                $row['updated_at'] = now();
                DB::table('settings')->insert($row);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
