<?php

namespace App\Enums;

enum AttendancePhase: string
{
    case CheckIn = 'check_in';
    case CheckOut = 'check_out';
}
