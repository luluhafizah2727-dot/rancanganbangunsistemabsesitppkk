<?php

namespace App\Jobs;

use App\Services\QrTokenService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RotateQrTokens implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 8;

    public function handle(QrTokenService $service): void
    {
        $service->rotateAll();
    }
}
