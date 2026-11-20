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
|--------------------------------------------------------------------------
| SEC-07 — appointment service & price tampering protection
|--------------------------------------------------------------------------
|
| Locks in the approved behaviour:
|  - service_id is admin-only on the generic update (patients editing
|    their own notes get 422 if they send it at all),
|  - the price always comes from the trusted server-side Service record
|    (client-sent service_price is never accepted),
|  - an admin service change recalculates the price transactionally,
|  - a paid appointment's service/price is never changed (the recorded
|    payment must stay consistent with what was purchased),
|  - nothing is written when no authorized service change occurs,
|  - booking still stores the server-side price exactly as before.
|
| All tests run against the isolated in-memory SQLite database from
| phpunit.xml. No real MySQL data, no external services.
|
*/

function s7pUser(): User
{
    return User::factory()->create(['role' => 'user']);
}

function s7pAdmin(): User
{
    return User::factory()->create(['role' => 'admin']);
}

function s7pAppointment(User $owner, array $overrides = []): Appointment
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

function s7pOwnedPair(array $appointmentOverrides = []): array
{
    $owner = s7pUser();
    $serviceA = Service::factory()->create(['price' => 300]);
    $serviceB = Service::factory()->create(['price' => 750]);

    $appointment = s7pAppointment($owner, array_merge([
        'service_id' => $serviceA->id,
        'service_price' => 300,
    ], $appointmentOverrides));

    return [$owner, $appointment, $serviceA, $serviceB];
}

/*
| 1. Patient side — service changes and price injection are impossible
*/

test('SEC-07: patient cannot change the service or inject a price (422, nothing written)', function () {
    [$owner, $appointment, $serviceA, $serviceB] = s7pOwnedPair();

    Sanctum::actingAs($owner);
    $this->patchJson("/api/appointments/{$appointment->id}", [
        'service_id' => $serviceB->id,
        'service_price' => 1,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['service_id']);

    $appointment->refresh();
    expect($appointment->service_id)->toEqual($serviceA->id)
        ->and((float) $appointment->service_price)->toBe(300.0);
});

test('SEC-07: patient cannot change the service of a completed appointment', function () {
    [$owner, $appointment, $serviceA, $serviceB] = s7pOwnedPair(['status' => 'completed']);

    Sanctum::actingAs($owner);
    $this->patchJson("/api/appointments/{$appointment->id}", [
        'service_id' => $serviceB->id,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['service_id']);

    $appointment->refresh();
    expect($appointment->status)->toBe('completed')
        ->and($appointment->service_id)->toEqual($serviceA->id)
        ->and((float) $appointment->service_price)->toBe(300.0);
});

test('SEC-07: patient cannot change the service of a paid appointment', function () {
    [$owner, $appointment, $serviceA, $serviceB] = s7pOwnedPair([
        'payment_status' => 'paid',
        'payment_method' => 'cash',
        'paid_at' => now(),
    ]);

    Sanctum::actingAs($owner);
    $this->patchJson("/api/appointments/{$appointment->id}", [
        'service_id' => $serviceB->id,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['service_id']);

    $appointment->refresh();
    expect($appointment->service_id)->toEqual($serviceA->id)
        ->and((float) $appointment->service_price)->toBe(300.0)
        ->and($appointment->payment_status)->toBe('paid')
        ->and($appointment->payment_method)->toBe('cash');
});

test('SEC-07: a client-supplied service_price is ignored on a normal patient edit', function () {
    [$owner, $appointment] = s7pOwnedPair();

    Sanctum::actingAs($owner);
    $this->patchJson("/api/appointments/{$appointment->id}", [
        'notes' => 'Please arrive 10 minutes early.',
        'service_price' => 1,
    ])->assertStatus(200);

    $appointment->refresh();
    expect((float) $appointment->service_price)->toBe(300.0)
        ->and($appointment->notes)->toBe('Please arrive 10 minutes early.');
});

/*
| 2. Admin side — authorized changes use the trusted server-side price
*/

test('SEC-07: an authorized admin service change recalculates the price from the Service record', function () {
    [, $appointment, $serviceA, $serviceB] = s7pOwnedPair();

    Sanctum::actingAs(s7pAdmin());
    $this->patchJson("/api/appointments/{$appointment->id}", [
        'service_id' => $serviceB->id,
        // Hostile/injected value — must be ignored entirely.
        'service_price' => 1,
    ])->assertStatus(200);

    $appointment->refresh();
    expect($appointment->service_id)->toEqual($serviceB->id)
        ->and((float) $appointment->service_price)->toBe((float) $serviceB->price)
        ->and((float) $appointment->service_price)->not->toBe(1.0)
        ->and($appointment->service_id)->not->toEqual($serviceA->id);
});

test('SEC-07: price stays unchanged when no authorized service change occurs', function () {
    [$owner, $appointment, $serviceA] = s7pOwnedPair();

    // Admin edits notes only (the admin form always resends the SAME
    // service_id — it must not recalculate anything).
    Sanctum::actingAs(s7pAdmin());
    $this->patchJson("/api/appointments/{$appointment->id}", [
        'notes' => 'Admin note.',
        'service_id' => $serviceA->id,
    ])->assertStatus(200);

    $appointment->refresh();
    expect((float) $appointment->service_price)->toBe(300.0)
        ->and($appointment->service_id)->toEqual($serviceA->id)
        ->and($appointment->notes)->toBe('Admin note.');

    // Owner edits notes: price also untouched.
    Sanctum::actingAs($owner);
    $this->patchJson("/api/appointments/{$appointment->id}", [
        'notes' => 'Owner note.',
    ])->assertStatus(200);

    expect((float) $appointment->refresh()->service_price)->toBe(300.0);
});

test('SEC-07: admin cannot change the service of a paid appointment (payment record preserved)', function () {
    [, $appointment, $serviceA, $serviceB] = s7pOwnedPair([
        'payment_status' => 'paid',
        'payment_method' => 'stripe',
        'paid_at' => now(),
    ]);

    Sanctum::actingAs(s7pAdmin());
    $this->patchJson("/api/appointments/{$appointment->id}", [
        'service_id' => $serviceB->id,
    ])->assertStatus(422)
        ->assertJsonPath('message', 'The service of a paid appointment cannot be changed: the recorded payment must stay consistent with the service that was purchased.');

    $appointment->refresh();
    expect($appointment->service_id)->toEqual($serviceA->id)
        ->and((float) $appointment->service_price)->toBe(300.0)
        ->and($appointment->payment_status)->toBe('paid')
        ->and($appointment->payment_method)->toBe('stripe');
});

test('SEC-07: admin service change on a completed unpaid appointment recalculates transactionally', function () {
    [, $appointment, , $serviceB] = s7pOwnedPair(['status' => 'completed']);

    Sanctum::actingAs(s7pAdmin());
    $this->patchJson("/api/appointments/{$appointment->id}", [
        'service_id' => $serviceB->id,
    ])->assertStatus(200);

    $appointment->refresh();
    expect($appointment->status)->toBe('completed')
        ->and($appointment->service_id)->toEqual($serviceB->id)
        ->and((float) $appointment->service_price)->toBe((float) $serviceB->price)
        ->and($appointment->payment_status)->toBe('unpaid');
});

/*
| 3. Creation & booking behaviour is preserved
*/

test('SEC-07: appointment creation stores the trusted server-side service price', function () {
    $owner = s7pUser();
    $doctor = Doctor::factory()->create();
    $service = Service::factory()->create(['price' => 450]);
    $date = now()->addDays(3)->toDateString();

    DoctorSchedule::factory()->create([
        'doctor_id' => $doctor->id,
        'day' => Carbon::parse($date)->format('l'),
        'start_time' => '09:00',
        'end_time' => '15:00',
        'is_available' => true,
        'max_patients' => 5,
    ]);

    Sanctum::actingAs($owner);
    $response = $this->postJson('/api/appointments', [
        'doctor_id' => $doctor->id,
        'service_id' => $service->id,
        'appointment_date' => $date,
        // Must be ignored: price comes from the Service record only.
        'service_price' => 1,
    ])->assertStatus(201)
        ->assertJsonStructure(['message', 'queue_number', 'data' => ['id', 'status']]);

    $created = Appointment::findOrFail($response->json('data.id'));

    expect((float) $created->service_price)->toBe(450.0)
        ->and($created->service_id)->toEqual($service->id)
        ->and($created->status)->toBe('pending')
        ->and($created->payment_status)->toBe('unpaid')
        ->and($created->user_id)->toEqual($owner->id);
});
