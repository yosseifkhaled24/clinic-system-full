<?php

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| SEC-15 — Registration must not act as an account-enumeration oracle
|--------------------------------------------------------------------------
|
| Duplicate email AND duplicate phone now answer with the SAME generic
| 422 (top-level `message`, no `errors` object, no field name), so an
| attacker cannot learn which emails/phones already belong to accounts.
|
| Preserved on purpose:
|  - the DB uniqueness constraint on users.email (defense in depth);
|  - the phone rule (users.phone has NO unique index — enforcing it in
|    the application layer keeps the old behavior without a migration);
|  - the successful-registration contract (201 + verification fields,
|    no token) and the email verification flow;
|  - field-level validation for NON-enumeration rules (e.g. password).
|
| Register is throttled 3/min/IP (fresh array cache per test), so each
| test below stays within the limit. In-memory SQLite only.
*/

function s15Payload(array $overrides = []): array
{
    static $sequence = 0;
    $sequence++;

    return array_merge([
        'name' => 'SEC15 User ' . $sequence,
        'email' => 's15-' . $sequence . '-' . uniqid() . '@gmail.com',
        'phone' => '017' . $sequence . uniqid(),
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ], $overrides);
}

test('duplicate email and duplicate phone return an identical generic 422 with no field oracle', function () {
    $existing = User::factory()->create([
        'role' => 'user',
        'email' => 's15-existing@gmail.com',
        'phone' => '01799999999',
    ]);
    $before = User::count();

    $duplicateEmail = $this->postJson('/api/register', s15Payload(['email' => $existing->email]));
    $duplicatePhone = $this->postJson('/api/register', s15Payload(['phone' => $existing->phone]));

    $duplicateEmail->assertStatus(422);
    $duplicatePhone->assertStatus(422);

    // Same status, same body, no `errors` object (which would key the
    // message to a field), no wording that names the conflicting field.
    expect($duplicateEmail->json())->not->toHaveKey('errors')
        ->and($duplicatePhone->json())->not->toHaveKey('errors')
        ->and($duplicateEmail->json('message'))->toBe($duplicatePhone->json('message'))
        ->and($duplicateEmail->json('message'))
            ->toBe('Registration failed. Please check your details and try again.');

    foreach (['email', 'phone', 'taken', 'exists'] as $leak) {
        expect(strtolower((string) $duplicateEmail->json('message')))->not->toContain($leak);
        expect(strtolower((string) $duplicatePhone->json('message')))->not->toContain($leak);
    }

    // No account was created by either attempt.
    expect(User::count())->toBe($before);
});

test('the successful registration contract is unchanged (201 + verification fields, no token)', function () {
    Notification::fake();

    $payload = s15Payload();

    $response = $this->postJson('/api/register', $payload);

    $response->assertStatus(201)
        ->assertJsonPath('verification_required', true)
        ->assertJsonStructure(['message', 'verification_required', 'notification_sent', 'user'])
        ->assertJsonMissingPath('token');

    $user = User::where('email', $payload['email'])->first();
    expect($user)->not->toBeNull()
        ->and($user->role)->toBe('user')
        ->and($user->email_verified_at)->toBeNull();

    Notification::assertSentTo($user, \Illuminate\Auth\Notifications\VerifyEmail::class);
});

test('non-enumeration validation rules still return field-level errors (password too short)', function () {
    $response = $this->postJson('/api/register', s15Payload([
        'password' => 'short',
        'password_confirmation' => 'short',
    ]));

    $response->assertStatus(422)->assertJsonValidationErrors(['password']);
});

test('the database-level email uniqueness constraint is still enforced (defense in depth)', function () {
    $existing = User::factory()->create();

    expect(fn () => User::create([
        'name' => 'Duplicate Row',
        'email' => $existing->email,
        'phone' => '01888888888',
        'password' => Hash::make('password123'),
    ]))->toThrow(UniqueConstraintViolationException::class);
});
