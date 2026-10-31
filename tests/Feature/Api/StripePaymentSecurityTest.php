<?php

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\User;
use App\Services\StripeService;
use Laravel\Sanctum\Sanctum;

/*
|---------------------------------------------------------------------------
| Batch 6 — Stripe & payment security regression tests (fully offline)
|---------------------------------------------------------------------------
|
| Webhook events are constructed locally and signed with HMAC-SHA256
| ("t={ts},v1={hmac}") using a test secret injected via
| config('services.stripe.webhook_secret'). No Stripe API calls, no real
| credentials, no network. Checkout tests use a stubbed StripeService so
| no session is ever created externally.
|
| All tests run against the isolated in-memory SQLite test database from
| phpunit.xml. Note: the appointments.payment_method enum CHECK constraint
| (cash/visa/insurance) is enforced by SQLite too, which is exactly why
| the webhook must never write 'card'.
|
*/

const STRIPE_TEST_SECRET = 'whsec_batch6_test_secret';

function stripeConfigureSecret(?string $secret = STRIPE_TEST_SECRET): void
{
    config(['services.stripe.webhook_secret' => $secret]);
}

function stripeAppointment(array $overrides = []): Appointment
{
    $owner = User::factory()->create(['role' => 'user']);
    $patient = Patient::factory()->create(['user_id' => $owner->id]);

    return Appointment::factory()->create(array_merge([
        'patient_id' => $patient->id,
        'user_id' => $owner->id,
        'payment_status' => 'unpaid',
        'payment_method' => null,
        'paid_at' => null,
        'service_price' => 250,
    ], $overrides));
}

function stripeSessionEvent(Appointment $appointment, array $sessionOverrides = []): array
{
    $session = array_merge([
        'object' => 'checkout.session',
        'payment_status' => 'paid',
        'currency' => 'egp',
        'amount_total' => (int) round($appointment->service_price * 100),
        'metadata' => ['appointment_id' => (string) $appointment->id],
    ], $sessionOverrides);

    return [
        'id' => 'evt_batch6_' . uniqid(),
        'object' => 'event',
        'type' => 'checkout.session.completed',
        'data' => ['object' => $session],
    ];
}

function stripeSign(array $event, string $secret = STRIPE_TEST_SECRET): array
{
    $payload = json_encode($event);
    $timestamp = time();
    $signature = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);

    return [$payload, "t={$timestamp},v1={$signature}"];
}

function stripePost($test, string $payload, ?string $header)
{
    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ];

    if ($header !== null) {
        $server['HTTP_STRIPE_SIGNATURE'] = $header;
    }

    return $test->call('POST', '/api/stripe/webhook', [], [], [], $server, $payload);
}

function stripeStubApp(): StripeService
{
    $stub = new class extends StripeService {
        public array $captured = [];

        public function __construct()
        {
            // Offline: no StripeClient is built, no network is touched.
        }

        public function createCheckoutSession(array $data)
        {
            $this->captured[] = $data;

            return (object) ['url' => 'https://checkout.stripe.test/c/session_test'];
        }
    };

    app()->instance(StripeService::class, $stub);

    return $stub;
}

/*
| 1. Signature verification
*/

test('webhook rejects a bad signature with 400 and the appointment stays unpaid', function () {
    stripeConfigureSecret();
    $appointment = stripeAppointment();
    $payload = json_encode(stripeSessionEvent($appointment));

    stripePost($this, $payload, 't=' . time() . ',v1=' . str_repeat('0', 64))
        ->assertStatus(400)
        ->assertJson(['message' => 'Invalid signature.']);

    expect($appointment->refresh()->payment_status)->toBe('unpaid');
});

test('webhook rejects a missing signature header with 400', function () {
    stripeConfigureSecret();
    $appointment = stripeAppointment();

    stripePost($this, json_encode(stripeSessionEvent($appointment)), null)
        ->assertStatus(400)
        ->assertJson(['message' => 'Invalid signature.']);

    expect($appointment->refresh()->payment_status)->toBe('unpaid');
});

test('webhook rejects a signature made with the wrong secret', function () {
    stripeConfigureSecret('whsec_the_configured_secret');
    $appointment = stripeAppointment();
    [$payload, $header] = stripeSign(stripeSessionEvent($appointment), 'whsec_some_other_secret');

    stripePost($this, $payload, $header)
        ->assertStatus(400)
        ->assertJson(['message' => 'Invalid signature.']);

    expect($appointment->refresh()->payment_status)->toBe('unpaid');
});

test('webhook rejects a payload tampered with after signing', function () {
    stripeConfigureSecret();
    $appointment = stripeAppointment();
    $other = stripeAppointment();
    [$payload, $header] = stripeSign(stripeSessionEvent($appointment));

    $tampered = str_replace(
        (string) $appointment->id,
        (string) $other->id,
        $payload
    );

    stripePost($this, $tampered, $header)->assertStatus(400);

    expect($appointment->refresh()->payment_status)->toBe('unpaid')
        ->and($other->refresh()->payment_status)->toBe('unpaid');
});

/*
| 2. Valid confirmation
*/

test('valid signed webhook marks the appointment paid, sets paid_at, and records payment_method stripe', function () {
    stripeConfigureSecret();
    $appointment = stripeAppointment();
    [$payload, $header] = stripeSign(stripeSessionEvent($appointment));

    stripePost($this, $payload, $header)
        ->assertStatus(200)
        ->assertJson(['received' => true]);

    $appointment->refresh();
    // D2: the session was created with payment_method_types => ['card'],
    // so the signature-verified completion is recorded as 'stripe'.
    expect($appointment->payment_status)->toBe('paid')
        ->and($appointment->paid_at)->not->toBeNull()
        ->and($appointment->payment_method)->toBe('stripe');
});

/*
| 3. Amount / currency verification
*/

test('webhook rejects a session whose amount does not match the appointment price', function () {
    stripeConfigureSecret();
    $appointment = stripeAppointment(['service_price' => 250]);
    [$payload, $header] = stripeSign(
        stripeSessionEvent($appointment, ['amount_total' => 100])
    );

    stripePost($this, $payload, $header)
        ->assertStatus(400)
        ->assertJson(['message' => 'Payment amount or currency mismatch.']);

    expect($appointment->refresh()->payment_status)->toBe('unpaid');
});

test('webhook rejects a session whose currency is not egp', function () {
    stripeConfigureSecret();
    $appointment = stripeAppointment();
    [$payload, $header] = stripeSign(
        stripeSessionEvent($appointment, ['currency' => 'usd'])
    );

    stripePost($this, $payload, $header)
        ->assertStatus(400)
        ->assertJson(['message' => 'Payment amount or currency mismatch.']);

    expect($appointment->refresh()->payment_status)->toBe('unpaid');
});

/*
| 4. Unrelated / foreign events
*/

test('webhook acknowledges events whose metadata points at a missing appointment', function () {
    stripeConfigureSecret();
    [$payload, $header] = stripeSign(
        stripeSessionEvent(stripeAppointment(), ['metadata' => ['appointment_id' => '999999']])
    );

    stripePost($this, $payload, $header)
        ->assertStatus(200)
        ->assertJson(['received' => true]);
});

test('other event types are acknowledged and ignored', function () {
    stripeConfigureSecret();
    $appointment = stripeAppointment();
    $event = stripeSessionEvent($appointment);
    $event['type'] = 'payment_intent.succeeded';
    [$payload, $header] = stripeSign($event);

    stripePost($this, $payload, $header)
        ->assertStatus(200)
        ->assertJson(['received' => true]);

    expect($appointment->refresh()->payment_status)->toBe('unpaid');
});

/*
| 5. Duplicate / replayed deliveries (idempotency)
*/

test('duplicate webhook deliveries are acknowledged without rewriting payment state', function () {
    stripeConfigureSecret();
    $appointment = stripeAppointment();
    $event = stripeSessionEvent($appointment);

    [$payload, $header] = stripeSign($event);
    stripePost($this, $payload, $header)->assertStatus(200);

    $firstPaidAt = $appointment->refresh()->getRawOriginal('paid_at');

    $this->travel(5)->hours();

    // A real replay arrives with a fresh signature timestamp.
    [$payload, $header] = stripeSign($event);
    stripePost($this, $payload, $header)
        ->assertStatus(200)
        ->assertJson(['received' => true]);

    expect($appointment->refresh()->getRawOriginal('paid_at'))->toBe($firstPaidAt);
});

test('webhook replay does not overwrite an existing admin cash confirmation', function () {
    stripeConfigureSecret();
    $appointment = stripeAppointment([
        'payment_status' => 'paid',
        'payment_method' => 'cash',
        'paid_at' => now(),
    ]);
    $originalPaidAt = $appointment->refresh()->getRawOriginal('paid_at');

    [$payload, $header] = stripeSign(stripeSessionEvent($appointment));
    stripePost($this, $payload, $header)
        ->assertStatus(200)
        ->assertJson(['received' => true]);

    $appointment->refresh();
    expect($appointment->payment_method)->toBe('cash')
        ->and($appointment->getRawOriginal('paid_at'))->toBe($originalPaidAt);
});

test('sessions Stripe reports as not paid are acknowledged but never confirmed', function () {
    stripeConfigureSecret();
    $appointment = stripeAppointment();
    [$payload, $header] = stripeSign(
        stripeSessionEvent($appointment, ['payment_status' => 'unpaid'])
    );

    stripePost($this, $payload, $header)
        ->assertStatus(200)
        ->assertJson(['received' => true]);

    expect($appointment->refresh()->payment_status)->toBe('unpaid');
});

/*
| 6. Configuration
*/

test('a missing webhook secret fails loudly with 500 instead of silently rejecting everything', function () {
    stripeConfigureSecret(null);
    $appointment = stripeAppointment();
    [$payload, $header] = stripeSign(stripeSessionEvent($appointment), 'whsec_anything');

    stripePost($this, $payload, $header)
        ->assertStatus(500)
        ->assertJson(['message' => 'Webhook secret is not configured.']);

    expect($appointment->refresh()->payment_status)->toBe('unpaid');
});

/*
| 7. Checkout trust boundary (stubbed StripeService, offline)
*/

test('checkout requires authentication and ownership', function () {
    stripeStubApp();
    $appointment = stripeAppointment();

    $this->postJson('/api/payments/checkout', ['appointment_id' => $appointment->id])
        ->assertStatus(401);

    Sanctum::actingAs(User::factory()->create(['role' => 'user']));
    $this->postJson('/api/payments/checkout', ['appointment_id' => $appointment->id])
        ->assertStatus(403);
});

test('owner checkout charges the DB price in piasters and tags the appointment id', function () {
    config(['services.stripe.frontend_url' => 'https://clinic.test']);
    $stub = stripeStubApp();
    $owner = User::factory()->create(['role' => 'user']);
    $patient = Patient::factory()->create(['user_id' => $owner->id]);
    $appointment = Appointment::factory()->create([
        'patient_id' => $patient->id,
        'user_id' => $owner->id,
        'payment_status' => 'unpaid',
        'service_price' => 250,
    ]);

    Sanctum::actingAs($owner);
    $this->postJson('/api/payments/checkout', ['appointment_id' => $appointment->id])
        ->assertStatus(200)
        ->assertJsonStructure(['message', 'checkout_url'])
        ->assertJsonPath('checkout_url', 'https://checkout.stripe.test/c/session_test');

    expect($stub->captured)->toHaveCount(1);

    $captured = $stub->captured[0];
    expect($captured['amount'])->toBe(25000)
        ->and($captured['appointment_id'])->toEqual($appointment->id)
        ->and($captured['success_url'])->toBe('https://clinic.test/payment/success')
        ->and($captured['cancel_url'])->toBe('https://clinic.test/payment/cancel');
});

test('checkout refuses already-paid appointments without contacting Stripe', function () {
    $stub = stripeStubApp();
    $appointment = stripeAppointment([
        'payment_status' => 'paid',
        'paid_at' => now(),
    ]);

    Sanctum::actingAs(User::find($appointment->user_id));
    $this->postJson('/api/payments/checkout', ['appointment_id' => $appointment->id])
        ->assertStatus(400);

    expect($stub->captured)->toHaveCount(0);
});

/*
| 8. Rate limiting (batch 8)
*/

test('checkout is rate limited per user (429 after the limit)', function () {
    config(['services.stripe.frontend_url' => 'https://clinic.test']);
    $stub = stripeStubApp();
    $owner = User::factory()->create(['role' => 'user']);
    $patient = Patient::factory()->create(['user_id' => $owner->id]);
    $appointment = Appointment::factory()->create([
        'patient_id' => $patient->id,
        'user_id' => $owner->id,
        'payment_status' => 'unpaid',
        'service_price' => 250,
    ]);

    Sanctum::actingAs($owner);

    foreach (range(1, 20) as $i) {
        $this->postJson('/api/payments/checkout', ['appointment_id' => $appointment->id])
            ->assertStatus(200);
    }

    $this->postJson('/api/payments/checkout', ['appointment_id' => $appointment->id])
        ->assertStatus(429);
});

test('the webhook route is throttled as defense in depth behind signature verification', function () {
    $route = collect(\Illuminate\Support\Facades\Route::getRoutes())
        ->first(fn ($r) => $r->uri() === 'api/stripe/webhook');

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain('throttle:60,1');
});
