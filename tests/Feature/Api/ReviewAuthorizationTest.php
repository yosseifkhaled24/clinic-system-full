<?php

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\Patient;
use App\Models\Review;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/*
|---------------------------------------------------------------------------
| Backend authorization batch 7 — reviews & remaining schedule gaps
|---------------------------------------------------------------------------
|
| Covers: review store relationship rules (own appointment, matching
| doctor, no duplicates, server-side user_id), owner-or-admin update and
| delete, comment length limits, and the admin-only policy for
| GET /schedules and GET /schedules/{id}.
|
| Actors use an EXPLICIT role (UserFactory randomizes 'admin'). All tests
| run against the isolated in-memory SQLite test database from phpunit.xml.
|
*/

function reviewAdmin(): User
{
    return User::factory()->create(['role' => 'admin']);
}

function reviewUser(): User
{
    return User::factory()->create(['role' => 'user']);
}

function reviewOwnAppointment(User $owner, array $overrides = []): Appointment
{
    $patient = Patient::factory()->create(['user_id' => $owner->id]);

    return Appointment::factory()->create(array_merge([
        'patient_id' => $patient->id,
        'user_id' => $owner->id,
        // The factory randomizes status; batch 9's eligibility rule
        // requires 'completed', so the default is pinned here.
        'status' => 'completed',
        // The factory also randomizes payment_status; the paid-gate rule
        // requires 'paid', so it is pinned here too (overridable below).
        'payment_status' => 'paid',
    ], $overrides));
}

function reviewPayload(Appointment $appointment, array $overrides = []): array
{
    return array_merge([
        'doctor_id' => $appointment->doctor_id,
        'appointment_id' => $appointment->id,
        'rating' => 5,
        'comment' => 'Legit review of my own appointment.',
    ], $overrides);
}

/*
| 1. Guests & public reads
*/

test('guest gets 401 on review writes while public reads stay open', function () {
    $owner = reviewUser();
    $appointment = reviewOwnAppointment($owner);
    $review = Review::factory()->create([
        'user_id' => $owner->id,
        'appointment_id' => $appointment->id,
    ]);

    $this->postJson('/api/reviews', reviewPayload($appointment))->assertStatus(401);
    $this->patchJson("/api/reviews/{$review->id}", ['rating' => 4])->assertStatus(401);
    $this->deleteJson("/api/reviews/{$review->id}")->assertStatus(401);

    $this->getJson('/api/reviews')->assertStatus(200);
    $this->getJson("/api/reviews/{$review->id}")->assertStatus(200);
});

/*
| 2. Store — relationship rules
*/

test('owner can review their own appointment for its doctor (201, shape preserved)', function () {
    $owner = reviewUser();
    $appointment = reviewOwnAppointment($owner);
    Sanctum::actingAs($owner);

    $this->postJson('/api/reviews', reviewPayload($appointment))
        ->assertStatus(201)
        ->assertJsonStructure([
            'id', 'rating', 'comment',
            'user' => ['id', 'name'],
            'doctor' => ['id', 'name'],
            'appointment' => ['id', 'appointment_date'],
            'created_at', 'updated_at',
        ])
        ->assertJsonPath('user.id', $owner->id)
        ->assertJsonPath('doctor.id', $appointment->doctor_id);

    expect(Review::where('appointment_id', $appointment->id)->count())->toEqual(1);
});

test('review cannot target a doctor other than the appointment doctor (403, nothing stored)', function () {
    $owner = reviewUser();
    $appointment = reviewOwnAppointment($owner);
    $otherDoctor = Doctor::factory()->create();
    Sanctum::actingAs($owner);

    $this->postJson('/api/reviews', reviewPayload($appointment, ['doctor_id' => $otherDoctor->id]))
        ->assertStatus(403)
        ->assertJson(['message' => 'Review can only be created for the doctor of the reviewed appointment.']);

    expect(Review::count())->toEqual(0);
});

test('store ignores a forged user_id; the reviewer is always the authenticated user', function () {
    $owner = reviewUser();
    $appointment = reviewOwnAppointment($owner);
    $victim = reviewUser();
    Sanctum::actingAs($owner);

    $response = $this->postJson('/api/reviews', reviewPayload($appointment, ['user_id' => $victim->id]));

    $response->assertStatus(201)
        ->assertJsonPath('user.id', $owner->id);

    expect(Review::find($response->json('id'))->user_id)->toEqual($owner->id)
        ->and(Review::where('user_id', $victim->id)->count())->toEqual(0);
});

test('a user cannot review another user appointment (403, existing rule locked)', function () {
    $owner = reviewUser();
    $appointment = reviewOwnAppointment($owner);
    $intruder = reviewUser();
    Sanctum::actingAs($intruder);

    $this->postJson('/api/reviews', reviewPayload($appointment))
        ->assertStatus(403)
        ->assertJson(['message' => 'Review can only be created for your own appointment.']);

    expect(Review::count())->toEqual(0);
});

test('duplicate reviews on the same appointment are rejected with 422', function () {
    $owner = reviewUser();
    $appointment = reviewOwnAppointment($owner);
    Sanctum::actingAs($owner);

    $this->postJson('/api/reviews', reviewPayload($appointment))->assertStatus(201);

    $this->postJson('/api/reviews', reviewPayload($appointment, [
        'rating' => 1,
        'comment' => 'A second, spammy review.',
    ]))
        ->assertStatus(422)
        ->assertJson(['message' => 'This appointment has already been reviewed.']);

    expect(Review::where('appointment_id', $appointment->id)->count())->toEqual(1);
});

test('store rejects invalid rating and oversized comment with 422', function () {
    $owner = reviewUser();
    $appointment = reviewOwnAppointment($owner);
    Sanctum::actingAs($owner);

    $this->postJson('/api/reviews', reviewPayload($appointment, ['rating' => 6]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['rating']);

    $this->postJson('/api/reviews', reviewPayload($appointment, ['comment' => str_repeat('x', 1001)]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['comment']);

    expect(Review::count())->toEqual(0);
});

/*
| 3. Update — owner or admin
*/

test('review update: owner 200 with shape, unrelated 403 (unchanged), admin 200 (moderation)', function () {
    $owner = reviewUser();
    $review = Review::factory()->create(['user_id' => $owner->id, 'rating' => 3]);

    Sanctum::actingAs($owner);
    $this->patchJson("/api/reviews/{$review->id}", ['rating' => 4, 'comment' => 'Better.'])
        ->assertStatus(200)
        ->assertJsonStructure([
            'id', 'rating', 'comment',
            'user' => ['id', 'name'],
            'doctor' => ['id', 'name'],
            'appointment' => ['id', 'appointment_date'],
        ])
        ->assertJsonPath('rating', 4);

    Sanctum::actingAs(reviewUser());
    $this->patchJson("/api/reviews/{$review->id}", ['rating' => 1])->assertStatus(403);
    expect($review->refresh()->rating)->toEqual(4);

    Sanctum::actingAs(reviewAdmin());
    $this->patchJson("/api/reviews/{$review->id}", ['rating' => 2])
        ->assertStatus(200)
        ->assertJsonPath('rating', 2);

    expect($review->refresh()->rating)->toEqual(2);
});

test('review update rejects an oversized comment with 422', function () {
    $owner = reviewUser();
    $review = Review::factory()->create(['user_id' => $owner->id]);
    Sanctum::actingAs($owner);

    $this->patchJson("/api/reviews/{$review->id}", ['comment' => str_repeat('y', 1001)])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['comment']);
});

/*
| 4. Destroy — owner or admin
*/

test('review delete: owner 204, unrelated 403 (record persists), admin 204 (moderation)', function () {
    $owner = reviewUser();
    $review = Review::factory()->create(['user_id' => $owner->id]);

    Sanctum::actingAs(reviewUser());
    $this->deleteJson("/api/reviews/{$review->id}")->assertStatus(403);
    expect(Review::find($review->id))->not->toBeNull();

    Sanctum::actingAs($owner);
    $this->deleteJson("/api/reviews/{$review->id}")->assertStatus(204);
    expect(Review::find($review->id))->toBeNull();

    $second = Review::factory()->create(['user_id' => $owner->id]);
    Sanctum::actingAs(reviewAdmin());
    $this->deleteJson("/api/reviews/{$second->id}")->assertStatus(204);
    expect(Review::find($second->id))->toBeNull();
});

/*
| 5. Schedule GETs — admin-only (batch 7 policy)
*/

test('schedule GETs are admin-only: guest 401, non-admin 403, admin passes the gate', function () {
    $schedule = DoctorSchedule::factory()->create([
        'start_time' => '09:00',
        'end_time' => '17:00',
    ]);

    $this->getJson('/api/schedules')->assertStatus(401);
    $this->getJson("/api/schedules/{$schedule->id}")->assertStatus(401);

    Sanctum::actingAs(reviewUser());
    $this->getJson('/api/schedules')->assertStatus(403);
    $this->getJson("/api/schedules/{$schedule->id}")->assertStatus(403);

    Sanctum::actingAs(reviewAdmin());
    // show has no MySQL-only FIELD() ordering, so 200 is fully verifiable.
    $this->getJson("/api/schedules/{$schedule->id}")
        ->assertStatus(200)
        ->assertJsonStructure([
            'id',
            'doctor' => ['id', 'name'],
            'day', 'start_time', 'end_time', 'is_available', 'max_patients',
        ]);

    // As of batch 8 the day ordering uses a portable CASE expression
    // (was MySQL-only FIELD()), so index is fully verifiable on SQLite.
    $this->getJson('/api/schedules')->assertStatus(200);
});

/*
| 6. Review eligibility — completed appointments only (batch 9)
*/

test('a review is rejected with 422 until the appointment is completed', function (string $status) {
    $owner = reviewUser();
    $appointment = reviewOwnAppointment($owner, ['status' => $status]);
    Sanctum::actingAs($owner);

    $this->postJson('/api/reviews', reviewPayload($appointment))
        ->assertStatus(422)
        ->assertJson(['message' => 'Review can only be created for a completed appointment.']);

    expect(Review::where('appointment_id', $appointment->id)->exists())->toBeFalse();
})->with([
    'pending' => 'pending',
    'cancelled' => 'cancelled',
    'no_show' => 'no_show',
]);

// The completed-appointment happy path (201) and the wrong-doctor (403)
// / other-user (403) rejections are locked by the pre-existing tests in
// sections 2-3 above, whose fixtures now pin status => 'completed'.

test('a review is rejected with 422 until the appointment payment is paid', function () {
    // payment_status is an enum of exactly ['paid', 'unpaid'] (appointments table).
    $owner = reviewUser();
    $appointment = reviewOwnAppointment($owner, [
        'payment_status' => 'unpaid',
        'payment_method' => null,
        'paid_at' => null,
    ]);
    Sanctum::actingAs($owner);

    $this->postJson('/api/reviews', reviewPayload($appointment))
        ->assertStatus(422)
        ->assertJson(['message' => 'Review can only be created for a paid appointment.']);

    expect(Review::where('appointment_id', $appointment->id)->exists())->toBeFalse();
});

test('a completed but unpaid appointment still cannot be reviewed (both conditions required)', function () {
    $owner = reviewUser();
    $appointment = reviewOwnAppointment($owner, [
        'status' => 'completed',
        'payment_status' => 'unpaid',
        'payment_method' => null,
        'paid_at' => null,
    ]);
    Sanctum::actingAs($owner);

    $this->postJson('/api/reviews', reviewPayload($appointment))
        ->assertStatus(422)
        ->assertJson(['message' => 'Review can only be created for a paid appointment.']);

    expect(Review::where('appointment_id', $appointment->id)->exists())->toBeFalse();
});

// The completed + paid happy path (201) stays locked by the section 2
// tests, whose fixture pins status => 'completed' and payment_status
// => 'paid'.

/*
| 7. Database-level uniqueness (batch 9 unique index)
*/

test('the database itself rejects a second review row for the same appointment', function () {
    $owner = reviewUser();
    $appointment = reviewOwnAppointment($owner);

    Review::create([
        'user_id' => $owner->id,
        'doctor_id' => $appointment->doctor_id,
        'appointment_id' => $appointment->id,
        'rating' => 5,
        'comment' => 'First row.',
    ]);

    // Bypasses the controller guard entirely: the unique index on
    // reviews.appointment_id must reject the duplicate insert itself.
    expect(fn () => Review::create([
        'user_id' => $owner->id,
        'doctor_id' => $appointment->doctor_id,
        'appointment_id' => $appointment->id,
        'rating' => 1,
        'comment' => 'Second row.',
    ]))->toThrow(\Illuminate\Database\UniqueConstraintViolationException::class);

    expect(Review::where('appointment_id', $appointment->id)->count())->toEqual(1);
});

test('a duplicate landing between the exists() check and the insert becomes the same 422 (race simulation)', function () {
    $owner = reviewUser();
    $appointment = reviewOwnAppointment($owner);
    Sanctum::actingAs($owner);

    $raced = false;

    Review::creating(function () use (&$raced, $owner, $appointment) {
        if ($raced) {
            return;
        }

        $raced = true;

        // Simulate a concurrent request winning the race inside the
        // same request window (the raw insert fires no model events).
        \Illuminate\Support\Facades\DB::table('reviews')->insert([
            'user_id' => $owner->id,
            'doctor_id' => $appointment->doctor_id,
            'appointment_id' => $appointment->id,
            'rating' => 5,
            'comment' => 'Concurrent winner.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $this->postJson('/api/reviews', reviewPayload($appointment, ['comment' => 'Losing racer.']))
        ->assertStatus(422)
        ->assertJson(['message' => 'This appointment has already been reviewed.']);

    expect(Review::where('appointment_id', $appointment->id)->count())->toEqual(1);
});
