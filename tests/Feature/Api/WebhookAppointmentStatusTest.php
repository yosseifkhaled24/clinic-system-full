<?php

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| SEC-09 — webhook independent appointment-status validation
|--------------------------------------------------------------------------
|
| The checkout-time eligibility gate (PAYABLE_STATUSES, Task 3) is NOT
| duplicated here — these tests lock in the NEW behaviour: the webhook
| re-validates the appointment's CURRENT status under a row lock, so a
| valid Stripe signature alone can never authorize payment for an
| appointment that became un-payable after checkout was created.
|
| Late deliveries (cancelled / no_show after checkout) record the money
| that Stripe genuinely collected, NEVER touch appointment status, flag
| the row via needs_investigation and emit a structured warning log —
| they are neither discarded nor silently treated as a normal payment.
|
| Events are constructed locally and signed with HMAC-SHA256 using a test
| secret injected via config('services.stripe.webhook_secret'). No Stripe
| API calls, no credentials, no network. All tests run against the
| isolated in-memory SQLite database from phpunit.xml.
|
| Unique prefix: s9w / S9W_TEST_SECRET (no clashes with the stripe or
| pay helper prefixes in the other payment test files).
|
*/

const S9W_TEST_SECRET = 'whsec_s9w_test_secret';

function s9wConfigureSecret(): void
{
    config(['services.stripe.webhook_secret' => S9W_TEST_SECRET]);
}

function s9wAppointment(array $overrides = []): Appointment
{
    $owner = User::factory()->create(['role' => 'user']);
    $patient = Patient::factory()->create(['user_id' => $owner->id]);

    return Appointment::factory()->create(array_merge([
        'patient_id' => $patient->id,
        'user_id' => $owner->id,
        'status' => 'pending',
        'payment_status' => 'unpaid',
        'payment_method' => null,
        'paid_at' => null,
        'service_price' => 250,
        'needs_investigation' => false,
    ], $overrides));
}

function s9wSessionEvent(Appointment $appointment, array $sessionOverrides = []): array
{
    $session = array_merge([
        'object' => 'checkout.session',
        'payment_status' => 'paid',
        'currency' => 'egp',
        'amount_total' => (int) round($appointment->service_price * 100),
        'metadata' => ['appointment_id' => (string) $appointment->id],
    ], $sessionOverrides);

    return [
        'id' => 'evt_s9w_' . uniqid(),
        'object' => 'event',
        'type' => 'checkout.session.completed',
        'data' => ['object' => $session],
    ];
}

function s9wSign(array $event): array
{
    $payload = json_encode($event);
    $timestamp = time();
    $signature = hash_hmac('sha256', $timestamp . '.' . $payload, S9W_TEST_SECRET);

    return [$payload, "t={$timestamp},v1={$signature}"];
}

function s9wPost($test, string $payload, string $header)
{
    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $header,
    ];

    return $test->call('POST', '/api/stripe/webhook', [], [], [], $server, $payload);
}

/*
| 1. Payable appointment — normal, status-preserving payment record
*/

test('SEC-09: valid webhook for a payable appointment records the payment and never touches status', function () {
    s9wConfigureSecret();
    $appointment = s9wAppointment(['status' => 'pending']);

    [$payload, $signature] = s9wSign(s9wSessionEvent($appointment));
    s9wPost($this, $payload, $signature)
        ->assertStatus(200)
        ->assertJson(['received' => true]);

    $appointment->refresh();
    expect($appointment->payment_status)->toBe('paid')
        ->and($appointment->payment_method)->toBe('stripe')
        ->and($appointment->paid_at)->not->toBeNull()
        ->and($appointment->status)->toBe('pending')
        ->and($appointment->needs_investigation)->toBeFalse();
});

/*
| 2. Late deliveries — status is NEVER changed, money is recorded truthfully
*/

test('SEC-09: late webhook for a cancelled appointment records the payment, flags it, and leaves status untouched', function () {
    s9wConfigureSecret();
    $appointment = s9wAppointment(['status' => 'cancelled']);

    [$payload, $signature] = s9wSign(s9wSessionEvent($appointment));
    s9wPost($this, $payload, $signature)
        ->assertStatus(200)
        ->assertJson(['received' => true]);

    $appointment->refresh();
    expect($appointment->status)->toBe('cancelled')
        ->and($appointment->payment_status)->toBe('paid')
        ->and($appointment->payment_method)->toBe('stripe')
        ->and($appointment->paid_at)->not->toBeNull()
        ->and($appointment->needs_investigation)->toBeTrue();
});

test('SEC-09: late webhook for a no_show appointment records the payment, flags it, and leaves status untouched', function () {
    s9wConfigureSecret();
    $appointment = s9wAppointment(['status' => 'no_show']);

    [$payload, $signature] = s9wSign(s9wSessionEvent($appointment));
    s9wPost($this, $payload, $signature)->assertStatus(200);

    $appointment->refresh();
    expect($appointment->status)->toBe('no_show')
        ->and($appointment->payment_status)->toBe('paid')
        ->and($appointment->needs_investigation)->toBeTrue();
});

test('SEC-09: late webhook for a completed appointment settles the payment and clears the investigation flag', function () {
    s9wConfigureSecret();
    // Simulates a row the end-of-day cleanup flagged as completed+unpaid
    // before this (earlier-created) checkout session settled.
    $appointment = s9wAppointment([
        'status' => 'completed',
        'needs_investigation' => true,
    ]);

    [$payload, $signature] = s9wSign(s9wSessionEvent($appointment));
    s9wPost($this, $payload, $signature)->assertStatus(200);

    $appointment->refresh();
    expect($appointment->status)->toBe('completed')
        ->and($appointment->payment_status)->toBe('paid')
        ->and($appointment->payment_method)->toBe('stripe')
        ->and($appointment->needs_investigation)->toBeFalse();
});

test('SEC-09: a late-webhook investigation outcome is logged without secrets', function () {
    s9wConfigureSecret();
    $appointment = s9wAppointment(['status' => 'cancelled']);

    Log::spy();

    [$payload, $signature] = s9wSign(s9wSessionEvent($appointment));
    s9wPost($this, $payload, $signature)->assertStatus(200);

    Log::shouldHaveReceived('warning')->with(
        Mockery::type('string'),
        Mockery::on(fn (array $context) => ($context['appointment_id'] ?? null) === $appointment->id
            && ($context['appointment_status'] ?? null) === 'cancelled'
            && ! array_key_exists('signature', $context)
            && ! array_key_exists('secret', $context))
    );
});

/*
| 3. Idempotency still holds for late deliveries
*/

test('SEC-09: duplicate late-webhook delivery is acknowledged without rewriting payment state', function () {
    s9wConfigureSecret();
    $appointment = s9wAppointment(['status' => 'cancelled']);

    [$payload, $signature] = s9wSign(s9wSessionEvent($appointment));
    s9wPost($this, $payload, $signature)->assertStatus(200);

    $paidAt = $appointment->refresh()->paid_at->toDateTimeString();

    // A replay arrives with a fresh event id and a fresh signature.
    [$replayPayload, $replaySignature] = s9wSign(s9wSessionEvent($appointment));
    s9wPost($this, $replayPayload, $replaySignature)
        ->assertStatus(200)
        ->assertJson(['received' => true]);

    $appointment->refresh();
    expect($appointment->paid_at->toDateTimeString())->toBe($paidAt)
        ->and($appointment->payment_status)->toBe('paid')
        ->and($appointment->status)->toBe('cancelled')
        ->and($appointment->needs_investigation)->toBeTrue();
});

/*
| 4. Pre-existing protections re-asserted (Task 3 — not weakened)
*/

test('SEC-09: invalid signature is still rejected and no status/payment write happens', function () {
    s9wConfigureSecret();
    $appointment = s9wAppointment(['status' => 'cancelled']);

    $payload = json_encode(s9wSessionEvent($appointment));
    s9wPost($this, $payload, 't=' . time() . ',v1=' . str_repeat('0', 64))
        ->assertStatus(400)
        ->assertJson(['message' => 'Invalid signature.']);

    $appointment->refresh();
    expect($appointment->payment_status)->toBe('unpaid')
        ->and($appointment->status)->toBe('cancelled')
        ->and($appointment->needs_investigation)->toBeFalse();
});

test('SEC-09: amount mismatch is still rejected with 400 even for a no-longer-payable appointment', function () {
    s9wConfigureSecret();
    $appointment = s9wAppointment(['status' => 'cancelled']);

    [$payload, $signature] = s9wSign(s9wSessionEvent($appointment, [
        'amount_total' => 100,
    ]));
    s9wPost($this, $payload, $signature)
        ->assertStatus(400)
        ->assertJson(['message' => 'Payment amount or currency mismatch.']);

    $appointment->refresh();
    expect($appointment->payment_status)->toBe('unpaid')
        ->and($appointment->needs_investigation)->toBeFalse();
});
