<?php

namespace App\Enums;

enum AttendanceDeviceStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Inactive = 'inactive';
    case Revoked = 'revoked';
}
