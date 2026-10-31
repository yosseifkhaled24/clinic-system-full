<?php

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\Patient;
use App\Models\Review;
use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Laravel\Sanctum\Sanctum;
use Maatwebsite\Excel\Facades\Excel;

/*
 |---------------------------------------------------------------------------
 | Clinic workflow suite — features A-G
 |---------------------------------------------------------------------------
 |
 |   A  queue lifecycle: start / skip(no_show) / complete-advances-queue
 |   B  complete + payment in one transaction (no double pay, no repeat)
 |   C  end-of-day cleanup command (delete unpaid misses, retain paid as
 |      no_show, flag completed+unpaid, idempotent, dry-run, scheduled)
 |   D  admin index chronological + queue-number ordering
 |   E  daily revenue export (completed+paid only, totals + breakdown)
 |   F  review rules preserved (integration check after completion)
 |   G  admin update() non-reschedule branch is transaction-wrapped
 |
 | Actors always use an EXPLICIT role (UserFactory randomizes 'admin').
 | All tests run on the isolated in-memory SQLite database from phpunit.xml;
 | the cleanup tests use factory data on the isolated DB only — never real
 | data — and the command is never pointed at anything but :memory:.
 |
 | Helper names carry the `wf` prefix to avoid Pest's global function
 | namespace colliding with the other test files.
 */

function wfAdmin(): User
{
    return User::factory()->create(['role' => 'admin']);
}

function wfUser(): User
{
    return User::factory()->create([
        'role' => 'user',
        'phone' => '010' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
    ]);
}

function wfSchedule(Doctor $doctor, string $date, array $overrides = []): DoctorSchedule
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
 * Factory appointments for a specific doctor/date MUST pin queue_number
 * (the factory randomizes 1-30 which would collide on the unique index)
 * and payment fields (the factory randomizes paid/unpaid).
 */
function wfAppointment(User $owner, array $overrides = [], ?Patient $patient = null): Appointment
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

/*
 | ---------------------------------------------------------------------------
 | B — complete + payment workflow
 | ---------------------------------------------------------------------------
 */

test('B: completing an unpaid appointment records payment and completion atomically', function () {
    $doctor = Doctor::factory()->create();
    $today = now()->toDateString();

    $appointment = wfAppointment(wfUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => $today,
        'queue_number' => 1,
        'status' => 'in_progress',
        'service_price' => 250,
    ]);

    Sanctum::actingAs(wfAdmin());

    $this->patchJson("/api/appointments/{$appointment->id}/complete", [
        'payment_method' => 'cash',
    ])
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.payment.status', 'paid')
        ->assertJsonPath('data.payment.method', 'cash');

    $appointment->refresh();

    expect($appointment->status)->toBe('completed')
        ->and($appointment->payment_status)->toBe('paid')
        ->and($appointment->payment_method)->toBe('cash')
        ->and($appointment->paid_at)->not->toBeNull();
});

test('B: an already-paid appointment completes WITHOUT recording a second payment', function () {
    $doctor = Doctor::factory()->create();
    $today = now()->toDateString();
    $paidAt = now()->subHour();

    // Paid earlier — e.g. by the signed Stripe webhook or a cash payment.
    $appointment = wfAppointment(wfUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => $today,
        'queue_number' => 1,
        'status' => 'in_progress',
        'payment_status' => 'paid',
        'payment_method' => 'stripe',
        'paid_at' => $paidAt,
    ]);

    Sanctum::actingAs(wfAdmin());

    // No payment_method is sent: the payment is already settled.
    $this->patchJson("/api/appointments/{$appointment->id}/complete", [])
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.payment.method', 'stripe');

    $appointment->refresh();

    expect($appointment->status)->toBe('completed')
        ->and($appointment->payment_status)->toBe('paid')
        ->and($appointment->payment_method)->toBe('stripe')
        ->and($appointment->paid_at->toDateTimeString())->toBe($paidAt->toDateTimeString());
});

test('B: completing an unpaid appointment without a payment method is rejected with 422', function () {
    $doctor = Doctor::factory()->create();
    $today = now()->toDateString();

    $appointment = wfAppointment(wfUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => $today,
        'queue_number' => 1,
        'status' => 'in_progress',
    ]);

    Sanctum::actingAs(wfAdmin());

    $this->patchJson("/api/appointments/{$appointment->id}/complete", [])
        ->assertStatus(422)
        ->assertJson(['message' => 'Payment method is required to complete this appointment.']);

    $appointment->refresh();

    expect($appointment->status)->toBe('in_progress')
        ->and($appointment->payment_status)->toBe('unpaid');
});

test('B: repeated completion is rejected with 422 and changes nothing', function () {
    $doctor = Doctor::factory()->create();
    $today = now()->toDateString();

    $appointment = wfAppointment(wfUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => $today,
        'queue_number' => 1,
        'status' => 'completed',
        'payment_status' => 'paid',
        'payment_method' => 'cash',
        'paid_at' => now(),
    ]);

    Sanctum::actingAs(wfAdmin());

    $this->patchJson("/api/appointments/{$appointment->id}/complete", [
        'payment_method' => 'visa',
    ])
        ->assertStatus(422)
        ->assertJson(['message' => 'Appointment already completed.']);

    // The original payment was not overwritten by the second attempt.
    expect($appointment->refresh()->payment_method)->toBe('cash');
});

test('B: complete rejects an unsupported payment method with 422 (nothing stored)', function () {
    $doctor = Doctor::factory()->create();
    $today = now()->toDateString();

    $appointment = wfAppointment(wfUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => $today,
        'queue_number' => 1,
        'status' => 'in_progress',
    ]);

    Sanctum::actingAs(wfAdmin());

    $this->patchJson("/api/appointments/{$appointment->id}/complete", [
        'payment_method' => 'bitcoin',
    ])->assertStatus(422);

    $appointment->refresh();

    expect($appointment->status)->toBe('in_progress')
        ->and($appointment->payment_status)->toBe('unpaid')
        ->and($appointment->payment_method)->toBeNull();
});

test('B: complete clears a leftover investigation flag (completed+paid is healthy)', function () {
    $doctor = Doctor::factory()->create();
    $today = now()->toDateString();

    $appointment = wfAppointment(wfUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => $today,
        'queue_number' => 1,
        'status' => 'arrived',
        'needs_investigation' => true,
    ]);

    Sanctum::actingAs(wfAdmin());

    $this->patchJson("/api/appointments/{$appointment->id}/complete", [
        'payment_method' => 'cash',
    ])->assertStatus(200);

    expect($appointment->refresh()->needs_investigation)->toBeFalse();
});

test('B: complete is admin-only (owner patient gets 403)', function () {
    $doctor = Doctor::factory()->create();
    $today = now()->toDateString();
    $owner = wfUser();

    $appointment = wfAppointment($owner, [
        'doctor_id' => $doctor->id,
        'appointment_date' => $today,
        'queue_number' => 1,
        'status' => 'in_progress',
    ]);

    Sanctum::actingAs($owner);
    $this->patchJson("/api/appointments/{$appointment->id}/complete", [
        'payment_method' => 'cash',
    ])->assertStatus(403);

    $appointment->refresh();
    expect($appointment->status)->toBe('in_progress')
        ->and($appointment->payment_status)->toBe('unpaid');
});

test('B: complete requires authentication (guest 401)', function () {
    $doctor = Doctor::factory()->create();
    $appointment = wfAppointment(wfUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => now()->toDateString(),
        'queue_number' => 1,
        'status' => 'in_progress',
    ]);

    $this->patchJson("/api/appointments/{$appointment->id}/complete", [
        'payment_method' => 'cash',
    ])->assertStatus(401);
});

/*
 | ---------------------------------------------------------------------------
 | A — queue lifecycle: start / skip / complete advances the queue
 | ---------------------------------------------------------------------------
 */

test('A: start promotes the next patient, skip marks no_show without reinsertion, complete advances the queue', function () {
    $doctor = Doctor::factory()->create();
    $today = now()->toDateString();

    wfSchedule($doctor, $today);

    $first = wfAppointment(wfUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => $today,
        'queue_number' => 1,
        'status' => 'pending',
    ]);
    $second = wfAppointment(wfUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => $today,
        'queue_number' => 2,
        'status' => 'pending',
    ]);
    $third = wfAppointment(wfUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => $today,
        'queue_number' => 3,
        'status' => 'pending',
    ]);

    Sanctum::actingAs(wfAdmin());

    // Start the first consultation.
    $this->patchJson("/api/appointments/{$first->id}/status", ['status' => 'in_progress'])
        ->assertStatus(200);

    $queue = $this->getJson("/api/queue/{$doctor->id}")->assertStatus(200);
    expect($queue->json('current.id'))->toEqual($first->id);

    // Skip the second patient -> no_show, not waiting, not reinserted.
    $this->patchJson("/api/appointments/{$second->id}/status", ['status' => 'no_show'])
        ->assertStatus(200);

    $queue = $this->getJson("/api/queue/{$doctor->id}")->assertStatus(200);

    $waitingIds = collect($queue->json('waiting'))->pluck('id')->all();
    $finishedIds = collect($queue->json('finished'))->pluck('id')->all();

    expect($second->refresh()->status)->toBe('no_show')
        ->and($waitingIds)->not->toContain($second->id)
        ->and($finishedIds)->toContain($second->id);

    // Complete the first patient WITH payment -> the queue advances:
    // current clears, the third patient is still waiting and startable.
    $this->patchJson("/api/appointments/{$first->id}/complete", [
        'payment_method' => 'cash',
    ])->assertStatus(200);

    $queue = $this->getJson("/api/queue/{$doctor->id}")->assertStatus(200);

    expect($queue->json('current'))->toBeNull()
        ->and($first->refresh()->status)->toBe('completed')
        ->and($first->refresh()->payment_status)->toBe('paid');

    // The next patient starts normally.
    $this->patchJson("/api/appointments/{$third->id}/status", ['status' => 'in_progress'])
        ->assertStatus(200);

    $queue = $this->getJson("/api/queue/{$doctor->id}")->assertStatus(200);
    expect($queue->json('current.id'))->toEqual($third->id);
});

test('A: starting a second consultation atomically demotes the current one (single in_progress)', function () {
    $doctor = Doctor::factory()->create();
    $today = now()->toDateString();

    $first = wfAppointment(wfUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => $today,
        'queue_number' => 1,
        'status' => 'in_progress',
    ]);
    $second = wfAppointment(wfUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => $today,
        'queue_number' => 2,
        'status' => 'arrived',
    ]);

    Sanctum::actingAs(wfAdmin());

    $this->patchJson("/api/appointments/{$second->id}/status", ['status' => 'in_progress'])
        ->assertStatus(200);

    $queue = $this->getJson("/api/queue/{$doctor->id}")->assertStatus(200);

    expect($first->refresh()->status)->toBe('arrived')
        ->and($second->refresh()->status)->toBe('in_progress')
        ->and($queue->json('current.id'))->toEqual($second->id);
});

test('A: skipping a patient releases the capacity slot (rebookable)', function () {
    $doctor = Doctor::factory()->create();
    $today = now()->addDays(2)->toDateString();
    $owner = wfUser();

    wfSchedule($doctor, $today, ['max_patients' => 1]);

    $appointment = wfAppointment($owner, [
        'doctor_id' => $doctor->id,
        'appointment_date' => $today,
        'queue_number' => 1,
        'status' => 'pending',
    ]);

    Sanctum::actingAs(wfAdmin());
    $this->patchJson("/api/appointments/{$appointment->id}/status", ['status' => 'no_show'])
        ->assertStatus(200);

    // The slot is free again: the same patient rebooks the same doctor/day.
    Sanctum::actingAs($owner);

    $service = Service::factory()->create();

    $this->postJson('/api/appointments', [
        'doctor_id' => $doctor->id,
        'service_id' => $service->id,
        'appointment_date' => $today,
    ])->assertStatus(201);
});

/*
 | ---------------------------------------------------------------------------
 | G — update() non-reschedule branch is transaction-wrapped
 | ---------------------------------------------------------------------------
 */

test('G: admin status-only PATCH demotes the previous in_progress atomically', function () {
    $doctor = Doctor::factory()->create();
    $today = now()->toDateString();

    $current = wfAppointment(wfUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => $today,
        'queue_number' => 1,
        'status' => 'in_progress',
    ]);
    $next = wfAppointment(wfUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => $today,
        'queue_number' => 2,
        'status' => 'arrived',
    ]);

    Sanctum::actingAs(wfAdmin());

    // No doctor_id / appointment_date in the payload: the non-reschedule
    // branch of update() runs, and it must demote inside its transaction.
    $this->patchJson("/api/appointments/{$next->id}", ['status' => 'in_progress'])
        ->assertStatus(200)
        ->assertJsonPath('status', 'in_progress');

    expect($current->refresh()->status)->toBe('arrived')
        ->and($next->refresh()->status)->toBe('in_progress');
});

test('G: a notes-only edit does not disturb the queue', function () {
    $doctor = Doctor::factory()->create();
    $today = now()->toDateString();

    $current = wfAppointment(wfUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => $today,
        'queue_number' => 1,
        'status' => 'in_progress',
    ]);

    Sanctum::actingAs(wfAdmin());

    $this->patchJson("/api/appointments/{$current->id}", ['notes' => 'Follow-up needed'])
        ->assertStatus(200);

    $current->refresh();
    expect($current->status)->toBe('in_progress')
        ->and($current->notes)->toBe('Follow-up needed');
});

/*
 | ---------------------------------------------------------------------------
 | D — admin index ordering
 | ---------------------------------------------------------------------------
 */

test('D: admin appointment list is chronological with queue order inside a day', function () {
    $doctor = Doctor::factory()->create();
    $otherDoctor = Doctor::factory()->create();
    $dayOne = now()->addDays(3)->toDateString();
    $dayTwo = now()->addDays(4)->toDateString();

    // Created out of order on purpose.
    $late = wfAppointment(wfUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => $dayTwo,
        'queue_number' => 2,
    ]);
    $early = wfAppointment(wfUser(), [
        'doctor_id' => $otherDoctor->id,
        'appointment_date' => $dayOne,
        'queue_number' => 5,
    ]);
    $first = wfAppointment(wfUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => $dayOne,
        'queue_number' => 1,
    ]);

    Sanctum::actingAs(wfAdmin());

    $ids = collect($this->getJson('/api/appointments')->assertStatus(200)->json())
        ->pluck('id')->all();

    // Chronological across days; within the same day grouped by doctor,
    // queue-number order inside each doctor/day.
    expect(array_search($first->id, $ids))->toBeLessThan(array_search($early->id, $ids))
        ->and(array_search($early->id, $ids))->toBeLessThan(array_search($late->id, $ids));
});

/*
 | ---------------------------------------------------------------------------
 | C — end-of-day cleanup command
 | ---------------------------------------------------------------------------
 */

test('C: expired unpaid misses are deleted while expired paid misses are retained as no_show', function () {
    $doctor = Doctor::factory()->create();
    $yesterday = now()->subDay()->toDateString();

    $unpaidMiss = wfAppointment(wfUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => $yesterday,
        'queue_number' => 1,
        'status' => 'pending',
        'payment_status' => 'unpaid',
    ]);
    $paidMiss = wfAppointment(wfUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => $yesterday,
        'queue_number' => 2,
        'status' => 'arrived',
        'payment_status' => 'paid',
        'payment_method' => 'visa',
        'paid_at' => now()->subDay(),
    ]);
    $completedPaid = wfAppointment(wfUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => $yesterday,
        'queue_number' => 3,
        'status' => 'completed',
        'payment_status' => 'paid',
        'payment_method' => 'cash',
        'paid_at' => now()->subDay(),
    ]);
    $cancelled = wfAppointment(wfUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => $yesterday,
        'queue_number' => 4,
        'status' => 'cancelled',
        'payment_status' => 'unpaid',
    ]);
    // Today and tomorrow are NOT expired — untouched.
    $todayAppt = wfAppointment(wfUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => now()->toDateString(),
        'queue_number' => 1,
        'status' => 'pending',
    ]);

    $this->artisan('appointments:end-of-day')
        ->assertExitCode(0);

    expect(Appointment::find($unpaidMiss->id))->toBeNull();

    $paidMiss->refresh();
    expect($paidMiss->status)->toBe('no_show')
        ->and($paidMiss->payment_status)->toBe('paid')
        ->and($paidMiss->payment_method)->toBe('visa')
        ->and($paidMiss->paid_at)->not->toBeNull();

    $completedPaid->refresh();
    expect($completedPaid->status)->toBe('completed')
        ->and($completedPaid->payment_status)->toBe('paid')
        ->and($completedPaid->needs_investigation)->toBeFalse();

    expect($cancelled->refresh()->status)->toBe('cancelled')
        ->and($todayAppt->refresh()->status)->toBe('pending');
});

test('C: completed unpaid appointments are flagged for investigation, never deleted', function () {
    $doctor = Doctor::factory()->create();
    $yesterday = now()->subDays(2)->toDateString();

    $completedUnpaid = wfAppointment(wfUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => $yesterday,
        'queue_number' => 1,
        'status' => 'completed',
        'payment_status' => 'unpaid',
    ]);

    $this->artisan('appointments:end-of-day')->assertExitCode(0);

    $completedUnpaid->refresh();

    expect($completedUnpaid)->not->toBeNull()
        ->and($completedUnpaid->needs_investigation)->toBeTrue()
        ->and($completedUnpaid->payment_status)->toBe('unpaid');
});

test('C: the command is idempotent — a second run changes nothing further', function () {
    $doctor = Doctor::factory()->create();
    $yesterday = now()->subDay()->toDateString();

    $unpaidMiss = wfAppointment(wfUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => $yesterday,
        'queue_number' => 1,
        'status' => 'pending',
        'payment_status' => 'unpaid',
    ]);
    $paidMiss = wfAppointment(wfUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => $yesterday,
        'queue_number' => 2,
        'status' => 'pending',
        'payment_status' => 'paid',
        'payment_method' => 'cash',
        'paid_at' => now()->subDay(),
    ]);
    $completedUnpaid = wfAppointment(wfUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => $yesterday,
        'queue_number' => 3,
        'status' => 'completed',
        'payment_status' => 'unpaid',
    ]);

    $this->artisan('appointments:end-of-day')->assertExitCode(0);

    $snapshot = Appointment::orderBy('id')->get()
        ->map(fn (Appointment $a) => [
            $a->id,
            $a->status,
            $a->payment_status,
            (bool) $a->needs_investigation,
        ])->all();

    $this->artisan('appointments:end-of-day')->assertExitCode(0);

    $afterSecond = Appointment::orderBy('id')->get()
        ->map(fn (Appointment $a) => [
            $a->id,
            $a->status,
            $a->payment_status,
            (bool) $a->needs_investigation,
        ])->all();

    expect($afterSecond)->toEqual($snapshot)
        ->and(Appointment::find($unpaidMiss->id))->toBeNull()
        ->and($paidMiss->refresh()->status)->toBe('no_show')
        ->and($completedUnpaid->refresh()->needs_investigation)->toBeTrue();
});

test('C: dry-run reports without writing anything', function () {
    $doctor = Doctor::factory()->create();
    $yesterday = now()->subDay()->toDateString();

    $unpaidMiss = wfAppointment(wfUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => $yesterday,
        'queue_number' => 1,
        'status' => 'pending',
        'payment_status' => 'unpaid',
    ]);
    $completedUnpaid = wfAppointment(wfUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => $yesterday,
        'queue_number' => 2,
        'status' => 'completed',
        'payment_status' => 'unpaid',
    ]);

    $this->artisan('appointments:end-of-day', ['--dry-run' => true])
        ->assertExitCode(0);

    expect(Appointment::find($unpaidMiss->id))->not->toBeNull()
        ->and($unpaidMiss->refresh()->status)->toBe('pending')
        ->and($completedUnpaid->refresh()->needs_investigation)->toBeFalse();
});

test('C: an unpaid miss carrying a review is never deleted (review data preserved)', function () {
    $doctor = Doctor::factory()->create();
    $yesterday = now()->subDay()->toDateString();
    $owner = wfUser();

    $appointment = wfAppointment($owner, [
        'doctor_id' => $doctor->id,
        'appointment_date' => $yesterday,
        'queue_number' => 1,
        'status' => 'pending',
        'payment_status' => 'unpaid',
    ]);

    // Reviews normally require completed+paid; this row models legacy or
    // imported data and must still be protected from cascade deletion.
    Review::create([
        'user_id' => $owner->id,
        'doctor_id' => $doctor->id,
        'appointment_id' => $appointment->id,
        'rating' => 5,
        'comment' => 'Legacy review',
    ]);

    $this->artisan('appointments:end-of-day')->assertExitCode(0);

    expect(Appointment::find($appointment->id))->not->toBeNull()
        ->and(Review::where('appointment_id', $appointment->id)->count())->toBe(1);
});

test('C: an explicit --date cutoff is honored and invalid dates are rejected', function () {
    $doctor = Doctor::factory()->create();
    $threeDaysAgo = now()->subDays(3)->toDateString();
    $twoDaysAgo = now()->subDays(2)->toDateString();

    $older = wfAppointment(wfUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => $threeDaysAgo,
        'queue_number' => 1,
        'status' => 'pending',
        'payment_status' => 'unpaid',
    ]);
    $newer = wfAppointment(wfUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => $twoDaysAgo,
        'queue_number' => 2,
        'status' => 'pending',
        'payment_status' => 'unpaid',
    ]);

    // Cutoff of "two days ago": only the older booking is expired.
    $this->artisan('appointments:end-of-day', ['--date' => $twoDaysAgo])
        ->assertExitCode(0);

    expect(Appointment::find($older->id))->toBeNull()
        ->and(Appointment::find($newer->id))->not->toBeNull();

    $this->artisan('appointments:end-of-day', ['--date' => 'not-a-date'])
        ->assertExitCode(1);
});

test('C: the cleanup command is scheduled daily at 00:10 Cairo time', function () {
    $events = app(Schedule::class)->events();

    $found = collect($events)->first(
        fn ($event) => str_contains($event->command ?? '', 'appointments:end-of-day')
    );

    expect($found)->not->toBeNull()
        ->and($found->timezone)->toBe('Africa/Cairo');
});

/*
 | ---------------------------------------------------------------------------
 | E — daily revenue export
 | ---------------------------------------------------------------------------
 */

test('E: the revenue export downloads an xlsx for the Cairo clinic day', function () {
    Sanctum::actingAs(wfAdmin());

    Excel::fake();

    $this->get('/api/appointments/revenue/export')
        ->assertStatus(200);

    Excel::assertDownloaded('revenue-' . now()->toDateString() . '.xlsx');
});

test('E: only completed + paid appointments are counted, with totals and method breakdown', function () {
    $doctor = Doctor::factory()->create();
    $today = now()->toDateString();

    $cashPaid = wfAppointment(wfUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => $today,
        'queue_number' => 1,
        'status' => 'completed',
        'payment_status' => 'paid',
        'payment_method' => 'cash',
        'paid_at' => now(),
        'service_price' => 100,
    ]);
    $stripePaid = wfAppointment(wfUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => $today,
        'queue_number' => 2,
        'status' => 'completed',
        'payment_status' => 'paid',
        'payment_method' => 'stripe',
        'paid_at' => now(),
        'service_price' => 250.50,
    ]);
    // Excluded rows:
    $completedUnpaid = wfAppointment(wfUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => $today,
        'queue_number' => 3,
        'status' => 'completed',
        'payment_status' => 'unpaid',
        'service_price' => 999,
        'needs_investigation' => true,
    ]);
    $paidNotCompleted = wfAppointment(wfUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => $today,
        'queue_number' => 4,
        'status' => 'in_progress',
        'payment_status' => 'paid',
        'payment_method' => 'cash',
        'paid_at' => now(),
        'service_price' => 500,
    ]);
    // Another day is out of scope for the default (today) export.
    $otherDay = wfAppointment(wfUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => now()->addDay()->toDateString(),
        'queue_number' => 1,
        'status' => 'completed',
        'payment_status' => 'paid',
        'payment_method' => 'cash',
        'paid_at' => now(),
        'service_price' => 75,
    ]);

    $export = new \App\Exports\DailyRevenueExport(null);

    $rowIds = $export->query()->get()->pluck('id')->all();

    expect($rowIds)->toContain($cashPaid->id, $stripePaid->id)
        ->and($rowIds)->not->toContain($completedUnpaid->id, $paidNotCompleted->id, $otherDay->id);

    $summary = $export->summary();

    expect($summary['appointments'])->toBe(2)
        ->and($summary['total'])->toEqual(350.50);

    $byMethod = collect($summary['by_method'])->keyBy('payment_method');

    expect($byMethod['cash']->appointments)->toBe(1)
        ->and((float) $byMethod['cash']->total)->toEqual(100.0)
        ->and($byMethod['stripe']->appointments)->toBe(1)
        ->and((float) $byMethod['stripe']->total)->toEqual(250.5);
});

test('E: an explicit ?date= selects that day instead of today', function () {
    $doctor = Doctor::factory()->create();
    $targetDay = now()->addDays(2)->toDateString();

    $target = wfAppointment(wfUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => $targetDay,
        'queue_number' => 1,
        'status' => 'completed',
        'payment_status' => 'paid',
        'payment_method' => 'cash',
        'paid_at' => now(),
        'service_price' => 400,
    ]);

    $export = new \App\Exports\DailyRevenueExport($targetDay);

    expect($export->query()->get()->pluck('id')->all())->toContain($target->id)
        ->and($export->summary()['total'])->toEqual(400.0);

    Sanctum::actingAs(wfAdmin());
    Excel::fake();

    $this->get('/api/appointments/revenue/export?date=' . $targetDay)
        ->assertStatus(200);

    Excel::assertDownloaded('revenue-' . $targetDay . '.xlsx');
});

test('E: the revenue export is admin-only', function () {
    Sanctum::actingAs(wfUser());
    $this->getJson('/api/appointments/revenue/export')->assertStatus(403);
});

test('E: the revenue export requires authentication (guest 401)', function () {
    $this->getJson('/api/appointments/revenue/export')->assertStatus(401);
});

test('E: the revenue sheet actually renders with the totals footer', function () {
    $doctor = Doctor::factory()->create();
    $today = now()->toDateString();

    wfAppointment(wfUser(), [
        'doctor_id' => $doctor->id,
        'appointment_date' => $today,
        'queue_number' => 1,
        'status' => 'completed',
        'payment_status' => 'paid',
        'payment_method' => 'cash',
        'paid_at' => now(),
        'service_price' => 120,
    ]);

    Sanctum::actingAs(wfAdmin());

    $response = $this->get('/api/appointments/revenue/export')
        ->assertStatus(200);

    // Parse the streamed workbook back and verify the footer rows.
    $tmp = tempnam(sys_get_temp_dir(), 'rev') . '.xlsx';
    file_put_contents($tmp, $response->streamedContent());

    $rows = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp)
        ->getActiveSheet()
        ->toArray();

    $flat = collect($rows)->flatten(1)->map(fn ($cell) => (string) $cell)->all();

    expect($flat)->toContain('Total Collected')
        ->and($flat)->toContain('Payment Method Breakdown')
        ->and(collect($flat)->contains(fn ($cell) => str_contains($cell, '120')))->toBeTrue();

    @unlink($tmp);
});

/*
 | ---------------------------------------------------------------------------
 | F — review rules preserved (integration through the new completion path)
 | ---------------------------------------------------------------------------
 */

test('F: the owner can review right after a completed+paid workflow, exactly once', function () {
    $doctor = Doctor::factory()->create();
    $today = now()->toDateString();
    $owner = wfUser();

    $appointment = wfAppointment($owner, [
        'doctor_id' => $doctor->id,
        'appointment_date' => $today,
        'queue_number' => 1,
        'status' => 'in_progress',
    ]);

    Sanctum::actingAs(wfAdmin());
    $this->patchJson("/api/appointments/{$appointment->id}/complete", [
        'payment_method' => 'cash',
    ])->assertStatus(200);

    Sanctum::actingAs($owner);

    $this->postJson('/api/reviews', [
        'doctor_id' => $doctor->id,
        'appointment_id' => $appointment->id,
        'rating' => 5,
        'comment' => 'Great consultation.',
    ])->assertStatus(201);

    // The one-review-per-appointment rule still holds.
    $this->postJson('/api/reviews', [
        'doctor_id' => $doctor->id,
        'appointment_id' => $appointment->id,
        'rating' => 4,
    ])->assertStatus(422)
        ->assertJson(['message' => 'This appointment has already been reviewed.']);
});
