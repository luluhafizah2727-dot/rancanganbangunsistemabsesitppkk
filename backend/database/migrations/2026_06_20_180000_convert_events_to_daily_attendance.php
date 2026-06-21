<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_weekly_schedules', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->unsignedSmallInteger('weekday')->unique();
            $table->boolean('is_working_day')->default(false);
            $table->time('check_in_time')->nullable();
            $table->unsignedSmallInteger('check_in_before_minutes')->default(30);
            $table->unsignedSmallInteger('check_in_after_minutes')->default(30);
            $table->time('check_out_time')->nullable();
            $table->unsignedSmallInteger('check_out_before_minutes')->default(30);
            $table->unsignedSmallInteger('check_out_after_minutes')->default(30);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('attendance_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->date('attendance_date')->unique();
            $table->boolean('is_working_day')->default(false);
            $table->time('check_in_time')->nullable();
            $table->unsignedSmallInteger('check_in_before_minutes')->default(30);
            $table->unsignedSmallInteger('check_in_after_minutes')->default(30);
            $table->time('check_out_time')->nullable();
            $table->unsignedSmallInteger('check_out_before_minutes')->default(30);
            $table->unsignedSmallInteger('check_out_after_minutes')->default(30);
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('attendance_days', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->date('attendance_date')->unique();
            $table->boolean('is_working_day')->default(false);
            $table->string('source', 30)->default('weekly');
            $table->dateTimeTz('check_in_target_at')->nullable();
            $table->dateTimeTz('check_in_opens_at')->nullable();
            $table->dateTimeTz('check_in_closes_at')->nullable();
            $table->dateTimeTz('check_out_target_at')->nullable();
            $table->dateTimeTz('check_out_opens_at')->nullable();
            $table->dateTimeTz('check_out_closes_at')->nullable();
            $table->string('status', 20)->default('scheduled')->index();
            $table->string('note', 500)->nullable();
            $table->json('schedule_snapshot')->nullable();
            $table->timestamps();
        });

        Schema::create('daily_attendances', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attendance_day_id')->constrained('attendance_days')->cascadeOnDelete();
            $table->unsignedTinyInteger('active_key')->nullable()->default(1);
            $table->string('status', 20)->default('pending')->index();
            $table->dateTimeTz('check_in_at')->nullable();
            $table->string('check_in_status', 20)->nullable();
            $table->foreignId('check_in_device_id')->nullable()->constrained('kiosk_devices')->nullOnDelete();
            $table->dateTimeTz('check_out_at')->nullable();
            $table->string('check_out_status', 20)->nullable();
            $table->foreignId('check_out_device_id')->nullable()->constrained('kiosk_devices')->nullOnDelete();
            $table->string('source', 20)->default('system');
            $table->text('note')->nullable();
            $table->text('adjustment_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['attendance_day_id', 'status']);
            $table->index(['member_id', 'attendance_day_id']);
        });

        Schema::create('daily_attendance_scans', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attendance_day_id')->nullable()->constrained('attendance_days')->nullOnDelete();
            $table->foreignId('kiosk_device_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phase', 20)->nullable();
            $table->string('token_hash', 64);
            $table->boolean('accepted')->default(false)->index();
            $table->string('reason', 60)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->dateTimeTz('scanned_at');
            $table->timestamps();
            $table->index(['attendance_day_id', 'scanned_at']);
            $table->index(['member_id', 'scanned_at']);
        });

        $this->migrateLegacyData();

        Schema::dropIfExists('attendance_scans');
        Schema::dropIfExists('attendances');
        Schema::dropIfExists('session_kiosks');
        Schema::dropIfExists('attendance_sessions');
        Schema::dropIfExists('event_members');
        Schema::dropIfExists('events');

        Schema::rename('daily_attendances', 'attendances');
        Schema::rename('daily_attendance_scans', 'attendance_scans');

        Schema::table('attendances', function (Blueprint $table): void {
            $table->unique(['member_id', 'attendance_day_id', 'active_key'], 'attendances_member_day_active_unique');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE attendance_weekly_schedules ADD CONSTRAINT attendance_weekly_schedules_weekday_check CHECK (weekday BETWEEN 1 AND 7)');
            DB::statement('ALTER TABLE attendance_weekly_schedules ADD CONSTRAINT attendance_weekly_schedules_tolerance_check CHECK (check_in_before_minutes <= 720 AND check_in_after_minutes <= 720 AND check_out_before_minutes <= 720 AND check_out_after_minutes <= 720)');
            DB::statement('ALTER TABLE attendance_exceptions ADD CONSTRAINT attendance_exceptions_tolerance_check CHECK (check_in_before_minutes <= 720 AND check_in_after_minutes <= 720 AND check_out_before_minutes <= 720 AND check_out_after_minutes <= 720)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_scans');
        Schema::dropIfExists('attendances');
        Schema::dropIfExists('attendance_days');
        Schema::dropIfExists('attendance_exceptions');
        Schema::dropIfExists('attendance_weekly_schedules');
    }

    private function migrateLegacyData(): void
    {
        if (! Schema::hasTable('events') || ! Schema::hasTable('attendances')) {
            return;
        }

        $timezone = config('app.timezone', 'Asia/Makassar');
        $events = DB::table('events')->get()->keyBy('id');
        $days = [];

        foreach ($events as $event) {
            $date = CarbonImmutable::parse($event->starts_at)->setTimezone($timezone)->toDateString();
            if (isset($days[$date])) {
                continue;
            }

            $targetIn = CarbonImmutable::parse("{$date} 08:00:00", $timezone);
            $targetOut = CarbonImmutable::parse("{$date} 16:00:00", $timezone);
            $id = DB::table('attendance_days')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'attendance_date' => $date,
                'is_working_day' => true,
                'source' => 'legacy_event',
                'check_in_target_at' => $targetIn,
                'check_in_opens_at' => $targetIn->subMinutes(30),
                'check_in_closes_at' => $targetIn->addMinutes(30),
                'check_out_target_at' => $targetOut,
                'check_out_opens_at' => $targetOut->subMinutes(30),
                'check_out_closes_at' => $targetOut->addMinutes(30),
                'status' => 'closed',
                'note' => 'Data absensi sebelumnya',
                'schedule_snapshot' => json_encode(['legacy' => true], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $days[$date] = $id;
        }

        $legacy = DB::table('attendances')->orderBy('id')->get()->groupBy(function ($row) use ($events, $timezone): string {
            $event = $events->get($row->event_id);
            $date = CarbonImmutable::parse($event->starts_at)->setTimezone($timezone)->toDateString();

            return $row->member_id.'|'.$date;
        });

        foreach ($legacy as $rows) {
            $first = $rows->first();
            $event = $events->get($first->event_id);
            $date = CarbonImmutable::parse($event->starts_at)->setTimezone($timezone)->toDateString();
            $checkIns = $rows->pluck('check_in_at')->filter()->sort()->values();
            $checkOuts = $rows->pluck('check_out_at')->filter()->sort()->values();
            $titles = $rows->map(fn ($row) => $events->get($row->event_id)?->title)->filter()->unique()->values();

            DB::table('daily_attendances')->insert([
                'public_id' => (string) Str::ulid(),
                'member_id' => $first->member_id,
                'attendance_day_id' => $days[$date],
                'status' => $checkIns->isNotEmpty() ? 'present' : 'absent',
                'check_in_at' => $checkIns->first(),
                'check_in_status' => $checkIns->isNotEmpty() ? 'on_time' : null,
                'check_in_device_id' => $rows->firstWhere('check_in_at', $checkIns->first())?->check_in_device_id,
                'check_out_at' => $checkOuts->last(),
                'check_out_status' => $checkOuts->isNotEmpty() ? 'on_time' : null,
                'check_out_device_id' => $rows->firstWhere('check_out_at', $checkOuts->last())?->check_out_device_id,
                'source' => 'legacy',
                'note' => $titles->isNotEmpty() ? 'Migrasi dari: '.$titles->implode(', ') : null,
                'created_at' => $rows->min('created_at') ?? now(),
                'updated_at' => $rows->max('updated_at') ?? now(),
            ]);
        }

        if (! Schema::hasTable('attendance_scans')) {
            return;
        }

        foreach (DB::table('attendance_scans')->orderBy('id')->cursor() as $scan) {
            $dayId = null;
            if ($scan->event_id && ($event = $events->get($scan->event_id))) {
                $date = CarbonImmutable::parse($event->starts_at)->setTimezone($timezone)->toDateString();
                $dayId = $days[$date] ?? null;
            }

            DB::table('daily_attendance_scans')->insert([
                'public_id' => $scan->public_id,
                'member_id' => $scan->member_id,
                'attendance_day_id' => $dayId,
                'kiosk_device_id' => $scan->kiosk_device_id,
                'phase' => $scan->phase,
                'token_hash' => $scan->token_hash,
                'accepted' => $scan->accepted,
                'reason' => $scan->reason,
                'ip_address' => $scan->ip_address,
                'user_agent' => $scan->user_agent,
                'scanned_at' => $scan->scanned_at,
                'created_at' => $scan->created_at,
                'updated_at' => $scan->updated_at,
            ]);
        }
    }
};
