<?php

use App\Models\Appointment;
use App\Models\ContactMessage;
use App\Models\Doctor;
use App\Models\Review;
use App\Models\Service;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| Validation Fix Batch 1 — API regression tests
|--------------------------------------------------------------------------
|
| Covers: removal of routes dispatching to nonexistent controller methods
| (405 instead of 500), restoration of UpdateReviewRequest, and alignment
| of validation rules with the database schema for doctors, services and
| contact messages (422 instead of database-generated 500).
|
| All tests run against the isolated in-memory SQLite test database
| configured in phpunit.xml. No production data or external services.
|
*/

function validationAdmin(): void
{
    Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
}

function validationDoctorPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Dr. Validation Test',
        // Admin doctor writes require the id of a REAL specialty record;
        // free-text `specialty` is rejected by the API (prohibited).
        'specialty_id' => Specialty::firstOrCreate(['name' => 'Cardiology'])->id,
        'email' => 'dr.validation.test@example.com',
        'phone' => '01012345678',
        'bio' => 'Test bio',
        'allow_reviews' => true,
    ], $overrides);
}

/*
|--------------------------------------------------------------------------
| 1. Removed routes dispatching to nonexistent controller methods
|--------------------------------------------------------------------------
*/

test('POST /api/patients is no longer routed and returns 405', function () {
    validationAdmin();

    $this->postJson('/api/patients', ['name' => 'Should Not Route'])
        ->assertStatus(405);
});

test('PUT and PATCH /api/contact-messages/{id} are no longer routed and return 405', function () {
    validationAdmin();
    $message = ContactMessage::factory()->create();

    $this->putJson("/api/contact-messages/{$message->id}", ['subject' => 'x'])
        ->assertStatus(405);

    $this->patchJson("/api/contact-messages/{$message->id}", ['subject' => 'x'])
        ->assertStatus(405);
});

/*
|--------------------------------------------------------------------------
| 2. Restored UpdateReviewRequest — only rating and comment are updatable
|--------------------------------------------------------------------------
*/

test('review owner can update rating and comment and response shape is preserved', function () {
    $owner = User::factory()->create();
    $review = Review::factory()->create(['user_id' => $owner->id]);
    Sanctum::actingAs($owner);

    $this->patchJson("/api/reviews/{$review->id}", [
        'rating' => 2,
        'comment' => 'Updated comment',
    ])
        ->assertStatus(200)
        ->assertJsonStructure([
            'id', 'rating', 'comment', 'user', 'doctor', 'appointment', 'created_at', 'updated_at',
        ]);

    $review->refresh();
    expect($review->rating)->toEqual(2)
        ->and($review->comment)->toBe('Updated comment');
});

test('review update rejects rating outside 1-5 with 422 and leaves record unchanged', function () {
    $owner = User::factory()->create();
    $review = Review::factory()->create(['user_id' => $owner->id, 'rating' => 5]);
    Sanctum::actingAs($owner);

    $this->patchJson("/api/reviews/{$review->id}", ['rating' => 6])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['rating']);

    $this->patchJson("/api/reviews/{$review->id}", ['rating' => 0])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['rating']);

    expect($review->refresh()->rating)->toEqual(5);
});

test('review update rejects null rating and non-string comment with 422', function () {
    $owner = User::factory()->create();
    $review = Review::factory()->create(['user_id' => $owner->id]);
    Sanctum::actingAs($owner);

    $this->patchJson("/api/reviews/{$review->id}", ['rating' => null])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['rating']);

    $this->patchJson("/api/reviews/{$review->id}", ['comment' => ['not', 'a', 'string']])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['comment']);
});

test('review update ignores client-supplied identity fields', function () {
    $owner = User::factory()->create();
    $review = Review::factory()->create(['user_id' => $owner->id]);
    $otherAppointment = Appointment::factory()->create();

    $originalAppointmentId = $review->appointment_id;
    $originalDoctorId = $review->doctor_id;
    $originalUserId = $review->user_id;

    Sanctum::actingAs($owner);

    $this->patchJson("/api/reviews/{$review->id}", [
        'rating' => 4,
        'appointment_id' => $otherAppointment->id,
        'doctor_id' => $otherAppointment->doctor_id,
        'user_id' => User::factory()->create()->id,
    ])->assertStatus(200);

    $review->refresh();
    expect($review->rating)->toEqual(4)
        ->and($review->appointment_id)->toEqual($originalAppointmentId)
        ->and($review->doctor_id)->toEqual($originalDoctorId)
        ->and($review->user_id)->toEqual($originalUserId);
});

test('review update with no known fields is a harmless no-op returning 200', function () {
    $owner = User::factory()->create();
    $review = Review::factory()->create(['user_id' => $owner->id, 'rating' => 3]);
    Sanctum::actingAs($owner);

    $this->patchJson("/api/reviews/{$review->id}", [])
        ->assertStatus(200);

    expect($review->refresh()->rating)->toEqual(3);
});

/*
|--------------------------------------------------------------------------
| 3. Doctor rules aligned with NOT NULL columns (create/update)
|--------------------------------------------------------------------------
*/

test('doctor can be created with a valid payload and response shape is preserved', function () {
    validationAdmin();

    $this->postJson('/api/doctors', validationDoctorPayload())
        ->assertStatus(201)
        ->assertJsonStructure([
            'data' => ['id', 'name', 'specialty', 'email', 'phone', 'bio', 'allow_reviews', 'image_url'],
        ]);

    expect(Doctor::where('email', 'dr.validation.test@example.com')->exists())->toBeTrue();
});

test('doctor store requires NOT NULL columns and returns 422 instead of 500', function () {
    validationAdmin();

    // specialty_id is the required specialty input on the admin API now
    // (the free-text string was removed); email/phone stay as before.
    $this->postJson('/api/doctors', ['name' => 'Dr. Missing Columns'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['specialty_id', 'email', 'phone']);
});

test('doctor store rejects explicit nulls for NOT NULL columns and allow_reviews with 422', function () {
    validationAdmin();

    // `specialty` is no longer an input field on the admin API (a null
    // value is simply ignored; specialty_id is the required input, which
    // this payload carries validly) — the nulls that still 422 are the
    // genuine NOT NULL columns plus allow_reviews.
    $this->postJson('/api/doctors', validationDoctorPayload([
        'email' => null,
        'phone' => null,
        'allow_reviews' => null,
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email', 'phone', 'allow_reviews']);
});

test('doctor store still allows omitting allow_reviews which falls back to the DB default', function () {
    validationAdmin();

    $payload = validationDoctorPayload();
    unset($payload['allow_reviews']);

    $response = $this->postJson('/api/doctors', $payload);

    $response->assertStatus(201);

    $stored = Doctor::find($response->json('data.id'));
    expect($stored->allow_reviews)->toBeTruthy();
});

test('doctor store still rejects duplicate email with 422', function () {
    $existing = Doctor::factory()->create();
    validationAdmin();

    $this->postJson('/api/doctors', validationDoctorPayload(['email' => $existing->email]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

test('doctor store rejects a non-image upload with 422', function () {
    validationAdmin();

    $this->post('/api/doctors', validationDoctorPayload([
        'image' => UploadedFile::fake()->create('payload.php', 16),
    ]), ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['image']);
});

test('doctor update rejects a missing specialty_id and null email/phone with 422', function () {
    $doctor = Doctor::factory()->create();
    validationAdmin();

    // A bare `specialty` value (even null) no longer satisfies the update
    // contract — every admin update must carry a valid specialty_id, so
    // omitting it 422s on that key.
    $this->patchJson("/api/doctors/{$doctor->id}", ['specialty' => null])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['specialty_id']);

    $this->patchJson("/api/doctors/{$doctor->id}", ['email' => null, 'specialty_id' => $doctor->specialty_id])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);

    $this->patchJson("/api/doctors/{$doctor->id}", ['phone' => null, 'specialty_id' => $doctor->specialty_id])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['phone']);
});

test('doctor update accepts a valid partial payload and preserves response shape', function () {
    $doctor = Doctor::factory()->create();
    $neurology = Specialty::firstOrCreate(['name' => 'Neurology']);
    validationAdmin();

    $this->patchJson("/api/doctors/{$doctor->id}", ['specialty_id' => $neurology->id])
        ->assertStatus(200)
        ->assertJsonStructure(['data' => ['id', 'specialty']])
        // The legacy string response field is preserved and re-synced from
        // the selected specialty record.
        ->assertJsonPath('data.specialty', 'Neurology')
        ->assertJsonPath('data.specialty_id', $neurology->id);

    $doctor->refresh();
    expect($doctor->specialty)->toBe('Neurology')
        ->and($doctor->specialty_id)->toBe($neurology->id);
});

/*
|--------------------------------------------------------------------------
| 4. Service description aligned with schema (create requires, update optional)
|--------------------------------------------------------------------------
*/

test('service can be created with a description and response shape is preserved', function () {
    validationAdmin();

    $this->postJson('/api/services', [
        'name' => 'Validation Test Service',
        'description' => 'A service used by validation tests.',
        'price' => 250,
    ])
        ->assertStatus(201)
        ->assertJsonStructure(['id', 'name', 'description', 'price', 'image_url', 'created_at', 'updated_at']);
});

test('service store rejects missing or null description with 422 instead of 500', function () {
    validationAdmin();

    $this->postJson('/api/services', ['name' => 'No Description', 'price' => 100])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['description']);

    $this->postJson('/api/services', [
        'name' => 'Null Description',
        'description' => null,
        'price' => 100,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['description']);
});

test('service update can omit description and keeps the stored value', function () {
    $service = Service::factory()->create(['description' => 'Original description']);
    validationAdmin();

    $this->patchJson("/api/services/{$service->id}", [
        'name' => 'Renamed Service',
        'price' => 300,
    ])
        ->assertStatus(200)
        ->assertJsonPath('name', 'Renamed Service');

    $service->refresh();
    expect($service->name)->toBe('Renamed Service')
        ->and($service->description)->toBe('Original description');
});

test('service update rejects explicit null description with 422', function () {
    $service = Service::factory()->create(['description' => 'Keep me']);
    validationAdmin();

    $this->patchJson("/api/services/{$service->id}", [
        'name' => 'Another Name',
        'price' => 100,
        'description' => null,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['description']);

    expect($service->refresh()->description)->toBe('Keep me');
});

/*
|--------------------------------------------------------------------------
| 5. Contact message subject aligned with schema
|--------------------------------------------------------------------------
*/

test('contact message can be submitted with subject and response shape is preserved', function () {
    $this->postJson('/api/contact-messages', [
        'name' => 'Validation Tester',
        'email' => 'validation.tester@example.com',
        'subject' => 'Validation batch test',
        'message' => 'Testing the contact endpoint after the subject rule fix.',
    ])
        ->assertStatus(201)
        ->assertJsonStructure([
            'message',
            'contact_message' => ['id', 'name', 'email', 'subject', 'message', 'created_at', 'updated_at'],
        ]);
});

test('contact message store rejects missing or null subject with 422 instead of 500', function () {
    $this->postJson('/api/contact-messages', [
        'name' => 'Validation Tester',
        'email' => 'validation.tester@example.com',
        'message' => 'No subject provided.',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['subject']);

    $this->postJson('/api/contact-messages', [
        'name' => 'Validation Tester',
        'email' => 'validation.tester@example.com',
        'subject' => null,
        'message' => 'Null subject provided.',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['subject']);
});
