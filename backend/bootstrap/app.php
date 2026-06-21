<?php

use App\Http\Middleware\AuthenticateAttendanceDevice;
use App\Http\Middleware\ConfigureCookieSecurity;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\RequestId;
use App\Http\Middleware\SecurityHeaders;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $trustedProxies = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('TRUSTED_PROXIES', '127.0.0.1,::1')),
        )));

        $middleware->trustProxies(at: $trustedProxies);
        $middleware->encryptCookies(except: ['attendance_device_token', 'kiosk_device_token', 'member_device_token']);
        $middleware->statefulApi();
        $middleware->append(ConfigureCookieSecurity::class);
        $middleware->append(RequestId::class);
        $middleware->append(SecurityHeaders::class);
        $middleware->alias([
            'attendance.device' => AuthenticateAttendanceDevice::class,
            'user.active' => EnsureUserIsActive::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $errors = $exception->errors();
            $message = collect($errors)->flatten()->first() ?: 'Data yang diberikan tidak valid.';

            return ApiResponse::error(
                $message,
                'validation_error',
                422,
                $errors,
            );
        });
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error('Sesi login diperlukan.', 'unauthenticated', 401);
        });
        $exceptions->render(function (AuthorizationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error('Anda tidak memiliki akses ke fitur ini.', 'forbidden', 403);
        });
        $exceptions->render(function (ThrottleRequestsException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error('Terlalu banyak percobaan. Coba lagi beberapa saat.', 'rate_limited', 429);
        });
        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = $exception->getStatusCode();
            $code = match ($status) {
                401 => 'unauthenticated',
                403 => 'forbidden',
                404 => 'not_found',
                409 => 'conflict',
                422 => 'validation_error',
                default => 'http_error',
            };

            return ApiResponse::error(
                $exception->getMessage() ?: 'Permintaan tidak dapat diproses.',
                $code,
                $status,
            );
        });
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            report($exception);

            return ApiResponse::error(
                'Terjadi kesalahan pada server.',
                'server_error',
                500,
            );
        });
    })->create();

$app->useEnvironmentPath(dirname(__DIR__, 2));

return $app;
