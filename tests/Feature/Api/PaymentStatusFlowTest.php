<?php

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\User;
use App\Services\StripeService;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| SEC-06 — Checkout eligibility & real payment status (fully offline)
|--------------------------------------------------------------------------
|
| The existing StripePaymentSecurityTest covers the webhook trust
| boundary (signatures, amounts, idempotency). This file covers the
| SEC-06 additions:
|
|  - checkout eligibility: only pending / arrived / in_progress may
|    enter checkout; cancelled, no_show and completed are rejected
|    server-side;
|  - checkout fails safely with 503 when STRIPE_WEBHOOK_SECRET is
|    missing ("online payments are not configured");
|  - the new read-only GET /api/payments/status endpoint returns
|    confirmed / pending / failed from the REAL Stripe session state,
|    ownership-checked, and NEVER marks an appointment as paid (a
|    browser redirect alone can never confirm payment; delayed
|    webhooks surface as pending).
|
| StripeService is stubbed (no StripeClient, no network); the webhook
| secret is injected via config. Everything runs on the in-memory
| SQLite test database.
*/

const PAY_TEST_SECRET = 'whsec_pay_flow_test_secret';

function payConfigure(?string $secret = PAY_TEST_SECRET): void
{
    config([
        'services.stripe.webhook_secret' => $secret,
        'services.stripe.frontend_url' => 'https://clinic.test',
    ]);
}

function payAppointment(array $overrides = []): Appointment
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
    ], $overrides));
}

/**
 * Offline StripeService stub: captures checkout calls and serves canned
 * session objects for getCheckoutSession(); unknown session ids throw
 * InvalidRequestException exactly like the real SDK.
 */
function payStub(array $sessionMap = []): StripeService
{
    $stub = new class($sessionMap) extends StripeService {
        public array $captured = [];

        public function __construct(private array $sessionMap = [])
        {
            // Offline: no StripeClient is built, no network is touched.
        }

        public function createCheckoutSession(array $data)
        {
            $this->captured[] = $data;

            return (object) ['url' => 'https://checkout.stripe.test/c/pay_session'];
        }

        public function getCheckoutSession(string $sessionId)
        {
            if (! isset($this->sessionMap[$sessionId])) {
                throw new \Stripe\Exception\InvalidRequestException(
                    'No such checkout session: ' . $sessionId
                );
            }

            return (object) $this->sessionMap[$sessionId];
        }
    };

    app()->instance(StripeService::class, $stub);

    return $stub;
}

function paySessionFor(Appointment $appointment, array $overrides = []): array
{
    return array_merge([
        'id' => 'cs_pay_' . uniqid(),
        'payment_status' => 'unpaid',
        'status' => 'open',
        'currency' => 'egp',
        'amount_total' => (int) round($appointment->service_price * 100),
        'metadata' => (object) ['appointment_id' => (string) $appointment->id],
    ], $overrides);
}

/*
| 1. Checkout eligibility (approved payable statuses only)
*/

test('checkout is rejected for cancelled appointments', function () {
    payConfigure();
    $stub = payStub();
    $appointment = payAppointment(['status' => 'cancelled']);

    Sanctum::actingAs(User::find($appointment->user_id));

    $this->postJson('/api/payments/checkout', ['appointment_id' => $appointment->id])
        ->assertStatus(422)
        ->assertJsonPath('code', 'appointment_not_payable');

    expect($stub->captured)->toHaveCount(0);
});

test('checkout is rejected for no_show appointments', function () {
    payConfigure();
    $stub = payStub();
    $appointment = payAppointment(['status' => 'no_show']);

    Sanctum::actingAs(User::find($appointment->user_id));

    $this->postJson('/api/payments/checkout', ['appointment_id' => $appointment->id])
        ->assertStatus(422)
        ->assertJsonPath('code', 'appointment_not_payable');

    expect($stub->captured)->toHaveCount(0);
});

test('checkout is rejected for completed appointments (settled via the admin cash flow)', function () {
    payConfigure();
    $stub = payStub();
    $appointment = payAppointment(['status' => 'completed']);

    Sanctum::actingAs(User::find($appointment->user_id));

    $this->postJson('/api/payments/checkout', ['appointment_id' => $appointment->id])
        ->assertStatus(422)
        ->assertJsonPath('code', 'appointment_not_payable');

    expect($stub->captured)->toHaveCount(0);
});

test('checkout is allowed for the approved payable statuses (pending, arrived, in_progress)', function () {
    payConfigure();
    $stub = payStub();

    foreach (['pending', 'arrived', 'in_progress'] as $status) {
        $appointment = payAppointment(['status' => $status]);

        Sanctum::actingAs(User::find($appointment->user_id));

        $this->postJson('/api/payments/checkout', ['appointment_id' => $appointment->id])
            ->assertStatus(200)
            ->assertJsonStructure(['message', 'checkout_url']);
    }

    expect($stub->captured)->toHaveCount(3);
});

test('checkout still refuses other users appointments (ownership server-side)', function () {
    payConfigure();
    payStub();
    $appointment = payAppointment(['status' => 'pending']);

    Sanctum::actingAs(User::factory()->create(['role' => 'user']));

    $this->postJson('/api/payments/checkout', ['appointment_id' => $appointment->id])
        ->assertStatus(403);
});

/*
| 2. Missing webhook secret — fail safely
*/

test('checkout fails safely with 503 when the webhook secret is not configured', function () {
    payConfigure(null);
    $stub = payStub();
    $appointment = payAppointment(['status' => 'pending']);

    Sanctum::actingAs(User::find($appointment->user_id));

    $this->postJson('/api/payments/checkout', ['appointment_id' => $appointment->id])
        ->assertStatus(503)
        ->assertJsonPath('code', 'payments_not_configured');

    // No session was created: money can never be taken without a
    // confirmation path.
    expect($stub->captured)->toHaveCount(0);
});

/*
| 3. GET /api/payments/status — the success page's source of truth
*/

test('payment status requires authentication', function () {
    payConfigure();
    payStub();

    $this->getJson('/api/payments/status?session_id=cs_test_123')->assertStatus(401);
});

test('payment status requires a session_id', function () {
    payConfigure();
    payStub();

    Sanctum::actingAs(User::factory()->create(['role' => 'user']));

    $this->getJson('/api/payments/status')->assertStatus(422);
});

test('payment status is ownership-checked: another patients session returns 403', function () {
    payConfigure();
    $appointment = payAppointment(['status' => 'pending']);
    $session = paySessionFor($appointment);
    payStub([$session['id'] => $session]);

    Sanctum::actingAs(User::factory()->create(['role' => 'user']));

    $this->getJson('/api/payments/status?session_id=' . $session['id'])
        ->assertStatus(403);
});

test('payment status is confirmed once the appointment is recorded as paid', function () {
    payConfigure();
    $appointment = payAppointment([
        'status' => 'pending',
        'payment_status' => 'paid',
        'payment_method' => 'stripe',
        'paid_at' => now(),
    ]);
    $session = paySessionFor($appointment, ['payment_status' => 'paid', 'status' => 'complete']);
    payStub([$session['id'] => $session]);

    Sanctum::actingAs(User::find($appointment->user_id));

    $this->getJson('/api/payments/status?session_id=' . $session['id'])
        ->assertStatus(200)
        ->assertJsonPath('status', 'confirmed')
        ->assertJsonPath('appointment_id', $appointment->id);
});

test('a delayed webhook is reported as pending and the appointment is never marked paid by the status check', function () {
    payConfigure();
    $appointment = payAppointment(['status' => 'pending']);
    // Stripe already took the money, but the signed webhook has not
    // landed yet (delayed delivery).
    $session = paySessionFor($appointment, ['payment_status' => 'paid', 'status' => 'complete']);
    payStub([$session['id'] => $session]);

    Sanctum::actingAs(User::find($appointment->user_id));

    $this->getJson('/api/payments/status?session_id=' . $session['id'])
        ->assertStatus(200)
        ->assertJsonPath('status', 'pending');

    // Read-only: only the signed webhook (or the admin flow) may confirm.
    expect($appointment->refresh()->payment_status)->toBe('unpaid');
});

test('an open (unpaid) session is reported as pending — never as successful', function () {
    payConfigure();
    $appointment = payAppointment(['status' => 'pending']);
    $session = paySessionFor($appointment, ['payment_status' => 'unpaid', 'status' => 'open']);
    payStub([$session['id'] => $session]);

    Sanctum::actingAs(User::find($appointment->user_id));

    $this->getJson('/api/payments/status?session_id=' . $session['id'])
        ->assertStatus(200)
        ->assertJsonPath('status', 'pending');

    expect($appointment->refresh()->payment_status)->toBe('unpaid');
});

test('an expired session is reported as failed', function () {
    payConfigure();
    $appointment = payAppointment(['status' => 'pending']);
    $session = paySessionFor($appointment, ['payment_status' => 'unpaid', 'status' => 'expired']);
    payStub([$session['id'] => $session]);

    Sanctum::actingAs(User::find($appointment->user_id));

    $this->getJson('/api/payments/status?session_id=' . $session['id'])
        ->assertStatus(200)
        ->assertJsonPath('status', 'failed');
});

test('an unknown session id returns 404 without leaking Stripe details', function () {
    payConfigure();
    payStub([]);

    Sanctum::actingAs(User::factory()->create(['role' => 'user']));

    $response = $this->getJson('/api/payments/status?session_id=cs_does_not_exist');

    $response->assertStatus(404)
        ->assertJsonPath('message', 'Payment session not found.');
    expect($response->getContent())->not->toContain('Stripe');
});

test('a Stripe connectivity failure answers 503, not a crash', function () {
    payConfigure();
    $stub = new class extends StripeService {
        public function __construct()
        {
        }

        public function getCheckoutSession(string $sessionId)
        {
            throw new \RuntimeException('Connection timed out (simulated)');
        }
    };
    app()->instance(StripeService::class, $stub);

    $user = User::factory()->create(['role' => 'user']);
    Sanctum::actingAs($user);

    $this->getJson('/api/payments/status?session_id=cs_any')
        ->assertStatus(503)
        ->assertJsonPath('message', 'Unable to verify the payment status right now. Please try again shortly.');
});

test('payment status fails safely with 503 when the webhook secret is not configured', function () {
    payConfigure(null);
    payStub([]);

    Sanctum::actingAs(User::factory()->create(['role' => 'user']));

    $this->getJson('/api/payments/status?session_id=cs_any')
        ->assertStatus(503)
        ->assertJsonPath('code', 'payments_not_configured');
});

test('admins may check the status of any session', function () {
    payConfigure();
    $appointment = payAppointment(['status' => 'pending']);
    $session = paySessionFor($appointment, ['payment_status' => 'unpaid', 'status' => 'open']);
    payStub([$session['id'] => $session]);

    Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

    $this->getJson('/api/payments/status?session_id=' . $session['id'])
        ->assertStatus(200)
        ->assertJsonPath('status', 'pending');
});
