<?php

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Review;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/*
|---------------------------------------------------------------------------
| Task 1 — patient "Write Review" button visibility contract
|---------------------------------------------------------------------------
|
| Locks the exact inputs the patient UI gate (lib/reviews.ts
| canReviewAppointment) reads from the API:
|
|   visible  <=>  status === 'completed'
|              && payment.status === 'paid'
|              && appointment id NOT present in the public review list
|
| Scenarios: completed+paid+not reviewed (visible), completed+unpaid,
| pending+paid, cancelled+paid, no_show+paid, already reviewed, foreign
| patient (403), admin ownership bypass (403), and a successful review
| surfacing in the public list the patient interface renders.
|
| Ownership/duplicate rules stay server-side; the gate never weakens
| them. All tests run against the in-memory SQLite test database.
*/

function rvbUser(): User
{
    return User::factory()->create(['role' => 'user']);
}

function rvbAppointment(User $owner, array $overrides = []): Appointment
{
    $patient = Patient::factory()->create(['user_id' => $owner->id]);

    return Appointment::factory()->create(array_merge([
        'patient_id' => $patient->id,
        'user_id' => $owner->id,
        // Pinned to the gate's happy state; overridable per scenario.
        'status' => 'completed',
        'payment_status' => 'paid',
        'payment_method' => 'cash',
        'paid_at' => now(),
    ], $overrides));
}

/**
 * Fetch the gate's two data sources exactly as the page does:
 * [appointments payload (owner), public reviews payload].
 *
 * Pest binds the calling test closure to the TestCase, so the test
 * instance is passed in explicitly ($t) — plain functions are not bound.
 */
function rvbGatePayloads($t, Appointment $appointment): array
{
    unset($appointment);

    $appointments = json_decode(
        $t->getJson('/api/appointments')->assertStatus(200)->getContent(),
        true
    )['data'];

    $reviews = json_decode(
        $t->getJson('/api/reviews')->assertStatus(200)->getContent(),
        true
    );

    return [$appointments, $reviews];
}

/**
 * Mirror of the frontend gate (lib/reviews.ts) evaluated against the
 * REAL API responses — these tests fail if the backend stops exposing
 * status / payment.status / appointment.id to the patient UI.
 */
function rvbButtonVisible(?array $appointmentRow, array $reviewsPayload, int $appointmentId): bool
{
    if ($appointmentRow === null) {
        return false;
    }

    $alreadyReviewed = collect($reviewsPayload)
        ->pluck('appointment.id')
        ->contains($appointmentId);

    return $appointmentRow['status'] === 'completed'
        && ($appointmentRow['payment']['status'] ?? null) === 'paid'
        && ! $alreadyReviewed;
}

/** The owner's appointment row from the payload (null when absent). */
function rvbRow(array $appointmentsPayload, int $appointmentId): ?array
{
    return collect($appointmentsPayload)->firstWhere('id', $appointmentId);
}

function rvbPayload(Appointment $appointment, array $overrides = []): array
{
    return array_merge([
        'doctor_id' => $appointment->doctor_id,
        'appointment_id' => $appointment->id,
        'rating' => 5,
        'comment' => 'Reviewed from the patient interface.',
    ], $overrides);
}

/*
| 1. Completed + paid + not reviewed → action available
*/

test('gate: completed + paid + not reviewed shows the action and the review succeeds (201)', function () {
    $owner = rvbUser();
    $appointment = rvbAppointment($owner);

    Sanctum::actingAs($owner);

    [$appointments, $reviews] = rvbGatePayloads($this, $appointment);

    expect(rvbButtonVisible(rvbRow($appointments, $appointment->id), $reviews, $appointment->id))->toBeTrue();

    $this->postJson('/api/reviews', rvbPayload($appointment))
        ->assertStatus(201)
        ->assertJsonPath('appointment.id', $appointment->id)
        ->assertJsonPath('user.id', $owner->id);
});

/*
| 2-5. Ineligible status/payment combinations hide the action AND the
|      backend rejects the write (defence in depth).
*/

test('gate: ineligible appointment state hides the action and the write is rejected', function (string $status, string $paymentStatus, string $expectedMessage) {
    $owner = rvbUser();

    $appointment = rvbAppointment($owner, array_merge(
        ['status' => $status],
        $paymentStatus === 'unpaid'
            ? ['payment_status' => 'unpaid', 'payment_method' => null, 'paid_at' => null]
            : ['payment_status' => 'paid', 'payment_method' => 'cash', 'paid_at' => now()]
    ));

    Sanctum::actingAs($owner);

    [$appointments, $reviews] = rvbGatePayloads($this, $appointment);

    expect(rvbButtonVisible(rvbRow($appointments, $appointment->id), $reviews, $appointment->id))->toBeFalse();

    $this->postJson('/api/reviews', rvbPayload($appointment))
        ->assertStatus(422)
        ->assertJson(['message' => $expectedMessage]);

    expect(Review::where('appointment_id', $appointment->id)->exists())->toBeFalse();
})->with([
    'completed + unpaid' => ['completed', 'unpaid', 'Review can only be created for a paid appointment.'],
    'pending + paid' => ['pending', 'paid', 'Review can only be created for a completed appointment.'],
    'cancelled + paid' => ['cancelled', 'paid', 'Review can only be created for a completed appointment.'],
    'no_show + paid' => ['no_show', 'paid', 'Review can only be created for a completed appointment.'],
]);

/*
| 6. Already reviewed → action unavailable (public review list drives it)
*/

test('gate: an already reviewed completed + paid appointment hides the action', function () {
    $owner = rvbUser();
    $appointment = rvbAppointment($owner);

    Review::factory()->create([
        'user_id' => $owner->id,
        'doctor_id' => $appointment->doctor_id,
        'appointment_id' => $appointment->id,
        'rating' => 4,
    ]);

    Sanctum::actingAs($owner);

    [$appointments, $reviews] = rvbGatePayloads($this, $appointment);

    expect(rvbButtonVisible(rvbRow($appointments, $appointment->id), $reviews, $appointment->id))->toBeFalse();

    // Server still rejects the duplicate write regardless of the UI state.
    $this->postJson('/api/reviews', rvbPayload($appointment))
        ->assertStatus(422)
        ->assertJson(['message' => 'This appointment has already been reviewed.']);

    expect(Review::where('appointment_id', $appointment->id)->count())->toEqual(1);
});

/*
| 7. Another patient cannot review the appointment (ownership never bypassed)
*/

test('gate: another patient neither sees the foreign appointment nor can review it', function () {
    $owner = rvbUser();
    $appointment = rvbAppointment($owner);
    $intruder = rvbUser();

    Sanctum::actingAs($intruder);

    // The foreign appointment is not even in the intruder's list …
    $foreignRows = json_decode(
        $this->getJson('/api/appointments')->assertStatus(200)->getContent(),
        true
    )['data'];
    expect(collect($foreignRows)->firstWhere('id', $appointment->id))->toBeNull();

    // … and a direct write is refused.
    $this->postJson('/api/reviews', rvbPayload($appointment))
        ->assertStatus(403)
        ->assertJson(['message' => 'Review can only be created for your own appointment.']);

    expect(Review::where('appointment_id', $appointment->id)->exists())->toBeFalse();
});

test('gate: an admin cannot bypass the patient ownership rule on store', function () {
    $owner = rvbUser();
    $appointment = rvbAppointment($owner);
    $admin = User::factory()->create(['role' => 'admin']);

    Sanctum::actingAs($admin);

    $this->postJson('/api/reviews', rvbPayload($appointment))
        ->assertStatus(403)
        ->assertJson(['message' => 'Review can only be created for your own appointment.']);

    expect(Review::where('appointment_id', $appointment->id)->exists())->toBeFalse();
});

/*
| 8. A successful review appears correctly in the patient interface
*/

test('a submitted review immediately appears in the public list and closes the gate', function () {
    $owner = rvbUser();
    $appointment = rvbAppointment($owner);

    Sanctum::actingAs($owner);

    [$appointments, $before] = rvbGatePayloads($this, $appointment);
    expect(rvbButtonVisible(rvbRow($appointments, $appointment->id), $before, $appointment->id))->toBeTrue();

    $this->postJson('/api/reviews', rvbPayload($appointment, ['rating' => 4]))
        ->assertStatus(201);

    // Re-read the review payload exactly as the page does after submission.
    [, $after] = rvbGatePayloads($this, $appointment);

    expect(rvbButtonVisible(rvbRow($appointments, $appointment->id), $after, $appointment->id))->toBeFalse();

    $listed = collect($after)->firstWhere('appointment.id', $appointment->id);

    expect($listed)->not->toBeNull()
        ->and($listed['rating'])->toEqual(4)
        ->and($listed['user']['id'])->toEqual($owner->id)
        ->and($listed['doctor']['id'])->toEqual($appointment->doctor_id);
});
