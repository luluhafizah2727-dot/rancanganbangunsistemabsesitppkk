<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('member_number')->unique();
            $table->string('position')->nullable();
            $table->string('department')->nullable();
            $table->text('address')->nullable();
            $table->timestamps();
        });

        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('location');
            $table->dateTimeTz('starts_at');
            $table->dateTimeTz('ends_at');
            $table->string('status', 20)->default('draft')->index();
            $table->boolean('all_active_members')->default(true);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('event_members', function (Blueprint $table) {
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->primary(['event_id', 'member_id']);
        });

        Schema::create('kiosks', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('location')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->json('ip_allowlist')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('kiosk_devices', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('kiosk_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->string('credential_hash', 64)->unique();
            $table->string('fingerprint_hash', 64)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->json('screen')->nullable();
            $table->string('timezone')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->dateTimeTz('activated_at');
            $table->foreignId('activated_by')->constrained('users');
            $table->dateTimeTz('last_seen_at')->nullable();
            $table->dateTimeTz('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::create('kiosk_activation_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kiosk_id')->constrained()->cascadeOnDelete();
            $table->string('code_hash', 64)->unique();
            $table->dateTimeTz('expires_at');
            $table->dateTimeTz('used_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('attendance_sessions', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('phase', 20)->index();
            $table->string('status', 20)->default('scheduled')->index();
            $table->dateTimeTz('opens_at')->nullable();
            $table->dateTimeTz('closes_at')->nullable();
            $table->dateTimeTz('started_at')->nullable();
            $table->dateTimeTz('ended_at')->nullable();
            $table->foreignId('started_by')->nullable()->constrained('users');
            $table->foreignId('ended_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index(['event_id', 'phase', 'status']);
        });

        Schema::create('session_kiosks', function (Blueprint $table) {
            $table->foreignId('attendance_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('kiosk_id')->constrained()->cascadeOnDelete();
            $table->primary(['attendance_session_id', 'kiosk_id']);
        });

        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->dateTimeTz('check_in_at')->nullable();
            $table->foreignId('check_in_device_id')->nullable()->constrained('kiosk_devices');
            $table->dateTimeTz('check_out_at')->nullable();
            $table->foreignId('check_out_device_id')->nullable()->constrained('kiosk_devices');
            $table->timestamps();
            $table->unique(['member_id', 'event_id']);
        });

        Schema::create('attendance_scans', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('attendance_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('kiosk_device_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phase', 20)->nullable();
            $table->string('token_hash', 64);
            $table->boolean('accepted')->default(false)->index();
            $table->string('reason', 60)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->dateTimeTz('scanned_at');
            $table->timestamps();
        });

        Schema::create('member_imports', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('created_by')->constrained('users');
            $table->string('original_name');
            $table->string('path');
            $table->string('status', 20)->default('previewed');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->json('errors')->nullable();
            $table->dateTimeTz('confirmed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action')->index();
            $table->nullableMorphs('subject');
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->dateTimeTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('member_imports');
        Schema::dropIfExists('attendance_scans');
        Schema::dropIfExists('attendances');
        Schema::dropIfExists('session_kiosks');
        Schema::dropIfExists('attendance_sessions');
        Schema::dropIfExists('kiosk_activation_codes');
        Schema::dropIfExists('kiosk_devices');
        Schema::dropIfExists('kiosks');
        Schema::dropIfExists('event_members');
        Schema::dropIfExists('events');
        Schema::dropIfExists('members');
    }
};
