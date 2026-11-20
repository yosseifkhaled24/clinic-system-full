<?php

/*
|--------------------------------------------------------------------------
| SEC-17 — Security headers on Laravel API responses
|--------------------------------------------------------------------------
|
| App\Http\Middleware\SecurityHeaders (registered globally in
| bootstrap/app.php) adds a baseline to every Laravel response without
| overwriting headers a response already carries:
|
|   - X-Content-Type-Options: nosniff
|   - X-Frame-Options: DENY            (API payloads must not be framed)
|   - Referrer-Policy: no-referrer
|   - Permissions-Policy: camera/mic/geo off
|   - Content-Security-Policy on JSON responses only (deny-all style:
|     API JSON is machine-consumed, never rendered)
|
| HSTS is deliberately NOT sent: development runs over plain HTTP and
| an HSTS header would poison browsers for localhost. Production must
| enable it at the TLS terminator (deployment requirement).
|
| The Next.js side (existing XFO/nosniff/Referrer/Permissions headers
| preserved + new Report-Only CSP) lives in next.config.ts and is
| verified by the production build, not by this suite.
*/

function s17ApiResponse($t): Illuminate\Testing\TestResponse
{
    return $t->getJson('/api/reviews')->assertStatus(200);
}

test('API JSON responses carry the full security header baseline', function () {
    $response = s17ApiResponse($this);

    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer')
        ->and($response->headers->get('Permissions-Policy'))
            ->toBe('camera=(), microphone=(), geolocation=()');
});

test('JSON responses receive a deny-all Content-Security-Policy', function () {
    $csp = s17ApiResponse($this)->headers->get('Content-Security-Policy');

    expect($csp)
        ->toContain("default-src 'none'")
        ->toContain("frame-ancestors 'none'")
        ->toContain("base-uri 'none'")
        ->toContain("form-action 'none'");
});

test('no Strict-Transport-Security header is sent over local HTTP', function () {
    expect(s17ApiResponse($this)->headers->get('Strict-Transport-Security'))->toBeNull();
});

test('non-JSON responses get the header baseline but no strict JSON CSP', function () {
    // A non-API, non-JSON response (the HTML 404 page) must not be
    // forced through the deny-all JSON policy — that CSP would break
    // any rendered page while adding nothing to machine consumers.
    $response = $this->get('/', ['Accept' => 'text/html']);

    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Security-Policy'))->toBeNull();
});

test('security headers are present on error responses too (401 JSON)', function () {
    $response = $this->postJson('/api/logout'); // guest → 401 JSON

    $response->assertStatus(401);
    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Security-Policy'))
            ->toContain("default-src 'none'");
});
