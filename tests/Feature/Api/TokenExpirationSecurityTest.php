<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

/*
|--------------------------------------------------------------------------
| SEC-14 — Sanctum token expiration (30 days = 43200 minutes)
|--------------------------------------------------------------------------
|
| config('sanctum.expiration') is enforced by Sanctum\Guard against
| personal_access_tokens.created_at on EVERY request, so:
|  - no migration was added and no existing token row was modified;
|  - tokens older than 43200 minutes are rejected with 401 on their
|    next request (the frontend interceptor then clears the token);
|  - the one-active-token-per-user policy in AuthController::login is
|    preserved.
|
| Raw `Authorization: Bearer` headers are used throughout because
| Sanctum::actingAs() bypasses the token guard (it would skip the
| expiration check entirely). In-memory SQLite only.
*/

function s14User(): User
{
    return User::factory()->create(['role' => 'user']);
}

function s14Login($t, User $user): string
{
    return $t->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertStatus(200)->json('token');
}

/**
 * Guards memoize the resolved user for the lifetime of the shared test
 * application instance (production = one fresh process per request).
 * Reset them so the NEXT request re-resolves authentication from its
 * own Bearer header — otherwise a prior login's session user would
 * short-circuit the token guard (Sanctum checks the web guard first).
 */
function s14FreshAuth(): void
{
    // Guards memoize the resolved user for the lifetime of the shared
    // test application instance (production = one fresh process per
    // request), and Auth::attempt leaves the login id in the shared
    // in-memory session store — clear both so the next request must
    // re-resolve authentication from its own Bearer header. Otherwise
    // the prior login's session user short-circuits the token guard
    // (Sanctum checks the web guard BEFORE the bearer token).
    Auth::guard('web')->logout();
    Auth::forgetGuards();
}

test('token expiration is configured to 30 days (43200 minutes)', function () {
    expect(config('sanctum.expiration'))->toBe(43200);
});

test('a freshly issued token authenticates API requests (token creation + valid token)', function () {
    $user = s14User();
    $token = s14Login($this, $user);
    s14FreshAuth();

    expect(PersonalAccessToken::count())->toBe(1)
        ->and($user->tokens()->first()->name)->toBe('api-token');

    $this->json('GET', '/api/user', [], ['Authorization' => "Bearer {$token}"])
        ->assertStatus(200)
        ->assertJsonPath('id', $user->id);
});

test('a token inside the 30-day window (20 days old) still authenticates', function () {
    $user = s14User();
    $token = s14Login($this, $user);
    s14FreshAuth();

    $user->tokens()->update(['created_at' => now()->subMinutes(28800)]); // 20 days

    $this->json('GET', '/api/user', [], ['Authorization' => "Bearer {$token}"])
        ->assertStatus(200);
});

test('a token older than 30 days is rejected with 401 and the token row is NOT deleted', function () {
    $user = s14User();
    $token = s14Login($this, $user);
    s14FreshAuth();

    $user->tokens()->update(['created_at' => now()->subMinutes(43201)]); // 30d + 1min

    $this->json('GET', '/api/user', [], ['Authorization' => "Bearer {$token}"])
        ->assertStatus(401);

    // Expiration is a guard-level rejection only: no existing token
    // record is deleted or modified by the new policy.
    expect(PersonalAccessToken::count())->toBe(1);
});

test('the one-active-token-per-user policy is preserved: a second login revokes the first token', function () {
    $user = s14User();

    $first = s14Login($this, $user);
    $second = s14Login($this, $user);
    s14FreshAuth();

    expect($first)->not->toBe($second)
        ->and(PersonalAccessToken::count())->toBe(1);

    $this->json('GET', '/api/user', [], ['Authorization' => "Bearer {$first}"])
        ->assertStatus(401);
    $this->json('GET', '/api/user', [], ['Authorization' => "Bearer {$second}"])
        ->assertStatus(200);
});
