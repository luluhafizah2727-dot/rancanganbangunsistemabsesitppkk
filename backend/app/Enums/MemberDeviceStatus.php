<?php

namespace App\Enums;

enum MemberDeviceStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Revoked = 'revoked';
}
