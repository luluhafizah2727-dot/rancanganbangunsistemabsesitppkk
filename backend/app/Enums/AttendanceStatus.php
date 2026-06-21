<?php

namespace App\Enums;

enum AttendanceStatus: string
{
    case Pending = 'pending';
    case Present = 'present';
    case Permission = 'permission';
    case Leave = 'leave';
    case Sick = 'sick';
    case OfficialDuty = 'official_duty';
    case Absent = 'absent';
}
