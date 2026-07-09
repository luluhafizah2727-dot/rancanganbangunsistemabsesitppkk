<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_notification_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->text('send_url')->nullable();
            $table->text('status_url')->nullable();
            $table->string('auth_mode', 20)->default('none');
            $table->text('auth_username')->nullable();
            $table->text('auth_password')->nullable();
            $table->string('auth_header_name')->nullable();
            $table->text('auth_header_value')->nullable();
            $table->text('auth_bearer_token')->nullable();
            $table->string('footer', 100)->default('Absensi TP PKK Balangan');
            $table->string('public_base_url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_notification_settings');
    }
};
