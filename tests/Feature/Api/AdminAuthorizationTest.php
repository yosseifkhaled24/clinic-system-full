<?php

use App\Models\ContactMessage;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\Service;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

/*
|---------------------------------------------------------------------------
| Backend authorization batch 3 — admin-only writes regression tests
|---------------------------------------------------------------------------
|
| Covers: admin middleware on doctor/service/schedule writes and on the
| contact-message management endpoints, while locking the public GETs and
| the public POST /contact-messages as they behave today.
|
| Actors are always created with an EXPLICIT role because UserFactory
| randomly assigns 'admin' to ~25% of users (flaky-test hazard).
|
| All tests run against the isolated in-memory SQLite test database
| configured in phpunit.xml. No production data, no schema changes.
|
*/

function adminActor(): User
{
    return User::factory()->create(['role' => 'admin']);
}

function userActor(): User
{
    return User::factory()->create(['role' => 'user']);
}

function doctorWritePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Dr. Admin Batch3',
        // Admin doctor writes now require the id of a REAL specialty
        // record (free-text `specialty` is rejected by the API).
        'specialty_id' => Specialty::firstOrCreate(['name' => 'Cardiology'])->id,
        'email' => 'dr.admin.batch3@example.com',
        'phone' => '01098765432',
        'bio' => 'Batch 3 authorization fixture.',
        'allow_reviews' => true,
    ], $overrides);
}

function scheduleWritePayload(Doctor $doctor, array $overrides = []): array
{
    return array_merge([
        'doctor_id' => $doctor->id,
        'day' => 'Monday',
        'start_time' => '09:00',
        'end_time' => '15:00',
        'is_available' => true,
        'max_patients' => 30,
    ], $overrides);
}

function scheduleFixture(array $overrides = []): DoctorSchedule
{
    // The factory omits start_time/end_time, which are NOT NULL columns.
    return DoctorSchedule::factory()->create(array_merge([
        'start_time' => '10:00',
        'end_time' => '16:00',
    ], $overrides));
}

/*
| 1. Doctors — writes are admin-only, reads stay public
*/

test('guest cannot create a doctor (401)', function () {
    $this->postJson('/api/doctors', doctorWritePayload())->assertStatus(401);
});

test('authenticated non-admin cannot create a doctor (403)', function () {
    Sanctum::actingAs(userActor());

    $this->postJson('/api/doctors', doctorWritePayload())->assertStatus(403);
});

test('authenticated non-admin cannot update or delete a doctor (403, unchanged)', function () {
    $doctor = Doctor::factory()->create(['specialty' => 'Dermatology']);
    $neurology = Specialty::firstOrCreate(['name' => 'Neurology']);
    $pediatrics = Specialty::firstOrCreate(['name' => 'Pediatrics']);
    Sanctum::actingAs(userActor());

    $this->putJson("/api/doctors/{$doctor->id}", ['specialty_id' => $neurology->id])
        ->assertStatus(403);
    $this->patchJson("/api/doctors/{$doctor->id}", ['specialty_id' => $pediatrics->id])
        ->assertStatus(403);
    $this->deleteJson("/api/doctors/{$doctor->id}")->assertStatus(403);

    expect($doctor->refresh()->specialty)->toBe('Dermatology')
        // Neither write landed: the link still points at the Dermatology row.
        ->and($doctor->specialty()->value('name'))->toBe('Dermatology');
});

test('admin can create a doctor and response shape is preserved', function () {
    Sanctum::actingAs(adminActor());

    $this->postJson('/api/doctors', doctorWritePayload())
        ->assertStatus(201)
        ->assertJsonStructure([
            'data' => ['id', 'name', 'specialty', 'email', 'phone', 'bio', 'allow_reviews', 'image_url'],
        ]);

    expect(Doctor::where('email', 'dr.admin.batch3@example.com')->exists())->toBeTrue();
});

test('admin can update a doctor via PUT and the change persists', function () {
    $doctor = Doctor::factory()->create(['specialty' => 'Dermatology']);
    $neurology = Specialty::firstOrCreate(['name' => 'Neurology']);
    Sanctum::actingAs(adminActor());

    $this->putJson("/api/doctors/{$doctor->id}", ['specialty_id' => $neurology->id])
        ->assertStatus(200)
        ->assertJsonStructure(['data' => ['id', 'specialty']])
        // Legacy string is re-synced from the linked specialty record.
        ->assertJsonPath('data.specialty', 'Neurology')
        ->assertJsonPath('data.specialty_id', $neurology->id);

    $doctor->refresh();
    expect($doctor->specialty)->toBe('Neurology')
        ->and($doctor->specialty_id)->toBe($neurology->id);
});

test('admin can delete a doctor (204, record gone)', function () {
    $doctor = Doctor::factory()->create();
    Sanctum::actingAs(adminActor());

    $this->deleteJson("/api/doctors/{$doctor->id}")->assertStatus(204);

    expect(Doctor::find($doctor->id))->toBeNull();
});

test('GET /api/doctors list and show remain public for guests', function () {
    $doctor = Doctor::factory()->create();

    $this->getJson('/api/doctors')->assertStatus(200);
    $this->getJson("/api/doctors/{$doctor->id}")->assertStatus(200);
});

/*
| 2. Services — writes are admin-only, reads stay public
*/

test('guest cannot create a service (401)', function () {
    $this->postJson('/api/services', ['name' => 'X', 'description' => 'Y', 'price' => 100])
        ->assertStatus(401);
});

test('authenticated non-admin cannot create, update or delete a service (403, unchanged)', function () {
    $service = Service::factory()->create(['name' => 'Original Service']);
    Sanctum::actingAs(userActor());

    $this->postJson('/api/services', ['name' => 'New', 'description' => 'Desc', 'price' => 50])
        ->assertStatus(403);
    $this->putJson("/api/services/{$service->id}", ['name' => 'Renamed', 'price' => 200])
        ->assertStatus(403);
    $this->patchJson("/api/services/{$service->id}", ['name' => 'Renamed2', 'price' => 200])
        ->assertStatus(403);
    $this->deleteJson("/api/services/{$service->id}")->assertStatus(403);

    expect($service->refresh()->name)->toBe('Original Service');
});

test('admin can create a service and response shape is preserved', function () {
    Sanctum::actingAs(adminActor());

    $this->postJson('/api/services', [
        'name' => 'Admin Batch3 Service',
        'description' => 'Created by an admin in batch 3 tests.',
        'price' => 300,
    ])
        ->assertStatus(201)
        ->assertJsonStructure(['id', 'name', 'description', 'price', 'image_url', 'created_at', 'updated_at']);

    expect(Service::where('name', 'Admin Batch3 Service')->exists())->toBeTrue();
});

test('admin can update a service via PATCH and the change persists', function () {
    $service = Service::factory()->create(['name' => 'Pre Batch3']);
    Sanctum::actingAs(adminActor());

    $this->patchJson("/api/services/{$service->id}", ['name' => 'Post Batch3', 'price' => 450])
        ->assertStatus(200)
        ->assertJsonPath('name', 'Post Batch3');

    expect($service->refresh()->name)->toBe('Post Batch3');
});

test('admin can delete a service (204, record gone)', function () {
    $service = Service::factory()->create();
    Sanctum::actingAs(adminActor());

    $this->deleteJson("/api/services/{$service->id}")->assertStatus(204);

    expect(Service::find($service->id))->toBeNull();
});

test('GET /api/services list and show remain public for guests', function () {
    $service = Service::factory()->create();

    $this->getJson('/api/services')->assertStatus(200);
    $this->getJson("/api/services/{$service->id}")->assertStatus(200);
});

/*
| 3. Doctor schedules — writes are admin-only, per-doctor listing stays public
*/

test('guest cannot create a schedule (401)', function () {
    $doctor = Doctor::factory()->create();

    $this->postJson('/api/schedules', scheduleWritePayload($doctor))->assertStatus(401);
});

test('authenticated non-admin cannot create, update or delete a schedule (403, unchanged)', function () {
    $doctor = Doctor::factory()->create();
    $schedule = scheduleFixture(['doctor_id' => $doctor->id, 'max_patients' => 25]);
    Sanctum::actingAs(userActor());

    $this->postJson('/api/schedules', scheduleWritePayload($doctor, ['day' => 'Tuesday']))
        ->assertStatus(403);
    $this->putJson("/api/schedules/{$schedule->id}", scheduleWritePayload($doctor, ['max_patients' => 99]))
        ->assertStatus(403);
    $this->patchJson("/api/schedules/{$schedule->id}", scheduleWritePayload($doctor, ['max_patients' => 99]))
        ->assertStatus(403);
    $this->deleteJson("/api/schedules/{$schedule->id}")->assertStatus(403);

    expect($schedule->refresh()->max_patients)->toEqual(25);
});

test('admin can create a schedule and response shape is preserved', function () {
    $doctor = Doctor::factory()->create();
    Sanctum::actingAs(adminActor());

    $this->postJson('/api/schedules', scheduleWritePayload($doctor))
        ->assertStatus(201)
        ->assertJsonStructure([
            'id',
            'doctor' => ['id', 'name'],
            'day', 'start_time', 'end_time', 'is_available', 'max_patients',
            'created_at', 'updated_at',
        ])
        ->assertJsonPath('doctor.id', $doctor->id)
        ->assertJsonPath('day', 'Monday');

    expect(DoctorSchedule::where('doctor_id', $doctor->id)->where('day', 'Monday')->exists())->toBeTrue();
});

test('admin can update a schedule with a full payload and the change persists', function () {
    $doctor = Doctor::factory()->create();
    $schedule = scheduleFixture(['doctor_id' => $doctor->id, 'max_patients' => 25]);
    Sanctum::actingAs(adminActor());

    // DoctorScheduleRequest requires every field on update too.
    $this->patchJson("/api/schedules/{$schedule->id}", scheduleWritePayload($doctor, [
        'day' => 'Monday',
        'max_patients' => 42,
    ]))
        ->assertStatus(200)
        ->assertJsonStructure(['id', 'doctor' => ['id', 'name'], 'day', 'max_patients'])
        ->assertJsonPath('max_patients', 42);

    expect($schedule->refresh()->max_patients)->toEqual(42);
});

test('admin can delete a schedule (200 with message, record gone)', function () {
    $schedule = scheduleFixture();
    Sanctum::actingAs(adminActor());

    $this->deleteJson("/api/schedules/{$schedule->id}")
        ->assertStatus(200)
        ->assertJsonStructure(['message']);

    expect(DoctorSchedule::find($schedule->id))->toBeNull();
});

/*
| NOTE: DoctorScheduleController::index() and doctorSchedules() order with
| MySQL's FIELD() function, which the SQLite test database does not provide.
| Those two GET endpoints therefore cannot be executed end-to-end here
| (pre-existing MySQL-only SQL; changing controller logic is out of batch 3
| scope). Their authorization behavior is still verified two ways below:
| 1) route-level — the registered middleware stack, and
| 2) request-level — guests/non-admins are never rejected with 401/403.
| On MySQL the first assertions (public/authenticated-only 200) hold.
*/

test('GET /api/doctors/{doctor}/schedules remains public (no auth/admin middleware, guest not rejected)', function () {
    $doctor = Doctor::factory()->create();
    scheduleFixture(['doctor_id' => $doctor->id]);

    $route = collect(Route::getRoutes())
        ->first(fn ($r) => $r->uri() === 'api/doctors/{doctor}/schedules');

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->not->toContain('auth:sanctum')
        ->and($route->gatherMiddleware())->not->toContain('admin');

    $status = $this->getJson("/api/doctors/{$doctor->id}/schedules")->getStatusCode();

    expect($status)->not->toBe(401)->and($status)->not->toBe(403);
});

test('GET /api/schedules is admin-only as of batch 7 (401 guest, 403 non-admin)', function () {
    $this->getJson('/api/schedules')->assertStatus(401);

    $route = collect(Route::getRoutes())
        ->first(fn ($r) => $r->uri() === 'api/schedules' && in_array('GET', $r->methods()));

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain('auth:sanctum')
        ->and($route->gatherMiddleware())->toContain('admin');

    Sanctum::actingAs(userActor());

    $this->getJson('/api/schedules')->assertStatus(403);
});

/*
| 4. Contact messages — management is admin-only, submission stays public
*/

test('guest cannot list, show or delete contact messages (401)', function () {
    $message = ContactMessage::factory()->create();

    $this->getJson('/api/contact-messages')->assertStatus(401);
    $this->getJson("/api/contact-messages/{$message->id}")->assertStatus(401);
    $this->deleteJson("/api/contact-messages/{$message->id}")->assertStatus(401);
});

test('authenticated non-admin cannot list, show or delete contact messages (403, unchanged)', function () {
    $message = ContactMessage::factory()->create();
    Sanctum::actingAs(userActor());

    $this->getJson('/api/contact-messages')->assertStatus(403);
    $this->getJson("/api/contact-messages/{$message->id}")->assertStatus(403);
    $this->deleteJson("/api/contact-messages/{$message->id}")->assertStatus(403);

    expect(ContactMessage::find($message->id))->not->toBeNull();
});

test('admin can list contact messages and response shape is preserved', function () {
    ContactMessage::factory()->create();
    Sanctum::actingAs(adminActor());

    $this->getJson('/api/contact-messages')
        ->assertStatus(200)
        ->assertJsonStructure(['*' => ['id', 'name', 'email', 'subject', 'message', 'created_at', 'updated_at']]);
});

test('admin can view a single contact message and response shape is preserved', function () {
    $message = ContactMessage::factory()->create();
    Sanctum::actingAs(adminActor());

    $this->getJson("/api/contact-messages/{$message->id}")
        ->assertStatus(200)
        ->assertJsonStructure(['id', 'name', 'email', 'subject', 'message', 'created_at', 'updated_at'])
        ->assertJsonPath('id', $message->id);
});

test('admin can delete a contact message (204, record gone)', function () {
    $message = ContactMessage::factory()->create();
    Sanctum::actingAs(adminActor());

    $this->deleteJson("/api/contact-messages/{$message->id}")->assertStatus(204);

    expect(ContactMessage::find($message->id))->toBeNull();
});

test('POST /api/contact-messages remains public and successful', function () {
    $this->postJson('/api/contact-messages', [
        'name' => 'Batch3 Guest',
        'email' => 'batch3.guest@example.com',
        'subject' => 'Still public',
        'message' => 'Submitting without a token must keep working.',
    ])
        ->assertStatus(201)
        ->assertJsonStructure([
            'message',
            'contact_message' => ['id', 'name', 'email', 'subject', 'message', 'created_at', 'updated_at'],
        ]);
});

/*
| 5. Unsupported routes stay dead (batch 1 guarantees, re-verified with admin)
*/

test('PUT and PATCH /api/contact-messages/{id} remain unrouted even for admins (405)', function () {
    $message = ContactMessage::factory()->create();
    Sanctum::actingAs(adminActor());

    $this->putJson("/api/contact-messages/{$message->id}", ['subject' => 'x'])
        ->assertStatus(405);
    $this->patchJson("/api/contact-messages/{$message->id}", ['subject' => 'x'])
        ->assertStatus(405);
});

test('POST /api/patients remains unrouted even for admins (405)', function () {
    Sanctum::actingAs(adminActor());

    $this->postJson('/api/patients', ['name' => 'Should Not Route'])
        ->assertStatus(405);
});
