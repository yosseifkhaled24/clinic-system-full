<?php

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\Patient;
use App\Models\Review;
use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;
use Laravel\Sanctum\Sanctum;

/*
 |---------------------------------------------------------------------------
 | Appointment & queue regression suite — approved decisions D1-D9
 |---------------------------------------------------------------------------
 |
 | Written BEFORE the fixes (tests-first) against the inspection report:
 |
 |   D1  cancelled / no_show release capacity & allow same-day rebooking
 |   D3  admin-selected create status is persisted and validated
 |   D4  only admins may change appointment doctor/date
 |   D5  at most one in_progress appointment per doctor per day
 |   D6  Cairo-local day boundaries (Africa/Cairo)
 |   +   queue sequencing, reschedule re-checks, response contract,
 |       date-only serialization of the appointments.date DATE column
 |
 | Actors always use an EXPLICIT role (UserFactory randomizes 'admin').
 | All tests run on the isolated in-memory SQLite database from phpunit.xml;
 | no live data, no schema changes from this file itself.
 |
 | Helper names carry the `bq` prefix to avoid Pest's global function
 | namespace colliding with the other batch test files.
 */

function bqAdmin(): User
{
    return User::factory()->create(['role' => 'admin']);
}

function bqUser(): User
{
    // UserFactory phones are random 7-digit numbers; pin a unique one
    // because the booking path may auto-create a Patient from user->phone
    // (patients.phone is unique).
    return User::factory()->create([
        'role' => 'user',
        'phone' => '010' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
    ]);
}

function bqSchedule(Doctor $doctor, string $date, array $overrides = []): DoctorSchedule
{
    return DoctorSchedule::factory()->create(array_merge([
        'doctor_id' => $doctor->id,
        'day' => Carbon::parse($date)->format('l'),
        'start_time' => '09:00',
        'end_time' => '15:00',
        'is_available' => true,
        'max_patients' => 30,
    ], $overrides));
}

/**
 * Factory appointments for a specific doctor/date MUST pin queue_number:
 * the factory randomizes 1-30 which would collide on the
 * (doctor_id, appointment_date, queue_number) unique index.
 */
function bqAppointment(User $owner, array $overrides = [], ?Patient $patient = null): Appointment
{
    // PatientFactory phones are random 7-digit numbers; pin a unique one
    // (patients.phone is unique) so multi-patient tests can't collide.
    $patient ??= Patient::factory()->create([
        'user_id' => $owner->id,
        'phone' => '012' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
    ]);

    return Appointment::factory()->create(array_merge([
        'patient_id' => $patient->id,
        'user_id' => $owner->id,
        'status' => 'pending',
        'payment_status' => 'unpaid',
        'payment_method' => null,
        'paid_at' => null,
    ], $overrides));
}

function bqBookingPayload(Doctor $doctor, Service $service, string $date, array $extra = []): array
{
    return array_merge([
        'doctor_id' => $doctor->id,
        'service_id' => $service->id,
        'appointment_date' => $date,
        'notes' => 'Regression booking.',
    ], $extra);
}

/** Admin bookings need name+phone or patient_id (required_without rule). */
function bqAdminBookingPayload(Doctor $doctor, Service $service, string $date, array $extra = []): array
{
    return bqBookingPayload($doctor, $service, $date, array_merge([
        'name' => 'Walk-in Regression',
        'phone' => '01000000001',
    ], $extra));
}

/*
 | ---------------------------------------------------------------------------
 | D1 — capacity & duplicate checks count ACTIVE appointments only
 | ---------------------------------------------------------------------------
 */

test('a day that is full of active appointments is rejected with 422', function () {
    $doctor = Doctor::factory()->create();
    $service = Service::factory()->create();
    $date = now()->addDays(3)->toDateString();

    bqSchedule($doctor, $date, ['max_patients' => 1]);
    bqAppointment(bqUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => $date,
        'queue_number' => 1,
        'status' => 'pending',
    ]);

    Sanctum::actingAs(bqAdmin());

    $this->postJson('/api/appointments', bqAdminBookingPayload($doctor, $service, $date))
        ->assertStatus(422)
        ->assertJson(['message' => 'This day is fully booked.']);

    expect(Appointment::where('doctor_id', $doctor->id)
        ->whereDate('appointment_date', $date)->count())->toEqual(1);
});

test('cancelled and no_show appointments release daily capacity (D1)', function () {
    $doctor = Doctor::factory()->create();
    $service = Service::factory()->create();
    $date = now()->addDays(3)->toDateString();

    bqSchedule($doctor, $date, ['max_patients' => 2]);

    // Both queue positions are taken, but neither row is active anymore.
    bqAppointment(bqUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => $date,
        'queue_number' => 1,
        'status' => 'cancelled',
    ]);
    bqAppointment(bqUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => $date,
        'queue_number' => 2,
        'status' => 'no_show',
    ]);

    Sanctum::actingAs(bqAdmin());

    $response = $this->postJson('/api/appointments', bqAdminBookingPayload($doctor, $service, $date));

    $response->assertStatus(201);

    // Capacity came from active rows (0 < 2), but cancelled queue numbers
    // are never reused: the new number continues after the highest issued.
    expect($response->json('queue_number'))->toEqual(3);
});

test('a patient can rebook the same doctor and day after cancelling (D1)', function () {
    $doctor = Doctor::factory()->create();
    $service = Service::factory()->create();
    $date = now()->addDays(3)->toDateString();

    bqSchedule($doctor, $date);
    $owner = bqUser();

    $old = bqAppointment($owner, [
        'doctor_id' => $doctor->id,
        'appointment_date' => $date,
        'queue_number' => 1,
        'status' => 'cancelled',
    ]);

    Sanctum::actingAs($owner);

    $this->postJson('/api/appointments', bqBookingPayload($doctor, $service, $date))
        ->assertStatus(201);

    expect(Appointment::where('patient_id', $old->patient_id)
        ->where('doctor_id', $doctor->id)
        ->whereDate('appointment_date', $date)
        ->count())->toEqual(2);
});

test('an active duplicate booking for the same patient doctor and day is rejected', function () {
    $doctor = Doctor::factory()->create();
    $service = Service::factory()->create();
    $date = now()->addDays(3)->toDateString();

    bqSchedule($doctor, $date);
    $owner = bqUser();

    bqAppointment($owner, [
        'doctor_id' => $doctor->id,
        'appointment_date' => $date,
        'queue_number' => 1,
        'status' => 'pending',
    ]);

    Sanctum::actingAs($owner);

    $this->postJson('/api/appointments', bqBookingPayload($doctor, $service, $date))
        ->assertStatus(422)
        ->assertJson(['message' => 'Patient already booked this doctor on this day.']);
});

test('booking is rejected when the doctor has no available schedule that day', function () {
    $doctor = Doctor::factory()->create();
    $service = Service::factory()->create();
    $date = now()->addDays(4)->toDateString();

    // No schedule row at all for that weekday.
    Sanctum::actingAs(bqAdmin());

    $this->postJson('/api/appointments', bqAdminBookingPayload($doctor, $service, $date))
        ->assertStatus(422)
        ->assertJson(['message' => 'Doctor is not available on this day.']);

    // A schedule that exists but is marked unavailable behaves the same.
    bqSchedule($doctor, $date, ['is_available' => false]);

    $this->postJson('/api/appointments', bqAdminBookingPayload($doctor, $service, $date))
        ->assertStatus(422)
        ->assertJson(['message' => 'Doctor is not available on this day.']);
});

/*
 | ---------------------------------------------------------------------------
 | Queue numbering — per (doctor, date), unique, never reused
 | ---------------------------------------------------------------------------
 */

test('queue numbers run 1..n per doctor and date and are never reused after cancellation', function () {
    $doctor = Doctor::factory()->create();
    $otherDoctor = Doctor::factory()->create();
    $service = Service::factory()->create();
    $date = now()->addDays(3)->toDateString();

    bqSchedule($doctor, $date);
    bqSchedule($otherDoctor, $date);

    $first = bqUser();
    $second = bqUser();
    $third = bqUser();
    $fourth = bqUser();

    Sanctum::actingAs($first);
    $q1 = $this->postJson('/api/appointments', bqBookingPayload($doctor, $service, $date))
        ->assertStatus(201)
        ->json('queue_number');

    Sanctum::actingAs($second);
    $secondAppointment = $this->postJson('/api/appointments', bqBookingPayload($doctor, $service, $date))
        ->assertStatus(201);
    $q2 = $secondAppointment->json('queue_number');

    Sanctum::actingAs($third);
    $q3 = $this->postJson('/api/appointments', bqBookingPayload($doctor, $service, $date))
        ->assertStatus(201)
        ->json('queue_number');

    expect([$q1, $q2, $q3])->toBe([1, 2, 3]);

    // A different doctor's queue is independent on the same day. Use a
    // fresh User instance: Sanctum::actingAs reuses the same object, and
    // Eloquent caches the null `patient` relation from the first request,
    // which would re-create a Patient and collide on the unique phone.
    Sanctum::actingAs(User::find($first->id));
    $this->postJson('/api/appointments', bqBookingPayload($otherDoctor, $service, $date))
        ->assertStatus(201)
        ->assertJsonPath('queue_number', 1);

    // Cancel #2, then a new patient books: number 2 is NOT recycled.
    Sanctum::actingAs($second);
    $this->deleteJson("/api/appointments/{$secondAppointment->json('data.id')}")
        ->assertStatus(200);

    Sanctum::actingAs($fourth);
    $q4 = $this->postJson('/api/appointments', bqBookingPayload($doctor, $service, $date))
        ->assertStatus(201)
        ->json('queue_number');

    expect($q4)->toEqual(4);
});

/*
 | ---------------------------------------------------------------------------
 | D4 — rescheduling (admin) re-checks everything; patients cannot reschedule
 | ---------------------------------------------------------------------------
 */

test('admin rescheduling to a day the doctor does not work is rejected', function () {
    $doctor = Doctor::factory()->create();
    $date = now()->addDays(3)->toDateString();
    $blockedDate = now()->addDays(5)->toDateString();

    bqSchedule($doctor, $date);
    // No schedule exists for $blockedDate.

    $appointment = bqAppointment(bqUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => $date,
        'queue_number' => 1,
    ]);

    Sanctum::actingAs(bqAdmin());

    $this->patchJson("/api/appointments/{$appointment->id}", [
        'appointment_date' => $blockedDate,
    ])
        ->assertStatus(422)
        ->assertJson(['message' => 'Doctor is not available on this day.']);

    expect($appointment->refresh()->appointment_date->toDateString())->toEqual($date);
});

test('admin rescheduling into a full day is rejected', function () {
    $doctor = Doctor::factory()->create();
    $date = now()->addDays(3)->toDateString();
    $targetDate = now()->addDays(5)->toDateString();

    bqSchedule($doctor, $date);
    bqSchedule($doctor, $targetDate, ['max_patients' => 1]);
    bqAppointment(bqUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => $targetDate,
        'queue_number' => 1,
        'status' => 'pending',
    ]);

    $appointment = bqAppointment(bqUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => $date,
        'queue_number' => 1,
    ]);

    Sanctum::actingAs(bqAdmin());

    $this->patchJson("/api/appointments/{$appointment->id}", [
        'appointment_date' => $targetDate,
    ])
        ->assertStatus(422)
        ->assertJson(['message' => 'This day is fully booked.']);

    expect($appointment->refresh()->appointment_date->toDateString())->toEqual($date);
});

test('admin rescheduling that would duplicate an active booking is rejected', function () {
    $doctor = Doctor::factory()->create();
    $date = now()->addDays(3)->toDateString();
    $targetDate = now()->addDays(5)->toDateString();

    bqSchedule($doctor, $date);
    bqSchedule($doctor, $targetDate);

    $owner = bqUser();
    $patient = Patient::factory()->create(['user_id' => $owner->id]);

    // Same patient already holds an ACTIVE booking on the target day.
    bqAppointment($owner, [
        'doctor_id' => $doctor->id,
        'appointment_date' => $targetDate,
        'queue_number' => 1,
    ], $patient);

    $appointment = bqAppointment($owner, [
        'doctor_id' => $doctor->id,
        'appointment_date' => $date,
        'queue_number' => 1,
    ], $patient);

    Sanctum::actingAs(bqAdmin());

    $this->patchJson("/api/appointments/{$appointment->id}", [
        'appointment_date' => $targetDate,
    ])
        ->assertStatus(422)
        ->assertJson(['message' => 'Patient already booked this doctor on this day.']);

    expect($appointment->refresh()->appointment_date->toDateString())->toEqual($date);
});

test('admin rescheduling recomputes the queue number for the target day', function () {
    $doctor = Doctor::factory()->create();
    $date = now()->addDays(3)->toDateString();
    $targetDate = now()->addDays(5)->toDateString();

    bqSchedule($doctor, $date);
    bqSchedule($doctor, $targetDate);

    // Target day already issued queue 1 and 2 — the moving appointment
    // still carries queue 1 from its old day (unique-index collision risk).
    bqAppointment(bqUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => $targetDate,
        'queue_number' => 1,
    ]);
    bqAppointment(bqUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => $targetDate,
        'queue_number' => 2,
    ]);

    $moving = bqAppointment(bqUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => $date,
        'queue_number' => 1,
    ]);

    Sanctum::actingAs(bqAdmin());

    $this->patchJson("/api/appointments/{$moving->id}", [
        'appointment_date' => $targetDate,
    ])
        ->assertStatus(200)
        ->assertJsonPath('queue_number', 3);

    expect($moving->refresh()->queue_number)->toEqual(3);
});

test('a notes-only admin edit keeps the date and queue number untouched', function () {
    $doctor = Doctor::factory()->create();
    $date = now()->addDays(3)->toDateString();

    bqSchedule($doctor, $date);

    $appointment = bqAppointment(bqUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => $date,
        'queue_number' => 7,
    ]);

    Sanctum::actingAs(bqAdmin());

    $this->patchJson("/api/appointments/{$appointment->id}", ['notes' => 'Updated notes.'])
        ->assertStatus(200)
        ->assertJsonPath('notes', 'Updated notes.');

    $appointment->refresh();
    expect($appointment->queue_number)->toEqual(7)
        ->and($appointment->appointment_date->toDateString())->toEqual($date);
});

test('a full edit payload on a past-dated appointment succeeds when the date does not change', function () {
    $doctor = Doctor::factory()->create();
    $pastDate = now()->subDays(3)->toDateString();

    $appointment = bqAppointment(bqUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => $pastDate,
        'queue_number' => 1,
        'status' => 'completed',
    ]);

    Sanctum::actingAs(bqAdmin());

    $this->patchJson("/api/appointments/{$appointment->id}", [
        'doctor_id' => $doctor->id,
        'appointment_date' => $pastDate,
        'notes' => 'Backfilled note.',
        'status' => 'completed',
    ])->assertStatus(200);

    expect($appointment->refresh()->notes)->toBe('Backfilled note.');
});

test('admin rescheduling to a past date is rejected', function () {
    $doctor = Doctor::factory()->create();
    $date = now()->addDays(3)->toDateString();
    $pastDate = now()->subDays(1)->toDateString();

    bqSchedule($doctor, $date);

    $appointment = bqAppointment(bqUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => $date,
        'queue_number' => 1,
    ]);

    Sanctum::actingAs(bqAdmin());

    $this->patchJson("/api/appointments/{$appointment->id}", [
        'appointment_date' => $pastDate,
    ])->assertStatus(422)->assertJsonValidationErrors(['appointment_date']);

    expect($appointment->refresh()->appointment_date->toDateString())->toEqual($date);
});

test('a non-admin cannot change the appointment doctor (D4)', function () {
    $doctor = Doctor::factory()->create();
    $otherDoctor = Doctor::factory()->create();
    $date = now()->addDays(3)->toDateString();

    $appointment = bqAppointment(bqUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => $date,
        'queue_number' => 1,
    ]);

    Sanctum::actingAs(User::find($appointment->user_id));

    $this->patchJson("/api/appointments/{$appointment->id}", [
        'doctor_id' => $otherDoctor->id,
    ])->assertStatus(422)->assertJsonValidationErrors(['doctor_id']);

    expect($appointment->refresh()->doctor_id)->toEqual($doctor->id);
});

test('a non-admin cannot change the appointment date (D4)', function () {
    $doctor = Doctor::factory()->create();
    $date = now()->addDays(3)->toDateString();
    $newDate = now()->addDays(6)->toDateString();

    $appointment = bqAppointment(bqUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => $date,
        'queue_number' => 1,
    ]);

    Sanctum::actingAs(User::find($appointment->user_id));

    $this->patchJson("/api/appointments/{$appointment->id}", [
        'appointment_date' => $newDate,
    ])->assertStatus(422)->assertJsonValidationErrors(['appointment_date']);

    expect($appointment->refresh()->appointment_date->toDateString())->toEqual($date);
});

/*
 | ---------------------------------------------------------------------------
 | D3 — create-time status contract
 | ---------------------------------------------------------------------------
 */

test('admin create persists a chosen status (D3)', function () {
    $doctor = Doctor::factory()->create();
    $service = Service::factory()->create();
    $date = now()->addDays(3)->toDateString();

    bqSchedule($doctor, $date);

    Sanctum::actingAs(bqAdmin());

    $response = $this->postJson('/api/appointments', bqAdminBookingPayload($doctor, $service, $date, [
        'status' => 'arrived',
    ]));

    $response->assertStatus(201)->assertJsonPath('data.status', 'arrived');

    expect(Appointment::find($response->json('data.id'))->status)->toBe('arrived');
});

test('admin create rejects an unknown status value with 422 (D3)', function () {
    $doctor = Doctor::factory()->create();
    $service = Service::factory()->create();
    $date = now()->addDays(3)->toDateString();

    bqSchedule($doctor, $date);

    Sanctum::actingAs(bqAdmin());

    $this->postJson('/api/appointments', bqAdminBookingPayload($doctor, $service, $date, [
        'status' => 'exploded',
    ]))->assertStatus(422)->assertJsonValidationErrors(['status']);

    expect(Appointment::count())->toEqual(0);
});

test('booking without a status still defaults to pending (backward compatibility)', function () {
    $doctor = Doctor::factory()->create();
    $service = Service::factory()->create();
    $date = now()->addDays(3)->toDateString();

    bqSchedule($doctor, $date);

    Sanctum::actingAs(bqAdmin());

    $this->postJson('/api/appointments', bqAdminBookingPayload($doctor, $service, $date))
        ->assertStatus(201)
        ->assertJsonPath('data.status', 'pending');
});

test('a patient cannot choose a status when booking (D3)', function () {
    $doctor = Doctor::factory()->create();
    $service = Service::factory()->create();
    $date = now()->addDays(3)->toDateString();

    bqSchedule($doctor, $date);

    Sanctum::actingAs(bqUser());

    $this->postJson('/api/appointments', bqBookingPayload($doctor, $service, $date, [
        'status' => 'completed',
    ]))->assertStatus(422)->assertJsonValidationErrors(['status']);

    expect(Appointment::count())->toEqual(0);
});

/*
 | ---------------------------------------------------------------------------
 | D5 — at most one in_progress appointment per doctor per day
 | ---------------------------------------------------------------------------
 */

test('only one appointment per doctor and day can be in_progress (D5)', function () {
    $doctor = Doctor::factory()->create();
    $otherDoctor = Doctor::factory()->create();
    $today = now()->toDateString();
    $otherDay = now()->addDays(5)->toDateString();

    $a = bqAppointment(bqUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => $today,
        'queue_number' => 1,
        'status' => 'pending',
    ]);
    $b = bqAppointment(bqUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => $today,
        'queue_number' => 2,
        'status' => 'pending',
    ]);
    // Same doctor but a different day — out of the demotion scope.
    $x = bqAppointment(bqUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => $otherDay,
        'queue_number' => 1,
        'status' => 'in_progress',
    ]);
    // Same day but a different doctor — out of the demotion scope.
    $y = bqAppointment(bqUser(), [
        'doctor_id' => $otherDoctor->id,
        'appointment_date' => $today,
        'queue_number' => 1,
        'status' => 'in_progress',
    ]);

    Sanctum::actingAs(bqAdmin());

    $this->patchJson("/api/appointments/{$a->id}/status", ['status' => 'in_progress'])
        ->assertStatus(200);
    $this->patchJson("/api/appointments/{$b->id}/status", ['status' => 'in_progress'])
        ->assertStatus(200);

    expect($a->refresh()->status)->toBe('arrived')
        ->and($b->refresh()->status)->toBe('in_progress')
        ->and($x->refresh()->status)->toBe('in_progress')
        ->and($y->refresh()->status)->toBe('in_progress');

    // The queue endpoint exposes exactly one current patient: B.
    $queue = $this->getJson("/api/queue/{$doctor->id}")->assertStatus(200);

    expect($queue->json('current.id'))->toEqual($b->id);

    $waitingIds = collect($queue->json('waiting'))->pluck('id')->all();
    expect($waitingIds)->toContain($a->id)
        ->and($waitingIds)->not->toContain($b->id);
});

test('the admin edit endpoint also demotes the previous in_progress appointment (D5)', function () {
    $doctor = Doctor::factory()->create();
    $today = now()->toDateString();

    $a = bqAppointment(bqUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => $today,
        'queue_number' => 1,
        'status' => 'in_progress',
    ]);
    $b = bqAppointment(bqUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => $today,
        'queue_number' => 2,
        'status' => 'pending',
    ]);

    Sanctum::actingAs(bqAdmin());

    $this->patchJson("/api/appointments/{$b->id}", ['status' => 'in_progress'])
        ->assertStatus(200)
        ->assertJsonPath('status', 'in_progress');

    expect($a->refresh()->status)->toBe('arrived')
        ->and($b->refresh()->status)->toBe('in_progress');
});

test('admin create with in_progress status demotes the previous current appointment (D5)', function () {
    $doctor = Doctor::factory()->create();
    $service = Service::factory()->create();
    $today = now()->toDateString();

    bqSchedule($doctor, $today);

    $current = bqAppointment(bqUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => $today,
        'queue_number' => 1,
        'status' => 'in_progress',
    ]);

    Sanctum::actingAs(bqAdmin());

    $response = $this->postJson('/api/appointments', bqAdminBookingPayload($doctor, $service, $today, [
        'status' => 'in_progress',
    ]));

    $response->assertStatus(201)->assertJsonPath('data.status', 'in_progress');

    expect($current->refresh()->status)->toBe('arrived');
});

/*
 | ---------------------------------------------------------------------------
 | Response contract — Phase 1 fixes
 | ---------------------------------------------------------------------------
 */

test('the booking response contains exactly the documented fields', function () {
    $doctor = Doctor::factory()->create();
    $service = Service::factory()->create();
    $date = now()->addDays(3)->toDateString();

    bqSchedule($doctor, $date);

    Sanctum::actingAs(bqAdmin());

    $response = $this->postJson('/api/appointments', bqAdminBookingPayload($doctor, $service, $date));

    $response->assertStatus(201)
        ->assertJsonStructure(['message', 'queue_number', 'data']);

    // No stray unkeyed entries (the raw role value previously landed
    // at numeric index 0 and leaked into the JSON contract).
    expect(array_keys($response->json()))->toBe(['message', 'queue_number', 'data']);
});

test('appointment_date is serialized as a date-only string on every appointment path', function () {
    $dateOnly = '/^\d{4}-\d{2}-\d{2}$/';

    // 1. Booking response.
    $doctor = Doctor::factory()->create();
    $service = Service::factory()->create();
    $date = now()->addDays(3)->toDateString();
    bqSchedule($doctor, $date);

    Sanctum::actingAs(bqAdmin());
    $booking = $this->postJson('/api/appointments', bqAdminBookingPayload($doctor, $service, $date))
        ->assertStatus(201);
    expect($booking->json('data.appointment_date'))->toMatch($dateOnly);

    $appointmentId = $booking->json('data.id');
    $appointment = Appointment::find($appointmentId);

    // 2. Appointments index + show.
    $index = $this->getJson('/api/appointments')->assertStatus(200);
    $entry = collect($index->json())->firstWhere('id', $appointmentId);
    expect($entry['appointment_date'])->toMatch($dateOnly);

    $this->getJson("/api/appointments/{$appointmentId}")
        ->assertStatus(200)
        ->assertJsonPath('appointment_date', $date);

    // 3. Queue endpoint (raw model serialization path).
    $today = now()->toDateString();
    bqSchedule($doctor, $today);
    $todayAppointment = bqAppointment(bqUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => $today,
        'queue_number' => 1,
        'status' => 'pending',
    ]);

    $queue = $this->getJson("/api/queue/{$doctor->id}")->assertStatus(200);
    expect($queue->json('waiting.0.appointment_date'))->toMatch($dateOnly);

    // 4. Patient history path.
    $history = $this->getJson("/api/patients/{$todayAppointment->patient_id}/history")
        ->assertStatus(200);
    expect($history->json('history.0.date'))->toMatch($dateOnly);

    // 5. Review payload embeds the appointment date too.
    $review = Review::factory()->create([
        'user_id' => $todayAppointment->user_id,
        'appointment_id' => $todayAppointment->id,
        'doctor_id' => $doctor->id,
    ]);
    $reviews = $this->getJson('/api/reviews')->assertStatus(200);
    $reviewEntry = collect($reviews->json())->firstWhere('id', $review->id);
    expect($reviewEntry['appointment']['appointment_date'])->toMatch($dateOnly);

    // Nothing about the stored DATE column itself changed: the raw value
    // still represents the same calendar day (SQLite stores a date cast
    // as 'Y-m-d H:i:s'; MySQL as 'Y-m-d' — parse tolerantly).
    expect(Carbon::parse($appointment->refresh()->getRawOriginal('appointment_date'))->toDateString())
        ->toEqual($date);
});

/*
 | ---------------------------------------------------------------------------
 | D6 — Africa/Cairo day boundaries
 | ---------------------------------------------------------------------------
 */

test('the application timezone is Africa/Cairo (D6)', function () {
    expect(config('app.timezone'))->toBe('Africa/Cairo');
});

test('booking validates against the Cairo-local today (D6)', function () {
    // 2026-06-08 23:00 UTC = 2026-06-09 02:00 in Cairo (UTC+3, summer time):
    // UTC and Cairo disagree about which day "today" is.
    $this->travelTo(
        Carbon::parse('2026-06-08 23:00:00', 'UTC')->setTimezone(config('app.timezone'))
    );

    $doctor = Doctor::factory()->create();
    $service = Service::factory()->create();

    bqSchedule($doctor, '2026-06-08'); // Cairo's "yesterday" (UTC's today)
    bqSchedule($doctor, '2026-06-09'); // Cairo's today

    Sanctum::actingAs(bqAdmin());

    $this->postJson('/api/appointments', bqAdminBookingPayload($doctor, $service, '2026-06-08'))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['appointment_date']);

    $this->postJson('/api/appointments', bqAdminBookingPayload($doctor, $service, '2026-06-09'))
        ->assertStatus(201);
});

test('the queue endpoint filters on the Cairo-local clinic day (D6)', function () {
    $this->travelTo(
        Carbon::parse('2026-06-08 23:00:00', 'UTC')->setTimezone(config('app.timezone'))
    );

    $doctor = Doctor::factory()->create();
    $service = Service::factory()->create();

    // Cairo's today (2026-06-09) and Cairo's yesterday (2026-06-08).
    $cairoToday = bqAppointment(bqUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => '2026-06-09',
        'queue_number' => 1,
        'status' => 'pending',
    ]);
    bqAppointment(bqUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => '2026-06-08',
        'queue_number' => 2,
        'status' => 'pending',
    ]);

    Sanctum::actingAs(bqAdmin());

    $queue = $this->getJson("/api/queue/{$doctor->id}")->assertStatus(200);

    $waitingIds = collect($queue->json('waiting'))->pluck('id')->all();

    expect($waitingIds)->toBe([$cairoToday->id]);
});
