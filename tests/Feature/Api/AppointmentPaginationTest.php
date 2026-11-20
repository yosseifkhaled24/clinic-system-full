<?php

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| Phase 2 — GET /api/appointments pagination contract
|--------------------------------------------------------------------------
|
| The index endpoint used to answer with a bare JSON array of every row
| the caller could see. It now ALWAYS answers with a paginated envelope
| (default page size 15, hard maximum 50) plus optional server-side
| `search` / `status` filters.
|
| These tests lock the contract that every consumer was migrated to:
|  - envelope keys and the 15-row default,
|  - the 50-row ceiling and 422 on anything outside the contract,
|  - no duplicated / dropped rows across page boundaries (stable order),
|  - admin sees everything, a patient sees only their own — even when
|    filters are in play (filters may narrow, never widen, visibility),
|  - search / status filters that keep working beyond the first page,
|  - the formatted row is byte-identical to GET /api/appointments/{id},
|  - a fixed query count (no N+1: one count, one page select, three
|    eager loads),
|  - the existing status / cancellation actions still work when the
|    caller only holds a page of rows.
|
| Actors are always created with an EXPLICIT role so the privilege each
| test depends on is stated at the call site (UserFactory now defaults
| to 'user' and offers an ->admin() state). All tests run against the
| isolated in-memory SQLite test database from phpunit.xml. No
| production data, no schema changes, no external services.
|
| Helper names are prefixed `apg` so they can never collide with the
| helpers of the other feature files when the whole suite loads.
*/

function apgAdmin(): User
{
    return User::factory()->create(['role' => 'admin']);
}

function apgUser(): User
{
    return User::factory()->create(['role' => 'user']);
}

/**
 * One patient + one doctor + one service, shared by a whole batch of
 * appointments so a 55-row page test stays a handful of inserts instead
 * of 200.
 *
 * @return array{0: Patient, 1: Doctor, 2: Service}
 */
function apgFixtures(User $owner, ?string $patientName = null): array
{
    $attributes = ['user_id' => $owner->id];

    if ($patientName !== null) {
        $attributes['name'] = $patientName;
    }

    $patient = Patient::factory()->create($attributes);

    return [$patient, Doctor::factory()->create(), Service::factory()->create()];
}

/**
 * Monotonic queue number.
 *
 * The appointments table carries a UNIQUE index on
 * (doctor_id, appointment_date, queue_number), so a batch that reuses one
 * doctor would otherwise collide whenever the factory drew the same random
 * (date, queue) pair twice. A process-wide counter keeps every generated
 * row inside the same doctor unique without touching the order the tests
 * actually assert on (appointment_date, doctor, queue_number, id).
 */
function apgNextQueue(): int
{
    static $queue = 0;

    return ++$queue;
}

/**
 * @param  array{0: Patient, 1: Doctor, 2: Service}  $fixtures
 */
function apgRow(array $fixtures, array $overrides = []): Appointment
{
    [$patient, $doctor, $service] = $fixtures;

    return Appointment::factory()->create(array_merge([
        'patient_id' => $patient->id,
        'user_id' => $patient->user_id,
        'doctor_id' => $doctor->id,
        'service_id' => $service->id,
        'queue_number' => apgNextQueue(),
    ], $overrides));
}

/**
 * @param  array{0: Patient, 1: Doctor, 2: Service}  $fixtures
 */
function apgRows(int $count, array $fixtures, array $overrides = []): void
{
    foreach (range(1, $count) as $ignored) {
        apgRow($fixtures, $overrides);
    }
}

/*
|--------------------------------------------------------------------------
| 1. Envelope & default page size
|--------------------------------------------------------------------------
*/

test('index answers with a paginated envelope and a 15-row default page', function () {
    apgRows(20, apgFixtures(apgUser()));

    Sanctum::actingAs(apgAdmin());

    $response = $this->getJson('/api/appointments')->assertStatus(200);

    $response->assertJsonStructure([
        'data' => ['*' => [
            'id',
            'doctor' => ['id', 'name', 'specialty'],
            'service' => ['id', 'name', 'price'],
            'payment' => ['status', 'method', 'paid_at'],
            'patient' => ['id', 'name', 'phone', 'email'],
            'queue_number',
            'appointment_date',
            'status',
            'notes',
            'needs_investigation',
            'created_at',
            'updated_at',
        ]],
        'current_page',
        'per_page',
        'total',
        'last_page',
        'from',
        'to',
        'path',
        'prev_page_url',
        'next_page_url',
    ]);

    $json = $response->json();

    expect($json['data'])->toHaveCount(15)
        ->and($json['current_page'])->toBe(1)
        ->and($json['per_page'])->toBe(15)
        ->and($json['total'])->toBe(20)
        ->and($json['last_page'])->toBe(2)
        ->and($json['from'])->toBe(1)
        ->and($json['to'])->toBe(15)
        ->and($json['prev_page_url'])->toBeNull()
        ->and($json['next_page_url'])->toContain('page=2')
        ->and($json['path'])->toContain('/api/appointments');
});

/*
|--------------------------------------------------------------------------
| 2. Stable ordering — no duplicate, no missing row across pages
|--------------------------------------------------------------------------
*/

test('consecutive pages never duplicate or drop a row and keep the documented order', function () {
    $fixtures = apgFixtures(apgUser());
    $base = now()->addDays(10)->startOfDay();

    // Deliberately created back-to-front so the asserted order cannot be
    // an accident of insertion order.
    foreach (array_reverse(range(1, 40)) as $i) {
        apgRow($fixtures, [
            'appointment_date' => $base->copy()->addDays($i % 7)->toDateString(),
        ]);
    }

    Sanctum::actingAs(apgAdmin());

    $rows = [];

    foreach ([1, 2, 3] as $page) {
        $rows = array_merge(
            $rows,
            $this->getJson("/api/appointments?page={$page}&per_page=15")
                ->assertStatus(200)
                ->json('data')
        );
    }

    $ids = array_column($rows, 'id');

    expect($ids)->toHaveCount(40)
        ->and(array_unique($ids))->toHaveCount(40);

    // Exactly the rows that exist — nothing repeated, nothing skipped.
    $seen = $ids;
    sort($seen);

    expect($seen)->toEqual(Appointment::pluck('id')->sort()->values()->all());

    // The concatenated pages are still sorted by the documented admin
    // key: (appointment_date, doctor, queue_number, id).
    $keys = array_map(
        fn (array $row) => [$row['appointment_date'], $row['doctor']['id'], $row['queue_number']],
        $rows
    );

    $sorted = $keys;
    usort($sorted, fn (array $a, array $b) => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);

    expect($keys)->toEqual($sorted);
});

/*
|--------------------------------------------------------------------------
| 3. per_page limits
|--------------------------------------------------------------------------
*/

test('per_page accepts up to the 50-row maximum and pages with it', function () {
    apgRows(55, apgFixtures(apgUser()));

    Sanctum::actingAs(apgAdmin());

    $first = $this->getJson('/api/appointments?per_page=50&page=1')->assertStatus(200);
    $second = $this->getJson('/api/appointments?per_page=50&page=2')->assertStatus(200);

    expect($first->json('data'))->toHaveCount(50)
        ->and($first->json('per_page'))->toBe(50)
        ->and($first->json('total'))->toBe(55)
        ->and($first->json('last_page'))->toBe(2)
        ->and($first->json('from'))->toBe(1)
        ->and($first->json('to'))->toBe(50)
        ->and($second->json('data'))->toHaveCount(5)
        ->and($second->json('from'))->toBe(51)
        ->and($second->json('to'))->toBe(55);

    // The two pages together are the whole set, in order, once each.
    $ids = array_merge(
        array_column($first->json('data'), 'id'),
        array_column($second->json('data'), 'id')
    );

    expect($ids)->toHaveCount(55)
        ->and(array_unique($ids))->toHaveCount(55);
});

test('per_page is capped at 50 — anything larger is rejected with 422', function () {
    apgRows(60, apgFixtures(apgUser()));

    Sanctum::actingAs(apgAdmin());

    $this->getJson('/api/appointments?per_page=51')->assertStatus(422);
    $this->getJson('/api/appointments?per_page=500')->assertStatus(422);
    $this->getJson('/api/appointments?per_page=abc')->assertStatus(422);
    $this->getJson('/api/appointments?per_page=0')->assertStatus(422);
    $this->getJson('/api/appointments?per_page=-1')->assertStatus(422);

    // Boundary values inside the contract are accepted.
    $this->getJson('/api/appointments?per_page=50')->assertStatus(200)
        ->assertJsonPath('per_page', 50);
    $this->getJson('/api/appointments?per_page=1&page=1')->assertStatus(200)
        ->assertJsonPath('per_page', 1)
        ->assertJsonPath('data', function ($rows) {
            return count($rows) === 1;
        });
});

/*
|--------------------------------------------------------------------------
| 4. Invalid page / filter values
|--------------------------------------------------------------------------
*/

test('invalid page and status values are rejected with 422', function () {
    apgRows(3, apgFixtures(apgUser()));

    Sanctum::actingAs(apgAdmin());

    foreach ([
        'page=0',
        'page=-1',
        'page=abc',
        'page=1.5',
        'page[]=2',
        'status=not_a_status',
    ] as $queryString) {
        $this->getJson("/api/appointments?{$queryString}")
            ->assertStatus(422)
            ->assertJsonStructure(['errors']);
    }
});

/*
|--------------------------------------------------------------------------
| 5. Page past the end
|--------------------------------------------------------------------------
*/

test('a page past the end answers 200 with an empty page, not an error', function () {
    apgRows(3, apgFixtures(apgUser()));

    Sanctum::actingAs(apgAdmin());

    $response = $this->getJson('/api/appointments?page=99')->assertStatus(200);

    $response->assertJsonPath('data', [])
        ->assertJsonPath('current_page', 99)
        ->assertJsonPath('total', 3)
        ->assertJsonPath('last_page', 1)
        ->assertJsonPath('per_page', 15);
});

/*
|--------------------------------------------------------------------------
| 6. Visibility — admin across pages / patient ownership
|--------------------------------------------------------------------------
*/

test('admin sees every appointment across all pages', function () {
    $firstOwner = apgUser();
    $secondOwner = apgUser();

    apgRows(16, apgFixtures($firstOwner));
    apgRows(16, apgFixtures($secondOwner));

    Sanctum::actingAs(apgAdmin());

    $ids = [];

    foreach ([1, 2, 3] as $page) {
        $response = $this->getJson("/api/appointments?page={$page}&per_page=15")
            ->assertStatus(200);

        expect($response->json('current_page'))->toBe($page);

        $ids = array_merge($ids, array_column($response->json('data'), 'id'));
    }

    expect($ids)->toHaveCount(32)
        ->and(array_unique($ids))->toHaveCount(32);

    // Both patients are present — the admin scope was not narrowed.
    $patientIds = Patient::whereIn('user_id', [$firstOwner->id, $secondOwner->id])->pluck('id');
    $seenPatientIds = collect($ids)
        ->map(fn ($id) => Appointment::find($id)->patient_id);

    expect($seenPatientIds->unique()->count())->toBe(2)
        ->and($seenPatientIds->every(fn ($pid) => $patientIds->contains($pid)))->toBeTrue();
});

test('a patient only ever receives their own appointments, across every page', function () {
    $owner = apgUser();
    $stranger = apgUser();

    apgRows(20, apgFixtures($owner));
    apgRows(10, apgFixtures($stranger));

    Sanctum::actingAs($owner);

    $ids = [];

    foreach ([1, 2] as $page) {
        $response = $this->getJson("/api/appointments?page={$page}&per_page=15")
            ->assertStatus(200);

        expect($response->json('total'))->toBe(20)
            ->and($response->json('last_page'))->toBe(2);

        $ids = array_merge($ids, array_column($response->json('data'), 'id'));
    }

    // Newest-first (patient order), which is also id-descending here
    // because every row was created inside the same test — so the same
    // array proves both the set (no dup, no drop) and the ordering.
    $expected = Appointment::where('user_id', $owner->id)
        ->pluck('id')->sortDesc()->values()->all();

    $seen = $ids;
    rsort($seen, SORT_NUMERIC);

    expect($ids)->toHaveCount(20)
        ->and(array_unique($ids))->toHaveCount(20)
        ->and($seen)->toEqual($expected)
        ->and($ids)->toEqual($seen);

    // Nothing belonging to the stranger leaked in.
    $strangerIds = Appointment::where('user_id', $stranger->id)->pluck('id')->all();
    expect(array_intersect($ids, $strangerIds))->toBe([]);
});

/*
|--------------------------------------------------------------------------
| 7. Server-side search across pages
|--------------------------------------------------------------------------
*/

test('search is evaluated server-side and keeps matching beyond page 1', function () {
    $markerOwner = apgUser();
    $markerFixtures = apgFixtures($markerOwner, 'Qqz Paginationmarker');
    apgRows(20, $markerFixtures);

    $otherOwner = apgUser();
    $otherFixtures = apgFixtures($otherOwner, 'Nobody Special');
    apgRows(3, $otherFixtures);

    // Also exercises the third match column: appointment status.
    apgRow($otherFixtures, ['status' => 'pending']);

    Sanctum::actingAs(apgAdmin());

    $first = $this->getJson('/api/appointments?search=Paginationmarker&page=1&per_page=15')
        ->assertStatus(200);
    $second = $this->getJson('/api/appointments?search=Paginationmarker&page=2&per_page=15')
        ->assertStatus(200);

    expect($first->json('total'))->toBe(20)
        ->and($first->json('last_page'))->toBe(2)
        ->and($first->json('data'))->toHaveCount(15)
        ->and($second->json('data'))->toHaveCount(5);

    $rows = array_merge($first->json('data'), $second->json('data'));

    expect(array_unique(array_column($rows, 'id')))->toHaveCount(20);

    foreach ($rows as $row) {
        expect($row['patient']['name'])->toContain('Paginationmarker');
    }

    // Status is a search column too (the admin box matched status before).
    $byStatus = $this->getJson('/api/appointments?search=pending&per_page=50')
        ->assertStatus(200);

    expect($byStatus->json('total'))->toBeGreaterThan(0);

    foreach ($byStatus->json('data') as $row) {
        expect($row['status'])->toBe('pending');
    }

    // Search narrows, it never widens: a patient cannot reach another
    // patient's rows by searching for them.
    Sanctum::actingAs($otherOwner);
    $this->getJson('/api/appointments?search=Paginationmarker')
        ->assertStatus(200)
        ->assertJsonPath('total', 0)
        ->assertJsonPath('data', []);
});

/*
|--------------------------------------------------------------------------
| 8. Server-side status filter across pages
|--------------------------------------------------------------------------
*/

test('the status filter is applied server-side and paginates correctly', function () {
    $owner = apgUser();
    $fixtures = apgFixtures($owner);

    apgRows(20, $fixtures, ['status' => 'pending']);
    apgRows(5, $fixtures, ['status' => 'completed']);

    Sanctum::actingAs(apgAdmin());

    $first = $this->getJson('/api/appointments?status=pending&page=1&per_page=15')
        ->assertStatus(200);
    $second = $this->getJson('/api/appointments?status=pending&page=2&per_page=15')
        ->assertStatus(200);

    expect($first->json('total'))->toBe(20)
        ->and($first->json('last_page'))->toBe(2)
        ->and($first->json('data'))->toHaveCount(15)
        ->and($second->json('data'))->toHaveCount(5);

    foreach (array_merge($first->json('data'), $second->json('data')) as $row) {
        expect($row['status'])->toBe('pending');
    }

    $done = $this->getJson('/api/appointments?status=completed&per_page=15')
        ->assertStatus(200);

    expect($done->json('total'))->toBe(5);

    foreach ($done->json('data') as $row) {
        expect($row['status'])->toBe('completed');
    }

    // The owner's filtered view is still scoped to their own rows.
    Sanctum::actingAs($owner);
    $this->getJson('/api/appointments?status=pending&per_page=50')
        ->assertStatus(200)
        ->assertJsonPath('total', 20);
});

/*
|--------------------------------------------------------------------------
| 9. Row contract identical to the single-resource endpoint
|--------------------------------------------------------------------------
*/

test('a paginated row is identical to the same appointment from GET /appointments/{id}', function () {
    apgRows(5, apgFixtures(apgUser()));

    Sanctum::actingAs(apgAdmin());

    $page = $this->getJson('/api/appointments?per_page=1&page=1')->assertStatus(200);
    $id = $page->json('data.0.id');

    $show = $this->getJson("/api/appointments/{$id}")->assertStatus(200);

    expect($show->json())->toEqual($page->json('data.0'));
});

/*
|--------------------------------------------------------------------------
| 10. Query count — the page must not introduce an N+1
|--------------------------------------------------------------------------
*/

test('one index request runs a fixed number of queries regardless of row count', function () {
    apgRows(40, apgFixtures(apgUser()));

    Sanctum::actingAs(apgAdmin());

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->getJson('/api/appointments?page=1&per_page=15')->assertStatus(200);

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    // 1 count + 1 page select + 3 eager loads (doctor, patient, service).
    expect($queries)->toHaveCount(5);
});

/*
|--------------------------------------------------------------------------
| 11. Existing actions still work when the caller only holds a page
|--------------------------------------------------------------------------
*/

test('status and cancellation actions still work against a paginated row', function () {
    $fixtures = apgFixtures(apgUser());
    apgRows(3, $fixtures, ['status' => 'pending']);

    Sanctum::actingAs(apgAdmin());

    $page = $this->getJson('/api/appointments?per_page=2&page=1')->assertStatus(200);
    expect($page->json('total'))->toBe(3);

    $target = $page->json('data.0.id');

    $this->patchJson("/api/appointments/{$target}/status", ['status' => 'cancelled'])
        ->assertStatus(200)
        ->assertJsonPath('data.id', $target)
        ->assertJsonPath('data.status', 'cancelled');

    // The refreshed page reflects the mutation.
    $refetched = $this->getJson('/api/appointments?per_page=2&page=1')->assertStatus(200);
    $row = collect($refetched->json('data'))->firstWhere('id', $target);

    expect($row)->not->toBeNull()
        ->and($row['status'])->toBe('cancelled');

    // Cancellation (DELETE) from the list keeps working too.
    $this->deleteJson("/api/appointments/{$target}")
        ->assertStatus(200)
        ->assertJsonPath('message', 'Appointment cancelled successfully.');

    // … and pagination recomputes from the new state.
    $this->getJson('/api/appointments?status=pending&per_page=50')
        ->assertStatus(200)
        ->assertJsonPath('total', 2);
});
