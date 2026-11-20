<?php

use App\Models\Doctor;
use App\Models\Service;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileNameNotAllowed;
use Spatie\MediaLibrary\MediaCollections\FileAdder;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/*
|--------------------------------------------------------------------------
| SEC-18 — Uploaded file filename and storage safety (lock tests)
|--------------------------------------------------------------------------
|
| The upload pipeline was inspected and found to be ALREADY PROTECTED:
|
|  1. request validation  — `image|mimes:jpg,jpeg,png|max:2048` on the
|     doctor and service endpoints rejects non-images and oversized
|     files BEFORE anything touches the disk;
|  2. Spatie MediaLibrary — sanitizes the client filename (control
|     characters stripped, `# / \\ space` → `-`) and REFUSES executable
|     extensions (php/phtml/phar/htaccess/... and any dangerous segment
|     anywhere in the name, e.g. `shell.php.jpg`) via
|     FileAdder::defaultSanitizer, which runs at the very start of
|     toMediaCollection — before any file is written.
|
| No production code change was therefore made for SEC-18 (and no
| existing upload was touched). These tests LOCK both layers:
| only rejection paths are exercised, so nothing is ever stored.
| In-memory SQLite only; no ext-gd needed (no fake()->image() calls).
*/

function s18Admin(): User
{
    return User::factory()->create(['role' => 'admin']);
}

function s18DoctorPayload(Specialty $specialty, UploadedFile $image): array
{
    return [
        'name' => 'SEC18 Doctor ' . uniqid(),
        'specialty_id' => $specialty->id,
        'email' => 's18-' . uniqid() . '@gmail.com',
        'phone' => '01100000001',
        'bio' => 'Upload safety probe.',
        'image' => $image,
    ];
}

test('a non-image upload (php script) is rejected by validation and nothing is stored', function () {
    Sanctum::actingAs(s18Admin());
    $specialty = Specialty::factory()->create();

    $response = $this->post('/api/doctors', s18DoctorPayload(
        $specialty,
        UploadedFile::fake()->create('shell.php', 4, 'text/plain')
    ), ['Accept' => 'application/json']);

    $response->assertStatus(422);
    expect(Doctor::count())->toBe(0)
        ->and(Media::count())->toBe(0);
});

test('an oversized image (> 2048 KB) is rejected by validation and nothing is stored', function () {
    Sanctum::actingAs(s18Admin());
    $specialty = Specialty::factory()->create();

    // Real JPEG magic bytes so the `image`/`mimes` rules pass and the
    // 2 MB size limit is the rule that fires.
    $oversizedJpeg = "\xFF\xD8\xFF\xE0" . str_repeat("\x00", (2049 * 1024));

    $response = $this->post('/api/doctors', s18DoctorPayload(
        $specialty,
        UploadedFile::fake()->createWithContent('huge.jpg', $oversizedJpeg)
    ), ['Accept' => 'application/json']);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['image']);
    expect(Doctor::count())->toBe(0)
        ->and(Media::count())->toBe(0);
});

test('a spoofed file on the service upload path is rejected and nothing is stored', function () {
    Sanctum::actingAs(s18Admin());

    $response = $this->post('/api/services', [
        'name' => 'SEC18 Service ' . uniqid(),
        'description' => 'Upload safety probe.',
        'price' => 25,
        'image' => UploadedFile::fake()->create('not-an-image.jpg', 4, 'application/pdf'),
    ], ['Accept' => 'application/json']);

    $response->assertStatus(422);
    expect(Service::count())->toBe(0)
        ->and(Media::count())->toBe(0);
});

test('MediaLibrary refuses executable filenames (defense in depth, before any write)', function () {
    $adder = new FileAdder(null);

    // Blocked anywhere in the name, including disguised variants.
    expect(fn () => $adder->defaultSanitizer('shell.php'))
        ->toThrow(FileNameNotAllowed::class);
    expect(fn () => $adder->defaultSanitizer('shell.php.jpg'))
        ->toThrow(FileNameNotAllowed::class);
    expect(fn () => $adder->defaultSanitizer('payload.phtml'))
        ->toThrow(FileNameNotAllowed::class);

    // Benign names pass through the sanitizer unchanged (except the
    // documented character replacements).
    expect($adder->defaultSanitizer('doctor.png'))->toBe('doctor.png')
        ->and($adder->defaultSanitizer('Dr. Strange#1.png'))->toBe('Dr.-Strange-1.png');
});
