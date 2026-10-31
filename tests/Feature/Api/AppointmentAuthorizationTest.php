<?php

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;
use Laravel\Sanctum\Sanctum;

/*
|---------------------------------------------------------------------------
| Backend authorization batch 5 — appointment ownership, status & payment
|---------------------------------------------------------------------------
|
| Covers: index scoping, show/update/destroy ownership (regression lock),
| admin-only PATCH .../status and PATCH .../payment, payment forging via
| the generic update, non-admin status via the generic update, and
| user_id/patient_id reassignment attempts on create and update.
|
| Actors are always created with an EXPLICIT role because UserFactory
| randomizes 'admin' (~25%). All tests run against the isolated in-memory
| SQLite test database from phpunit.xml. No production data, no schema
| changes, no external services.
|
*/

function apptAdmin(): User
{
    return User::factory()->create(['role' => 'admin']);
}

function apptUser(): User
{
    return User::factory()->create(['role' => 'user']);
}

function apptOwnAppointment(User $owner, array $overrides = []): Appointment
{
    $patient = Patient::factory()->create(['user_id' => $owner->id]);

    return Appointment::factory()->create(array_merge([
        'patient_id' => $patient->id,
        'user_id' => $owner->id,
    ], $overrides));
}

function apptBookingFixtures(): array
{
    $doctor = Doctor::factory()->create();
    $service = Service::factory()->create();
    $date = now()->addDays(3)->toDateString();

    DoctorSchedule::factory()->create([
        'doctor_id' => $doctor->id,
        'day' => Carbon::parse($date)->format('l'),
        'start_time' => '09:00',
        'end_time' => '15:00',
        'is_available' => true,
        'max_patients' => 30,
    ]);

    return [$doctor, $service, $date];
}

/*
| 1. Guests
*/

test('guest gets 401 on every appointment endpoint', function () {
    $appointment = Appointment::factory()->create();

    $this->postJson('/api/appointments', [])->assertStatus(401);
    $this->getJson('/api/appointments')->assertStatus(401);
    $this->getJson("/api/appointments/{$appointment->id}")->assertStatus(401);
    $this->patchJson("/api/appointments/{$appointment->id}", ['notes' => 'x'])->assertStatus(401);
    $this->patchJson("/api/appointments/{$appointment->id}/status", ['status' => 'cancelled'])->assertStatus(401);
    $this->patchJson("/api/appointments/{$appointment->id}/payment", ['payment_method' => 'cash'])->assertStatus(401);
    $this->deleteJson("/api/appointments/{$appointment->id}")->assertStatus(401);
});

/*
| 2. Index scoping & show ownership
*/

test('patient index contains only their own appointments; admin sees all', function () {
    $owner = apptUser();
    $mine = apptOwnAppointment($owner);
    $victim = apptUser();
    apptOwnAppointment($victim);

    Sanctum::actingAs($owner);
    $this->getJson('/api/appointments')
        ->assertStatus(200)
        ->assertJsonStructure(['*' => ['id', 'status', 'patient']])
        ->assertJsonCount(1)
        ->assertJsonPath('0.id', $mine->id);

    Sanctum::actingAs(apptAdmin());
    $this->getJson('/api/appointments')
        ->assertStatus(200)
        ->assertJsonCount(2);
});

test('appointment show: owner 200 with shape, unrelated 403, admin 200', function () {
    $owner = apptUser();
    $appointment = apptOwnAppointment($owner);

    Sanctum::actingAs($owner);
    $this->getJson("/api/appointments/{$appointment->id}")
        ->assertStatus(200)
        ->assertJsonStructure([
            'id',
            'doctor' => ['id', 'name', 'specialty'],
            'service' => ['id', 'name', 'price'],
            'payment' => ['status', 'method', 'paid_at'],
            'patient' => ['id', 'name', 'phone', 'email'],
            'queue_number', 'appointment_date', 'status', 'notes',
            'created_at', 'updated_at',
        ]);

    Sanctum::actingAs(apptUser());
    $this->getJson("/api/appointments/{$appointment->id}")->assertStatus(403);

    Sanctum::actingAs(apptAdmin());
    $this->getJson("/api/appointments/{$appointment->id}")->assertStatus(200);
});

/*
| 3. Update ownership & shape
*/

test('appointment update: owner edits notes, unrelated 403 (unchanged), admin can edit', function () {
    $owner = apptUser();
    $appointment = apptOwnAppointment($owner);

    Sanctum::actingAs($owner);
    $this->patchJson("/api/appointments/{$appointment->id}", ['notes' => 'Owner note'])
        ->assertStatus(200)
        ->assertJsonStructure(['id', 'status', 'notes'])
        ->assertJsonPath('notes', 'Owner note');

    Sanctum::actingAs(apptUser());
    $this->patchJson("/api/appointments/{$appointment->id}", ['notes' => 'Intruder'])
        ->assertStatus(403);
    expect($appointment->refresh()->notes)->toBe('Owner note');

    Sanctum::actingAs(apptAdmin());
    $this->patchJson("/api/appointments/{$appointment->id}", ['notes' => 'Admin note'])
        ->assertStatus(200)
        ->assertJsonPath('notes', 'Admin note');
});

test('update ignores client-supplied user_id and patient_id (no owner reassignment)', function () {
    $owner = apptUser();
    $appointment = apptOwnAppointment($owner);
    $originalPatientId = $appointment->patient_id;
    $victim = apptUser();
    $victimPatient = Patient::factory()->create(['user_id' => $victim->id]);

    Sanctum::actingAs($owner);
    $this->patchJson("/api/appointments/{$appointment->id}", [
        'user_id' => $victim->id,
        'patient_id' => $victimPatient->id,
        'notes' => 'still mine',
    ])->assertStatus(200);

    $appointment->refresh();
    expect($appointment->user_id)->toEqual($owner->id)
        ->and($appointment->patient_id)->toEqual($originalPatientId)
        ->and($appointment->notes)->toBe('still mine');
});

/*
| 4. Payment forging via the generic update (fields ignored for everyone)
*/

test('owner cannot forge payment through the generic update (fields ignored)', function () {
    $owner = apptUser();
    $appointment = apptOwnAppointment($owner, [
        'payment_status' => 'unpaid',
        'payment_method' => null,
        'paid_at' => null,
    ]);

    Sanctum::actingAs($owner);
    $this->patchJson("/api/appointments/{$appointment->id}", [
        'payment_status' => 'paid',
        'payment_method' => 'visa',
        'paid_at' => now()->toDateTimeString(),
    ])->assertStatus(200);

    $appointment->refresh();
    expect($appointment->payment_status)->toBe('unpaid')
        ->and($appointment->payment_method)->toBeNull()
        ->and($appointment->paid_at)->toBeNull();
});

test('payment fields sent by an admin through the generic update are ignored too (dedicated endpoint only)', function () {
    $appointment = apptOwnAppointment(apptUser(), [
        'payment_status' => 'unpaid',
        'payment_method' => null,
        'paid_at' => null,
    ]);

    Sanctum::actingAs(apptAdmin());
    $this->patchJson("/api/appointments/{$appointment->id}", [
        'payment_status' => 'paid',
        'payment_method' => 'cash',
    ])->assertStatus(200);

    $appointment->refresh();
    expect($appointment->payment_status)->toBe('unpaid')
        ->and($appointment->payment_method)->toBeNull();
});

/*
| 5. Status through the generic update
*/

test('non-admin cannot set status through the generic update (422, unchanged)', function () {
    $owner = apptUser();
    $appointment = apptOwnAppointment($owner, ['status' => 'pending']);

    Sanctum::actingAs($owner);
    $this->patchJson("/api/appointments/{$appointment->id}", ['status' => 'completed'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);

    expect($appointment->refresh()->status)->toBe('pending');
});

test('admin can still set status through the generic update', function () {
    $appointment = apptOwnAppointment(apptUser(), ['status' => 'pending']);

    Sanctum::actingAs(apptAdmin());
    $this->patchJson("/api/appointments/{$appointment->id}", ['status' => 'completed'])
        ->assertStatus(200)
        ->assertJsonPath('status', 'completed');
});

/*
| 6. PATCH /appointments/{id}/status — admin-only
*/

test('status PATCH: guest 401, non-admin 403 even on their own, admin 200 with shape', function () {
    $owner = apptUser();
    $appointment = apptOwnAppointment($owner, ['status' => 'pending']);

    $this->patchJson("/api/appointments/{$appointment->id}/status", ['status' => 'arrived'])
        ->assertStatus(401);

    Sanctum::actingAs($owner);
    $this->patchJson("/api/appointments/{$appointment->id}/status", ['status' => 'arrived'])
        ->assertStatus(403);
    expect($appointment->refresh()->status)->toBe('pending');

    Sanctum::actingAs(apptAdmin());
    $this->patchJson("/api/appointments/{$appointment->id}/status", ['status' => 'arrived'])
        ->assertStatus(200)
        ->assertJsonStructure(['message', 'data' => ['id', 'status']])
        ->assertJsonPath('data.status', 'arrived');

    expect($appointment->refresh()->status)->toBe('arrived');
});

test('status PATCH: invalid status still returns 422 for admin', function () {
    $appointment = apptOwnAppointment(apptUser());
    Sanctum::actingAs(apptAdmin());

    $this->patchJson("/api/appointments/{$appointment->id}/status", ['status' => 'exploded'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);
});

/*
| 7. PATCH /appointments/{id}/payment — admin-only (trusted manual flow)
*/

test('payment PATCH: guest 401, non-admin 403 even on their own, admin confirms cash payment', function () {
    $owner = apptUser();
    $appointment = apptOwnAppointment($owner, [
        'payment_status' => 'unpaid',
        'payment_method' => null,
        'paid_at' => null,
    ]);

    $this->patchJson("/api/appointments/{$appointment->id}/payment", ['payment_method' => 'cash'])
        ->assertStatus(401);

    Sanctum::actingAs($owner);
    $this->patchJson("/api/appointments/{$appointment->id}/payment", ['payment_method' => 'cash'])
        ->assertStatus(403);
    expect($appointment->refresh()->payment_status)->toBe('unpaid');

    Sanctum::actingAs(apptAdmin());
    $this->patchJson("/api/appointments/{$appointment->id}/payment", ['payment_method' => 'cash'])
        ->assertStatus(200)
        ->assertJsonStructure([
            'message',
            'data' => ['id', 'payment' => ['status', 'method', 'paid_at']],
        ])
        ->assertJsonPath('data.payment.status', 'paid')
        ->assertJsonPath('data.payment.method', 'cash');

    $appointment->refresh();
    expect($appointment->payment_status)->toBe('paid')
        ->and($appointment->payment_method)->toBe('cash')
        ->and($appointment->paid_at)->not->toBeNull();
});

test('payment PATCH: already-paid appointment returns 422 for admin', function () {
    $appointment = apptOwnAppointment(apptUser(), ['payment_status' => 'paid']);
    Sanctum::actingAs(apptAdmin());

    $this->patchJson("/api/appointments/{$appointment->id}/payment", ['payment_method' => 'cash'])
        ->assertStatus(422);
});

test('payment PATCH: invalid method still returns 422 for admin', function () {
    $appointment = apptOwnAppointment(apptUser(), ['payment_status' => 'unpaid']);
    Sanctum::actingAs(apptAdmin());

    $this->patchJson("/api/appointments/{$appointment->id}/payment", ['payment_method' => 'bitcoin'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['payment_method']);
});

/*
| 8. Destroy — existing owner-cancel semantics locked
*/

test('delete: unrelated 403, owner cancels own appointment, admin cancels any', function () {
    $owner = apptUser();
    $appointment = apptOwnAppointment($owner, ['status' => 'pending']);

    Sanctum::actingAs(apptUser());
    $this->deleteJson("/api/appointments/{$appointment->id}")->assertStatus(403);
    expect($appointment->refresh()->status)->toBe('pending');

    Sanctum::actingAs($owner);
    $this->deleteJson("/api/appointments/{$appointment->id}")
        ->assertStatus(200)
        ->assertJsonStructure(['message']);
    expect($appointment->refresh()->status)->toBe('cancelled');

    $second = apptOwnAppointment($owner);
    Sanctum::actingAs(apptAdmin());
    $this->deleteJson("/api/appointments/{$second->id}")->assertStatus(200);
    expect($second->refresh()->status)->toBe('cancelled');
});

/*
| 9. Store — server-side ownership assignment (scope 5)
*/

test('patient booking succeeds and the server assigns their own ownership, ignoring forged ids', function () {
    [$doctor, $service, $date] = apptBookingFixtures();
    $owner = apptUser();
    $victim = apptUser();
    $victimPatient = Patient::factory()->create(['user_id' => $victim->id]);

    Sanctum::actingAs($owner);

    $response = $this->postJson('/api/appointments', [
        'doctor_id' => $doctor->id,
        'service_id' => $service->id,
        'appointment_date' => $date,
        'user_id' => $victim->id,
        'patient_id' => $victimPatient->id,
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure(['message', 'queue_number', 'data' => ['id', 'status']]);

    $created = Appointment::find($response->json('data.id'));

    expect($created->user_id)->toEqual($owner->id)
        ->and($created->patient_id)->not->toEqual($victimPatient->id)
        ->and($created->payment_status)->toBe('unpaid')
        ->and($created->status)->toBe('pending');
});

test('admin booking with patient_id assigns ownership from the linked patient user', function () {
    [$doctor, $service, $date] = apptBookingFixtures();
    $victim = apptUser();
    $victimPatient = Patient::factory()->create(['user_id' => $victim->id]);

    Sanctum::actingAs(apptAdmin());

    $response = $this->postJson('/api/appointments', [
        'doctor_id' => $doctor->id,
        'service_id' => $service->id,
        'appointment_date' => $date,
        'patient_id' => $victimPatient->id,
    ]);

    $response->assertStatus(201);

    $created = Appointment::find($response->json('data.id'));

    expect($created->patient_id)->toEqual($victimPatient->id)
        ->and($created->user_id)->toEqual($victim->id);
});
