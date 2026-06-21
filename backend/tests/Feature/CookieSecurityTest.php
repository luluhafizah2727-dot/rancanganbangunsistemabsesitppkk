<?php

use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;

function csrfCookie(TestResponse $response): Cookie
{
    return collect($response->headers->getCookies())
        ->first(fn (Cookie $cookie) => $cookie->getName() === 'XSRF-TOKEN');
}

it('marks cookies secure when a trusted proxy forwards https', function () {
    config(['session.secure_mode' => 'auto']);

    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->withHeader('X-Forwarded-Proto', 'https')
        ->get('/sanctum/csrf-cookie');

    expect(csrfCookie($response)->isSecure())->toBeTrue();
});

it('allows non secure cookies for direct http network access', function () {
    config(['session.secure_mode' => 'auto']);

    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => '20.20.20.2'])
        ->get('/sanctum/csrf-cookie');

    expect(csrfCookie($response)->isSecure())->toBeFalse();
});

it('ignores forwarded https from an untrusted address', function () {
    config(['session.secure_mode' => 'auto']);

    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
        ->withHeader('X-Forwarded-Proto', 'https')
        ->get('/sanctum/csrf-cookie');

    expect(csrfCookie($response)->isSecure())->toBeFalse();
});
