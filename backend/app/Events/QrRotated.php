<?php

namespace App\Events;

use App\Models\AttendanceDevice;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class QrRotated implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public function __construct(public AttendanceDevice $device, public array $payload) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('attendance-device.'.$this->device->public_id)];
    }

    public function broadcastAs(): string
    {
        return 'qr.rotated';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
