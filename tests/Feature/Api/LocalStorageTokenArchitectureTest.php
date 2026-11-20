<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

/*
|--------------------------------------------------------------------------
| SEC-19 — Option A (approved): Bearer token + localStorage, no cookies
|--------------------------------------------------------------------------
|
| The architecture is intentionally PRESERVED (no HttpOnly migration):
|  - login issues a Bearer token and sets no session cookie;
|  - the Authorization header alone authenticates (no cookie pipeline);
|  - the frontend stores the token in localStorage and attaches it via
|    axios (documented residual XSS risk + mitigations in
|    context/AuthContext.tsx and lib/axios.ts: 30-day expiry from
|    SEC-14, one-active-token-per-user, 401 cleanup, Report-Only CSP).
|
| These tests lock the backend half; a source-level check locks the
| presence of the SEC-19 documentation in the frontend repo (skipped
| when the sibling checkout is absent).
*/

test('login issues a Bearer token and sets no session cookie (cookie auth NOT introduced)', function () {
    $user = User::factory()->create(['role' => 'user']);

    $response = $this->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertStatus(200)->assertJsonStructure(['message', 'token', 'user']);

    $setCookie = (string) $response->headers->get('Set-Cookie');
    expect($setCookie === '' || ! str_contains(strtolower($setCookie), 'session'))
        ->toBeTrue();
});

test('the Authorization header alone authenticates — no cookies are required', function () {
    $user = User::factory()->create(['role' => 'user']);
    $token = $user->createToken('api-token')->plainTextToken;

    $this->json('GET', '/api/user', [], ['Authorization' => "Bearer {$token}"])
        ->assertStatus(200)
        ->assertJsonPath('id', $user->id);

    // Guards memoize the resolved user on the shared test app instance
    // (production = fresh process per request) — reset so the next
    // request must authenticate from scratch.
    Auth::forgetGuards();

    // A session cookie by itself must NOT authenticate (no stateful guard).
    $this->withCookie(config('session.cookie'), 'forged-session-value')
        ->getJson('/api/user')
        ->assertStatus(401);
});

test('the SEC-19 documentation is present at the frontend localStorage token sites', function () {
    $frontendRoot = dirname(__DIR__, 3) . '/../clinic-system_frontend';
    $axiosConfig = $frontendRoot . '/lib/axios.ts';
    $authContext = $frontendRoot . '/context/AuthContext.tsx';

    if (! is_file($axiosConfig) || ! is_file($authContext)) {
        $this->markTestSkipped('frontend checkout not present next to the backend');
    }

    expect(file_get_contents($axiosConfig))->toContain('SEC-19')
        ->and(file_get_contents($authContext))->toContain('SEC-19');
});
