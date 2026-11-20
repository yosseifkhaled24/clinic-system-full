<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SEC-05 — Debug configuration hardening (fully offline)
|--------------------------------------------------------------------------
|
| Verifies the security contract around APP_DEBUG:
|
|  - with APP_DEBUG=false (the production requirement) a server error
|    returns a generic message only — no exception text, no stack
|    traces, no file paths, no config;
|  - with APP_DEBUG=true (local development) the diagnostic detail is
|    still available, so developer debugging is preserved.
|
| The real .env is never read from or written to: the flag is toggled
| through the config repository only, on the in-memory SQLite test
| database.
*/

const SEC05_MARKER = 'SEC05-sensitive-marker-do-not-leak';

function sec05BoomRoute(): void
{
    Route::get('/_sec05_boom', function () {
        throw new \RuntimeException('simulated failure: ' . SEC05_MARKER);
    });
}

test('with APP_DEBUG=false a server error exposes nothing beyond a generic message', function () {
    sec05BoomRoute();
    config(['app.debug' => false]);

    $response = $this->getJson('/_sec05_boom');

    $response->assertStatus(500);
    expect($response->json('message'))->toBe('Server Error');

    $body = $response->getContent();
    expect($body)->not->toContain(SEC05_MARKER)
        ->and($body)->not->toContain('RuntimeException')
        ->and($body)->not->toContain('Trace')
        ->and($body)->not->toContain('.php');
});

test('with APP_DEBUG=true the diagnostic detail is still available for local debugging', function () {
    sec05BoomRoute();
    config(['app.debug' => true]);

    $response = $this->getJson('/_sec05_boom');

    $response->assertStatus(500);
    // Local debugging preserved: the exception detail is present when
    // (and only when) debug mode is on.
    expect($response->getContent())->toContain(SEC05_MARKER);
});
