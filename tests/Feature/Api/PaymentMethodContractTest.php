<?php

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/*
 |---------------------------------------------------------------------------
 | Payment method contract — approved decision D2
 |---------------------------------------------------------------------------
 |
 | PATCH /api/appointments/{id}/payment is admin-only and previously
 | accepted `stripe` / `instapay` / `vodafone_cash` in validation while the
 | DB enum only knew `cash` / `visa` / `insurance`, so those requests died
 | as an uncaught 500 (SQLSTATE 23000) instead of a controlled response.
 | Conversely `insurance` was a valid DB value the validation rejected.
 |
 | This suite pins the FULL approved method list
 | (cash, visa, insurance, stripe, instapay, vodafone_cash) as 200 + a
 | persisted row, and pins unsupported values as a controlled 422 with no
 | write. It is expected RED before the D2 migration/validation alignment
 | and GREEN after.
 |
 | The helper names carry the `pm` prefix to avoid Pest's global function
 | namespace colliding with the other batch test files.
 */

function pmAdmin(): User
{
    return User::factory()->create(['role' => 'admin']);
}

function pmUnpaidAppointment(): Appointment
{
    $owner = User::factory()->create(['role' => 'user']);
    $patient = Patient::factory()->create([
        'user_id' => $owner->id,
        'phone' => '012' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
    ]);

    return Appointment::factory()->create([
        'patient_id' => $patient->id,
        'user_id' => $owner->id,
        'status' => 'pending',
        'payment_status' => 'unpaid',
        'payment_method' => null,
        'paid_at' => null,
    ]);
}

test('every approved payment method validates and persists (D2)', function (string $method) {
    $appointment = pmUnpaidAppointment();

    Sanctum::actingAs(pmAdmin());

    $this->patchJson("/api/appointments/{$appointment->id}/payment", [
        'payment_method' => $method,
    ])
        ->assertStatus(200)
        ->assertJsonPath('data.payment.method', $method);

    $appointment->refresh();

    expect($appointment->payment_method)->toEqual($method)
        ->and($appointment->payment_status)->toBe('paid')
        ->and($appointment->paid_at)->not->toBeNull();
})->with([
    'cash' => 'cash',
    'visa' => 'visa',
    'insurance' => 'insurance',
    'stripe' => 'stripe',
    'instapay' => 'instapay',
    'vodafone_cash' => 'vodafone_cash',
]);

test('an unsupported payment method fails validation with 422 and writes nothing', function (string $method) {
    $appointment = pmUnpaidAppointment();

    Sanctum::actingAs(pmAdmin());

    $this->patchJson("/api/appointments/{$appointment->id}/payment", [
        'payment_method' => $method,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['payment_method']);

    $appointment->refresh();

    expect($appointment->payment_method)->toBeNull()
        ->and($appointment->payment_status)->toBe('unpaid')
        ->and($appointment->paid_at)->toBeNull();
})->with([
    'card' => 'card',
    'mastercard' => 'mastercard',
    'cheque' => 'cheque',
    'empty string' => '',
    'sql injection attempt' => "cash'); DROP TABLE appointments; --",
]);

test('a missing payment_method is rejected with 422', function () {
    $appointment = pmUnpaidAppointment();

    Sanctum::actingAs(pmAdmin());

    $this->patchJson("/api/appointments/{$appointment->id}/payment", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['payment_method']);

    expect($appointment->refresh()->payment_status)->toBe('unpaid');
});

test('the payment endpoint stays admin-only (guest 401, non-admin 403)', function () {
    $appointment = pmUnpaidAppointment();

    $this->patchJson("/api/appointments/{$appointment->id}/payment", [
        'payment_method' => 'cash',
    ])->assertStatus(401);

    Sanctum::actingAs(User::factory()->create(['role' => 'user']));

    $this->patchJson("/api/appointments/{$appointment->id}/payment", [
        'payment_method' => 'cash',
    ])->assertStatus(403);

    expect($appointment->refresh()->payment_status)->toBe('unpaid');
});

test('an already-paid appointment is not re-paid through the payment endpoint', function () {
    $appointment = pmUnpaidAppointment();
    $appointment->update([
        'payment_status' => 'paid',
        'payment_method' => 'cash',
        'paid_at' => now(),
    ]);

    Sanctum::actingAs(pmAdmin());

    $this->patchJson("/api/appointments/{$appointment->id}/payment", [
        'payment_method' => 'visa',
    ])
        ->assertStatus(422)
        ->assertJson(['message' => 'Appointment already paid.']);

    expect($appointment->refresh()->payment_method)->toBe('cash');
});
