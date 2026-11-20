<?php

use App\Models\User;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| SEC-04 — Login / registration brute-force protection (fully offline)
|--------------------------------------------------------------------------
|
| All tests run on the isolated in-memory SQLite database from
| phpunit.xml (CACHE_STORE=array keeps limiter state inside a single
| test). No network, no external services.
|
| Policy under test:
|  - login: 5 failed attempts / 60s per NORMALIZED email + IP,
|           429 + Retry-After afterwards, counter CLEARED by success,
|           trivial case/whitespace variations share one bucket,
|           responses never reveal whether an account exists,
|           no permanent lockout (short window only).
|  - register: 3/min per IP.
*/

function rlVerifiedUser(): User
{
    return User::factory()->create(['role' => 'user']);
}

function rlBadLogin($test, string $email): int
{
    return $test->postJson('/api/login', [
        'email' => $email,
        'password' => 'wrong-password-' . uniqid(),
    ])->getStatusCode();
}

test('login is throttled: the 6th rapid failed attempt returns 429 with Retry-After', function () {
    $user = rlVerifiedUser();

    foreach (range(1, 5) as $i) {
        expect(rlBadLogin($this, $user->email))->toBe(422); // not throttled yet
    }

    $sixth = $this->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'still-wrong',
    ]);

    $sixth->assertStatus(429);
    expect($sixth->headers->get('Retry-After'))->not->toBeNull()
        ->and($sixth->json('message'))->toContain('Too many attempts');
});

test('trivial case and whitespace variations cannot bypass the login throttle', function () {
    $email = 'Throttle.Variation.' . uniqid() . '@gmail.com';
    User::factory()->create(['role' => 'user', 'email' => $email]);

    // Five failures in different casing/whitespace forms of ONE identity.
    $variations = [
        $email,
        strtoupper($email),
        '  ' . $email . '  ',
        Str::lower($email),
        ucfirst($email),
    ];

    foreach ($variations as $variation) {
        expect(rlBadLogin($this, $variation))->toBe(422);
    }

    // A sixth form of the same identity must hit the shared bucket.
    $this->postJson('/api/login', [
        'email' => '   ' . strtoupper($email) . '   ',
        'password' => 'wrong',
    ])->assertStatus(429);
});

test('a successful login clears the failed-attempt counter (no permanent lockout)', function () {
    $user = rlVerifiedUser();

    foreach (range(1, 4) as $i) {
        expect(rlBadLogin($this, $user->email))->toBe(422);
    }

    // Correct credentials reset the bucket.
    $this->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertStatus(200);

    // Five more failures fit in a fresh budget — never429 (the limiter
    // trips only once the counter reaches 5 and the NEXT request comes in).
    foreach (range(1, 5) as $i) {
        expect(rlBadLogin($this, $user->email))->toBe(422);
    }

    // The sixth failure after the reset trips the limiter again.
    $this->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'wrong-again',
    ])->assertStatus(429);
});

test('the login throttle is isolated per identity: exhausting one account does not block another', function () {
    $blocked = rlVerifiedUser();
    $other = rlVerifiedUser();

    foreach (range(1, 5) as $i) {
        rlBadLogin($this, $blocked->email);
    }

    $this->postJson('/api/login', [
        'email' => $blocked->email,
        'password' => 'wrong',
    ])->assertStatus(429);

    // A different identity still logs in fine from the same IP.
    $this->postJson('/api/login', [
        'email' => $other->email,
        'password' => 'password',
    ])->assertStatus(200);
});

test('throttle responses do not leak whether an account exists', function () {
    $existing = rlVerifiedUser();
    $ghostEmail = 'ghost-' . uniqid() . '@gmail.com';

    foreach (range(1, 5) as $i) {
        rlBadLogin($this, $existing->email);
        rlBadLogin($this, $ghostEmail);
    }

    $existingBlocked = $this->postJson('/api/login', [
        'email' => $existing->email,
        'password' => 'wrong',
    ])->assertStatus(429);

    $ghostBlocked = $this->postJson('/api/login', [
        'email' => $ghostEmail,
        'password' => 'wrong',
    ])->assertStatus(429);

    // Identical status and message shape — only the countdown seconds
    // may differ by one between the two keys — so an attacker cannot
    // tell the two accounts apart by the throttle response.
    $existingMessage = (string) $existingBlocked->json('message');
    $ghostMessage = (string) $ghostBlocked->json('message');

    expect($ghostBlocked->getStatusCode())->toBe($existingBlocked->getStatusCode())
        ->and($existingMessage)->toMatch('/^Too many attempts\. Please try again in \d+ seconds\.$/')
        ->and($ghostMessage)->toMatch('/^Too many attempts\. Please try again in \d+ seconds\.$/')
        ->and(preg_replace('/\d+/', '#', $ghostMessage))->toBe(preg_replace('/\d+/', '#', $existingMessage))
        ->and($existingBlocked->headers->get('Retry-After'))->not->toBeNull()
        ->and($ghostBlocked->headers->get('Retry-After'))->not->toBeNull();
});

test('the throttle itself does not reveal account state before the limit is reached', function () {
    $existing = rlVerifiedUser();
    $ghostEmail = 'ghost-' . uniqid() . '@gmail.com';

    $existingStatus = rlBadLogin($this, $existing->email);
    $ghostStatus = rlBadLogin($this, $ghostEmail);

    // Same validation failure (422) for known and unknown accounts.
    expect($existingStatus)->toBe(422)
        ->and($ghostStatus)->toBe(422);
});
