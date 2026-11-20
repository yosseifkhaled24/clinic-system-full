<?php

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;

/*
|--------------------------------------------------------------------------
| SEC-03 — Email verification flow (fully offline)
|--------------------------------------------------------------------------
|
| Runs on the isolated in-memory SQLite database from phpunit.xml. Mail
| goes through Notification::fake() or a stubbed MailManager — no real
| email is ever sent and no external service is contacted.
|
| Covered: register creates an unverified account without a token, the
| notification is sent, the signed link verifies, invalid/expired/tampered
| links are rejected gracefully, resend works uniformly and is throttled,
| notification failure is handled safely, and no duplicate accounts are
| created.
*/

function evUser(array $overrides = []): User
{
    return User::factory()->create(array_merge(['role' => 'user'], $overrides));
}

function evUnverifiedUser(array $overrides = []): User
{
    return User::factory()->unverified()->create(array_merge(['role' => 'user'], $overrides));
}

function evRegisterPayload(array $overrides = []): array
{
    static $sequence = 0;
    $sequence++;

    return array_merge([
        'name' => 'EV Test User ' . $sequence . '-' . uniqid(),
        'email' => 'ev-' . uniqid() . '-'.$sequence.'@gmail.com',
        'phone' => '015' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT) . ($sequence % 10),
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ], $overrides);
}

function evSignedUrl(User $user, $expiresAt = null): string
{
    return URL::temporarySignedRoute(
        'verification.verify',
        $expiresAt ?? now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())]
    );
}

function evStatusFromRedirect(TestResponse $response): ?string
{
    $location = (string) $response->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    return $query['status'] ?? null;
}

/*
| 1. Registration
*/

test('registration creates an unverified account, issues no token, and sends the verification notification', function () {
    Notification::fake();

    $payload = evRegisterPayload();

    $response = $this->postJson('/api/register', $payload);

    $response->assertStatus(201)
        ->assertJsonPath('verification_required', true)
        ->assertJsonPath('notification_sent', true)
        ->assertJsonMissingPath('token');

    $user = User::where('email', $payload['email'])->first();
    expect($user)->not->toBeNull()
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->role)->toBe('user');

    Notification::assertSentTo($user, VerifyEmail::class);
});

test('the verification mail links to the frontend verify page while keeping the signed backend URL params', function () {
    config(['app.frontend_url' => 'https://clinic.example']);
    $user = evUnverifiedUser();

    $mail = (new VerifyEmail())->toMail($user);

    expect($mail->actionUrl)->toStartWith('https://clinic.example/verify-email?')
        ->and($mail->actionUrl)->toContain('id=' . $user->id)
        ->and($mail->actionUrl)->toContain('hash=')
        ->and($mail->actionUrl)->toContain('expires=')
        ->and($mail->actionUrl)->toContain('signature=');
});

test('a mail transport failure never 500s registration, keeps the account, and reports notification_sent=false', function () {
    // Simulate an unreachable SMTP server: the mailer resolution itself
    // throws, exactly like a transport outage would. The container
    // resolves Illuminate\Contracts\Mail\Factory through the
    // 'mail.manager' alias, so the stub is bound there.
    $this->app->instance('mail.manager', new class implements MailFactory {
        public function mailer($name = null)
        {
            throw new \RuntimeException('SMTP unavailable (simulated)');
        }
    });

    Log::spy();

    $payload = evRegisterPayload();

    $response = $this->postJson('/api/register', $payload);

    $response->assertStatus(201)
        ->assertJsonPath('verification_required', true)
        ->assertJsonPath('notification_sent', false)
        ->assertJsonMissingPath('token');

    // The account survived and can still be verified / resent later.
    $user = User::where('email', $payload['email'])->first();
    expect($user)->not->toBeNull()
        ->and($user->email_verified_at)->toBeNull();

    // Operational logging happened, carrying only the user id and the
    // failure reason — never the password or any token/link.
    Log::shouldHaveReceived('error')->once()->withArgs(function ($message, $context) use ($user, $payload) {
        return $message === 'Email verification notification failed.'
            && ($context['user_id'] ?? null) === $user->id
            && ! str_contains(json_encode($context), $payload['password']);
    });
});

/*
| 2. The signed verification link
*/

test('a valid signed link verifies the address and reports status=verified', function () {
    config(['app.frontend_url' => 'https://clinic.example']);
    $user = evUnverifiedUser();

    Event::fake();

    $response = $this->get(evSignedUrl($user));

    $response->assertStatus(302);
    expect(evStatusFromRedirect($response))->toBe('verified')
        ->and($user->fresh()->hasVerifiedEmail())->toBeTrue();

    Event::assertDispatched(Verified::class);
});

test('a link with a wrong hash is rejected with status=invalid and verifies nothing', function () {
    config(['app.frontend_url' => 'https://clinic.example']);
    $user = evUnverifiedUser();

    $url = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1('someone-else@example.com')]
    );

    $response = $this->get($url);

    expect(evStatusFromRedirect($response))->toBe('invalid')
        ->and($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('a link whose signature is tampered with is rejected with status=invalid', function () {
    config(['app.frontend_url' => 'https://clinic.example']);
    $user = evUnverifiedUser();

    $url = evSignedUrl($user);
    $tampered = preg_replace('/signature=[^&]+/', 'signature=' . str_repeat('a', 64), $url);

    $response = $this->get($tampered);

    expect(evStatusFromRedirect($response))->toBe('invalid')
        ->and($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('an expired link is rejected with status=expired and verifies nothing', function () {
    config(['app.frontend_url' => 'https://clinic.example']);
    $user = evUnverifiedUser();

    $response = $this->get(evSignedUrl($user, now()->subMinutes(10)));

    expect(evStatusFromRedirect($response))->toBe('expired')
        ->and($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('visiting a valid link twice is idempotent (already-verified accounts stay verified)', function () {
    config(['app.frontend_url' => 'https://clinic.example']);
    $user = evUnverifiedUser();
    $url = evSignedUrl($user);

    $this->get($url)->assertStatus(302);

    $second = $this->get($url);

    expect(evStatusFromRedirect($second))->toBe('verified')
        ->and($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

test('an unknown account id in the link is rejected with status=invalid', function () {
    config(['app.frontend_url' => 'https://clinic.example']);
    $user = evUnverifiedUser();

    $url = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id + 999999, 'hash' => sha1($user->getEmailForVerification())]
    );

    $response = $this->get($url);

    expect(evStatusFromRedirect($response))->toBe('invalid');
});

/*
| 3. Login gating
*/

test('unverified login is rejected with a consistent 403 code and issues no token', function () {
    $user = evUnverifiedUser();

    $response = $this->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertStatus(403)
        ->assertJsonPath('code', 'email_not_verified')
        ->assertJsonMissingPath('token');
});

test('login works normally once the address is verified', function () {
    $user = User::factory()->create(['role' => 'user']); // factory default: verified

    $this->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'password',
    ])
        ->assertStatus(200)
        ->assertJsonStructure(['message', 'token', 'user']);
});

/*
| 4. Resend endpoint
*/

test('resend accepts valid credentials for an unverified account and sends the notification', function () {
    Notification::fake();
    $user = evUnverifiedUser();

    $this->postJson('/api/email/verification-notification', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertStatus(202);

    Notification::assertSentTo($user, VerifyEmail::class);
});

test('resend answers uniformly and sends nothing for unknown, verified, or wrong-password requests', function () {
    Notification::fake();

    $verified = User::factory()->create(['role' => 'user']);
    $unverified = evUnverifiedUser();

    $ghost = $this->postJson('/api/email/verification-notification', [
        'email' => 'ghost-' . uniqid() . '@gmail.com',
        'password' => 'whatever123',
    ]);

    $alreadyVerified = $this->postJson('/api/email/verification-notification', [
        'email' => $verified->email,
        'password' => 'password',
    ]);

    $wrongPassword = $this->postJson('/api/email/verification-notification', [
        'email' => $unverified->email,
        'password' => 'not-the-password',
    ]);

    // Identical 202 response for every outcome — no account enumeration.
    foreach ([$ghost, $alreadyVerified, $wrongPassword] as $response) {
        $response->assertStatus(202)
            ->assertJson(['message' => 'If an unverified account exists for these credentials, a verification email has been sent.']);
    }

    Notification::assertNothingSent();
});

test('resending never creates or duplicates users', function () {
    Notification::fake();
    $user = evUnverifiedUser();
    $before = User::count();

    $this->postJson('/api/email/verification-notification', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertStatus(202);

    expect(User::count())->toBe($before)
        ->and(User::where('email', $user->email)->count())->toBe(1);
});

test('resend is rate limited (3/min per identity+IP) with 429 + Retry-After', function () {
    Notification::fake();
    $user = evUnverifiedUser();

    foreach (range(1, 3) as $i) {
        $this->postJson('/api/email/verification-notification', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertStatus(202);
    }

    $blocked = $this->postJson('/api/email/verification-notification', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $blocked->assertStatus(429);
    expect($blocked->headers->get('Retry-After'))->not->toBeNull();
});

/*
| 5. Registration throttle (SEC-04)
*/

test('registration is rate limited per IP (3/min) and answers uniformly with 429', function () {
    foreach (range(1, 3) as $i) {
        $this->postJson('/api/register', evRegisterPayload())->assertStatus(201);
    }

    $blocked = $this->postJson('/api/register', evRegisterPayload());

    $blocked->assertStatus(429);
    expect($blocked->headers->get('Retry-After'))->not->toBeNull();
});

/*
| 6. Signed-route plumbing
*/

test('the verification routes exist with their Laravel names', function () {
    $routes = collect(Route::getRoutes()->getRoutes());

    expect($routes->first(fn ($r) => $r->getName() === 'verification.verify'))->not->toBeNull()
        ->and($routes->first(fn ($r) => $r->getName() === 'verification.resend'))->not->toBeNull();
});
