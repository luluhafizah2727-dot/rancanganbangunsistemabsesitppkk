<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Services\DailyAttendanceService;
use App\Support\ApiResponse;
use App\Support\Present;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportController extends Controller
{
    public function preview(Request $request, DailyAttendanceService $days): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return ApiResponse::success($this->reportData($from, $to, $days));
    }

    public function pdf(Request $request, DailyAttendanceService $days): BinaryFileResponse|Response
    {
        [$from, $to] = $this->range($request);
        $data = $this->reportData($from, $to, $days);

        return Pdf::loadView('reports.attendance', $data)
            ->setPaper('a4', 'landscape')
            ->download("laporan-absensi-{$from->format('Ymd')}-{$to->format('Ymd')}.pdf");
    }

    public function xlsx(Request $request, DailyAttendanceService $days): BinaryFileResponse
    {
        [$from, $to] = $this->range($request);
        $data = $this->reportData($from, $to, $days);
        $sheet = (new Spreadsheet)->getActiveSheet();
        $sheet->setTitle('Absensi Harian');
        $sheet->fromArray([
            'No', 'Tanggal', 'Nomor Anggota', 'Nama', 'Jabatan', 'Status',
            'Jejak Hadir', 'Check-in', 'Ketepatan Masuk', 'Check-out', 'Ketepatan Pulang', 'Catatan',
        ], null, 'A1');

        $rows = collect($data['attendances'])->map(fn (array $attendance, int $index) => [
            $index + 1,
            CarbonImmutable::parse($attendance['day']['attendance_date'])->format('d/m/Y'),
            $attendance['member']['member_number'],
            $attendance['member']['user']['name'],
            $attendance['member']['position'],
            $this->statusLabel($attendance['status']),
            $attendance['presence_summary']['label'] ?? '-',
            $attendance['check_in_at'] ? CarbonImmutable::parse($attendance['check_in_at'])->timezone(config('app.timezone'))->format('H:i:s') : '-',
            $attendance['check_in_status'] === 'late' ? 'Terlambat' : ($attendance['check_in_status'] ? 'Tepat waktu' : '-'),
            $attendance['check_out_at'] ? CarbonImmutable::parse($attendance['check_out_at'])->timezone(config('app.timezone'))->format('H:i:s') : '-',
            $attendance['check_out_status'] === 'early' ? 'Pulang awal' : ($attendance['check_out_status'] ? 'Selesai' : '-'),
            $attendance['note'] ?? '-',
        ])->all();
        $sheet->fromArray($rows, null, 'A2');
        foreach (range('A', 'L') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:K'.max(1, count($rows) + 1));

        $path = tempnam(sys_get_temp_dir(), 'tppkk-report-').'.xlsx';
        (new Xlsx($sheet->getParent()))->save($path);

        return response()->download($path, "laporan-absensi-{$from->format('Ymd')}-{$to->format('Ymd')}.xlsx")->deleteFileAfterSend(true);
    }

    private function reportData(CarbonImmutable $from, CarbonImmutable $to, DailyAttendanceService $days): array
    {
        $dayIds = [];
        $dayItems = [];
        for ($date = $from; $date->lte($to); $date = $date->addDay()) {
            $day = $days->forDate($date);
            $dayIds[] = $day->id;
            $dayItems[] = Present::day($day);
        }

        $attendances = Attendance::query()
            ->with('member.user', 'day', 'checkInDevice', 'checkOutDevice')
            ->whereIn('attendance_day_id', $dayIds)
            ->get()
            ->sortBy(fn (Attendance $attendance) => $attendance->day->attendance_date->format('Y-m-d').'|'.($attendance->member->user?->name ?? ''))
            ->map(fn (Attendance $attendance) => Present::attendance($attendance))
            ->values();

        return [
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'days' => $dayItems,
            'attendances' => $attendances,
            'summary' => [
                'expected' => $attendances->count(),
                'present' => $attendances->where('status', 'present')->count(),
                'permission' => $attendances->where('status', 'permission')->count(),
                'leave' => $attendances->where('status', 'leave')->count(),
                'sick' => $attendances->where('status', 'sick')->count(),
                'official_duty' => $attendances->where('status', 'official_duty')->count(),
                'absent' => $attendances->where('status', 'absent')->count(),
                'pending' => $attendances->where('status', 'pending')->count(),
                'partial_absence' => $attendances->filter(fn (array $attendance) => (bool) ($attendance['presence_summary']['is_partial_absence'] ?? false))->count(),
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function range(Request $request): array
    {
        $data = $request->validate([
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);
        $from = CarbonImmutable::parse($data['date_from'], config('app.timezone'))->startOfDay();
        $to = CarbonImmutable::parse($data['date_to'], config('app.timezone'))->startOfDay();
        if ($from->diffInDays($to) > 31) {
            throw ValidationException::withMessages(['date_to' => 'Rentang laporan maksimal 31 hari.']);
        }

        return [$from, $to];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'present' => 'Hadir',
            'permission' => 'Izin',
            'leave' => 'Cuti',
            'sick' => 'Sakit',
            'official_duty' => 'Dinas',
            'absent' => 'Alpa',
            default => 'Belum hadir',
        };
    }
}
