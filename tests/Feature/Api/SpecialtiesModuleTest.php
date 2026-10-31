<?php

use App\Models\Doctor;
use App\Models\Specialty;
use App\Models\User;
use App\Services\ClinicContextService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| Specialties module tests
|--------------------------------------------------------------------------
|
| Covers the dedicated specialties table and the doctors.specialty_id
| link added by:
|
|   2026_10_29_000001_create_specialties_table.php
|   2026_10_29_000002_add_specialty_id_to_doctors_table.php
|
| Sections:
|   1. Specialty CRUD / management (admin API)
|   2. Doctor <-> specialty relations + validation (admin API contract)
|   3. Existing-data migration (legacy rows -> backfill + rollback)
|   4. Chatbot specialty recommendations still use real DB data
|   5. Factories and seeders produce linked, deterministic data
|
| Contracts locked throughout:
|  - `data.specialty` stays a plain STRING in every doctor/appointment
|    response (additive `specialty_id` key only).
|  - Admin doctor writes (Api/DoctorController) require a valid
|    `specialty_id` and reject free-text `specialty` (prohibited).
|  - Model-level writes (factories, seeders, tests passing
|    `'specialty' => 'X'`) keep working and always end up linked through
|    the Doctor::saving() hook.
|
| Everything runs on the isolated in-memory SQLite test database from
| phpunit.xml — no production data, no DB resets.
|
*/

// Unique helper names (Pest exposes test-file functions globally across
// the suite — AdminAuthorizationTest already owns adminActor/userActor).

function specialtiesAdmin(): User
{
    return User::factory()->create(['role' => 'admin']);
}

function specialtiesMember(): User
{
    return User::factory()->create(['role' => 'user']);
}

function specialtyDoctorPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Dr. Specialties Module',
        // Admin doctor writes require the id of a REAL specialty record;
        // free-text `specialty` is rejected by the API (prohibited).
        'specialty_id' => Specialty::firstOrCreate(['name' => 'Cardiology'])->id,
        'email' => 'dr.specialties.module@example.com',
        'phone' => '01012345678',
        'bio' => 'Specialties module fixture.',
        'allow_reviews' => true,
    ], $overrides);
}

/**
 * Insert doctors exactly like a PRE-migration database would hold them:
 * plain query builder, legacy string only, specialty_id untouched (NULL).
 * Bypasses the Doctor model on purpose so the saving hook — which links
 * automatically — never runs. This is the state the backfill must handle.
 *
 * @param list<array{0: string, 1: string}> $rows [name, specialty]
 */
function specialtiesInsertLegacyDoctors(array $rows): void
{
    foreach ($rows as $index => $row) {
        DB::table('doctors')->insert([
            'name' => $row[0],
            'specialty' => $row[1],
            'email' => 'legacy.'.$index.'@example.com',
            'phone' => '010000000'.($index + 1),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

function specialtiesMigration(): object
{
    return require base_path('database/migrations/2026_10_29_000002_add_specialty_id_to_doctors_table.php');
}

/*
|--------------------------------------------------------------------------
| 1. Specialty CRUD / management
|--------------------------------------------------------------------------
*/

test('specialties index and show are public and use the standard data wrapper', function () {
    $cardiology = Specialty::create(['name' => 'Cardiology']);
    $dermatology = Specialty::create(['name' => 'Dermatology']);

    $this->getJson('/api/specialties')
        ->assertStatus(200)
        ->assertJsonStructure(['data' => [['id', 'name', 'created_at', 'updated_at']]])
        ->assertJsonCount(2, 'data');

    // Sorted by name (Laravel default string order on this data).
    $names = collect($this->getJson('/api/specialties')->json('data'))->pluck('name')->all();
    expect($names)->toBe(['Cardiology', 'Dermatology']);

    $this->getJson("/api/specialties/{$cardiology->id}")
        ->assertStatus(200)
        ->assertJsonPath('data.id', $cardiology->id)
        ->assertJsonPath('data.name', 'Cardiology');

    $this->getJson("/api/specialties/{$dermatology->id}")->assertStatus(200);
});

test('an unknown specialty returns 404 with the standard message shape', function () {
    $this->getJson('/api/specialties/999999')
        ->assertStatus(404)
        ->assertJson(['message' => 'Specialty not found']);
});

test('guest cannot create a specialty (401) and non-admin cannot write specialties (403)', function () {
    $this->postJson('/api/specialties', ['name' => 'Cardiology'])->assertStatus(401);

    Sanctum::actingAs(specialtiesMember());

    $this->postJson('/api/specialties', ['name' => 'Cardiology'])->assertStatus(403);
    $this->putJson('/api/specialties/1', ['name' => 'Renamed'])->assertStatus(403);
    $this->deleteJson('/api/specialties/1')->assertStatus(403);

    expect(Specialty::count())->toBe(0);
});

test('admin can create a specialty and the stored name is normalized', function () {
    Sanctum::actingAs(specialtiesAdmin());

    $this->postJson('/api/specialties', ['name' => '  Cardiology  '])
        ->assertStatus(201)
        ->assertJsonStructure(['data' => ['id', 'name']])
        ->assertJsonPath('data.name', 'Cardiology');

    expect(Specialty::count())->toBe(1)
        ->and(Specialty::first()->name)->toBe('Cardiology');
});

test('specialty names are unique case-insensitively and whitespace-insensitively (422)', function () {
    Specialty::create(['name' => 'Cardiology']);
    Sanctum::actingAs(specialtiesAdmin());

    $this->postJson('/api/specialties', ['name' => 'cardiology'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name'])
        ->assertJsonPath('errors.name.0', 'The name has already been taken.');

    $this->postJson('/api/specialties', ['name' => ' CARDIOLOGY '])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);

    $this->postJson('/api/specialties', ['name' => 'cardiology   '])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);

    expect(Specialty::count())->toBe(1);
});

test('genuinely different specialty names are accepted alongside similar ones', function () {
    Specialty::create(['name' => 'Cardiology']);
    Sanctum::actingAs(specialtiesAdmin());

    // A longer, different name must NOT be treated as a duplicate.
    $this->postJson('/api/specialties', ['name' => 'Cardiology and Electrophysiology'])
        ->assertStatus(201);

    $this->postJson('/api/specialties', ['name' => 'Cardio'])
        ->assertStatus(201);

    expect(Specialty::count())->toBe(3);
});

test('whitespace-only, missing and oversized specialty names are rejected (422)', function () {
    Sanctum::actingAs(specialtiesAdmin());

    $this->postJson('/api/specialties', ['name' => '   '])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);

    $this->postJson('/api/specialties', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);

    $this->postJson('/api/specialties', ['name' => str_repeat('a', 256)])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);

    expect(Specialty::count())->toBe(0);
});

test('admin can rename a specialty and linked doctors keep a correct string mirror', function () {
    $specialty = Specialty::create(['name' => 'Cardiology']);
    $doctor = Doctor::factory()->create(['specialty' => 'Cardiology']);
    Sanctum::actingAs(specialtiesAdmin());

    $this->patchJson("/api/specialties/{$specialty->id}", ['name' => '  Heart Medicine '])
        ->assertStatus(200)
        ->assertJsonPath('data.name', 'Heart Medicine');

    // Same row (no recreate), same link, mirror updated on the doctor.
    expect($specialty->refresh()->name)->toBe('Heart Medicine')
        ->and($doctor->refresh()->specialty_id)->toBe($specialty->id)
        ->and($doctor->specialty)->toBe('Heart Medicine')
        ->and(DB::table('doctors')->where('specialty', 'Cardiology')->count())->toBe(0);
});

test('renaming a specialty to a name used by another specialty is rejected (422)', function () {
    Specialty::create(['name' => 'Cardiology']);
    $dermatology = Specialty::create(['name' => 'Dermatology']);
    Sanctum::actingAs(specialtiesAdmin());

    $this->patchJson("/api/specialties/{$dermatology->id}", ['name' => 'cardiology'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);

    expect($dermatology->refresh()->name)->toBe('Dermatology');

    // Renaming to itself is not a duplicate of anything else.
    $this->patchJson("/api/specialties/{$dermatology->id}", ['name' => 'Dermatology'])
        ->assertStatus(200);
});

test('an unused specialty can be deleted (204) and disappears', function () {
    $specialty = Specialty::create(['name' => 'Orthopedics']);
    Sanctum::actingAs(specialtiesAdmin());

    $this->deleteJson("/api/specialties/{$specialty->id}")->assertStatus(204);

    expect(Specialty::find($specialty->id))->toBeNull()
        ->and($this->getJson('/api/specialties')->json('data'))->toBe([]);
});

test('a specialty assigned to doctors is refused with 409 and nothing is deleted', function () {
    $specialty = Specialty::create(['name' => 'Neurology']);
    $doctor = Doctor::factory()->create(['specialty' => 'Neurology']);
    Sanctum::actingAs(specialtiesAdmin());

    $this->deleteJson("/api/specialties/{$specialty->id}")
        ->assertStatus(409)
        ->assertJson([
            'message' => 'Cannot delete a specialty that is assigned to doctors.',
        ]);

    // Neither the specialty nor the doctor nor the link was touched —
    // deletion strategy never cascades to doctors.
    expect(Specialty::find($specialty->id))->not->toBeNull()
        ->and(Doctor::find($doctor->id))->not->toBeNull()
        ->and($doctor->refresh()->specialty_id)->toBe($specialty->id)
        ->and($doctor->specialty)->toBe('Neurology');
});

test('the database itself refuses to delete a referenced specialty (restrict)', function () {
    // Hard backstop behind the API's 409: even a raw query outside the
    // application cannot remove a specialty doctors still reference.
    $specialty = Specialty::create(['name' => 'Pediatrics']);
    Doctor::factory()->create(['specialty' => 'Pediatrics']);

    $rejected = false;

    try {
        DB::table('specialties')->where('id', $specialty->id)->delete();
    } catch (\Illuminate\Database\QueryException $e) {
        $rejected = true;
    }

    expect($rejected)->toBeTrue()
        ->and(Specialty::find($specialty->id))->not->toBeNull()
        ->and(Doctor::count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| 2. Doctor <-> specialty relations + validation (admin API contract)
|--------------------------------------------------------------------------
*/

test('a doctor created from a specialty string is linked to a matching specialties row', function () {
    $doctor = Doctor::factory()->create(['specialty' => 'Cardiology']);

    expect($doctor->specialty_id)->not->toBeNull()
        ->and($doctor->specialty)->toBe('Cardiology')          // still a plain string
        ->and(Specialty::count())->toBe(1)
        ->and($doctor->specialty()->value('name'))->toBe('Cardiology');
});

test('specialty strings differing only by case or whitespace share ONE row with canonical strings', function () {
    $first = Doctor::factory()->create(['specialty' => 'Neurology']);
    $second = Doctor::factory()->create(['specialty' => ' neurology ']);
    $third = Doctor::factory()->create(['specialty' => 'NEUROLOGY']);

    expect(Specialty::count())->toBe(1)
        ->and($second->refresh()->specialty_id)->toBe($first->specialty_id)
        ->and($third->refresh()->specialty_id)->toBe($first->specialty_id)
        // First-seen spelling is canonical for every mirrored string.
        ->and($first->refresh()->specialty)->toBe('Neurology')
        ->and($second->refresh()->specialty)->toBe('Neurology')
        ->and($third->refresh()->specialty)->toBe('Neurology');
});

test('genuinely different specialty strings never merge into one row', function () {
    Doctor::factory()->create(['specialty' => 'Cardiology']);
    Doctor::factory()->create(['specialty' => 'Cardiology and Electrophysiology']);
    Doctor::factory()->create(['specialty' => 'Cardio']);

    expect(Specialty::count())->toBe(3);
});

test('the doctor API keeps returning a plain-string specialty and now also exposes specialty_id', function () {
    Sanctum::actingAs(specialtiesAdmin());

    $response = $this->postJson('/api/doctors', specialtyDoctorPayload())
        ->assertStatus(201)
        ->assertJsonStructure([
            'data' => ['id', 'name', 'specialty', 'specialty_id', 'email', 'phone', 'bio', 'allow_reviews', 'image_url'],
        ]);

    // The frontend contract: `specialty` is a STRING, never an object.
    expect(is_string($response->json('data.specialty')))->toBeTrue()
        ->and($response->json('data.specialty'))->toBe('Cardiology')
        ->and($response->json('data.specialty_id'))->toBe(Specialty::where('name', 'Cardiology')->value('id'));

    // The public read endpoints keep the same shape too.
    $doctorId = $response->json('data.id');
    $this->getJson("/api/doctors/{$doctorId}")
        ->assertStatus(200)
        ->assertJsonPath('data.specialty', 'Cardiology');

    $this->getJson('/api/doctors')
        ->assertStatus(200)
        ->assertJsonStructure(['data' => [['id', 'name', 'specialty']]])
        ->assertJsonPath('data.0.specialty', 'Cardiology');
});

test('a doctor is created from specialty_id with the legacy string mirrored', function () {
    $specialty = Specialty::create(['name' => 'Dermatology']);
    Sanctum::actingAs(specialtiesAdmin());

    // specialty_id is the ONLY specialty input the API accepts now.
    $payload = specialtyDoctorPayload(['specialty_id' => $specialty->id]);

    $this->postJson('/api/doctors', $payload)
        ->assertStatus(201)
        ->assertJsonPath('data.specialty', 'Dermatology')
        ->assertJsonPath('data.specialty_id', $specialty->id);

    $doctor = Doctor::where('email', 'dr.specialties.module@example.com')->firstOrFail();
    expect($doctor->specialty)->toBe('Dermatology')
        ->and($doctor->specialty_id)->toBe($specialty->id);
});

test('free-text specialty is rejected on doctor create and update (422)', function () {
    $doctor = Doctor::factory()->create(['specialty' => 'Dermatology']);
    Sanctum::actingAs(specialtiesAdmin());

    // Create: free text alone reports BOTH problems — the prohibited
    // `specialty` key and the missing required `specialty_id` key.
    $payload = specialtyDoctorPayload();
    unset($payload['specialty_id']);
    $payload['specialty'] = 'Cardiology';

    $this->postJson('/api/doctors', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['specialty', 'specialty_id']);

    // Update: free text alone is rejected the same way.
    $this->patchJson("/api/doctors/{$doctor->id}", ['specialty' => 'Pediatrics'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['specialty', 'specialty_id']);

    // Nothing changed: the doctor keeps its original link and the free
    // text never became a specialty record.
    $doctor->refresh();
    expect($doctor->specialty)->toBe('Dermatology')
        ->and($doctor->specialty()->value('name'))->toBe('Dermatology')
        ->and(Specialty::where('name', 'Pediatrics')->exists())->toBeFalse()
        ->and(Doctor::count())->toBe(1);
});

test('a request carrying both specialty and specialty_id is rejected (422)', function () {
    $doctor = Doctor::factory()->create(['specialty' => 'Dermatology']);
    $neurology = Specialty::create(['name' => 'Neurology']);
    Sanctum::actingAs(specialtiesAdmin());

    // Conflicting pairs can never reach the model — the free-text field
    // is rejected outright, so there is no "last one wins" ambiguity.
    $this->postJson('/api/doctors', specialtyDoctorPayload([
        'specialty_id' => $neurology->id,
        'specialty' => 'Free Text',
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['specialty']);

    $this->patchJson("/api/doctors/{$doctor->id}", [
        'specialty_id' => $neurology->id,
        'specialty' => 'Free Text',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['specialty']);

    $doctor->refresh();
    expect($doctor->specialty)->toBe('Dermatology')
        ->and($doctor->specialty_id)->not->toBe($neurology->id)
        ->and(Specialty::where('name', 'Free Text')->exists())->toBeFalse()
        ->and(Doctor::count())->toBe(1);
});

test('changing specialty_id re-links the doctor and re-syncs the legacy string', function () {
    $doctor = Doctor::factory()->create(['specialty' => 'Dermatology']);
    $neurology = Specialty::create(['name' => 'Neurology']);
    Sanctum::actingAs(specialtiesAdmin());

    $this->patchJson("/api/doctors/{$doctor->id}", ['specialty_id' => $neurology->id])
        ->assertStatus(200)
        ->assertJsonPath('data.specialty', 'Neurology')
        ->assertJsonPath('data.specialty_id', $neurology->id);

    $doctor->refresh();
    expect($doctor->specialty)->toBe('Neurology')
        ->and($doctor->specialty_id)->toBe($neurology->id);
});

test('doctor writes require a valid specialty_id and reject invalid ones', function () {
    Sanctum::actingAs(specialtiesAdmin());

    // No specialty field at all -> 422 on the required specialty_id.
    $payload = specialtyDoctorPayload();
    unset($payload['specialty_id']);

    $this->postJson('/api/doctors', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['specialty_id']);

    // Unknown / non-numeric specialty_id -> 422 on specialty_id.
    $this->postJson('/api/doctors', specialtyDoctorPayload(['specialty_id' => 999999]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['specialty_id']);

    $this->postJson('/api/doctors', specialtyDoctorPayload(['specialty_id' => 'abc']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['specialty_id']);

    // Partial updates must carry specialty_id too. (The factory doctor is
    // pinned to Dermatology so the final Cardiology check below is
    // deterministic — the factory's random pool pick must not decide it.)
    $doctor = Doctor::factory()->create(['specialty' => 'Dermatology']);

    $this->patchJson("/api/doctors/{$doctor->id}", ['name' => 'Renamed Doctor'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['specialty_id']);

    // A null `specialty` satisfies nothing: it is ignored (prohibited
    // treats null as absence) and the missing id still 422s.
    $this->patchJson("/api/doctors/{$doctor->id}", ['specialty' => null])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['specialty_id']);

    // None of the invalid payloads landed.
    expect(Doctor::count())->toBe(1)
        ->and($doctor->refresh()->name)->not->toBe('Renamed Doctor')
        ->and(Specialty::where('name', 'Cardiology')->whereHas('doctors')->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| 3. Existing-data migration (legacy rows -> backfill, and rollback)
|--------------------------------------------------------------------------
*/

test('the backfill links legacy doctors, collapses case/whitespace dupes and loses nothing', function () {
    // A pre-migration database: only the legacy string column is filled.
    specialtiesInsertLegacyDoctors([
        ['Dr. Legacy One', 'Cardiology'],
        ['Dr. Legacy Two', '  cardiology  '],
        ['Dr. Legacy Three', 'Neurology'],
        ['Dr. Legacy Four', 'Neurology '],
        ['Dr. Legacy Five', 'Dentistry'],
    ]);

    expect(DB::table('doctors')->whereNull('specialty_id')->count())->toBe(5)
        ->and(Specialty::count())->toBe(0);

    // Re-run the real migration's up(): the hasColumn/hasTable guards
    // skip DDL, the backfill does its job — this is the code path a
    // production `php artisan migrate` executes for existing rows.
    specialtiesMigration()->up();

    // Every doctor survived and every doctor is linked.
    expect(DB::table('doctors')->count())->toBe(5)
        ->and(DB::table('doctors')->whereNull('specialty_id')->count())->toBe(0)
        ->and(DB::table('doctors')->orderBy('id')->pluck('email')->all())
        ->toBe(['legacy.0@example.com', 'legacy.1@example.com', 'legacy.2@example.com', 'legacy.3@example.com', 'legacy.4@example.com']);

    // Case/whitespace variants collapsed into ONE row; distinct names stayed.
    expect(Specialty::count())->toBe(3)
        ->and(Specialty::orderBy('id')->pluck('name')->all())
        ->toBe(['Cardiology', 'Neurology', 'Dentistry']);

    // Each doctor's mirror equals its linked specialty's name, and the
    // dirty variants were canonicalized to the first-seen spelling.
    $doctors = DB::table('doctors')->orderBy('id')->get(['specialty', 'specialty_id']);
    $namesById = Specialty::pluck('name', 'id');

    foreach ($doctors as $doctor) {
        expect($namesById[$doctor->specialty_id])->toBe($doctor->specialty);
    }

    expect($doctors[0]->specialty)->toBe('Cardiology')
        ->and($doctors[1]->specialty)->toBe('Cardiology')
        ->and($doctors[2]->specialty)->toBe('Neurology')
        ->and($doctors[3]->specialty)->toBe('Neurology')
        ->and($doctors[4]->specialty)->toBe('Dentistry');

    // Idempotent: re-running up() changes nothing at all.
    specialtiesMigration()->up();

    expect(Specialty::count())->toBe(3)
        ->and(DB::table('doctors')->count())->toBe(5)
        ->and(DB::table('doctors')->whereNull('specialty_id')->count())->toBe(0);
});

test('rollback removes only the link and keeps every doctor, string and specialty row', function () {
    specialtiesInsertLegacyDoctors([
        ['Dr. Rollback One', 'Cardiology'],
        ['Dr. Rollback Two', ' cardiology '],
    ]);

    $migration = specialtiesMigration();
    $migration->up();

    expect(Schema::hasColumn('doctors', 'specialty_id'))->toBeTrue()
        ->and(Specialty::count())->toBe(1);

    $stringsBefore = DB::table('doctors')->orderBy('id')->pluck('specialty')->all();
    $emailsBefore = DB::table('doctors')->orderBy('id')->pluck('email')->all();

    $migration->down();

    // Only the link column is gone; no doctor row, no specialty row and
    // no specialty string was deleted or lost.
    expect(Schema::hasColumn('doctors', 'specialty_id'))->toBeFalse()
        ->and(DB::table('doctors')->count())->toBe(2)
        ->and(DB::table('doctors')->orderBy('id')->pluck('specialty')->all())->toBe($stringsBefore)
        ->and(DB::table('doctors')->orderBy('id')->pluck('email')->all())->toBe($emailsBefore)
        ->and(DB::table('doctors')->orderBy('id')->pluck('specialty')->all())
        ->toBe(['Cardiology', 'Cardiology'])
        // The specialties table belongs to its own migration's down().
        ->and(Schema::hasTable('specialties'))->toBeTrue()
        ->and(Specialty::count())->toBe(1);

    // Re-running down() must be safe too.
    $migration->down();
    expect(DB::table('doctors')->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| 4. Chatbot specialty recommendations use real DB data
|--------------------------------------------------------------------------
*/

test('chatbot clinic context lists only real staffed specialties from the specialties table', function () {
    // Staffed specialty (via the legacy free-text write path) ...
    Doctor::factory()->create(['specialty' => 'Cardiology']);
    // ... and an unassigned specialty row that must NEVER be recommended.
    Specialty::create(['name' => 'Oncology']);

    $context = app(ClinicContextService::class)->build();

    expect($context['specialties'])
        ->toContain('Cardiology')
        ->not->toContain('Oncology')
        ->toBe(['Cardiology']);

    // Doctor entries still expose the plain string the prompt expects.
    expect($context['doctors'])->toHaveCount(1)
        ->and($context['doctors'][0]['specialty'])->toBe('Cardiology');
});

test('the chatbot system instruction carries only staffed DB specialties', function () {
    config([
        'services.gemini.key' => 'specialties_test_key_do_not_leak_555',
        'services.gemini.model' => 'gemini-specialty-test-model',
        'services.gemini.retry_backoff_ms' => 0,
        'services.gemini.retry_max_ms' => 0,
    ]);

    Doctor::factory()->create(['specialty' => 'Cardiology']);
    Specialty::create(['name' => 'Oncology']); // no doctor -> must not appear

    $staffedDoctor = Doctor::firstOrFail();

    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
        'candidates' => [[
            'content' => ['role' => 'model', 'parts' => [['text' => 'We list Cardiology here.']]],
            'finishReason' => 'STOP',
        ]],
    ], 200)]);

    $this->postJson('/api/chatbot', [
        'message' => 'What specialties do you have at the clinic?',
        'language' => 'en',
    ])->assertOk()->assertJsonPath('model', 'gemini-specialty-test-model');

    $captured = null;
    Http::assertSent(function ($request) use (&$captured) {
        if (str_contains($request->url(), 'generativelanguage.googleapis.com')) {
            $captured = $request->data();
        }

        return true;
    });

    $system = $captured['systemInstruction']['parts'][0]['text'];

    // Real, staffed specialty present; unassigned row absent; doctor list intact.
    expect($system)
        ->toContain('Cardiology')
        ->not->toContain('Oncology')
        ->toContain($staffedDoctor->name); // doctors still come from the doctors table
});

/*
|--------------------------------------------------------------------------
| 5. Factories and seeders produce linked, deterministic data
|--------------------------------------------------------------------------
*/

test('doctors created by the factory are linked to real specialty records by default', function () {
    $doctor = Doctor::factory()->create();

    expect($doctor->specialty_id)->not->toBeNull()
        ->and(Specialty::whereKey($doctor->specialty_id)->exists())->toBeTrue()
        // The legacy string mirrors the linked record's canonical name.
        ->and($doctor->specialty)->toBe($doctor->specialty()->value('name'));
});

test('a factory specialty_id override produces a consistent id/string pair', function () {
    $specialty = Specialty::create(['name' => 'Oncology']);

    $doctor = Doctor::factory()->create(['specialty_id' => $specialty->id]);

    // The explicit FK wins and the string is mirrored from the record —
    // the definition's random pool string never survives as a mismatch.
    expect($doctor->specialty_id)->toBe($specialty->id)
        ->and($doctor->specialty)->toBe('Oncology')
        ->and($doctor->specialty()->value('name'))->toBe('Oncology');
});

test('a factory free-text override still resolves a linked specialty record', function () {
    $doctor = Doctor::factory()->create(['specialty' => 'Physiotherapy']);

    $linked = Specialty::whereRaw('LOWER(name) = LOWER(?)', ['Physiotherapy'])->first();

    expect($linked)->not->toBeNull()
        ->and($doctor->specialty_id)->toBe($linked->id)
        ->and($doctor->specialty)->toBe($linked->name);
});

test('SpecialtySeeder is idempotent — re-running never duplicates specialties', function () {
    $this->seed(SpecialtySeeder::class);
    $this->seed(SpecialtySeeder::class);

    expect(Specialty::count())->toBe(count(SpecialtySeeder::NAMES))
        // Stable order and stable names across runs.
        ->and(Specialty::orderBy('id')->pluck('name')->all())
        ->toBe(SpecialtySeeder::NAMES);
});

test('DatabaseSeeder links every doctor to a seeded specialty record with synced strings', function () {
    // Spatie media uploads resolve through the `public` disk — fake it so
    // the real seeder can run inside the test without touching real files.
    Storage::fake('public');

    $this->seed(DatabaseSeeder::class);

    // One doctor per seeded specialty, none of them unlinked, and no
    // specialty beyond the seeder's stable list.
    expect(Doctor::count())->toBe(count(SpecialtySeeder::NAMES))
        ->and(Doctor::whereNull('specialty_id')->count())->toBe(0)
        ->and(Specialty::count())->toBe(count(SpecialtySeeder::NAMES))
        ->and(Specialty::orderBy('id')->pluck('name')->all())
        ->toBe(SpecialtySeeder::NAMES);

    // Every doctor references a REAL seeded specialty record (resolved by
    // name — no hardcoded IDs) and its legacy string mirrors that record.
    $namesById = Specialty::pluck('name', 'id');

    foreach (Doctor::all() as $doctor) {
        expect($namesById[$doctor->specialty_id])->toBe($doctor->specialty);
    }

    // Deterministic assignment: each specialty has exactly one seeded doctor.
    expect(
        DB::table('doctors')
            ->groupBy('specialty_id')
            ->havingRaw('count(*) != 1')
            ->get()
    )->toBeEmpty();
});
