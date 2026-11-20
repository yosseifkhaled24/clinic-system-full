<?php

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Review;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| SEC-20 — Public review privacy / reviewer anonymization
|--------------------------------------------------------------------------
|
| Public GET /api/reviews and /api/reviews/{id} must NOT expose:
|  - reviewer user IDs or names,
|  - appointment IDs or exact appointment dates,
|  - email addresses or phone numbers (never part of the payload, and
|    asserted here so they can never creep in).
|
| Kept public on purpose: rating, comment, doctor, review timestamps.
|
| Reveal rules (see ReviewController::revealReviewerDetails):
|  - admin        → full shape (moderation/dashboard views);
|  - the review's own owner → full shape (that reviewer only ever sees
|    THEIR OWN id/name/appointment — data they already have — which is
|    what the patient "Write Review" gate and the existing
|    ReviewButtonVisibilityTest contract rely on);
|  - everyone else (guests AND other authenticated users) → anonymized
|    shape on both index and show.
|
| Store/update responses keep the full shape (contract locked by
| ReviewAuthorizationTest). Ownership/eligibility rules untouched.
| In-memory SQLite only.
*/

function s20Review(array $overrides = []): Review
{
    $owner = User::factory()->create(['role' => 'user']);
    $patient = Patient::factory()->create(['user_id' => $owner->id]);
    $appointment = Appointment::factory()->create([
        'patient_id' => $patient->id,
        'user_id' => $owner->id,
        'appointment_date' => '2030-05-05',
        'status' => 'completed',
        'payment_status' => 'paid',
    ]);

    return Review::factory()->create(array_merge([
        'user_id' => $owner->id,
        'appointment_id' => $appointment->id,
        'rating' => 5,
        'comment' => 'A perfectly ordinary review comment.',
    ], $overrides));
}

function s20AssertAnonymized(array $entry): void
{
    expect($entry)->not->toHaveKey('appointment')
        ->and($entry['user'])->not->toHaveKey('id')
        ->and($entry['user']['name'])->toBeNull()
        ->and($entry['rating'])->toBe(5)
        ->and($entry['comment'])->not->toBeNull()
        ->and($entry['doctor']['name'])->not->toBeNull();
}

test('guest index is anonymized: no reviewer id/name, no appointment id/date, rating+text stay public', function () {
    $review = s20Review();

    $response = $this->getJson('/api/reviews')->assertStatus(200);

    $rows = $response->json();
    expect($rows)->toHaveCount(1);
    s20AssertAnonymized($rows[0]);

    // No PII strings anywhere in the raw payload.
    $content = (string) $response->getContent();
    expect($content)->not->toContain($review->user->email);
    expect($content)->not->toContain((string) $review->user->phone);
    expect($content)->not->toContain('2030-05-05');
    expect($content)->not->toContain('appointment_date');
});

test('guest show is anonymized too', function () {
    $review = s20Review();

    $response = $this->getJson("/api/reviews/{$review->id}")->assertStatus(200);

    $response->assertJsonMissingPath('user.id')
        ->assertJsonMissingPath('appointment')
        ->assertJsonPath('user.name', null)
        ->assertJsonPath('rating', 5)
        ->assertJsonPath('doctor.id', $review->doctor_id);

    $content = (string) $response->getContent();
    expect($content)->not->toContain($review->user->email);
    expect($content)->not->toContain('2030-05-05');
});

test('an authenticated STRANGER gets the anonymized shape as well (registration is open)', function () {
    $review = s20Review();
    Sanctum::actingAs(User::factory()->create(['role' => 'user']));

    $rows = $this->getJson('/api/reviews')->assertStatus(200)->json();
    s20AssertAnonymized($rows[0]);

    $this->getJson("/api/reviews/{$review->id}")
        ->assertStatus(200)
        ->assertJsonMissingPath('user.id')
        ->assertJsonMissingPath('appointment');
});

test('the admin keeps the full shape on index and show (moderation views)', function () {
    $review = s20Review();
    Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

    $rows = $this->getJson('/api/reviews')->assertStatus(200)->json();
    $entry = collect($rows)->firstWhere('id', $review->id);

    expect($entry['user']['id'])->toBe($review->user_id)
        ->and($entry['user']['name'])->not->toBeNull()
        ->and($entry['appointment']['id'])->toBe($review->appointment_id)
        ->and($entry['appointment']['appointment_date'])->toBe('2030-05-05');

    $this->getJson("/api/reviews/{$review->id}")
        ->assertStatus(200)
        ->assertJsonPath('user.id', $review->user_id)
        ->assertJsonPath('appointment.appointment_date', '2030-05-05');
});

test('the review owner sees their OWN review in full (write-review gate contract)', function () {
    $review = s20Review();
    Sanctum::actingAs(User::find($review->user_id));

    $rows = $this->getJson('/api/reviews')->assertStatus(200)->json();
    $mine = collect($rows)->firstWhere('id', $review->id);

    expect($mine['user']['id'])->toBe($review->user_id)
        ->and($mine['appointment']['id'])->toBe($review->appointment_id)
        ->and($mine['appointment']['appointment_date'])->toBe('2030-05-05');
});

test("other people's reviews stay anonymized even for a logged-in owner", function () {
    $mine = s20Review();
    $theirs = s20Review();

    Sanctum::actingAs(User::find($mine->user_id));

    $rows = $this->getJson('/api/reviews')->assertStatus(200)->json();

    $ownEntry = collect($rows)->firstWhere('id', $mine->id);
    $foreignEntry = collect($rows)->firstWhere('id', $theirs->id);

    expect($ownEntry)->toHaveKey('appointment')
        ->and($foreignEntry)->not->toHaveKey('appointment')
        ->and($foreignEntry['user'])->not->toHaveKey('id')
        ->and($foreignEntry['user']['name'])->toBeNull();
});
