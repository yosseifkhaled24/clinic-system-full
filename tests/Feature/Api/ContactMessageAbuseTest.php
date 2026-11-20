<?php

use App\Models\ContactMessage;

/*
|--------------------------------------------------------------------------
| SEC-11 — contact message abuse protection & safe content handling
|--------------------------------------------------------------------------
|
|  - POST /api/contact-messages is public but throttled: 5/min per IP,
|    answered with the standard 429 + Retry-After.
|  - message length is bounded (max:2000 chars) and must be a string;
|    Arabic and English pass untouched (UTF-8, nothing stripped).
|  - HTML/script payloads are stored as INERT PLAIN TEXT — preserved
|    byte-for-byte for the record, never parsed or rewritten server-side.
|    Every display location in the frontend renders message content as a
|    React text node (verified: zero dangerouslySetInnerHTML usages in the
|    whole frontend), so submitted markup can never execute.
|  - Existing messages are never modified or deleted by new submissions.
|
| Isolated in-memory SQLite (phpunit.xml). Throttle state lives in the
| per-test array cache, so tests never leak into each other.
|
*/

function s11tPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Contact Tester',
        'email' => 'contact.tester@example.com',
        'subject' => 'Question about booking',
        'message' => 'Hello, I would like to book an appointment next week.',
    ], $overrides);
}

test('SEC-11: a valid contact submission still works', function () {
    $this->postJson('/api/contact-messages', s11tPayload())
        ->assertStatus(201)
        ->assertJsonStructure([
            'message',
            'contact_message' => ['id', 'name', 'email', 'subject', 'message', 'created_at', 'updated_at'],
        ]);

    expect(ContactMessage::count())->toBe(1);
});

test('SEC-11: Arabic and English messages are accepted and stored byte-for-byte', function () {
    $arabic = 'مرحباً، أودّ حجز موعد مع الطبيب الأسبوع القادم. Thanks!';
    $english = 'Can I reschedule my Thursday appointment, please?';

    $this->postJson('/api/contact-messages', s11tPayload(['message' => $arabic]))
        ->assertStatus(201);
    $this->postJson('/api/contact-messages', s11tPayload([
        'email' => 'second.sender@example.com',
        'message' => $english,
    ]))->assertStatus(201);

    expect(ContactMessage::count())->toBe(2)
        ->and(ContactMessage::where('message', $arabic)->exists())->toBeTrue()
        ->and(ContactMessage::where('message', $english)->exists())->toBeTrue();
});

test('SEC-11: the 6th submission from the same IP within a minute is throttled with 429', function () {
    foreach (range(1, 5) as $i) {
        $this->postJson('/api/contact-messages', s11tPayload([
            'email' => "throttle.s11t{$i}@example.com",
        ]))->assertStatus(201);
    }

    $this->postJson('/api/contact-messages', s11tPayload([
        'email' => 'throttle.s11t6@example.com',
    ]))
        ->assertStatus(429)
        ->assertHeader('Retry-After');

    expect(ContactMessage::count())->toBe(5);
});

test('SEC-11: oversized messages are rejected with 422 and nothing is stored', function () {
    $this->postJson('/api/contact-messages', s11tPayload([
        'message' => str_repeat('a', 2001),
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['message']);

    expect(ContactMessage::count())->toBe(0);
});

test('SEC-11: non-string message values are rejected', function () {
    $this->postJson('/api/contact-messages', s11tPayload([
        'message' => ['not', 'a', 'string'],
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['message']);

    expect(ContactMessage::count())->toBe(0);
});

test('SEC-11: HTML/script payloads are preserved as inert plain text, never rewritten', function () {
    $raw = '<script>alert("xss")</script><b onmouseover="steal()">bold</b>';

    $response = $this->postJson('/api/contact-messages', s11tPayload(['message' => $raw]))
        ->assertStatus(201);

    // Stored exactly as submitted — no server-side parsing/execution —
    // and echoed back as a plain JSON string (escaped by the encoder).
    expect(ContactMessage::first()->message)->toBe($raw)
        ->and($response->json('contact_message.message'))->toBe($raw);
});

test('SEC-11: a new submission never modifies or deletes existing messages', function () {
    $existing = ContactMessage::factory()->create();
    $before = $existing->refresh()->toArray();

    $this->postJson('/api/contact-messages', s11tPayload())
        ->assertStatus(201);

    expect($existing->refresh()->toArray())->toBe($before)
        ->and(ContactMessage::count())->toBe(2);
});
