<?php

use App\Models\Patient;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| Patient data authorization regression tests (batch 2)
|--------------------------------------------------------------------------
|
| Covers: admin middleware on the patient CRUD/search endpoints and the
| admin-or-linked-owner guard on GET /patients/{patient}/history.
|
| Actors are always created with an EXPLICIT role because UserFactory
| randomly assigns 'admin' to ~25% of users (flaky-test hazard).
|
| All tests run against the isolated in-memory SQLite test database
| configured in phpunit.xml. No production data, no schema changes.
|
*/

function patientAdmin(): User
{
    return User::factory()->create(['role' => 'admin']);
}

function patientUser(): User
{
    return User::factory()->create(['role' => 'user']);
}

/*
| 1. GET /api/patients (index)
*/

test('patient list requires authentication and returns 401 for guests', function () {
    $this->getJson('/api/patients')->assertStatus(401);
});

test('admin can list patients and response shape is preserved', function () {
    Patient::factory()->create();
    Sanctum::actingAs(patientAdmin());

    $this->getJson('/api/patients')
        ->assertStatus(200)
        ->assertJsonStructure(['*' => ['id', 'name', 'phone']]);
});

test('patient owner cannot list all patients (403)', function () {
    $owner = patientUser();
    Patient::factory()->create(['user_id' => $owner->id]);
    Sanctum::actingAs($owner);

    $this->getJson('/api/patients')->assertStatus(403);
});

test('unrelated authenticated user cannot list all patients (403)', function () {
    Sanctum::actingAs(patientUser());

    $this->getJson('/api/patients')->assertStatus(403);
});

/*
| 2. GET /api/patients/search
*/

test('admin can search patients and matches are returned', function () {
    $target = Patient::factory()->create(['name' => 'Zania Q SearchTarget']);
    Sanctum::actingAs(patientAdmin());

    $this->getJson('/api/patients/search?query=SearchTarget')
        ->assertStatus(200)
        ->assertJsonFragment(['id' => $target->id, 'name' => 'Zania Q SearchTarget']);
});

test('patient owner cannot search the patient directory (403)', function () {
    $owner = patientUser();
    Patient::factory()->create(['user_id' => $owner->id]);
    Sanctum::actingAs($owner);

    $this->getJson('/api/patients/search?query=SearchTarget')->assertStatus(403);
});

test('unrelated authenticated user cannot search the patient directory (403)', function () {
    Sanctum::actingAs(patientUser());

    $this->getJson('/api/patients/search?query=SearchTarget')->assertStatus(403);
});

/*
| 3. GET /api/patients/{patient} (show)
*/

test('admin can view a patient record and response shape is preserved', function () {
    $patient = Patient::factory()->create();
    Sanctum::actingAs(patientAdmin());

    $this->getJson("/api/patients/{$patient->id}")
        ->assertStatus(200)
        ->assertJsonStructure(['id', 'name', 'phone', 'email', 'gender', 'date_of_birth', 'address', 'notes']);
});

test('patient owner gets 403 when viewing their own raw patient record (strict admin policy)', function () {
    $owner = patientUser();
    $patient = Patient::factory()->create(['user_id' => $owner->id]);
    Sanctum::actingAs($owner);

    $this->getJson("/api/patients/{$patient->id}")->assertStatus(403);
});

test('unrelated authenticated user cannot view another patient record (403)', function () {
    $patient = Patient::factory()->create();
    Sanctum::actingAs(patientUser());

    $this->getJson("/api/patients/{$patient->id}")->assertStatus(403);
});

/*
| 4. PUT/PATCH /api/patients/{patient} (update)
*/

test('admin can update a patient and response shape is preserved', function () {
    $patient = Patient::factory()->create(['name' => 'Before Update']);
    Sanctum::actingAs(patientAdmin());

    $this->putJson("/api/patients/{$patient->id}", ['name' => 'After Update'])
        ->assertStatus(200)
        ->assertJsonStructure(['message', 'data' => ['id', 'name']])
        ->assertJsonPath('data.name', 'After Update');

    expect($patient->refresh()->name)->toBe('After Update');
});

test('patient owner cannot update their own record via PATCH (403, unchanged)', function () {
    $owner = patientUser();
    $patient = Patient::factory()->create(['user_id' => $owner->id, 'name' => 'Owner Original']);
    Sanctum::actingAs($owner);

    $this->patchJson("/api/patients/{$patient->id}", ['name' => 'Should Not Apply'])
        ->assertStatus(403);

    expect($patient->refresh()->name)->toBe('Owner Original');
});

test('unrelated authenticated user cannot update another patient record (403, unchanged)', function () {
    $patient = Patient::factory()->create(['name' => 'Untouched Name']);
    Sanctum::actingAs(patientUser());

    $this->putJson("/api/patients/{$patient->id}", ['name' => 'Should Not Apply'])
        ->assertStatus(403);

    expect($patient->refresh()->name)->toBe('Untouched Name');
});

/*
| 5. DELETE /api/patients/{patient} (destroy)
*/

test('admin can delete a patient record', function () {
    $patient = Patient::factory()->create();
    Sanctum::actingAs(patientAdmin());

    $this->deleteJson("/api/patients/{$patient->id}")->assertStatus(200);

    expect(Patient::find($patient->id))->toBeNull();
});

test('patient owner cannot delete their own record (403, record persists)', function () {
    $owner = patientUser();
    $patient = Patient::factory()->create(['user_id' => $owner->id]);
    Sanctum::actingAs($owner);

    $this->deleteJson("/api/patients/{$patient->id}")->assertStatus(403);

    expect(Patient::find($patient->id))->not->toBeNull();
});

test('unrelated authenticated user cannot delete another patient record (403, record persists)', function () {
    $patient = Patient::factory()->create();
    Sanctum::actingAs(patientUser());

    $this->deleteJson("/api/patients/{$patient->id}")->assertStatus(403);

    expect(Patient::find($patient->id))->not->toBeNull();
});

/*
| 6. GET /api/patients/{patient}/history (admin OR linked owner)
*/

test('patient history requires authentication and returns 401 for guests', function () {
    $patient = Patient::factory()->create();

    $this->getJson("/api/patients/{$patient->id}/history")->assertStatus(401);
});

test('admin can view any patient history including unlinked walk-in records (shape preserved)', function () {
    $walkIn = Patient::factory()->create(['user_id' => null]);
    Sanctum::actingAs(patientAdmin());

    $this->getJson("/api/patients/{$walkIn->id}/history")
        ->assertStatus(200)
        ->assertJsonStructure([
            'patient' => ['id', 'name', 'phone', 'email', 'gender', 'date_of_birth', 'address'],
            'statistics' => [
                'total_visits', 'completed_visits', 'cancelled_visits',
                'no_show_visits', 'total_paid', 'last_visit',
            ],
            'history',
        ])
        ->assertJsonPath('patient.id', $walkIn->id);
});

test('linked patient owner can view their own history', function () {
    $owner = patientUser();
    $patient = Patient::factory()->create(['user_id' => $owner->id]);
    Sanctum::actingAs($owner);

    $this->getJson("/api/patients/{$patient->id}/history")
        ->assertStatus(200)
        ->assertJsonPath('patient.id', $patient->id);
});

test('linked patient owner cannot view another patient history (403)', function () {
    $owner = patientUser();
    Patient::factory()->create(['user_id' => $owner->id]);
    $someoneElses = Patient::factory()->create();
    Sanctum::actingAs($owner);

    $this->getJson("/api/patients/{$someoneElses->id}/history")->assertStatus(403);
});

test('non-admin with their own separate patient record cannot view someone else history (403)', function () {
    $unrelated = patientUser();
    Patient::factory()->create(['user_id' => $unrelated->id]);
    $victim = Patient::factory()->create();
    Sanctum::actingAs($unrelated);

    $this->getJson("/api/patients/{$victim->id}/history")->assertStatus(403);
});

test('non-admin cannot view an unlinked walk-in patient history (403)', function () {
    $walkIn = Patient::factory()->create(['user_id' => null]);
    Sanctum::actingAs(patientUser());

    $this->getJson("/api/patients/{$walkIn->id}/history")->assertStatus(403);
});

test('authenticated user without any linked patient record gets 403 on history (403)', function () {
    $userWithoutPatient = patientUser();
    $patient = Patient::factory()->create();
    Sanctum::actingAs($userWithoutPatient);

    $this->getJson("/api/patients/{$patient->id}/history")->assertStatus(403);
});
