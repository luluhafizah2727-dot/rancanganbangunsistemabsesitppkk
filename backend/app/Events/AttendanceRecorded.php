<?php

namespace App\Events;

use App\Models\Attendance;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AttendanceRecorded implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public function __construct(public Attendance $attendance, public string $phase) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('attendance-day.'.$this->attendance->day->public_id)];
    }

    public function broadcastAs(): string
    {
        return 'attendance.recorded';
    }

    public function broadcastWith(): array
    {
        $this->attendance->loadMissing('member.user', 'day');

        return [
            'attendance_id' => $this->attendance->public_id,
            'attendance_date' => $this->attendance->day->attendance_date->format('Y-m-d'),
            'phase' => $this->phase,
            'member' => $this->attendance->member->user->name,
            'recorded_at' => ($this->phase === 'check_in' ? $this->attendance->check_in_at : $this->attendance->check_out_at)?->toIso8601String(),
        ];
    }
}
