<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kiosk_devices', function (Blueprint $table): void {
            $table->string('previous_credential_hash', 64)->nullable()->unique();
            $table->timestampTz('previous_credential_expires_at')->nullable();
            $table->timestampTz('credential_rotated_at')->nullable();
            $table->timestampTz('credential_expires_at')->nullable()->index();
        });

        DB::table('kiosk_devices')->update([
            'credential_rotated_at' => now(),
            'credential_expires_at' => now()->addDays(400),
        ]);
    }

    public function down(): void
    {
        Schema::table('kiosk_devices', function (Blueprint $table): void {
            $table->dropColumn([
                'previous_credential_hash', 'previous_credential_expires_at',
                'credential_rotated_at', 'credential_expires_at',
            ]);
        });
    }
};
