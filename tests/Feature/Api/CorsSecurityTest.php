<?php

use Illuminate\Testing\TestResponse;

/*
|--------------------------------------------------------------------------
| SEC-16 — Explicit CORS configuration (no more wildcard origins)
|--------------------------------------------------------------------------
|
| config/cors.php pins the allowed origins to exactly:
|   - http://localhost:3000     (local Next.js dev server)
|   - http://127.0.0.1:3000     (local Next.js dev server, IP form)
|   - the FRONTEND_URL origin from the environment (never hard-coded;
|     may be absent in tests, so assertions treat it as optional)
|
| supports_credentials stays false (stateless Bearer auth), methods and
| headers are enumerated instead of `*`, and unapproved origins receive
| NO Access-Control-Allow-Origin grant — on preflight AND on actual
| requests. In-memory SQLite only; no network involved.
*/

function s16Preflight($t, string $origin, string $method = 'GET'): TestResponse
{
    return $t->call('OPTIONS', '/api/reviews', [], [], [], [
        'HTTP_ORIGIN' => $origin,
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => $method,
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
        'HTTP_ACCEPT' => 'application/json',
    ]);
}

test('the CORS config allows exactly the approved origins with credentials disabled', function () {
    $origins = config('cors.allowed_origins');

    expect($origins)->toContain('http://localhost:3000')
        ->and($origins)->toContain('http://127.0.0.1:3000');

    // Every configured origin is either a local dev origin or exactly
    // the configured FRONTEND_URL (no invented production domain).
    $frontend = rtrim((string) config('app.frontend_url'), '/');
    foreach ($origins as $origin) {
        $approved = in_array($origin, ['http://localhost:3000', 'http://127.0.0.1:3000'], true)
            || ($frontend !== '' && $origin === $frontend);
        expect($approved)->toBeTrue();
    }

    expect(config('cors.supports_credentials'))->toBeFalse()
        ->and(config('cors.allowed_methods'))->not->toContain('*')
        ->and(config('cors.allowed_headers'))->not->toContain('*')
        ->and(config('cors.paths'))->toContain('api/*');
});

test('preflight from an approved origin echoes that exact origin (never `*`) and grants the API verbs/headers', function () {
    foreach (['http://localhost:3000', 'http://127.0.0.1:3000'] as $origin) {
        $response = s16Preflight($this, $origin, 'POST');

        $response->assertStatus(204);
        expect($response->headers->get('Access-Control-Allow-Origin'))->toBe($origin)
            ->and($response->headers->get('Access-Control-Allow-Credentials'))->toBeNull();

        $methods = (string) $response->headers->get('Access-Control-Allow-Methods');
        expect($methods)->toContain('GET')->toContain('POST')->toContain('PUT')
            ->toContain('PATCH')->toContain('DELETE');

        $headers = strtolower((string) $response->headers->get('Access-Control-Allow-Headers'));
        expect($headers)->toContain('authorization')->toContain('content-type');
    }
});

test('preflight from an unapproved origin receives NO CORS grant', function () {
    $response = s16Preflight($this, 'https://evil.example');

    expect($response->headers->has('Access-Control-Allow-Origin'))->toBeFalse()
        ->and($response->headers->has('Access-Control-Allow-Credentials'))->toBeFalse();
});

test('actual requests: approved origin is echoed, unapproved origin gets no Access-Control-Allow-Origin', function () {
    $allowed = $this->call('GET', '/api/reviews', [], [], [], [
        'HTTP_ORIGIN' => 'http://localhost:3000',
        'HTTP_ACCEPT' => 'application/json',
    ]);
    $allowed->assertStatus(200);
    expect($allowed->headers->get('Access-Control-Allow-Origin'))
        ->toBe('http://localhost:3000');

    $denied = $this->call('GET', '/api/reviews', [], [], [], [
        'HTTP_ORIGIN' => 'https://evil.example',
        'HTTP_ACCEPT' => 'application/json',
    ]);
    $denied->assertStatus(200);
    expect($denied->headers->has('Access-Control-Allow-Origin'))->toBeFalse();
});
