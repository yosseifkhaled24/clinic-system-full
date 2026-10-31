<?php

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\Patient;
use App\Models\Service;
use App\Services\ClinicContextService;
use App\Services\GeminiChatbotService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| Chatbot (Gemini) API tests — fully offline
|--------------------------------------------------------------------------
|
| Gemini is faked with Http::fake() using the real generateContent
| response shape. No network, no real credentials. The suite covers:
| validation, bounded multi-turn history, real clinic data in the
| system instruction, privacy (no patient data, no key leakage),
| safe upstream error handling and route rate limiting.
|
*/

const CHATBOT_TEST_KEY = 'gemini_test_key_do_not_leak_123456';

function chatbotConfigure(?string $key = CHATBOT_TEST_KEY): void
{
    config(['services.gemini.key' => $key]);
    config(['services.gemini.model' => 'gemini-test-model']);
}

function chatbotGeminiBody(string $reply = 'Hello from Gemini'): array
{
    return [
        'candidates' => [[
            'content' => [
                'role' => 'model',
                'parts' => [['text' => $reply]],
            ],
            'finishReason' => 'STOP',
        ]],
        'usageMetadata' => [
            'promptTokenCount' => 10,
            'candidatesTokenCount' => 5,
            'totalTokenCount' => 15,
        ],
    ];
}

function chatbotFakeGemini(string $reply = 'Hello from Gemini', int $status = 200, array $body = []): void
{
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(
            $body !== [] ? $body : chatbotGeminiBody($reply),
            $status
        ),
    ]);
}

function chatbotPayload(array $overrides = []): array
{
    return array_merge([
        'message' => 'What services are available?',
        'language' => 'en',
    ], $overrides);
}

function chatbotBody(): ?array
{
    $captured = null;

    Http::assertSent(function ($request) use (&$captured) {
        if (str_contains($request->url(), 'generativelanguage.googleapis.com')) {
            $captured = $request->data();
        }

        return true;
    });

    return $captured;
}

/*
|--------------------------------------------------------------------------
| Validation
*/

test('chatbot requires a message', function () {
    chatbotConfigure();

    $this->postJson('/api/chatbot', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['message']);
});

test('chatbot rejects an empty or over-long message', function () {
    chatbotConfigure();

    $this->postJson('/api/chatbot', chatbotPayload(['message' => '   ']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['message']);

    $this->postJson('/api/chatbot', chatbotPayload([
        'message' => str_repeat('a', GeminiChatbotService::MAX_MESSAGE_LENGTH + 1),
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['message']);
});

test('chatbot validates history shape, bounds and language', function () {
    chatbotConfigure();

    $this->postJson('/api/chatbot', chatbotPayload([
        'history' => [['role' => 'system', 'text' => 'hijack']],
    ]))->assertStatus(422)->assertJsonValidationErrors(['history.0.role']);

    $this->postJson('/api/chatbot', chatbotPayload([
        'history' => [['role' => 'user']],
    ]))->assertStatus(422)->assertJsonValidationErrors(['history.0.text']);

    $this->postJson('/api/chatbot', chatbotPayload([
        'history' => array_fill(0, GeminiChatbotService::MAX_HISTORY_MESSAGES + 1, [
            'role' => 'user',
            'text' => 'hi',
        ]),
    ]))->assertStatus(422)->assertJsonValidationErrors(['history']);

    $this->postJson('/api/chatbot', chatbotPayload(['language' => 'fr']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['language']);
});

/*
|--------------------------------------------------------------------------
| Happy path
*/

test('chatbot returns the Gemini reply and configured model', function () {
    chatbotConfigure();
    chatbotFakeGemini('We offer a full range of medical services.');

    $this->postJson('/api/chatbot', chatbotPayload())
        ->assertOk()
        ->assertJson([
            'reply' => 'We offer a full range of medical services.',
            'model' => 'gemini-test-model',
        ]);
});

test('chatbot sends the API key as a header and never returns it', function () {
    chatbotConfigure();
    chatbotFakeGemini('مرحبا، كيف يمكنني مساعدتك؟');

    $response = $this->postJson('/api/chatbot', chatbotPayload(['language' => 'ar']));

    $response->assertOk();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'generativelanguage.googleapis.com')
            && $request->hasHeader('x-goog-api-key', CHATBOT_TEST_KEY);
    });

    expect($response->getContent())->not->toContain(CHATBOT_TEST_KEY);
});

test('chatbot system instruction restricts the assistant role and contains safety rules', function () {
    chatbotConfigure();
    chatbotFakeGemini();

    $this->postJson('/api/chatbot', chatbotPayload())->assertOk();

    $body = chatbotBody();
    $system = $body['systemInstruction']['parts'][0]['text'];

    expect($system)->toContain('administrative assistant')
        ->toContain('NOT a medical professional')
        ->toContain('never diagnose')
        ->toContain('emergency')
        ->toContain('Never invent')
        ->toContain('cannot create, modify, cancel');
});

test('chatbot prompt contains real doctors, specialties, schedules and services from the database', function () {
    chatbotConfigure();
    chatbotFakeGemini();

    $doctor = Doctor::factory()->create([
        'name' => 'Dr. Test Cardiologist',
        'specialty' => 'Cardiology',
        'bio' => 'Senior cardiologist.',
    ]);
    DoctorSchedule::factory()->create([
        'doctor_id' => $doctor->id,
        'day' => 'Saturday',
        'start_time' => '10:00:00',
        'end_time' => '16:00:00',
        'is_available' => true,
    ]);
    $service = Service::factory()->create([
        'name' => 'Heart Echo',
        'description' => 'Echocardiography screening.',
        'price' => 450,
    ]);

    $this->postJson('/api/chatbot', chatbotPayload(['message' => 'How much is the heart echo?']))
        ->assertOk();

    $system = chatbotBody()['systemInstruction']['parts'][0]['text'];

    expect($system)->toContain('Dr. Test Cardiologist')
        ->toContain('Cardiology')
        ->toContain('Heart Echo')
        ->toContain('450')
        ->toContain('Saturday')
        ->toContain('10:00')
        ->toContain('EGP');
});

test('chatbot never sends private patient or appointment data to Gemini', function () {
    chatbotConfigure();
    chatbotFakeGemini();

    $patient = Patient::factory()->create([
        'name' => 'Private Patient Name',
        'phone' => '01099999999',
        'email' => 'private.patient@example.com',
        'notes' => 'Confidential medical notes about the patient.',
    ]);
    Appointment::factory()->create([
        'patient_id' => $patient->id,
        'user_id' => $patient->user_id,
    ]);

    $this->postJson('/api/chatbot', chatbotPayload(['message' => 'Tell me about my appointment']))
        ->assertOk();

    $system = chatbotBody()['systemInstruction']['parts'][0]['text'];

    expect($system)->not->toContain('Private Patient Name')
        ->not->toContain('01099999999')
        ->not->toContain('private.patient@example.com')
        ->not->toContain('Confidential medical notes');
});

/*
|--------------------------------------------------------------------------
| Multi-turn conversation
*/

test('chatbot replays multi-turn history as alternating user and model turns', function () {
    chatbotConfigure();
    chatbotFakeGemini('Yes, Dr. Ali is available on Saturdays.');

    $this->postJson('/api/chatbot', chatbotPayload([
        'message' => 'Great, thanks!',
        'history' => [
            ['role' => 'user', 'text' => 'Do you have a cardiologist?'],
            ['role' => 'model', 'text' => 'Yes, we do.'],
            ['role' => 'user', 'text' => 'Is Dr. Ali available on Saturdays?'],
        ],
    ]))->assertOk();

    $contents = chatbotBody()['contents'];

    expect($contents)->toHaveCount(4)
        ->and($contents[0]['role'])->toBe('user')
        ->and($contents[1]['role'])->toBe('model')
        ->and($contents[2]['role'])->toBe('user')
        ->and($contents[3]['role'])->toBe('user')
        ->and($contents[3]['parts'][0]['text'])->toBe('Great, thanks!');
});

test('chatbot trims oversized history to the newest bounded turns and starts with a user turn', function () {
    chatbotConfigure();
    chatbotFakeGemini();

    $history = [
        ['role' => 'model', 'text' => 'an early model turn that must be dropped'],
    ];

    foreach (range(1, GeminiChatbotService::MAX_HISTORY_MESSAGES) as $i) {
        $history[] = ['role' => 'user', 'text' => "question {$i}"];
        $history[] = ['role' => 'model', 'text' => "answer {$i}"];
    }

    $this->postJson('/api/chatbot', chatbotPayload([
        'message' => 'final question',
        'history' => array_slice($history, -GeminiChatbotService::MAX_HISTORY_MESSAGES),
    ]))->assertOk();

    $contents = chatbotBody()['contents'];

    // Bounded history (12) + current message.
    expect($contents)->toHaveCount(GeminiChatbotService::MAX_HISTORY_MESSAGES + 1)
        ->and($contents[0]['role'])->toBe('user')
        ->and(end($contents)['parts'][0]['text'])->toBe('final question');
});

test('chatbot rejects whitespace-only history turns at validation', function () {
    chatbotConfigure();

    // TrimStrings middleware turns "   " into "" before validation, so the
    // request never reaches the service; the service additionally filters
    // blank/junk turns as defense in depth (covered in the test below).
    $history = array_merge(
        array_fill(0, 11, ['role' => 'user', 'text' => 'valid question']),
        [['role' => 'user', 'text' => '   ']]
    );

    $this->postJson('/api/chatbot', chatbotPayload([
        'message' => 'hello',
        'history' => $history,
    ]))->assertStatus(422)->assertJsonValidationErrors(['history.11.text']);
});

test('chatbot service filters junk history turns instead of forwarding them to Gemini', function () {
    chatbotConfigure();
    chatbotFakeGemini();

    app(GeminiChatbotService::class)->chat('hello', [
        ['role' => 'user', 'text' => 'valid question'],
        ['role' => 'tool', 'text' => 'ignored role'],
        ['role' => 'user', 'text' => '   '],
        ['role' => 'model', 'text' => 'valid answer'],
    ], 'en');

    $contents = chatbotBody()['contents'];

    expect($contents)->toHaveCount(3) // 2 kept history turns + current message
        ->and($contents[0]['parts'][0]['text'])->toBe('valid question')
        ->and($contents[1]['role'])->toBe('model')
        ->and($contents[2]['parts'][0]['text'])->toBe('hello');
});

/*
|--------------------------------------------------------------------------
| Safety: errors, secrets, fallbacks
*/

test('chatbot returns a safe 503 when Gemini fails and leaks nothing', function () {
    chatbotConfigure();
    chatbotFakeGemini('', 500, [
        'error' => [
            'code' => 500,
            'message' => 'Internal error with key '.CHATBOT_TEST_KEY,
            'status' => 'INTERNAL',
        ],
    ]);

    Log::spy();

    $response = $this->postJson('/api/chatbot', chatbotPayload());

    $response->assertStatus(503);

    expect($response->getContent())
        ->not->toContain(CHATBOT_TEST_KEY)
        ->not->toContain('Internal error');

    Log::shouldHaveReceived('error')->atLeast()->once();
});

test('chatbot returns 503 without calling Gemini when the API key is missing', function () {
    chatbotConfigure('');
    Http::fake();

    $this->postJson('/api/chatbot', chatbotPayload())
        ->assertStatus(503)
        ->assertJson(['message' => 'The AI assistant is not configured on the server.']);

    Http::assertNothingSent();
});

test('chatbot maps Gemini rate limiting to a friendly busy message', function () {
    chatbotConfigure();
    chatbotFakeGemini('', 429, ['error' => ['status' => 'RESOURCE_EXHAUSTED']]);

    $this->postJson('/api/chatbot', chatbotPayload())
        ->assertStatus(503)
        ->assertJson(['message' => 'The assistant is busy right now. Please try again in a moment.']);
});

test('chatbot returns a bilingual fallback when Gemini sends no usable candidate', function () {
    chatbotConfigure();
    chatbotFakeGemini('', 200, ['promptFeedback' => ['blockReason' => 'SAFETY']]);

    $this->postJson('/api/chatbot', chatbotPayload(['language' => 'ar']))
        ->assertOk()
        ->assertJsonStructure(['reply']);

    $this->postJson('/api/chatbot', chatbotPayload(['language' => 'en']))
        ->assertOk()
        ->assertJsonStructure(['reply']);
});

/*
|--------------------------------------------------------------------------
| Rate limiting
*/

test('chatbot is rate limited to 20 messages per minute', function () {
    chatbotConfigure();
    chatbotFakeGemini();

    foreach (range(1, 20) as $i) {
        $this->postJson('/api/chatbot', chatbotPayload(['message' => "message {$i}"]))
            ->assertOk();
    }

    $this->postJson('/api/chatbot', chatbotPayload(['message' => 'one too many']))
        ->assertStatus(429);
});

/*
|--------------------------------------------------------------------------
| Clinic context service
*/

test('clinic context exposes specialties derived from doctors and only public data', function () {
    Doctor::factory()->create(['specialty' => 'Cardiology']);
    Doctor::factory()->create(['specialty' => 'Dermatology']);
    Doctor::factory()->create(['specialty' => 'Cardiology']);
    Service::factory()->create(['name' => 'X-Ray', 'price' => 200]);
    Patient::factory()->create();

    $context = app(ClinicContextService::class)->build();

    expect($context)->toHaveKeys(['clinic_name', 'specialties', 'doctors', 'services', 'notes'])
        ->and($context['specialties'])->toBe(['Cardiology', 'Dermatology'])
        ->and($context['doctors'])->toHaveCount(3)
        ->and($context['services'][0]['name'])->toBe('X-Ray')
        ->and(json_encode($context))->not->toContain('date_of_birth')
        ->and(json_encode($context))->not->toContain('patient');
});
