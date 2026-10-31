<?php

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\Patient;
use App\Models\Service;
use App\Models\Specialty;
use App\Models\User;
use Carbon\Carbon;
use Laravel\Sanctum\Sanctum;

/*
 |---------------------------------------------------------------------------
 | Smart Booking suite — specialty filter, availability endpoint, and the
 | booking-authority guarantees the new read APIs must agree with
 |--------------------------------------------------------------------------- |
 |
 |   - GET /api/doctors?specialty_id= / ?specialty= (id, name, unknown,
 |     combined with search, unchanged shape without filters)
 |   - GET /api/doctors/{doctor}/availability (public; contract keys,
 |     working/non-working days, capacity with D1 active-only semantics,
 |     past/today/full/no-schedule cases, defaults, window cap, 404)
 |   - authority parity: whatever availability reports, store() enforces
 |     (non-working day 422, full day 422, past date 422, last-slot race
 |     resolved to exactly one winner)
 |   - patients can never book for another patient
 |
 | All tests run on the isolated in-memory SQLite database from phpunit.xml.
 | Helper names carry the `sb` prefix to avoid Pest's global function
 | namespace colliding with the other test files.
 */

function sbUser(): User
{
    return User::factory()->create([
        'role' => 'user',
        'phone' => '010' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
    ]);
}

function sbDoctor(array $overrides = []): Doctor
{
    return Doctor::factory()->create($overrides);
}

/** Schedule row for the weekday that $date falls on (same as store() resolves it). */
function sbSchedule(Doctor $doctor, string $date, array $overrides = []): DoctorSchedule
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

/** Factory appointments MUST pin queue_number (unique per doctor/day). */
function sbAppointment(User $owner, array $overrides = [], ?Patient $patient = null): Appointment
{
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

function sbBookingPayload(Doctor $doctor, Service $service, string $date, array $extra = []): array
{
    return array_merge([
        'doctor_id' => $doctor->id,
        'service_id' => $service->id,
        'appointment_date' => $date,
        'notes' => 'Smart booking.',
    ], $extra);
}

/** Find the days[] entry for a date string inside an availability payload. */
function sbDay(array $availability, string $date): array
{
    $day = collect($availability['days'])->firstWhere('date', $date);

    expect($day)->not->toBeNull();

    return $day;
}

/*
 | ---------------------------------------------------------------------------
 | Specialty → doctor filtering
 | ---------------------------------------------------------------------------
 */

test('GET /api/doctors filters by specialty_id', function () {
    $cardio = Specialty::factory()->create(['name' => 'Cardiology']);
    $derma = Specialty::factory()->create(['name' => 'Dermatology']);

    $a = sbDoctor(['specialty_id' => $cardio->id, 'name' => 'Dr. Heart']);
    $b = sbDoctor(['specialty_id' => $derma->id, 'name' => 'Dr. Skin']);
    $c = sbDoctor(['specialty_id' => $cardio->id, 'name' => 'Dr. Valve']);

    $response = $this->getJson('/api/doctors?specialty_id=' . $cardio->id)
        ->assertStatus(200)
        ->json();

    expect(array_keys($response))->toBe(['data'])
        ->and(collect($response['data'])->pluck('id')->sort()->values()->all())
        ->toBe(collect([$a->id, $c->id])->sort()->values()->all());

    // The plain shape is untouched: identity + synced specialty string.
    $first = collect($response['data'])->first();
    expect($first)->toHaveKeys(['id', 'name', 'specialty', 'specialty_id'])
        ->and($first['specialty'])->toBe('Cardiology');
});

test('GET /api/doctors filters by specialty NAME case-insensitively and whitespace-normalized', function () {
    sbDoctor(['specialty' => 'Cardiology', 'name' => 'Dr. Heart']);
    sbDoctor(['specialty' => 'Dermatology', 'name' => 'Dr. Skin']);

    // Different case + padding still matches the canonical Cardiology.
    $response = $this->getJson('/api/doctors?specialty=' . urlencode('  cardiology '))
        ->assertStatus(200)
        ->json();

    expect(collect($response['data'])->pluck('name')->all())->toBe(['Dr. Heart']);
});

test('unknown specialty filters return an empty list (not an error)', function () {
    sbDoctor(['specialty' => 'Cardiology']);

    $this->getJson('/api/doctors?specialty_id=999999')
        ->assertStatus(200)
        ->assertExactJson(['data' => []]);

    $this->getJson('/api/doctors?specialty=' . urlencode('Nonexistent'))
        ->assertStatus(200)
        ->assertExactJson(['data' => []]);
});

test('specialty_id combines with search (AND semantics) and a non-integer id is a 422', function () {
    sbDoctor(['specialty' => 'Cardiology', 'name' => 'Dr. Heart']);
    sbDoctor(['specialty' => 'Cardiology', 'name' => 'Dr. Lungs']);
    sbDoctor(['specialty' => 'Dermatology', 'name' => 'Dr. Skin']);

    $cardioId = Specialty::query()->where('name', 'Cardiology')->first()->id;

    $response = $this->getJson("/api/doctors?specialty_id={$cardioId}&search=Lungs")
        ->assertStatus(200)
        ->json();

    expect(collect($response['data'])->pluck('name')->all())->toBe(['Dr. Lungs']);

    $this->getJson('/api/doctors?specialty_id=abc')->assertStatus(422);
});

test('without filters every doctor is returned (unchanged contract)', function () {
    sbDoctor(['name' => 'Dr. One']);
    sbDoctor(['name' => 'Dr. Two']);

    $response = $this->getJson('/api/doctors')->assertStatus(200)->json();

    expect(array_keys($response))->toBe(['data'])
        ->and(collect($response['data'])->pluck('name')->sort()->values()->all())
        ->toBe(['Dr. One', 'Dr. Two']);
});

/*
 | ---------------------------------------------------------------------------
 | Availability endpoint — public contract
 | ---------------------------------------------------------------------------
 */

test('availability is public and returns the documented contract exactly', function () {
    $doctor = sbDoctor(['name' => 'Dr. Available']);

    // No Sanctum::actingAs — the endpoint must answer unauthenticated.
    $json = $this->getJson('/api/doctors/' . $doctor->id . '/availability')
        ->assertStatus(200)
        ->json();

    expect(array_keys($json))->toBe(['doctor', 'from', 'to', 'days'])
        ->and(array_keys($json['doctor']))->toBe(['id', 'name', 'specialty'])
        ->and($json['doctor']['id'])->toBe($doctor->id)
        ->and($json['from'])->toBe(now()->toDateString())
        // Default window: 30 days starting today.
        ->and($json['to'])->toBe(now()->addDays(29)->toDateString())
        ->and($json['days'])->toHaveCount(30);

    // Every day entry carries exactly the documented keys.
    expect(array_keys($json['days'][0]))->toBe([
        'date',
        'day',
        'is_past',
        'is_working_day',
        'start_time',
        'end_time',
        'max_patients',
        'booked',
        'remaining',
        'is_bookable',
    ]);
});

test('availability reports working days with live capacity (D1 active-only counting)', function () {
    $doctor = sbDoctor();
    $target = now()->addDays(3)->toDateString();

    sbSchedule($doctor, $target, ['max_patients' => 5]);

    // 2 active + 1 cancelled + 1 no_show: only the active ones hold a slot.
    foreach ([['pending'], ['arrived'], ['cancelled'], ['no_show']] as $i => [$status]) {
        sbAppointment(sbUser(), [
            'doctor_id' => $doctor->id,
            'appointment_date' => $target,
            'queue_number' => $i + 1,
            'status' => $status,
        ]);
    }

    $json = $this->getJson("/api/doctors/{$doctor->id}/availability?from={$target}")
        ->assertStatus(200)
        ->json();

    $day = sbDay($json, $target);

    expect($day['is_working_day'])->toBeTrue()
        ->and($day['is_past'])->toBeFalse()
        ->and($day['max_patients'])->toBe(5)
        ->and($day['booked'])->toBe(2)
        ->and($day['remaining'])->toBe(3)
        ->and($day['is_bookable'])->toBeTrue()
        ->and($day['day'])->toBe(Carbon::parse($target)->format('l'));
});

test('availability flags non-working days and never exposes their capacity', function () {
    $doctor = sbDoctor();
    $target = now()->addDays(4)->toDateString();

    // The doctor works exactly one weekday; the target is another day.
    $working = now()->addDays(5)->toDateString();
    sbSchedule($doctor, $working, ['max_patients' => 10]);

    $json = $this->getJson("/api/doctors/{$doctor->id}/availability?from={$target}&to={$working}")
        ->assertStatus(200)
        ->json();

    $day = sbDay($json, $target);

    expect($day['is_working_day'])->toBeFalse()
        ->and($day['start_time'])->toBeNull()
        ->and($day['end_time'])->toBeNull()
        ->and($day['max_patients'])->toBeNull()
        ->and($day['booked'])->toBe(0)
        ->and($day['remaining'])->toBe(0)
        ->and($day['is_bookable'])->toBeFalse()
        // The configured working day in the same window IS bookable.
        ->and(sbDay($json, $working)['is_bookable'])->toBeTrue();
});

test('past days are reported, never rejected, and never bookable', function () {
    $doctor = sbDoctor();

    $yesterday = now()->subDay()->toDateString();
    sbSchedule($doctor, $yesterday, ['max_patients' => 10]);

    $json = $this->getJson("/api/doctors/{$doctor->id}/availability?from={$yesterday}&to={$yesterday}")
        ->assertStatus(200)
        ->json();

    $day = sbDay($json, $yesterday);

    // Stale deep links degrade gracefully: the day is described, with
    // is_past = true and is_bookable = false.
    expect($day['is_past'])->toBeTrue()
        ->and($day['is_working_day'])->toBeTrue()
        ->and($day['is_bookable'])->toBeFalse();
});

test('today counts as bookable while capacity remains (Cairo-local)', function () {
    $doctor = sbDoctor();
    $today = now()->toDateString();

    sbSchedule($doctor, $today, ['max_patients' => 30]);

    $json = $this->getJson("/api/doctors/{$doctor->id}/availability?from={$today}&to={$today}")
        ->assertStatus(200)
        ->json();

    $day = sbDay($json, $today);

    expect($day['is_past'])->toBeFalse()
        ->and($day['is_bookable'])->toBeTrue();
});

test('a full day reports zero remaining and is not bookable', function () {
    $doctor = sbDoctor();
    $target = now()->addDays(2)->toDateString();

    sbSchedule($doctor, $target, ['max_patients' => 2]);

    sbAppointment(sbUser(), ['doctor_id' => $doctor->id, 'appointment_date' => $target, 'queue_number' => 1]);
    sbAppointment(sbUser(), ['doctor_id' => $doctor->id, 'appointment_date' => $target, 'queue_number' => 2]);

    $json = $this->getJson("/api/doctors/{$doctor->id}/availability?from={$target}&to={$target}")
        ->assertStatus(200)
        ->json();

    $day = sbDay($json, $target);

    expect($day['booked'])->toBe(2)
        ->and($day['remaining'])->toBe(0)
        ->and($day['is_bookable'])->toBeFalse();
});

test('a doctor with no schedules has no working days at all', function () {
    $doctor = sbDoctor();

    $json = $this->getJson("/api/doctors/{$doctor->id}/availability")
        ->assertStatus(200)
        ->json();

    expect(collect($json['days'])->where('is_working_day', true))->toBeEmpty()
        ->and(collect($json['days'])->where('is_bookable', true))->toBeEmpty();
});

test('a schedule row with is_available = false does not make a working day', function () {
    $doctor = sbDoctor();
    $target = now()->addDays(3)->toDateString();

    sbSchedule($doctor, $target, ['is_available' => false]);

    $json = $this->getJson("/api/doctors/{$doctor->id}/availability?from={$target}&to={$target}")
        ->assertStatus(200)
        ->json();

    expect(sbDay($json, $target)['is_working_day'])->toBeFalse();
});

test('unknown doctor returns 404 with the doctors convention message', function () {
    $this->getJson('/api/doctors/999999/availability')
        ->assertStatus(404)
        ->assertJson(['message' => 'Doctor not found']);
});

test('the requested window is honored and the 62-day cap is enforced', function () {
    $doctor = sbDoctor();

    $from = now()->addDays(2)->toDateString();
    $to = now()->addDays(9)->toDateString();

    $json = $this->getJson("/api/doctors/{$doctor->id}/availability?from={$from}&to={$to}")
        ->assertStatus(200)
        ->json();

    expect($json['from'])->toBe($from)
        ->and($json['to'])->toBe($to)
        ->and($json['days'])->toHaveCount(8);

    // 63 days → 422; inverted range → 422; bad format → 422.
    $this->getJson("/api/doctors/{$doctor->id}/availability?from={$from}&to=" . now()->addDays(64)->toDateString())
        ->assertStatus(422)
        ->assertJson(['message' => 'The date range cannot exceed 62 days.']);

    $this->getJson("/api/doctors/{$doctor->id}/availability?from={$to}&to={$from}")
        ->assertStatus(422);

    $this->getJson("/api/doctors/{$doctor->id}/availability?from=not-a-date")
        ->assertStatus(422);
});

test('availability refreshes after a booking so the frontend always sees live capacity', function () {
    $doctor = sbDoctor();
    $service = Service::factory()->create();
    $target = now()->addDays(3)->toDateString();

    sbSchedule($doctor, $target, ['max_patients' => 1]);

    $user = sbUser();
    Sanctum::actingAs($user);

    $before = $this->getJson("/api/doctors/{$doctor->id}/availability?from={$target}&to={$target}")->json();
    expect(sbDay($before, $target)['remaining'])->toBe(1);

    $this->postJson('/api/appointments', sbBookingPayload($doctor, $service, $target))
        ->assertStatus(201);

    $after = $this->getJson("/api/doctors/{$doctor->id}/availability?from={$target}&to={$target}")->json();

    expect(sbDay($after, $target)['booked'])->toBe(1)
        ->and(sbDay($after, $target)['remaining'])->toBe(0)
        ->and(sbDay($after, $target)['is_bookable'])->toBeFalse();
});

/*
 | ---------------------------------------------------------------------------
 | Authority parity: availability mirrors what store() enforces
 | ---------------------------------------------------------------------------
 */

test('a day availability reports as non-working is rejected at booking with the same message', function () {
    $doctor = sbDoctor();
    $service = Service::factory()->create();
    $target = now()->addDays(6)->toDateString(); // no schedule created

    Sanctum::actingAs(sbUser());

    $this->postJson('/api/appointments', sbBookingPayload($doctor, $service, $target))
        ->assertStatus(422)
        ->assertJson(['message' => 'Doctor is not available on this day.']);
});

test('a day availability reports as full is rejected at booking with the same message', function () {
    $doctor = sbDoctor();
    $service = Service::factory()->create();
    $target = now()->addDays(6)->toDateString();

    sbSchedule($doctor, $target, ['max_patients' => 1]);
    sbAppointment(sbUser(), ['doctor_id' => $doctor->id, 'appointment_date' => $target, 'queue_number' => 1]);

    Sanctum::actingAs(sbUser());

    $this->postJson('/api/appointments', sbBookingPayload($doctor, $service, $target))
        ->assertStatus(422)
        ->assertJson(['message' => 'This day is fully booked.']);
});

test('booking in the past stays rejected by the write path (422)', function () {
    $doctor = sbDoctor();
    $service = Service::factory()->create();
    $yesterday = now()->subDay()->toDateString();

    sbSchedule($doctor, $yesterday);

    Sanctum::actingAs(sbUser());

    $this->postJson('/api/appointments', sbBookingPayload($doctor, $service, $yesterday))
        ->assertStatus(422);
});

test('two patients racing for the last slot: exactly one booking wins and availability flips', function () {
    $doctor = sbDoctor();
    $service = Service::factory()->create();
    $target = now()->addDays(3)->toDateString();

    sbSchedule($doctor, $target, ['max_patients' => 1]);

    // Sequential requests standing in for the two simultaneous submits;
    // the doctor-row lock + capacity check + unique index resolve the
    // race the same way regardless of interleaving.
    Sanctum::actingAs(sbUser());
    $this->postJson('/api/appointments', sbBookingPayload($doctor, $service, $target))
        ->assertStatus(201);

    Sanctum::actingAs(sbUser());
    $this->postJson('/api/appointments', sbBookingPayload($doctor, $service, $target))
        ->assertStatus(422)
        ->assertJson(['message' => 'This day is fully booked.']);

    expect(Appointment::where('doctor_id', $doctor->id)
        ->whereDate('appointment_date', $target)
        ->active()->count())->toBe(1);

    // The loser's frontend refresh shows the day as taken.
    $json = $this->getJson("/api/doctors/{$doctor->id}/availability?from={$target}&to={$target}")->json();
    expect(sbDay($json, $target)['is_bookable'])->toBeFalse();
});

test('a patient can never book for another patient (patient_id is ignored for non-admins)', function () {
    $doctor = sbDoctor();
    $service = Service::factory()->create();
    $target = now()->addDays(3)->toDateString();

    sbSchedule($doctor, $target);

    $me = sbUser();
    $other = sbUser();
    $otherPatient = Patient::factory()->create([
        'user_id' => $other->id,
        'phone' => '012' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
    ]);

    Sanctum::actingAs($me);

    $response = $this->postJson('/api/appointments', sbBookingPayload(
        $doctor,
        $service,
        $target,
        ['patient_id' => $otherPatient->id]
    ))->assertStatus(201);

    // The booking belongs to MY patient (auto-created from the user),
    // never to the patient whose id was smuggled into the payload.
    $myPatient = Patient::query()->where('user_id', $me->id)->first();

    expect($myPatient)->not->toBeNull()
        ->and($response->json('data.patient.id'))->toBe($myPatient->id)
        ->and($response->json('data.patient.id'))->not->toBe($otherPatient->id)
        ->and($otherPatient->refresh()->user_id)->toBe($other->id);
});
