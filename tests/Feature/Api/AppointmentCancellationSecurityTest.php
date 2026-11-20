<?php

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\Patient;
use App\Models\User;
use Carbon\Carbon;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| SEC-08 — completed/paid appointments are protected from cancellation
|--------------------------------------------------------------------------
|
| Approved rules locked in here:
|  - PATIENTS may cancel only pending/arrived AND unpaid appointments —
|    the exact rule the patient UI has always shown (D7), which at a
|    minimum blocks completed, paid, already-cancelled and no-show rows.
|    Rejections are 422 and write nothing, so historical status/payment
|    records are never silently altered.
|  - ADMIN keeps the existing workflow (cancel any row), because
|    AppointmentAuthorizationTest locks "admin cancels any" as the
|    approved business rule and the admin table offers Cancel on every
|    row.
|  - Cancellation runs in one transaction with the row locked; capacity
|    (derived from the active() scope) is only released by a cancellation
|    that actually succeeded — a rejected patient cancel never frees a
|    slot.
|
| Isolated in-memory SQLite (phpunit.xml). No real MySQL data.
|
*/

function s8cUser(): User
{
    return User::factory()->create(['role' => 'user']);
}

function s8cAdmin(): User
{
    return User::factory()->create(['role' => 'admin']);
}

function s8cAppointment(User $owner, array $overrides = []): Appointment
{
    $patient = Patient::factory()->create(['user_id' => $owner->id]);

    return Appointment::factory()->create(array_merge([
        'patient_id' => $patient->id,
        'user_id' => $owner->id,
        'status' => 'pending',
        'payment_status' => 'unpaid',
        'payment_method' => null,
        'paid_at' => null,
    ], $overrides));
}

/*
| 1. Eligible cancellations still work
*/

test('SEC-08: patient can cancel an eligible pending appointment', function () {
    $owner = s8cUser();
    $appointment = s8cAppointment($owner, ['status' => 'pending']);

    Sanctum::actingAs($owner);
    $this->deleteJson("/api/appointments/{$appointment->id}")
        ->assertStatus(200)
        ->assertJson(['message' => 'Appointment cancelled successfully.']);

    expect($appointment->refresh()->status)->toBe('cancelled');
});

test('SEC-08: patient can cancel an eligible arrived appointment', function () {
    $owner = s8cUser();
    $appointment = s8cAppointment($owner, ['status' => 'arrived']);

    Sanctum::actingAs($owner);
    $this->deleteJson("/api/appointments/{$appointment->id}")->assertStatus(200);

    expect($appointment->refresh()->status)->toBe('cancelled');
});

/*
| 2. Patient rejections — completed / paid / cancelled / no_show / in_progress
*/

test('SEC-08: patient cannot cancel a completed appointment', function () {
    $owner = s8cUser();
    $appointment = s8cAppointment($owner, ['status' => 'completed']);

    Sanctum::actingAs($owner);
    $this->deleteJson("/api/appointments/{$appointment->id}")->assertStatus(422);

    expect($appointment->refresh()->status)->toBe('completed');
});

test('SEC-08: patient cannot cancel a paid appointment', function () {
    $owner = s8cUser();
    $appointment = s8cAppointment($owner, [
        'status' => 'pending',
        'payment_status' => 'paid',
        'payment_method' => 'cash',
        'paid_at' => now(),
    ]);

    Sanctum::actingAs($owner);
    $this->deleteJson("/api/appointments/{$appointment->id}")->assertStatus(422);

    $appointment->refresh();
    expect($appointment->status)->toBe('pending')
        ->and($appointment->payment_status)->toBe('paid')
        ->and($appointment->payment_method)->toBe('cash');
});

test('SEC-08: patient cannot cancel a no_show appointment', function () {
    $owner = s8cUser();
    $appointment = s8cAppointment($owner, ['status' => 'no_show']);

    Sanctum::actingAs($owner);
    $this->deleteJson("/api/appointments/{$appointment->id}")->assertStatus(422);

    expect($appointment->refresh()->status)->toBe('no_show');
});

test('SEC-08: patient cannot cancel an already-cancelled appointment again', function () {
    $owner = s8cUser();
    $appointment = s8cAppointment($owner, ['status' => 'cancelled']);

    Sanctum::actingAs($owner);
    $this->deleteJson("/api/appointments/{$appointment->id}")->assertStatus(422);

    expect($appointment->refresh()->status)->toBe('cancelled');
});

test('SEC-08: patient cannot cancel an in-progress appointment (existing D7 rule)', function () {
    $owner = s8cUser();
    $appointment = s8cAppointment($owner, ['status' => 'in_progress']);

    Sanctum::actingAs($owner);
    $this->deleteJson("/api/appointments/{$appointment->id}")->assertStatus(422);

    expect($appointment->refresh()->status)->toBe('in_progress');
});

test('SEC-08: ownership is still checked first — unrelated users get 403', function () {
    $owner = s8cUser();
    $appointment = s8cAppointment($owner, ['status' => 'pending']);

    Sanctum::actingAs(s8cUser());
    $this->deleteJson("/api/appointments/{$appointment->id}")->assertStatus(403);

    expect($appointment->refresh()->status)->toBe('pending');
});

/*
| 3. Admin workflow is preserved (approved rule: admin can cancel any)
*/

test('SEC-08: admin cancellation behaviour remains unchanged', function () {
    $owner = s8cUser();
    $completed = s8cAppointment($owner, ['status' => 'completed']);
    $paid = s8cAppointment($owner, [
        'status' => 'pending',
        'payment_status' => 'paid',
        'payment_method' => 'cash',
        'paid_at' => now(),
    ]);

    Sanctum::actingAs(s8cAdmin());
    $this->deleteJson("/api/appointments/{$completed->id}")->assertStatus(200);
    $this->deleteJson("/api/appointments/{$paid->id}")->assertStatus(200);

    expect($completed->refresh()->status)->toBe('cancelled')
        ->and($paid->refresh()->status)->toBe('cancelled');
});

/*
| 4. Capacity is never incorrectly released by a rejected cancellation
*/

function s8cFullDay(): array
{
    $doctor = Doctor::factory()->create();
    $date = now()->addDays(3)->toDateString();

    $schedule = DoctorSchedule::factory()->create([
        'doctor_id' => $doctor->id,
        'day' => Carbon::parse($date)->format('l'),
        'start_time' => '09:00',
        'end_time' => '15:00',
        'is_available' => true,
        'max_patients' => 1,
    ]);

    return [$doctor, $date, $schedule];
}

test('SEC-08: a rejected patient cancel does not release capacity held by a completed appointment', function () {
    [$doctor, $date] = s8cFullDay();

    $owner = s8cUser();
    $holder = s8cAppointment($owner, [
        'doctor_id' => $doctor->id,
        'appointment_date' => $date,
        'status' => 'completed',
    ]);

    // The blocked cancel must not free the slot.
    Sanctum::actingAs($owner);
    $this->deleteJson("/api/appointments/{$holder->id}")->assertStatus(422);
    expect($holder->refresh()->status)->toBe('completed');

    // The day still counts as full (completed rows hold capacity).
    Sanctum::actingAs(s8cUser());
    $this->postJson('/api/appointments', [
        'doctor_id' => $doctor->id,
        'service_id' => \App\Models\Service::factory()->create()->id,
        'appointment_date' => $date,
    ])->assertStatus(422)
        ->assertJson(['message' => 'This day is fully booked.']);
});

test('SEC-08: a rejected patient cancel does not release capacity held by a paid appointment', function () {
    [$doctor, $date] = s8cFullDay();

    $owner = s8cUser();
    $holder = s8cAppointment($owner, [
        'doctor_id' => $doctor->id,
        'appointment_date' => $date,
        'status' => 'pending',
        'payment_status' => 'paid',
        'payment_method' => 'stripe',
        'paid_at' => now(),
    ]);

    Sanctum::actingAs($owner);
    $this->deleteJson("/api/appointments/{$holder->id}")->assertStatus(422);
    expect($holder->refresh()->status)->toBe('pending');

    Sanctum::actingAs(s8cUser());
    $this->postJson('/api/appointments', [
        'doctor_id' => $doctor->id,
        'service_id' => \App\Models\Service::factory()->create()->id,
        'appointment_date' => $date,
    ])->assertStatus(422)
        ->assertJson(['message' => 'This day is fully booked.']);
});
