<?php

namespace App\Enums;

enum CheckInStatus: string
{
    case OnTime = 'on_time';
    case Late = 'late';
}
