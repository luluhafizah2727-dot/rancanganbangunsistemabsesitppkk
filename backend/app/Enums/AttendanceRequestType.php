<?php

namespace App\Enums;

enum AttendanceRequestType: string
{
    case MissedCheckIn = 'missed_check_in';
    case MissedCheckOut = 'missed_check_out';
    case TimeCorrection = 'time_correction';
    case Permission = 'permission';
    case Leave = 'leave';
    case Sick = 'sick';
    case OfficialDuty = 'official_duty';
    case Other = 'other';

    public function isTimeCorrection(): bool
    {
        return in_array($this, [self::MissedCheckIn, self::MissedCheckOut, self::TimeCorrection], true);
    }

    public function attendanceStatus(): AttendanceStatus
    {
        return match ($this) {
            self::Leave => AttendanceStatus::Leave,
            self::Sick => AttendanceStatus::Sick,
            self::OfficialDuty => AttendanceStatus::OfficialDuty,
            default => AttendanceStatus::Permission,
        };
    }
}
