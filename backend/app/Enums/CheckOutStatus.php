<?php

namespace App\Enums;

enum CheckOutStatus: string
{
    case OnTime = 'on_time';
    case Early = 'early';
}
