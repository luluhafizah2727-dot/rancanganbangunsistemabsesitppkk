<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AttendanceDeviceController;
use App\Http\Controllers\Api\AttendanceRequestController;
use App\Http\Controllers\Api\AttendanceRequestReviewerController;
use App\Http\Controllers\Api\AttendanceSettingsController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\MemberDeviceController;
use App\Http\Controllers\Api\MemberImportController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\PublicAttendanceRequestActionController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SecuritySettingController;
use App\Http\Controllers\Api\WhatsAppNotificationSettingController;
use App\Support\ApiResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', function () {
        DB::select('select 1');
        Redis::connection()->ping();

        return ApiResponse::success([
            'status' => 'ok',
            'database' => 'ok',
            'redis' => 'ok',
            'server_time' => now()->toIso8601String(),
        ]);
    });

    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/registrations', [AuthController::class, 'register'])->middleware('throttle:registration');
    Route::get('/public/attendance-request-actions/{token}', [PublicAttendanceRequestActionController::class, 'show'])->middleware('throttle:public-attendance-request-action');
    Route::post('/public/attendance-request-actions/{token}/confirm', [PublicAttendanceRequestActionController::class, 'confirm'])->middleware('throttle:public-attendance-request-action');
    Route::post('/attendance-devices/activate', [AttendanceDeviceController::class, 'activate'])->middleware('throttle:device-activation');
    Route::get('/attendance-device/context', [AttendanceDeviceController::class, 'context']);
    Route::get('/attendance-device/qr', [AttendanceDeviceController::class, 'currentQr'])->middleware('attendance.device');
    Route::post('/attendance-device/heartbeat', [AttendanceDeviceController::class, 'heartbeat'])->middleware('attendance.device');

    // One-release aliases keep previously registered displays working during migration.
    Route::post('/kiosk-devices/activate', [AttendanceDeviceController::class, 'activate'])->middleware('throttle:device-activation');
    Route::get('/kiosk-device/context', [AttendanceDeviceController::class, 'context']);
    Route::get('/kiosk-device/qr', [AttendanceDeviceController::class, 'currentQr'])->middleware('attendance.device');
    Route::post('/kiosk-device/heartbeat', [AttendanceDeviceController::class, 'heartbeat'])->middleware('attendance.device');

    Route::middleware(['auth:sanctum', 'user.active'])->group(function (): void {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::put('/auth/password', [AuthController::class, 'changePassword']);
        Route::put('/profile', [ProfileController::class, 'update']);
        Route::post('/profile/avatar', [ProfileController::class, 'avatar']);
        Route::delete('/profile/avatar', [ProfileController::class, 'destroyAvatar']);

        Route::get('/attendance/history', [AttendanceController::class, 'history'])->middleware('role:member');
        Route::get('/attendance/today', [AttendanceController::class, 'today'])->middleware('role:member');
        Route::post('/attendance/scans', [AttendanceController::class, 'scan'])->middleware(['role:member', 'throttle:attendance-scan']);
        Route::get('/member-devices/current', [MemberDeviceController::class, 'current'])->middleware('role:member');
        Route::get('/member-devices', [MemberDeviceController::class, 'index'])->middleware('role:member|super_admin|operator');
        Route::post('/member-devices', [MemberDeviceController::class, 'store'])->middleware(['role:member', 'throttle:attendance-request']);
        Route::get('/attendance-requests', [AttendanceRequestController::class, 'memberIndex'])->middleware('role:member');
        Route::post('/attendance-requests', [AttendanceRequestController::class, 'store'])->middleware(['role:member', 'throttle:attendance-request']);
        Route::get('/attendance-requests/{attendanceRequest}', [AttendanceRequestController::class, 'show']);
        Route::delete('/attendance-requests/{attendanceRequest}', [AttendanceRequestController::class, 'cancel'])->middleware('role:member');
        Route::get('/attendance-requests/{attendanceRequest}/attachment', [AttendanceRequestController::class, 'attachment']);

        Route::middleware('role:super_admin|operator')->group(function (): void {
            Route::get('/dashboard', DashboardController::class);
            Route::get('/members', [MemberController::class, 'index']);
            Route::get('/members/{member}', [MemberController::class, 'show']);
            Route::get('/attendance-devices', [AttendanceDeviceController::class, 'index']);
            Route::get('/attendances', [AttendanceController::class, 'index']);
            Route::get('/reports/attendance', [ReportController::class, 'preview']);
            Route::get('/reports/attendance/pdf', [ReportController::class, 'pdf']);
            Route::get('/reports/attendance/xlsx', [ReportController::class, 'xlsx']);
            Route::get('/admin/attendance-requests', [AttendanceRequestController::class, 'adminIndex']);
            Route::post('/admin/attendance-requests/{attendanceRequest}/approve', [AttendanceRequestController::class, 'approve']);
            Route::post('/admin/attendance-requests/{attendanceRequest}/reject', [AttendanceRequestController::class, 'reject']);
            Route::get('/security-settings/member-device-binding', [SecuritySettingController::class, 'memberDeviceBinding']);
        });

        Route::middleware('role:super_admin')->group(function (): void {
            Route::get('/accounts', [AccountController::class, 'index']);
            Route::post('/accounts', [AccountController::class, 'store']);
            Route::get('/accounts/{account}', [AccountController::class, 'show']);
            Route::put('/accounts/{account}', [AccountController::class, 'update']);
            Route::post('/accounts/{account}/reset-password', [AccountController::class, 'resetPassword']);
            Route::post('/accounts/{account}/toggle-status', [AccountController::class, 'toggleStatus']);
            Route::post('/members', [MemberController::class, 'store']);
            Route::put('/members/{member}', [MemberController::class, 'update']);
            Route::delete('/members/{member}', [MemberController::class, 'destroy']);
            Route::post('/members/{member}/approve', [MemberController::class, 'approve']);
            Route::post('/members/{member}/reject', [MemberController::class, 'reject']);
            Route::post('/members/{member}/toggle-status', [MemberController::class, 'toggleStatus']);
            Route::post('/members/{member}/reset-password', [MemberController::class, 'resetPassword']);
            Route::post('/member-imports/preview', [MemberImportController::class, 'preview']);
            Route::post('/member-imports/{memberImport}/confirm', [MemberImportController::class, 'confirm']);
            Route::get('/attendance-settings', [AttendanceSettingsController::class, 'index']);
            Route::put('/attendance-settings/weekly/{weekday}', [AttendanceSettingsController::class, 'updateWeekly']);
            Route::post('/attendance-exceptions', [AttendanceSettingsController::class, 'storeException']);
            Route::put('/attendance-exceptions/{attendanceException}', [AttendanceSettingsController::class, 'updateException']);
            Route::delete('/attendance-exceptions/{attendanceException}', [AttendanceSettingsController::class, 'destroyException']);
            Route::post('/attendances', [AttendanceController::class, 'store']);
            Route::put('/attendances/{attendance}', [AttendanceController::class, 'update']);
            Route::delete('/attendances/{attendance}', [AttendanceController::class, 'destroy']);
            Route::get('/admin/attendance-request-reviewers', [AttendanceRequestReviewerController::class, 'index']);
            Route::put('/admin/attendance-request-reviewers', [AttendanceRequestReviewerController::class, 'update']);
            Route::get('/admin/settings/whatsapp', [WhatsAppNotificationSettingController::class, 'show']);
            Route::put('/admin/settings/whatsapp', [WhatsAppNotificationSettingController::class, 'update']);
            Route::post('/admin/settings/whatsapp/test', [WhatsAppNotificationSettingController::class, 'test'])->middleware('throttle:whatsapp-test');
            Route::post('/attendance-devices', [AttendanceDeviceController::class, 'store']);
            Route::put('/attendance-devices/{attendanceDevice}', [AttendanceDeviceController::class, 'update']);
            Route::post('/attendance-devices/{attendanceDevice}/activation-code', [AttendanceDeviceController::class, 'activationCode']);
            Route::post('/attendance-devices/{attendanceDevice}/revoke', [AttendanceDeviceController::class, 'revoke']);
            Route::put('/security-settings/member-device-binding', [SecuritySettingController::class, 'updateMemberDeviceBinding']);
            Route::post('/member-devices/{memberDevice}/approve', [MemberDeviceController::class, 'approve']);
            Route::post('/member-devices/{memberDevice}/reject', [MemberDeviceController::class, 'reject']);
            Route::post('/member-devices/{memberDevice}/revoke', [MemberDeviceController::class, 'revoke']);
            Route::get('/audit-logs', AuditLogController::class);
        });
    });
});
