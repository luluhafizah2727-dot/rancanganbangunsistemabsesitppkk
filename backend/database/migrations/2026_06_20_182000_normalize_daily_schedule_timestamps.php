<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $timezone = config('app.timezone', 'Asia/Makassar');

        foreach (DB::table('attendance_days')->orderBy('id')->cursor() as $day) {
            if (! $day->is_working_day) {
                continue;
            }

            $snapshot = json_decode((string) $day->schedule_snapshot, true) ?: [];
            $checkInTime = $snapshot['check_in_time'] ?? '08:00:00';
            $checkOutTime = $snapshot['check_out_time'] ?? '16:00:00';
            $inBefore = (int) ($snapshot['check_in_before_minutes'] ?? 30);
            $inAfter = (int) ($snapshot['check_in_after_minutes'] ?? 30);
            $outBefore = (int) ($snapshot['check_out_before_minutes'] ?? 30);
            $outAfter = (int) ($snapshot['check_out_after_minutes'] ?? 30);
            $checkIn = CarbonImmutable::parse($day->attendance_date.' '.$checkInTime, $timezone);
            $checkOut = CarbonImmutable::parse($day->attendance_date.' '.$checkOutTime, $timezone);

            DB::table('attendance_days')->where('id', $day->id)->update([
                'check_in_target_at' => $checkIn,
                'check_in_opens_at' => $checkIn->subMinutes($inBefore),
                'check_in_closes_at' => $checkIn->addMinutes($inAfter),
                'check_out_target_at' => $checkOut,
                'check_out_opens_at' => $checkOut->subMinutes($outBefore),
                'check_out_closes_at' => $checkOut->addMinutes($outAfter),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void {}
};
