<?php

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/*
|---------------------------------------------------------------------------
| Backend authorization batch 4 — queue & appointment export regression
|---------------------------------------------------------------------------
|
| Covers: admin middleware on GET /queue/{doctor} and GET /appointments/
| export, plus validation of the export from/to query parameters (422 on
| malformed or inverted ranges; default "today" export preserved when
| parameters are omitted or empty).
|
| Actors are always created with an EXPLICIT role because UserFactory
| randomly assigns 'admin' to ~25% of users (flaky-test hazard).
|
| All tests run against the isolated in-memory SQLite test database
| configured in phpunit.xml. No production data, no schema changes, no
| external services (Excel generation is fully offline).
|
*/

function staffAdmin(): User
{
    return User::factory()->create(['role' => 'admin']);
}

function staffUser(): User
{
    return User::factory()->create(['role' => 'user']);
}

/*
| 1. GET /api/queue/{doctor} — admin-only (patient details inside)
*/

test('queue requires authentication and returns 401 for guests', function () {
    $doctor = Doctor::factory()->create();

    $this->getJson("/api/queue/{$doctor->id}")->assertStatus(401);
});

test('authenticated non-admin cannot access the doctor queue (403)', function () {
    $doctor = Doctor::factory()->create();
    Sanctum::actingAs(staffUser());

    $this->getJson("/api/queue/{$doctor->id}")->assertStatus(403);
});

test('admin can view the full queue with patient details and response shape is preserved', function () {
    $doctor = Doctor::factory()->create();
    $appointment = Appointment::factory()->create([
        'doctor_id' => $doctor->id,
        'appointment_date' => now()->format('Y-m-d'),
        'status' => 'pending',
        'queue_number' => 1,
    ]);
    Sanctum::actingAs(staffAdmin());

    $this->getJson("/api/queue/{$doctor->id}")
        ->assertStatus(200)
        ->assertJsonStructure([
            'current',
            'waiting' => [
                '*' => ['id', 'queue_number', 'status', 'patient'],
            ],
            'finished',
        ])
        ->assertJsonPath('waiting.0.id', $appointment->id)
        ->assertJsonPath('waiting.0.patient.id', $appointment->patient_id);
});

/*
| 2. GET /api/appointments/export — admin-only
*/

test('export requires authentication and returns 401 for guests', function () {
    $this->getJson('/api/appointments/export')->assertStatus(401);
});

test('authenticated non-admin cannot download the export (403)', function () {
    Sanctum::actingAs(staffUser());

    $this->getJson('/api/appointments/export')->assertStatus(403);
});

test('admin export with no parameters returns the default XLSX download', function () {
    Sanctum::actingAs(staffAdmin());

    $response = $this->getJson('/api/appointments/export');

    $response->assertStatus(200)
        ->assertDownload('appointments.xlsx');

    expect($response->headers->get('content-type'))->toContain('spreadsheetml');
});

test('admin export with a valid from and to range returns the XLSX download', function () {
    Sanctum::actingAs(staffAdmin());

    $this->getJson('/api/appointments/export?from=2026-01-01&to=2026-01-31')
        ->assertStatus(200)
        ->assertDownload('appointments.xlsx');
});

test('export rejects a malformed from date with 422', function () {
    Sanctum::actingAs(staffAdmin());

    $this->getJson('/api/appointments/export?from=not-a-date')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['from']);
});

test('export rejects a calendar-rollover date that loose parsing would accept (422)', function () {
    Sanctum::actingAs(staffAdmin());

    // 2026-02-30 does not exist; strtotime() would roll it over to March.
    $this->getJson('/api/appointments/export?from=2026-02-30')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['from']);
});

test('export rejects a malformed to date with 422', function () {
    Sanctum::actingAs(staffAdmin());

    $this->getJson('/api/appointments/export?from=2026-01-01&to=garbage')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['to']);
});

test('export rejects a to date earlier than from with 422', function () {
    Sanctum::actingAs(staffAdmin());

    $this->getJson('/api/appointments/export?from=2026-01-10&to=2026-01-05')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['to']);
});

test('export rejects to supplied without from with 422 (intentional tightening)', function () {
    Sanctum::actingAs(staffAdmin());

    // Laravel's after_or_equal passes when the other field is absent, so the
    // pair is enforced via required_with on from; the error lands on from.
    $this->getJson('/api/appointments/export?to=2026-01-05')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['from']);
});

test('export accepts from without to and keeps the existing today fallback', function () {
    Sanctum::actingAs(staffAdmin());

    $this->getJson('/api/appointments/export?from=2026-01-05')
        ->assertStatus(200)
        ->assertDownload('appointments.xlsx');
});

test('export treats empty from/to parameters as omitted and keeps the default download', function () {
    Sanctum::actingAs(staffAdmin());

    $this->getJson('/api/appointments/export?from=&to=')
        ->assertStatus(200)
        ->assertDownload('appointments.xlsx');
});
