<?php

namespace App\Http\Middleware;

use App\Support\CookieSecurity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ConfigureCookieSecurity
{
    public function handle(Request $request, Closure $next): Response
    {
        config(['session.secure' => CookieSecurity::forRequest($request)]);

        return $next($request);
    }
}
