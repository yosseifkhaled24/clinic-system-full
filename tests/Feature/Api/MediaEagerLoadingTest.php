<?php

use App\Models\Doctor;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/*
|--------------------------------------------------------------------------
| Phase 1 performance — media eager-loading regression tests
|--------------------------------------------------------------------------
|
| GET /api/doctors and GET /api/services used to lazy-load Spatie media
| inside their map() loops: 1 + N queries (measured 8 and 9 queries on
| the live MySQL data set during the audit). The controllers now
| eager-load `media`, so each index runs exactly 2 queries (rows +
| one batched media select) no matter how many records exist.
|
| These tests ALSO lock the response contract:
|  - same top-level shapes ({data: [...]} for doctors, bare array for
|    services),
|  - `media` still present on every doctor object (the previous lazy
|    access loaded the relation before serialization, so it was already
|    in the JSON — eager loading keeps it there),
|  - image_url semantics: first media by order_column wins, '' for
|    doctors / null for services when the collection is empty,
|  - `specialty` stays the plain scalar string (never the relation).
|
| In-memory SQLite only (phpunit.xml). Media rows are created directly
| (Spatie's model is unguarded) — no files are written to disk.
*/

test('GET /api/doctors eager-loads media — exactly 2 queries regardless of doctor count', function () {
    Doctor::factory()->count(4)->create();

    DB::enableQueryLog();
    $response = $this->getJson('/api/doctors');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    $response->assertStatus(200);

    // 1 select on doctors + 1 batched select on media.
    // Before the fix this was 1 + N (= 5 here, 8 on the live data set).
    expect(count($queries))->toBe(2);
});

test('GET /api/services eager-loads media — exactly 2 queries regardless of service count', function () {
    Service::factory()->count(5)->create();

    DB::enableQueryLog();
    $response = $this->getJson('/api/services');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    $response->assertStatus(200);

    expect(count($queries))->toBe(2);
});

test('GET /api/doctors response contract is unchanged: wrapper, scalar specialty, media array and image_url ordering/fallback', function () {
    $withMedia = Doctor::factory()->create();
    $withoutMedia = Doctor::factory()->create();

    // Two media rows on one doctor: order_column decides which URL wins,
    // exactly as the lazy path's loadMedia() sortBy('order_column') did.
    Media::create([
        'model_type' => Doctor::class,
        'model_id' => $withMedia->id,
        'collection_name' => 'doctors',
        'name' => 'image',
        'file_name' => 'second.jpg',
        'mime_type' => 'image/jpeg',
        'disk' => 'public',
        'size' => 1024,
        'manipulations' => [],
        'custom_properties' => [],
        'generated_conversions' => [],
        'responsive_images' => [],
        'order_column' => 2,
    ]);

    Media::create([
        'model_type' => Doctor::class,
        'model_id' => $withMedia->id,
        'collection_name' => 'doctors',
        'name' => 'image',
        'file_name' => 'first.jpg',
        'mime_type' => 'image/jpeg',
        'disk' => 'public',
        'size' => 2048,
        'manipulations' => [],
        'custom_properties' => [],
        'generated_conversions' => [],
        'responsive_images' => [],
        'order_column' => 1,
    ]);

    $json = $this->getJson('/api/doctors')->assertStatus(200)->json();

    expect($json)->toHaveKey('data');

    $rows = collect($json['data'])->keyBy('id');
    $with = $rows[$withMedia->id];
    $without = $rows[$withoutMedia->id];

    // Same attribute set as before (media was already serialized because
    // the old lazy access loaded the relation before response encoding).
    expect($with)->toHaveKeys([
        'id', 'name', 'specialty', 'specialty_id', 'email', 'phone', 'bio',
        'allow_reviews', 'image_url', 'created_at', 'updated_at', 'media',
    ]);

    // `specialty` must stay the plain string, never the relation object.
    expect($with['specialty'])->toBeString()
        ->and($with['specialty'])->toBe($withMedia->specialty);

    // First media by order_column wins; both rows remain in the array.
    expect($with['media'])->toHaveCount(2)
        ->and($with['image_url'])->toContain('first.jpg')
        ->not->toContain('second.jpg');

    // Empty-collection fallback preserved.
    expect($without['image_url'])->toBe('')
        ->and($without['media'])->toBe([]);
});

test('GET /api/services keeps its fixed scalar object shape with image_url ordering and null fallback', function () {
    $service = Service::factory()->create(['name' => 'Phase1 Imaging', 'price' => 450]);

    Media::create([
        'model_type' => Service::class,
        'model_id' => $service->id,
        'collection_name' => 'services',
        'name' => 'image',
        'file_name' => 'svc-second.jpg',
        'mime_type' => 'image/jpeg',
        'disk' => 'public',
        'size' => 1024,
        'manipulations' => [],
        'custom_properties' => [],
        'generated_conversions' => [],
        'responsive_images' => [],
        'order_column' => 2,
    ]);

    Media::create([
        'model_type' => Service::class,
        'model_id' => $service->id,
        'collection_name' => 'services',
        'name' => 'image',
        'file_name' => 'svc-first.jpg',
        'mime_type' => 'image/jpeg',
        'disk' => 'public',
        'size' => 2048,
        'manipulations' => [],
        'custom_properties' => [],
        'generated_conversions' => [],
        'responsive_images' => [],
        'order_column' => 1,
    ]);

    $plain = Service::factory()->create(['name' => 'No Image Service']);

    $json = $this->getJson('/api/services')->assertStatus(200)->json();

    expect($json)->toHaveCount(2);

    $rows = collect($json)->keyBy('id');
    $withMedia = $rows[$service->id];
    $withoutMedia = $rows[$plain->id];

    // formatService()'s exact key set — a plain scalar object.
    expect($withMedia)->toHaveKeys([
        'id', 'name', 'description', 'price', 'image_url',
        'created_at', 'updated_at',
    ])
        ->and($withMedia['name'])->toBe('Phase1 Imaging')
        ->and($withMedia['price'])->toBe(450)
        ->and($withMedia['image_url'])->toContain('svc-first.jpg')
        ->not->toContain('svc-second.jpg');

    // Fallback: `?: null` — no media means null (not '').
    expect($withoutMedia['image_url'])->toBeNull()
        ->and($withoutMedia['name'])->toBe('No Image Service');
});
