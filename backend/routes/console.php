<?php

use App\Jobs\RotateQrTokens;
use App\Services\DailyAttendanceService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new RotateQrTokens)
    ->everyTenSeconds()
    ->withoutOverlapping(1);

Schedule::call(fn () => app(DailyAttendanceService::class)->forDate())
    ->dailyAt('00:00')
    ->name('attendance-day-create')
    ->withoutOverlapping();

Schedule::call(fn () => app(DailyAttendanceService::class)->refreshStatuses())
    ->everyMinute()
    ->name('attendance-status-refresh')
    ->withoutOverlapping();
