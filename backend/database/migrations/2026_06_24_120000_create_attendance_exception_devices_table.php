<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_exception_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('attendance_exception_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attendance_device_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['attendance_exception_id', 'attendance_device_id'], 'attendance_exception_devices_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_exception_devices');
    }
};
