<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| SEC-13 — Logout compatibility (Bearer tokens AND transient/session)
|--------------------------------------------------------------------------
|
| AuthController::logout must:
|  - revoke the CURRENT PersonalAccessToken when the caller used a
|    Bearer token (and nothing else — other users' tokens, and the
|    caller's other tokens, stay alive);
|  - return the same 200 contract for transient/session callers, whose
|    TransientToken has NO delete() method (calling it used to fatal);
|  - keep the guest 401 contract of the auth:sanctum route group.
|
| All tests run on the isolated in-memory SQLite database from
| phpunit.xml. Helper names carry the `s13` prefix to avoid Pest's
| global function namespace colliding with other batch test files.
*/

function s13User(string $role = 'user'): User
{
    return User::factory()->create(['role' => $role]);
}

/** Issues a real token through the real login flow (password = factory default). */
function s13Login($t, User $user): string
{
    return $t->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertStatus(200)->json('token');
}

/**
 * The test harness shares ONE application instance across the requests
 * of a single test, so Laravel's auth guards (RequestGuard/SessionGuard)
 * memoize the resolved user between them — production runs a fresh
 * process per request, which never leaks. Resetting the guards emulates
 * that fresh cycle so each request re-resolves authentication from its
 * own credentials.
 */
function s13FreshAuth(): void
{
    // Auth::attempt during login wrote the user id into the shared
    // in-memory session store (API routes never persist a session
    // across requests in production) — clear it, then discard every
    // memoized guard instance so the next request re-resolves
    // authentication from its own credentials.
    Auth::guard('web')->logout();
    Auth::forgetGuards();
}

test('logout with a personal access token revokes only that token (200 + contract)', function () {
    $alice = s13User();
    $bob = s13User();

    $aliceToken = s13Login($this, $alice);
    $bobToken = s13Login($this, $bob);
    s13FreshAuth();

    expect(PersonalAccessToken::count())->toBe(2);

    $this->json('POST', '/api/logout', [], ['Authorization' => "Bearer {$aliceToken}"])
        ->assertStatus(200)
        ->assertExactJson(['message' => 'Logged out successfully.']);
    s13FreshAuth();

    // Alice's token is gone; Bob's token (another user) is untouched.
    expect(PersonalAccessToken::count())->toBe(1)
        ->and($alice->tokens()->count())->toBe(0)
        ->and($bob->tokens()->count())->toBe(1);

    $this->json('GET', '/api/user', [], ['Authorization' => "Bearer {$aliceToken}"])
        ->assertStatus(401);
    $this->json('GET', '/api/user', [], ['Authorization' => "Bearer {$bobToken}"])
        ->assertStatus(200);
});

test('logout revokes the CURRENT token only — other tokens of the same caller survive', function () {
    $user = s13User();
    $first = $user->createToken('api-token')->plainTextToken;
    $second = $user->createToken('api-token')->plainTextToken;

    $this->json('POST', '/api/logout', [], ['Authorization' => "Bearer {$first}"])
        ->assertStatus(200);
    s13FreshAuth();

    $this->json('GET', '/api/user', [], ['Authorization' => "Bearer {$first}"])
        ->assertStatus(401);
    $this->json('GET', '/api/user', [], ['Authorization' => "Bearer {$second}"])
        ->assertStatus(200);
});

test('logout with a transient (stateful/session) token succeeds without calling delete()', function () {
    // Sanctum::actingAs authenticates through the guard with a
    // TransientToken — the exact object that has no delete() method.
    Sanctum::actingAs(s13User());

    $this->postJson('/api/logout')
        ->assertStatus(200)
        ->assertExactJson(['message' => 'Logged out successfully.']);

    // A transient caller has no token row to remove — none was created.
    expect(PersonalAccessToken::count())->toBe(0);
});

test('guest logout still answers 401 (auth:sanctum contract unchanged)', function () {
    $this->postJson('/api/logout')->assertStatus(401);
});
