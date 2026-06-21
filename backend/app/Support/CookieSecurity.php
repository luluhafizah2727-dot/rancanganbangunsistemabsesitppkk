<?php

namespace App\Support;

use Illuminate\Http\Request;

final class CookieSecurity
{
    public static function forRequest(Request $request): bool
    {
        $mode = strtolower(trim((string) config('session.secure_mode', 'auto')));

        if ($mode === 'auto' || $mode === '') {
            return $request->isSecure();
        }

        return filter_var($mode, FILTER_VALIDATE_BOOL);
    }
}
