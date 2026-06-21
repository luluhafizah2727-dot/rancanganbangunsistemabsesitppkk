<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\AttendanceDevice;
use App\Models\AttendanceRequest;
use App\Models\User;
use App\Services\DailyAttendanceService;
use App\Services\QrTokenService;
use App\Support\ApiResponse;
use App\Support\Present;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __invoke(DailyAttendanceService $days, QrTokenService $tokens): JsonResponse
    {
        $day = $days->forDate();
        $recent = Attendance::query()
            ->with('member.user', 'day', 'checkInDevice', 'checkOutDevice')
            ->where('attendance_day_id', $day->id)
            ->whereNotNull('check_in_at')
            ->latest('check_in_at')
            ->limit(8)
            ->get();

        return ApiResponse::success([
            'attendance_day' => Present::day($day),
            'current_phase' => $days->phaseAt($day),
            'next_working_day' => ($next = $days->nextWorkingDay()) ? Present::day($next) : null,
            'metrics' => [
                'total_members' => User::role('member')->where('status', UserStatus::Active->value)->count(),
                ...$tokens->summaryFor($day),
                'active_devices' => AttendanceDevice::query()->where('status', 'active')->where('last_seen_at', '>=', now()->subMinutes(2))->count(),
                'pending_requests' => AttendanceRequest::query()->where('status', 'pending')->count(),
            ],
            'recent_attendance' => $recent->map(fn (Attendance $attendance) => Present::attendance($attendance))->values(),
            'device_status' => AttendanceDevice::query()->whereIn('status', ['active', 'pending'])->get()->map(fn (AttendanceDevice $device) => [
                ...Present::device($device),
                'online' => $device->last_seen_at?->gte(now()->subMinutes(2)) ?? false,
            ])->values(),
            'pending_requests' => AttendanceRequest::query()->with('member.user')->where('status', 'pending')->oldest()->limit(5)->get()
                ->map(fn (AttendanceRequest $request) => Present::attendanceRequest($request))->values(),
            'server_time' => now()->toIso8601String(),
        ]);
    }
}
