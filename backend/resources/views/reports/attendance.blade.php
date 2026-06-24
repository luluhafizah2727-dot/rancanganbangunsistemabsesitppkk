<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Laporan Absensi Harian</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #14213d; font-size: 10px; }
        h1 { margin: 0 0 4px; font-size: 20px; }
        .header { display: table; width: 100%; }
        .header img, .header div { display: table-cell; vertical-align: middle; }
        .header img { width: 54px; height: 54px; object-fit: contain; padding-right: 12px; }
        p { margin: 0 0 12px; color: #52627a; }
        .summary { margin: 14px 0; }
        .summary span { display: inline-block; margin-right: 20px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #cbd5e1; padding: 6px; text-align: left; }
        th { background: #eaf2ff; color: #0b4ba3; }
        tr:nth-child(even) { background: #f8fafc; }
    </style>
</head>
<body>
    <div class="header"><img src="{{ public_path('tp-pkk-logo.png') }}" alt=""><div><h1>Laporan Absensi Harian TP PKK Balangan</h1><p>Periode {{ \Carbon\Carbon::parse($date_from)->format('d/m/Y') }} sampai {{ \Carbon\Carbon::parse($date_to)->format('d/m/Y') }}</p></div></div>
    <div class="summary">
        <span>Hadir: <strong>{{ $summary['present'] }}</strong></span>
        <span>Izin: <strong>{{ $summary['permission'] }}</strong></span>
        <span>Cuti: <strong>{{ $summary['leave'] }}</strong></span>
        <span>Sakit: <strong>{{ $summary['sick'] }}</strong></span>
        <span>Dinas: <strong>{{ $summary['official_duty'] }}</strong></span>
        <span>Sempat hadir: <strong>{{ $summary['partial_absence'] ?? 0 }}</strong></span>
        <span>Alpa: <strong>{{ $summary['absent'] }}</strong></span>
    </div>
    <table>
        <thead><tr><th>No</th><th>Tanggal</th><th>Nomor</th><th>Nama</th><th>Jabatan</th><th>Status</th><th>Jejak hadir</th><th>Masuk</th><th>Pulang</th><th>Catatan</th></tr></thead>
        <tbody>
        @foreach ($attendances as $index => $attendance)
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>{{ \Carbon\Carbon::parse($attendance['day']['attendance_date'])->format('d/m/Y') }}</td>
                <td>{{ $attendance['member']['member_number'] }}</td>
                <td>{{ $attendance['member']['user']['name'] }}</td>
                <td>{{ $attendance['member']['position'] ?: '-' }}</td>
                <td>{{ ['present' => 'Hadir', 'permission' => 'Izin', 'leave' => 'Cuti', 'sick' => 'Sakit', 'official_duty' => 'Dinas', 'absent' => 'Alpa', 'pending' => 'Belum hadir'][$attendance['status']] }}</td>
                <td>{{ $attendance['presence_summary']['label'] ?? '-' }}</td>
                <td>{{ $attendance['check_in_at'] ? \Carbon\Carbon::parse($attendance['check_in_at'])->timezone(config('app.timezone'))->format('H:i:s') : '-' }}</td>
                <td>{{ $attendance['check_out_at'] ? \Carbon\Carbon::parse($attendance['check_out_at'])->timezone(config('app.timezone'))->format('H:i:s') : '-' }}</td>
                <td>{{ $attendance['note'] ?: '-' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</body>
</html>
