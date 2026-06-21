<?php

use App\Enums\AttendanceDeviceStatus;
use App\Models\AttendanceDay;
use App\Models\AttendanceDevice;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('attendance-day.{publicId}', function ($user, string $publicId) {
    return AttendanceDay::query()->where('public_id', $publicId)->exists()
        && $user->hasAnyRole(['super_admin', 'operator']);
});

Broadcast::channel('attendance-device.{publicId}', function ($user, string $publicId) {
    $credential = request()->cookie('attendance_device_token') ?: request()->cookie('kiosk_device_token');

    return $user->hasAnyRole(['super_admin', 'operator'])
        && is_string($credential)
        && AttendanceDevice::query()
            ->where('public_id', $publicId)
            ->where('credential_hash', hash('sha256', $credential))
            ->where('status', AttendanceDeviceStatus::Active->value)
            ->exists();
});
