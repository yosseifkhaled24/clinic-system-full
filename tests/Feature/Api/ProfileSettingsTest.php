<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

/*
 |--------------------------------------------------------------------------
 | F-01 — profile settings API contract (Phase 0 fix A1)
 |--------------------------------------------------------------------------
 |
 | The settings page PATCHes /api/user and /api/user/password, but only
 | GET /user existed until Phase 0, so every save answered 404/405.
 | These tests lock the new contract:
 |
 |  - GET  /api/user          → 200 + bare user object (unchanged),
 |  - PATCH /api/user         → 200 + the BARE updated user object (no
 |                               `data` wrapper — profile.service.ts
 |                               passes a bare object through untouched),
 |  - PATCH /api/user/password → 200 + {message},
 |  - validation failures     → the standard 422 field-error payload the
 |                               settings page renders via
 |                               getErrorMessage(),
 |  - no token                → 401,
 |  - `role` can never be escalated through either endpoint.
 |
 | Helper names carry the `psf` prefix so they can never collide with
 | the helpers of the other feature files when the whole suite loads.
 | All tests run on the isolated in-memory SQLite database from
 | phpunit.xml — no MySQL, no real mail, no schema changes.
 */

function psfUser(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => 'user',
        // gmail.com satisfies the same `email:rfc,dns` rule registration
        // uses (the DNS check needs a domain with real MX records).
        'email' => 'psf-' . uniqid() . '@gmail.com',
    ], $overrides));
}

/*
 | ---------------------------------------------------------------------------
 | Profile update
 | ---------------------------------------------------------------------------
 */

test('GET /user still answers with the bare authenticated user', function () {
    $user = psfUser();

    Sanctum::actingAs($user);

    $this->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('id', $user->id)
        ->assertJsonPath('email', $user->email)
        ->assertJsonMissingPath('data');
});

test('PATCH /api/user updates the profile and answers with the bare user object', function () {
    $user = psfUser();
    $newEmail = 'psf-updated-' . uniqid() . '@gmail.com';

    Sanctum::actingAs($user);

    $response = $this->patchJson('/api/user', [
        'name' => 'Updated Name',
        'email' => $newEmail,
        'phone' => '01012345678',
    ]);

    $response->assertOk();

    // Bare object (no `data` wrapper) — exactly what GET /user answers
    // with, so unwrapResponse() treats both the same way.
    expect($response->json())
        ->not->toHaveKey('data')
        ->and($response->json('id'))->toBe($user->id)
        ->and($response->json('name'))->toBe('Updated Name')
        ->and($response->json('email'))->toBe($newEmail);

    $fresh = $user->fresh();

    expect($fresh->name)->toBe('Updated Name')
        ->and($fresh->email)->toBe($newEmail)
        ->and($fresh->phone)->toBe('01012345678');
});

test('PATCH /api/user accepts a partial payload and leaves untouched fields alone', function () {
    $user = psfUser();

    Sanctum::actingAs($user);

    $this->patchJson('/api/user', ['name' => 'Only Name Changed'])
        ->assertOk()
        ->assertJsonPath('name', 'Only Name Changed')
        ->assertJsonPath('email', $user->email);

    expect($user->fresh()->email)->toBe($user->email);
});

test('PATCH /api/user rejects an invalid email with a 422 field error', function () {
    Sanctum::actingAs(psfUser());

    $this->patchJson('/api/user', ['email' => 'not-an-email'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

test('PATCH /api/user rejects an email that belongs to another account', function () {
    $user = psfUser();
    $other = psfUser();

    Sanctum::actingAs($user);

    $this->patchJson('/api/user', ['email' => $other->email])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);

    expect($user->fresh()->email)->toBe($user->email);
});

test('PATCH /api/user allows keeping your own email address', function () {
    $user = psfUser();

    Sanctum::actingAs($user);

    $this->patchJson('/api/user', [
        'name' => 'Same Email Owner',
        'email' => $user->email,
    ])->assertOk()->assertJsonPath('email', $user->email);
});

test('PATCH /api/user never lets the caller escalate their own role', function () {
    $user = psfUser();

    Sanctum::actingAs($user);

    // `role` is neither validated nor mass assignable (SEC-10): it is
    // silently ignored and the account stays exactly what it was.
    $this->patchJson('/api/user', ['name' => 'Sneaky', 'role' => 'admin'])
        ->assertOk()
        ->assertJsonPath('role', 'user');

    expect($user->fresh()->role)->toBe('user');
});

test('PATCH /api/user requires authentication', function () {
    $this->patchJson('/api/user', ['name' => 'Nope'])->assertStatus(401);
});

/*
 | ---------------------------------------------------------------------------
 | Password change
 | ---------------------------------------------------------------------------
 */

test('PATCH /api/user/password changes the password and keeps the new one working', function () {
    $user = psfUser(['password' => Hash::make('old-password-123')]);

    Sanctum::actingAs($user);

    $this->patchJson('/api/user/password', [
        'current_password' => 'old-password-123',
        'password' => 'brand-new-password',
        'password_confirmation' => 'brand-new-password',
    ])
        ->assertOk()
        ->assertJsonPath('message', 'Password changed successfully.');

    expect(Hash::check('brand-new-password', $user->fresh()->password))->toBeTrue()
        ->and(Hash::check('old-password-123', $user->fresh()->password))->toBeFalse();
});

test('PATCH /api/user/password rejects a wrong current password and keeps the old one', function () {
    $user = psfUser(['password' => Hash::make('old-password-123')]);

    Sanctum::actingAs($user);

    $this->patchJson('/api/user/password', [
        'current_password' => 'wrong-password',
        'password' => 'brand-new-password',
        'password_confirmation' => 'brand-new-password',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['current_password']);

    expect(Hash::check('old-password-123', $user->fresh()->password))->toBeTrue();
});

test('PATCH /api/user/password enforces minimum length and confirmation match', function () {
    $user = psfUser(['password' => Hash::make('old-password-123')]);

    Sanctum::actingAs($user);

    $this->patchJson('/api/user/password', [
        'current_password' => 'old-password-123',
        'password' => 'short',
        'password_confirmation' => 'short',
    ])->assertStatus(422)->assertJsonValidationErrors(['password']);

    $this->patchJson('/api/user/password', [
        'current_password' => 'old-password-123',
        'password' => 'brand-new-password',
        'password_confirmation' => 'different-password',
    ])->assertStatus(422)->assertJsonValidationErrors(['password']);

    expect(Hash::check('old-password-123', $user->fresh()->password))->toBeTrue();
});

test('PATCH /api/user/password requires authentication', function () {
    $this->patchJson('/api/user/password', [
        'current_password' => 'x',
        'password' => 'brand-new-password',
        'password_confirmation' => 'brand-new-password',
    ])->assertStatus(401);
});
