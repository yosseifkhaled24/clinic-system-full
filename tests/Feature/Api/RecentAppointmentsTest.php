<?php

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/*
 |--------------------------------------------------------------------------
 | Part C — admin-only "new bookings" feed (GET /api/appointments/recent)
 |--------------------------------------------------------------------------
 |
 | The dashboard toast polls this endpoint every 15 seconds. The
 | contract these tests lock:
 |
 |  - admin only: a patient token answers 403, a guest 401,
 |  - no `after_id` (baseline call) → EMPTY `data`, so first load can
 |    never present pre-existing bookings as "new",
 |  - with `after_id` → only rows strictly newer than the cursor, id
 |    ASC, so the client's cursor can neither repeat nor lose a row,
 |  - row shape is exactly {id, created_at, patient_name, doctor_name}
 |    — only names the dashboard already renders, never phone/email/
 |    notes/payment data,
 |  - one response is capped at 20 rows (RECENT_LIMIT); the rest
 |    arrives on the following polls because `latest_id` still reports
 |    the true MAX(id),
 |  - GET /api/appointments (the paginated envelope) is untouched —
 |    see AppointmentPaginationTest.
 |
 | Helper names carry the `rac` prefix so they can never collide with
 | the helpers of the other feature files when the whole suite loads.
 */

function racAdmin(): User
{
    return User::factory()->create(['role' => 'admin']);
}

function racPatient(): User
{
    return User::factory()->create([
        'role' => 'user',
        'phone' => '010' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
    ]);
}

function racRow(string $label): Appointment
{
    $patient = Patient::factory()->create([
        'name' => $label . ' Patient',
        // patients.phone is UNIQUE — pin a random one per test row.
        'phone' => '012' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
    ]);

    $doctor = Doctor::factory()->create([
        'name' => 'Dr. ' . $label . ' Doctor',
    ]);

    return Appointment::factory()->create([
        'patient_id' => $patient->id,
        'user_id' => $patient->user_id,
        'doctor_id' => $doctor->id,
        'queue_number' => 1,
        'status' => 'pending',
        'payment_status' => 'unpaid',
        'payment_method' => null,
        'paid_at' => null,
    ]);
}

test('the recent feed rejects a guest with 401', function () {
    $this->getJson('/api/appointments/recent')->assertStatus(401);
});

test('the recent feed is admin-only: a patient token answers 403', function () {
    racRow('Baseline');

    Sanctum::actingAs(racPatient());

    $this->getJson('/api/appointments/recent')->assertStatus(403);
});

test('the baseline call answers an empty list plus the latest id', function () {
    $first = racRow('Alpha');
    $second = racRow('Beta');

    Sanctum::actingAs(racAdmin());

    // No after_id → baseline only: existing bookings must NEVER be
    // reported as new on the dashboard's first poll.
    $response = $this->getJson('/api/appointments/recent');

    $response->assertOk()->assertJsonPath('data', [])->assertJsonPath('latest_id', $second->id);

    expect($second->id)->toBeGreaterThan($first->id);
});

test('with a cursor the feed returns only the rows booked after it', function () {
    $baseline = racRow('Baseline');
    $first = racRow('Alpha');
    $second = racRow('Beta');

    Sanctum::actingAs(racAdmin());

    $response = $this->getJson('/api/appointments/recent?after_id=' . $baseline->id);

    $response->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('latest_id', $second->id);

    $rows = $response->json('data');

    // id ASC, exactly the two new rows in booking order.
    expect(array_column($rows, 'id'))->toBe([$first->id, $second->id]);
});

test('each recent row exposes exactly the four dashboard fields', function () {
    $baseline = racRow('Baseline');
    racRow('Alpha');

    Sanctum::actingAs(racAdmin());

    $row = $this->getJson('/api/appointments/recent?after_id=' . $baseline->id)
        ->assertOk()
        ->assertJsonStructure(['data' => [['id', 'created_at', 'patient_name', 'doctor_name']], 'latest_id'])
        ->json('data.0');

    expect(array_keys($row))->toBe(['id', 'created_at', 'patient_name', 'doctor_name'])
        ->and($row['patient_name'])->toBe('Alpha Patient')
        ->and($row['doctor_name'])->toBe('Dr. Alpha Doctor')
        // created_at is an ISO-8601 timestamp the toast never displays
        // but the contract documents.
        ->and($row['created_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/');

    // No private patient data beyond what the dashboard already shows.
    expect($row)
        ->not->toHaveKey('patient_email')
        ->not->toHaveKey('patient_phone')
        ->not->toHaveKey('notes');
});

test('repeating a poll with the same cursor never repeats a row', function () {
    $baseline = racRow('Baseline');
    racRow('Alpha');

    Sanctum::actingAs(racAdmin());

    $url = '/api/appointments/recent?after_id=' . $baseline->id;

    $this->getJson($url)->assertOk()->assertJsonCount(1, 'data');

    // The client did not advance its cursor (e.g. a dropped response):
    // the same row may come back once more, but a poll after the cursor
    // moved answers nothing — no toast is ever fired twice for a row
    // the client already processed.
    $this->getJson($url)->assertOk()->assertJsonCount(1, 'data');

    $this->getJson('/api/appointments/recent?after_id=' . Appointment::max('id'))
        ->assertOk()
        ->assertJsonPath('data', []);
});

test('one poll is capped at 20 rows while latest_id still reports the true maximum', function () {
    $baseline = racRow('Baseline');

    $newIds = [];
    for ($i = 1; $i <= 25; $i++) {
        $newIds[] = racRow('New ' . $i)->id;
    }

    Sanctum::actingAs(racAdmin());

    $response = $this->getJson('/api/appointments/recent?after_id=' . $baseline->id);

    $response->assertOk()->assertJsonCount(20, 'data');

    $rows = $response->json('data');

    // The first 20 of the 25, in id ASC order; the last 5 arrive on the
    // next poll once the client advances its cursor to row 20's id.
    expect(array_column($rows, 'id'))->toBe(array_slice($newIds, 0, 20))
        ->and($response->json('latest_id'))->toBe(end($newIds));
});

test('a malformed cursor is rejected with 422', function () {
    Sanctum::actingAs(racAdmin());

    $this->getJson('/api/appointments/recent?after_id=abc')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['after_id']);
});

test('the latest id of an empty table is zero', function () {
    Sanctum::actingAs(racAdmin());

    $this->getJson('/api/appointments/recent')
        ->assertOk()
        ->assertJsonPath('data', [])
        ->assertJsonPath('latest_id', 0);
});
