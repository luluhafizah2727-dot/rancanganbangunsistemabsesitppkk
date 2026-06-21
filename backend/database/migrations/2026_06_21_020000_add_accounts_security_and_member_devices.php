<?php

use App\Services\MemberDeviceBindingService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('app_settings')) {
            Schema::create('app_settings', function (Blueprint $table): void {
                $table->id();
                $table->string('key')->unique();
                $table->text('value')->nullable();
                $table->timestamps();
            });
        }

        DB::table('app_settings')->updateOrInsert(
            ['key' => MemberDeviceBindingService::SETTING_KEY],
            ['value' => MemberDeviceBindingService::MODE_APPROVAL, 'created_at' => now(), 'updated_at' => now()],
        );

        if (! Schema::hasTable('member_devices')) {
            Schema::create('member_devices', function (Blueprint $table): void {
                $table->id();
                $table->ulid('public_id')->unique();
                $table->foreignId('member_id')->constrained()->cascadeOnDelete();
                $table->string('label', 100)->nullable();
                $table->string('status', 20)->default('pending')->index();
                $table->string('credential_hash', 64)->nullable()->unique();
                $table->string('fingerprint_hash', 64)->nullable();
                $table->text('user_agent')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->timestampTz('last_seen_at')->nullable();
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestampTz('reviewed_at')->nullable();
                $table->text('review_note')->nullable();
                $table->timestampTz('revoked_at')->nullable();
                $table->unsignedTinyInteger('approved_key')->nullable();
                $table->timestamps();
                $table->unique(['member_id', 'approved_key'], 'member_devices_one_approved_unique');
                $table->index(['member_id', 'status']);
            });
        }

        if (Schema::hasTable('attendances') && ! Schema::hasColumn('attendances', 'active_key')) {
            Schema::table('attendances', function (Blueprint $table): void {
                $table->unsignedTinyInteger('active_key')->nullable()->default(1)->after('attendance_day_id');
            });
            DB::table('attendances')->whereNotNull('deleted_at')->update(['active_key' => null]);
        }

        if (Schema::hasTable('attendances')) {
            $this->dropAttendanceActiveIndex();
            try {
                Schema::table('attendances', function (Blueprint $table): void {
                    $table->unique(['member_id', 'attendance_day_id', 'active_key'], 'attendances_member_day_active_unique');
                });
            } catch (Throwable) {
                // Existing installations may already have this index.
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('member_devices');
        Schema::dropIfExists('app_settings');
    }

    private function dropAttendanceActiveIndex(): void
    {
        try {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('drop index if exists attendances_member_day_active_unique');

                return;
            }

            Schema::table('attendances', function (Blueprint $table): void {
                $table->dropUnique('attendances_member_day_active_unique');
            });
        } catch (Throwable) {
            //
        }
    }
};
