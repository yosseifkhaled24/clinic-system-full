<?php

use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/*
|---------------------------------------------------------------------------
| Batch 8 — portable schedule ordering (FIELD() replaced with CASE)
|---------------------------------------------------------------------------
|
| These tests are only possible against the SQLite test database because
| the day ordering is now expressed with a standard SQL CASE expression
| instead of MySQL-only FIELD(). They lock the Saturday→Friday day order,
| the doctor_id grouping of the admin index, and the response shape of
| both schedule listing endpoints.
|
| Actors use an EXPLICIT role (UserFactory randomizes 'admin').
|
*/

function scheduleDay(Doctor $doctor, string $day): DoctorSchedule
{
    return DoctorSchedule::factory()->create([
        'doctor_id' => $doctor->id,
        'day' => $day,
        'start_time' => '09:00',
        'end_time' => '17:00',
    ]);
}

const SCHEDULE_SHAPE = [
    'id',
    'doctor' => ['id', 'name'],
    'day',
    'start_time',
    'end_time',
    'is_available',
    'max_patients',
];

test('public doctor schedules are ordered Saturday to Friday regardless of insertion order', function () {
    $doctor = Doctor::factory()->create();

    // Scrambled insertion order on purpose.
    scheduleDay($doctor, 'Wednesday');
    scheduleDay($doctor, 'Saturday');
    scheduleDay($doctor, 'Monday');
    scheduleDay($doctor, 'Friday');

    $response = $this->getJson("/api/doctors/{$doctor->id}/schedules")
        ->assertStatus(200)
        ->assertJsonStructure(['*' => SCHEDULE_SHAPE]);

    expect($response->json('*.day'))
        ->toBe(['Saturday', 'Monday', 'Wednesday', 'Friday']);
});

test('admin schedule index returns 200 on any database, doctors by id, Saturday-first days', function () {
    $doctorA = Doctor::factory()->create();
    $doctorB = Doctor::factory()->create();

    scheduleDay($doctorA, 'Thursday');
    scheduleDay($doctorA, 'Sunday');
    scheduleDay($doctorA, 'Saturday');
    scheduleDay($doctorB, 'Monday');
    scheduleDay($doctorB, 'Friday');

    Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

    // Before batch 8 this call was only assertable at authorization level
    // on SQLite because of MySQL-only FIELD(); now it is a full 200.
    $response = $this->getJson('/api/schedules')
        ->assertStatus(200)
        ->assertJsonStructure(['*' => SCHEDULE_SHAPE]);

    expect($response->json('*.doctor.id'))->toBe([
        $doctorA->id, $doctorA->id, $doctorA->id,
        $doctorB->id, $doctorB->id,
    ])->and($response->json('*.day'))->toBe([
        'Saturday', 'Sunday', 'Thursday',
        'Monday', 'Friday',
    ]);
});

test('a doctor without schedules gets an empty list with 200', function () {
    $doctor = Doctor::factory()->create();

    $this->getJson("/api/doctors/{$doctor->id}/schedules")
        ->assertStatus(200)
        ->assertExactJson([]);
});
