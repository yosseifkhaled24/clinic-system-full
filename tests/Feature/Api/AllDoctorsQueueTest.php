<?php

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/*
 |---------------------------------------------------------------------------
 | All-doctors queue control center suite
 |---------------------------------------------------------------------------
 |
 |   - GET /api/queue returns every doctor with independent buckets
 |   - current / waiting / finished selection & ordering
 |   - the single-doctor endpoint keeps its exact contract
 |   - independence of per-doctor queues
 |   - the Next-patient workflow's backend invariants:
 |       * no current -> the first ARRIVED patient is startable
 |       * unfinished current -> exactly one in_progress survives (the
 |         frontend gates the Next button; the backend enforces single
 |         current per doctor/day)
 |       * next after complete+payment
 |       * skipped (no_show) patients never reappear as eligible
 |   - authorization (guest 401, non-admin 403, admin 200)
 |   - query efficiency (no N+1)
 |
 | All tests run on the isolated in-memory SQLite database from phpunit.xml.
 | Helper names carry the `aq` prefix to avoid Pest's global function
 | namespace colliding with the other test files.
 */

function aqAdmin(): User
{
    return User::factory()->create(['role' => 'admin']);
}

function aqUser(): User
{
    return User::factory()->create([
        'role' => 'user',
        'phone' => '010' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
    ]);
}

/** Factory appointment with pinned payment/queue fields (factory randomizes). */
function aqAppointment(User $owner, array $overrides = [], ?Patient $patient = null): Appointment
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
        'appointment_date' => Carbon::today()->toDateString(),
    ], $overrides));
}

/** Find the /queue payload entry for a doctor id. */
function aqQueueFor(array $queues, Doctor $doctor): array
{
    $entry = collect($queues)->firstWhere('doctor.id', $doctor->id);

    expect($entry)->not->toBeNull();

    return $entry;
}

/*
 | ---------------------------------------------------------------------------
 | Retrieval: all doctors, correct buckets, ordering
 | ---------------------------------------------------------------------------
 */

test('GET /api/queue returns every doctor with independent buckets, ordered by name', function () {
    $alpha = Doctor::factory()->create(['name' => 'Dr. Alpha']);
    $zulu = Doctor::factory()->create(['name' => 'Dr. Zulu']);
    $empty = Doctor::factory()->create(['name' => 'Dr. Mid']); // no patients

    aqAppointment(aqUser(), [
        'doctor_id' => $alpha->id,
        'queue_number' => 1,
        'status' => 'in_progress',
    ]);
    aqAppointment(aqUser(), [
        'doctor_id' => $alpha->id,
        'queue_number' => 2,
        'status' => 'arrived',
    ]);
    aqAppointment(aqUser(), [
        'doctor_id' => $zulu->id,
        'queue_number' => 1,
        'status' => 'pending',
    ]);

    Sanctum::actingAs(aqAdmin());

    $response = $this->getJson('/api/queue')->assertStatus(200)->json();

    // Every doctor present, ordered by name, with identity attached.
    expect(collect($response)->pluck('doctor.name')->all())
        ->toBe(['Dr. Alpha', 'Dr. Mid', 'Dr. Zulu']);

    $alphaQueue = aqQueueFor($response, $alpha);
    expect($alphaQueue['doctor']['specialty'])->not->toBeNull()
        ->and($alphaQueue['current']['status'])->toBe('in_progress')
        ->and(collect($alphaQueue['waiting'])->pluck('status')->all())->toBe(['arrived']);

    // A doctor with no patients gets clean empty buckets.
    $emptyQueue = aqQueueFor($response, $empty);
    expect($emptyQueue['current'])->toBeNull()
        ->and($emptyQueue['waiting'])->toBe([])
        ->and($emptyQueue['finished'])->toBe([]);

    $zuluQueue = aqQueueFor($response, $zulu);
    expect($zuluQueue['current'])->toBeNull()
        ->and(collect($zuluQueue['waiting'])->pluck('status')->all())->toBe(['pending']);
});

test('current / waiting / finished selection: skipped and cancelled never sit in waiting', function () {
    $doctor = Doctor::factory()->create();

    aqAppointment(aqUser(), ['doctor_id' => $doctor->id, 'queue_number' => 1, 'status' => 'cancelled']);
    aqAppointment(aqUser(), ['doctor_id' => $doctor->id, 'queue_number' => 2, 'status' => 'in_progress']);
    aqAppointment(aqUser(), ['doctor_id' => $doctor->id, 'queue_number' => 3, 'status' => 'arrived']);
    aqAppointment(aqUser(), ['doctor_id' => $doctor->id, 'queue_number' => 4, 'status' => 'pending']);
    aqAppointment(aqUser(), ['doctor_id' => $doctor->id, 'queue_number' => 5, 'status' => 'no_show']);
    aqAppointment(aqUser(), ['doctor_id' => $doctor->id, 'queue_number' => 6, 'status' => 'completed']);

    Sanctum::actingAs(aqAdmin());

    $entry = aqQueueFor($this->getJson('/api/queue')->assertStatus(200)->json(), $doctor);

    expect($entry['current']['queue_number'])->toBe(2)
        ->and(collect($entry['waiting'])->pluck('queue_number')->all())->toBe([3, 4])
        ->and(collect($entry['finished'])->pluck('queue_number')->all())->toContain(1, 5, 6)
        // Date-only serialization rides along on every row.
        ->and($entry['current']['appointment_date'])->toBe(Carbon::today()->toDateString());
});

test('only today’s appointments appear; other days are excluded', function () {
    $doctor = Doctor::factory()->create();

    aqAppointment(aqUser(), [
        'doctor_id' => $doctor->id,
        'queue_number' => 1,
        'status' => 'pending',
        'appointment_date' => now()->addDay()->toDateString(),
    ]);
    aqAppointment(aqUser(), [
        'doctor_id' => $doctor->id,
        'queue_number' => 2,
        'status' => 'pending',
    ]);

    Sanctum::actingAs(aqAdmin());

    $entry = aqQueueFor($this->getJson('/api/queue')->json(), $doctor);

    expect(collect($entry['waiting'])->pluck('queue_number')->all())->toBe([2]);
});

/*
 | ---------------------------------------------------------------------------
 | Compatibility: the existing single-doctor endpoint is untouched
 | ---------------------------------------------------------------------------
 */

test('GET /api/queue/{doctor} keeps its exact contract (no doctor key added)', function () {
    $doctor = Doctor::factory()->create();

    aqAppointment(aqUser(), ['doctor_id' => $doctor->id, 'queue_number' => 1, 'status' => 'arrived']);

    Sanctum::actingAs(aqAdmin());

    $json = $this->getJson("/api/queue/{$doctor->id}")
        ->assertStatus(200)
        ->json();

    expect(array_keys($json))->toBe(['current', 'waiting', 'finished']);
});

/*
 | ---------------------------------------------------------------------------
 | Independence & the Next-patient workflow invariants
 | ---------------------------------------------------------------------------
 */

test('queues are independent per doctor: acting on one never touches another', function () {
    $alpha = Doctor::factory()->create(['name' => 'Dr. Alpha']);
    $zulu = Doctor::factory()->create(['name' => 'Dr. Zulu']);

    $alphaCurrent = aqAppointment(aqUser(), ['doctor_id' => $alpha->id, 'queue_number' => 1, 'status' => 'in_progress']);
    $alphaNext = aqAppointment(aqUser(), ['doctor_id' => $alpha->id, 'queue_number' => 2, 'status' => 'arrived']);
    $zuluOnly = aqAppointment(aqUser(), ['doctor_id' => $zulu->id, 'queue_number' => 1, 'status' => 'in_progress']);

    Sanctum::actingAs(aqAdmin());

    // Complete alpha's current patient (with payment) — zulu is untouched.
    $this->patchJson("/api/appointments/{$alphaCurrent->id}/complete", ['payment_method' => 'cash'])
        ->assertStatus(200);

    $queues = $this->getJson('/api/queue')->json();

    $alphaEntry = aqQueueFor($queues, $alpha);
    $zuluEntry = aqQueueFor($queues, $zulu);

    expect($alphaEntry['current'])->toBeNull()
        ->and(collect($alphaEntry['waiting'])->pluck('id')->all())->toEqual([$alphaNext->id])
        ->and($zuluEntry['current']['id'])->toEqual($zuluOnly->id)
        ->and($zuluOnly->refresh()->status)->toBe('in_progress');
});

test('Next with no current: the first ARRIVED patient starts while an earlier pending stays pending', function () {
    $doctor = Doctor::factory()->create();

    // Queue order: #1 still pending (not arrived), #2 arrived, #3 arrived.
    $notArrived = aqAppointment(aqUser(), ['doctor_id' => $doctor->id, 'queue_number' => 1, 'status' => 'pending']);
    $firstArrived = aqAppointment(aqUser(), ['doctor_id' => $doctor->id, 'queue_number' => 2, 'status' => 'arrived']);
    $secondArrived = aqAppointment(aqUser(), ['doctor_id' => $doctor->id, 'queue_number' => 3, 'status' => 'arrived']);

    Sanctum::actingAs(aqAdmin());

    // The frontend's Next action targets the first ARRIVED patient via
    // the existing status endpoint — it never starts a pending patient.
    $this->patchJson("/api/appointments/{$firstArrived->id}/status", ['status' => 'in_progress'])
        ->assertStatus(200);

    $entry = aqQueueFor($this->getJson('/api/queue')->json(), $doctor);

    expect($entry['current']['id'])->toEqual($firstArrived->id)
        ->and($notArrived->refresh()->status)->toBe('pending')
        ->and(collect($entry['waiting'])->pluck('id')->all())->toEqual([$notArrived->id, $secondArrived->id]);
});

test('Next is gated while a consultation is unfinished: the backend keeps exactly one current', function () {
    $doctor = Doctor::factory()->create();

    $current = aqAppointment(aqUser(), ['doctor_id' => $doctor->id, 'queue_number' => 1, 'status' => 'in_progress']);
    $candidate = aqAppointment(aqUser(), ['doctor_id' => $doctor->id, 'queue_number' => 2, 'status' => 'arrived']);

    Sanctum::actingAs(aqAdmin());

    // The frontend disables Next while a current exists (with an
    // explanation pointing at Complete + Payment). If that gate is raced,
    // the backend invariant still holds: demotion, never two currents.
    $this->patchJson("/api/appointments/{$candidate->id}/status", ['status' => 'in_progress'])
        ->assertStatus(200);

    $entry = aqQueueFor($this->getJson('/api/queue')->json(), $doctor);

    expect($entry['current']['id'])->toEqual($candidate->id)
        ->and($current->refresh()->status)->toBe('arrived');

    // Exactly one in_progress row exists for this doctor/day.
    expect(Appointment::where('doctor_id', $doctor->id)
        ->whereDate('appointment_date', Carbon::today())
        ->where('status', 'in_progress')->count())->toBe(1);
});

test('Next after successful completion and payment: the arrived patient becomes current', function () {
    $doctor = Doctor::factory()->create();

    $current = aqAppointment(aqUser(), ['doctor_id' => $doctor->id, 'queue_number' => 1, 'status' => 'in_progress']);
    $next = aqAppointment(aqUser(), ['doctor_id' => $doctor->id, 'queue_number' => 2, 'status' => 'arrived']);

    Sanctum::actingAs(aqAdmin());

    // 1) The unfinished consultation must go through Complete + Payment.
    $this->patchJson("/api/appointments/{$current->id}/complete", ['payment_method' => 'cash'])
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.payment.status', 'paid');

    // 2) Current clears — Next becomes available again.
    $entry = aqQueueFor($this->getJson('/api/queue')->json(), $doctor);
    expect($entry['current'])->toBeNull()
        ->and(collect($entry['waiting'])->pluck('id')->all())->toEqual([$next->id]);

    // 3) Calling the next patient promotes the arrived candidate.
    $this->patchJson("/api/appointments/{$next->id}/status", ['status' => 'in_progress'])
        ->assertStatus(200);

    $entry = aqQueueFor($this->getJson('/api/queue')->json(), $doctor);
    expect($entry['current']['id'])->toEqual($next->id)
        ->and($entry['waiting'])->toBe([]);
});

test('skipped patients never re-enter the eligible queue and release their slot', function () {
    $doctor = Doctor::factory()->create();

    $skipped = aqAppointment(aqUser(), ['doctor_id' => $doctor->id, 'queue_number' => 1, 'status' => 'arrived']);
    $fallback = aqAppointment(aqUser(), ['doctor_id' => $doctor->id, 'queue_number' => 2, 'status' => 'pending']);

    Sanctum::actingAs(aqAdmin());

    // Skip = no_show through the existing status endpoint.
    $this->patchJson("/api/appointments/{$skipped->id}/status", ['status' => 'no_show'])
        ->assertStatus(200);

    $entry = aqQueueFor($this->getJson('/api/queue')->json(), $doctor);

    // Not waiting, not current: only in finished. Next would skip it.
    expect(collect($entry['waiting'])->pluck('id')->all())->not->toContain($skipped->id)
        ->and($entry['current'])->toBeNull()
        ->and(collect($entry['finished'])->pluck('id')->all())->toContain($skipped->id)
        ->and($skipped->refresh()->status)->toBe('no_show');

    // The following patient can still be started normally.
    $this->patchJson("/api/appointments/{$fallback->id}/status", ['status' => 'in_progress'])
        ->assertStatus(200);

    $entry = aqQueueFor($this->getJson('/api/queue')->json(), $doctor);
    expect($entry['current']['id'])->toEqual($fallback->id);
});

/*
 | ---------------------------------------------------------------------------
 | Authorization & efficiency
 | ---------------------------------------------------------------------------
 */

test('GET /api/queue is admin-only: guest 401, non-admin 403', function () {
    $this->getJson('/api/queue')->assertStatus(401);

    Sanctum::actingAs(aqUser());
    $this->getJson('/api/queue')->assertStatus(403);
});

test('the all-doctors endpoint runs a bounded number of queries (no N+1)', function () {
    // Several doctors with several appointments each: a per-doctor query
    // loop would scale with doctors/appointments; this stays flat.
    foreach (range(1, 5) as $i) {
        $doctor = Doctor::factory()->create();

        foreach (range(1, 4) as $q) {
            aqAppointment(aqUser(), [
                'doctor_id' => $doctor->id,
                'queue_number' => $q,
                'status' => ['pending', 'arrived', 'in_progress', 'completed'][$q - 1],
            ]);
        }
    }

    Sanctum::actingAs(aqAdmin());

    DB::enableQueryLog();
    $this->getJson('/api/queue')->assertStatus(200);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // sanctum user + doctors + today's appointments (+ eager patient).
    expect($queries)->toBeLessThanOrEqual(5);
});

test('queue actions stay behind the admin status endpoint (patients cannot drive the queue)', function () {
    $doctor = Doctor::factory()->create();
    $owner = aqUser();

    $appointment = aqAppointment($owner, ['doctor_id' => $doctor->id, 'queue_number' => 1, 'status' => 'arrived']);

    Sanctum::actingAs($owner);

    $this->patchJson("/api/appointments/{$appointment->id}/status", ['status' => 'in_progress'])
        ->assertStatus(403);

    expect($appointment->refresh()->status)->toBe('arrived');
});
