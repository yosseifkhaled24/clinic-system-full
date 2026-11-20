<?php

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/*
 |--------------------------------------------------------------------------
 | F-02 — server-side `date` filter on GET /api/appointments
 |--------------------------------------------------------------------------
 |
 | The dashboard's "Today's Appointments" card used to fetch page 1 of
 | the whole list and filter it IN THE BROWSER, so it could only ever
 | show today's rows that happened to sit on page 1. Phase 0 fix A2
 | added an optional, purely additive `date` (Y-m-d) query parameter:
 |
 |  - absent  → the response is exactly the pre-existing contract,
 |  - present → WHERE DATE(appointment_date) = date, AND-combined with
 |              search/status AFTER the visibility scope, so it can only
 |              NARROW what the caller may already see,
 |  - malformed → 422, like page / per_page, never a silent no-op.
 |
 | Helper names carry the `adf` prefix so they can never collide with
 | the helpers of the other feature files when the whole suite loads.
 */

function adfAdmin(): User
{
    return User::factory()->create(['role' => 'admin']);
}

function adfOwner(): User
{
    $user = User::factory()->create([
        'role' => 'user',
        'phone' => '010' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
    ]);

    Patient::factory()->create([
        'user_id' => $user->id,
        // patients.phone is UNIQUE — pin a random one per test row.
        'phone' => '012' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
    ]);

    return $user;
}

function adfRow(User $owner, string $date, int $queue): Appointment
{
    $patient = Patient::where('user_id', $owner->id)->firstOrFail();

    return Appointment::factory()->create([
        'patient_id' => $patient->id,
        'user_id' => $owner->id,
        'appointment_date' => $date,
        'queue_number' => $queue,
        'status' => 'pending',
        'payment_status' => 'unpaid',
        'payment_method' => null,
        'paid_at' => null,
    ]);
}

test('the date filter returns only today\'s rows for the admin list', function () {
    $owner = adfOwner();
    $today = now()->toDateString();
    $tomorrow = now()->addDay()->toDateString();

    adfRow($owner, $today, 1);
    adfRow($owner, $today, 2);
    adfRow($owner, $tomorrow, 3);

    Sanctum::actingAs(adfAdmin());

    $response = $this->getJson('/api/appointments?date=' . $today . '&per_page=50');

    $response->assertOk()->assertJsonPath('total', 2)->assertJsonCount(2, 'data');

    foreach ($response->json('data') as $row) {
        expect($row['appointment_date'])->toBe($today);
    }
});

test('the date filter can request a non-today calendar day', function () {
    $owner = adfOwner();
    $today = now()->toDateString();
    $other = now()->addDays(5)->toDateString();

    adfRow($owner, $other, 1);
    adfRow($owner, $today, 2);

    Sanctum::actingAs(adfAdmin());

    $response = $this->getJson('/api/appointments?date=' . $other . '&per_page=50');

    $response->assertOk()->assertJsonPath('total', 1)->assertJsonCount(1, 'data');

    expect($response->json('data.0.appointment_date'))->toBe($other);
});

test('omitting the date keeps the existing unfiltered contract', function () {
    $owner = adfOwner();
    $today = now()->toDateString();
    $tomorrow = now()->addDay()->toDateString();

    adfRow($owner, $today, 1);
    adfRow($owner, $today, 2);
    adfRow($owner, $tomorrow, 3);

    Sanctum::actingAs(adfAdmin());

    $this->getJson('/api/appointments?per_page=50')
        ->assertOk()
        ->assertJsonPath('total', 3)
        ->assertJsonCount(3, 'data');
});

test('a malformed date is rejected with 422 instead of silently dropping rows', function () {
    Sanctum::actingAs(adfAdmin());

    $this->getJson('/api/appointments?date=19-11-2026')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['date']);

    $this->getJson('/api/appointments?date=not-a-date')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['date']);
});

test('the date filter narrows but never widens a patient\'s visible rows', function () {
    $mine = adfOwner();
    $theirs = adfOwner();
    $today = now()->toDateString();
    $tomorrow = now()->addDay()->toDateString();

    adfRow($mine, $today, 1);
    adfRow($theirs, $today, 2);
    adfRow($theirs, $tomorrow, 3);

    Sanctum::actingAs($mine);

    $response = $this->getJson('/api/appointments?date=' . $today . '&per_page=50');

    // Only the caller's own row for that day — the other patient's
    // same-day row stays invisible even though it matches the filter.
    $response->assertOk()->assertJsonPath('total', 1)->assertJsonCount(1, 'data');

    $row = $response->json('data.0');

    expect($row['appointment_date'])->toBe($today)
        ->and($row['patient']['id'])
        ->toBe(Patient::where('user_id', $mine->id)->firstOrFail()->id);
});
