<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceDevice;
use App\Models\AttendanceException;
use App\Models\AttendanceWeeklySchedule;
use App\Services\AuditLogger;
use App\Services\DailyAttendanceService;
use App\Support\ApiResponse;
use App\Support\Present;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AttendanceSettingsController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success([
            'weekly' => AttendanceWeeklySchedule::query()->orderBy('weekday')->get()
                ->map(fn (AttendanceWeeklySchedule $schedule) => Present::weeklySchedule($schedule))->values(),
            'exceptions' => AttendanceException::query()->with('devices')->orderByDesc('attendance_date')->limit(100)->get()
                ->map(fn (AttendanceException $exception) => Present::exception($exception))->values(),
            'timezone' => config('app.timezone'),
        ]);
    }

    public function updateWeekly(
        Request $request,
        int $weekday,
        AuditLogger $audit,
        DailyAttendanceService $days,
    ): JsonResponse {
        abort_unless($weekday >= 1 && $weekday <= 7, 404);
        $schedule = AttendanceWeeklySchedule::query()->where('weekday', $weekday)->firstOrFail();
        $before = Present::weeklySchedule($schedule);
        $data = $this->validateSchedule($request);
        $schedule->update([...$data, 'updated_by' => $request->user()->id]);
        $days->clearFutureSnapshots();
        $days->syncTodayFromWeeklySchedule($weekday);
        $audit->log('attendance_schedule.updated', $schedule, [
            'before' => $before,
            'after' => Present::weeklySchedule($schedule->fresh()),
        ]);

        return ApiResponse::success(Present::weeklySchedule($schedule->fresh()));
    }

    public function storeException(
        Request $request,
        AuditLogger $audit,
        DailyAttendanceService $days,
    ): JsonResponse {
        [$data, $devicePublicIds] = $this->validateException($request);
        $exception = DB::transaction(function () use ($data, $devicePublicIds, $request): AttendanceException {
            $exception = AttendanceException::query()->create([
                ...$data,
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ]);
            $this->syncExceptionDevices($exception, (bool) $data['is_working_day'], $devicePublicIds);

            return $exception->fresh('devices');
        });
        $days->clearFutureSnapshots();
        $days->syncDateIfToday($exception->attendance_date);
        $audit->log('attendance_exception.created', $exception, ['after' => Present::exception($exception)]);

        return ApiResponse::success(Present::exception($exception), status: 201);
    }

    public function updateException(
        Request $request,
        AttendanceException $attendanceException,
        AuditLogger $audit,
        DailyAttendanceService $days,
    ): JsonResponse {
        $this->ensureDateCanChange($attendanceException->attendance_date->toDateString());
        $oldDate = $attendanceException->attendance_date->toDateString();
        $before = Present::exception($attendanceException->load('devices'));
        [$data, $devicePublicIds] = $this->validateException($request, $attendanceException);
        $attendanceException = DB::transaction(function () use ($attendanceException, $data, $devicePublicIds, $request): AttendanceException {
            $attendanceException->update([...$data, 'updated_by' => $request->user()->id]);
            $this->syncExceptionDevices($attendanceException, (bool) $data['is_working_day'], $devicePublicIds);

            return $attendanceException->fresh('devices');
        });
        $days->clearFutureSnapshots();
        $days->syncDateIfToday($oldDate);
        $days->syncDateIfToday($attendanceException->attendance_date);
        $audit->log('attendance_exception.updated', $attendanceException, [
            'before' => $before,
            'after' => Present::exception($attendanceException),
        ]);

        return ApiResponse::success(Present::exception($attendanceException));
    }

    public function destroyException(
        AttendanceException $attendanceException,
        AuditLogger $audit,
        DailyAttendanceService $days,
    ): JsonResponse {
        $this->ensureDateCanChange($attendanceException->attendance_date->toDateString());
        $before = Present::exception($attendanceException);
        $audit->log('attendance_exception.deleted', $attendanceException, ['before' => $before]);
        $attendanceException->delete();
        $days->clearFutureSnapshots();
        $days->syncDateIfToday($before['attendance_date']);

        return ApiResponse::success(['deleted' => true]);
    }

    private function validateSchedule(Request $request): array
    {
        $data = $request->validate([
            'is_working_day' => ['required', 'boolean'],
            'check_in_time' => ['nullable', 'date_format:H:i', 'required_if:is_working_day,true'],
            'check_in_before_minutes' => ['required', 'integer', 'min:0', 'max:720'],
            'check_in_after_minutes' => ['required', 'integer', 'min:0', 'max:720'],
            'check_out_time' => ['nullable', 'date_format:H:i', 'required_if:is_working_day,true'],
            'check_out_before_minutes' => ['required', 'integer', 'min:0', 'max:720'],
            'check_out_after_minutes' => ['required', 'integer', 'min:0', 'max:720'],
        ]);

        return $this->validateWindowOrder($data);
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<int, string>}
     */
    private function validateException(Request $request, ?AttendanceException $exception = null): array
    {
        $data = $request->validate([
            'attendance_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today', 'unique:attendance_exceptions,attendance_date'.($exception ? ','.$exception->id : '')],
            'is_working_day' => ['required', 'boolean'],
            'check_in_time' => ['nullable', 'date_format:H:i', 'required_if:is_working_day,true'],
            'check_in_before_minutes' => ['required', 'integer', 'min:0', 'max:720'],
            'check_in_after_minutes' => ['required', 'integer', 'min:0', 'max:720'],
            'check_out_time' => ['nullable', 'date_format:H:i', 'required_if:is_working_day,true'],
            'check_out_before_minutes' => ['required', 'integer', 'min:0', 'max:720'],
            'check_out_after_minutes' => ['required', 'integer', 'min:0', 'max:720'],
            'note' => ['required', 'string', 'max:500'],
            'attendance_device_ids' => ['exclude_unless:is_working_day,true', 'required', 'array', 'min:1', 'max:100'],
            'attendance_device_ids.*' => [
                'string',
                'distinct',
                Rule::exists('attendance_devices', 'public_id')->where(fn ($query) => $query->where('status', '!=', 'revoked')),
            ],
        ]);

        $devicePublicIds = array_values(array_unique($data['attendance_device_ids'] ?? []));
        unset($data['attendance_device_ids']);

        return [$this->validateWindowOrder($data), $request->boolean('is_working_day') ? $devicePublicIds : []];
    }

    /**
     * @param  array<int, string>  $devicePublicIds
     */
    private function syncExceptionDevices(AttendanceException $exception, bool $isWorkingDay, array $devicePublicIds): void
    {
        if (! $isWorkingDay) {
            $exception->devices()->sync([]);

            return;
        }

        $deviceIds = AttendanceDevice::query()
            ->whereIn('public_id', $devicePublicIds)
            ->where('status', '!=', 'revoked')
            ->pluck('id')
            ->all();

        $exception->devices()->sync($deviceIds);
    }

    private function validateWindowOrder(array $data): array
    {
        if (! $data['is_working_day']) {
            return [
                ...$data,
                'check_in_time' => null,
                'check_out_time' => null,
            ];
        }

        $date = CarbonImmutable::parse('2026-01-01', config('app.timezone'));
        $in = CarbonImmutable::parse($date->toDateString().' '.$data['check_in_time'], config('app.timezone'));
        $out = CarbonImmutable::parse($date->toDateString().' '.$data['check_out_time'], config('app.timezone'));
        $inCloses = $in->addMinutes($data['check_in_after_minutes']);
        $outOpens = $out->subMinutes($data['check_out_before_minutes']);

        if ($out->lessThanOrEqualTo($in) || $inCloses->greaterThanOrEqualTo($outOpens)) {
            throw ValidationException::withMessages([
                'check_out_time' => 'Rentang check-in harus selesai sebelum rentang check-out dimulai.',
            ]);
        }

        return $data;
    }

    private function ensureDateCanChange(string $date): void
    {
        abort_if($date < today(config('app.timezone'))->toDateString(), 409, 'Pengecualian tanggal yang sudah lewat tidak dapat diubah.');
    }
}
