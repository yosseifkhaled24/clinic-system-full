<?php

use App\Models\Doctor;
use App\Services\ConcernSpecialtyMapper;
use App\Services\EmergencyDetector;
use App\Services\PHIRedactor;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| Chatbot safety tests — fully offline
|--------------------------------------------------------------------------
|
| Covers the deterministic safety layer added on top of the Gemini
| integration:
|   - emergency escalation (short-circuits BEFORE any Gemini call),
|     including the breathing/swallowing + swelling combination rule,
|   - triage-mode prompt rules (no diagnosis as fact, bounded questions),
|   - conservative first-aid rules (no medication/dosage/remedies),
|   - doctor recommendations grounded in real DB specialties only,
|   - hours-vs-availability clarity,
|   - deterministic PHI redaction before anything reaches Gemini,
|   - hallucination-resistant grounding (empty DB, unmatched concern).
|
*/

const SAFETY_TEST_KEY = 'gemini_safety_test_key_987654321';

function safetyConfigure(?string $key = SAFETY_TEST_KEY): void
{
    config(['services.gemini.key' => $key]);
    config(['services.gemini.model' => 'gemini-test-model']);
}

function safetyGeminiBody(string $reply = 'OK from model'): array
{
    return [
        'candidates' => [[
            'content' => ['role' => 'model', 'parts' => [['text' => $reply]]],
            'finishReason' => 'STOP',
        ]],
    ];
}

function safetyFakeGemini(string $reply = 'OK from model'): void
{
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(safetyGeminiBody($reply)),
    ]);
}

function safetyPayload(array $overrides = []): array
{
    return array_merge([
        'message' => 'What services are available?',
        'language' => 'en',
    ], $overrides);
}

function safetySentBody(): ?array
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

function safetySystemInstruction(): ?string
{
    $body = safetySentBody();

    return $body['systemInstruction']['parts'][0]['text'] ?? null;
}

/*
|--------------------------------------------------------------------------
| 1. Deterministic emergency escalation
*/

test('breathing difficulty with facial swelling short-circuits to emergency escalation', function () {
    safetyConfigure();
    Http::fake();

    $response = $this->postJson('/api/chatbot', safetyPayload([
        'message' => "I'm having difficulty breathing and my face is swelling",
    ]));

    $response->assertOk()->assertJson([
        'escalation' => 'emergency',
        'model' => 'rule-based-safety',
    ]);

    expect(strtolower($response->json('reply')))->toContain('emergency');

    // Deterministic: the LLM is never consulted in an emergency.
    Http::assertNothingSent();
});

test('arabic emergency message gets an arabic escalation reply without calling Gemini', function () {
    safetyConfigure();
    Http::fake();

    $response = $this->postJson('/api/chatbot', safetyPayload([
        'message' => 'عندي صعوبة تنفس مع تورم في الوجه',
        'language' => 'ar',
    ]));

    $response->assertOk()->assertJson(['escalation' => 'emergency']);

    expect(preg_match('/\p{Arabic}/u', $response->json('reply')))->toBe(1);
    Http::assertNothingSent();
});

test('chest pain alone escalates and never suggests a routine appointment', function () {
    safetyConfigure();
    Http::fake();

    $response = $this->postJson('/api/chatbot', safetyPayload([
        'message' => 'My father has severe chest pain since an hour',
    ]));

    $response->assertOk()->assertJson(['escalation' => 'emergency']);

    $reply = $response->json('reply');

    // No routine questions, no booking suggestion in an emergency.
    expect($reply)->not->toContain('booking page')
        ->not->toContain('appointment')
        ->not->toContain('weekly hours');

    Http::assertNothingSent();
});

test('emergency escalation still works when no Gemini API key is configured', function () {
    safetyConfigure('');
    Http::fake();

    $this->postJson('/api/chatbot', safetyPayload([
        'message' => 'help my child is unconscious',
    ]))
        ->assertOk()
        ->assertJson(['escalation' => 'emergency', 'model' => 'rule-based-safety']);

    Http::assertNothingSent();
});

test('arabic script in the message produces an arabic reply even with english UI language', function () {
    safetyConfigure();
    Http::fake();

    $response = $this->postJson('/api/chatbot', safetyPayload([
        'message' => 'لا أستطيع التنفس',
        'language' => 'en',
    ]));

    $response->assertOk()->assertJson(['escalation' => 'emergency']);
    expect(preg_match('/\p{Arabic}/u', $response->json('reply')))->toBe(1);
});

test('emergency escalations are logged without message content', function () {
    safetyConfigure();
    Http::fake();
    Log::spy();

    $this->postJson('/api/chatbot', safetyPayload([
        'message' => 'severe chest pain right now',
    ]))->assertOk();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message) => $message === 'chatbot.emergency_escalation')
        ->atLeast()
        ->once();
});

test('non-medical mentions of chest are not escalated and pass through to Gemini', function () {
    safetyConfigure();
    safetyFakeGemini();

    $this->postJson('/api/chatbot', safetyPayload([
        'message' => 'How much does a chest X-ray service cost?',
    ]))
        ->assertOk()
        ->assertJson([
            'reply' => 'OK from model',
            'model' => 'gemini-test-model',
            'escalation' => null,
        ]);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'generativelanguage'));
});

test('emergency detector unit — high recall with bounded false positives', function () {
    $detector = app(EmergencyDetector::class);

    // The spec's combination rule: airway difficulty + swelling.
    expect($detector->assess('I cannot swallow, my lips are swollen')['emergency'])->toBeTrue()
        ->and($detector->assess('ضيق تنفس مع تورم الشفاه')['emergency'])->toBeTrue();

    // Swelling ALONE is not decisive (may be a non-airway reaction).
    expect($detector->assess('my cheek is swollen after the dentist')['emergency'])->toBeFalse()
        ->and($detector->assess('تورم في الوجه بعد سحب السن')['emergency'])->toBeFalse();

    // Single red flags.
    expect($detector->assess('he is having a seizure')['emergency'])->toBeTrue()
        ->and($detector->assess('the bleeding will not stop')['emergency'])->toBeTrue()
        ->and($detector->assess('I want to hurt myself')['emergency'])->toBeTrue();

    // Normalization: apostrophes, alef forms, diacritics.
    expect($detector->assess('I cant breathe')['emergency'])->toBeTrue()
        ->and($detector->assess('لا استطيع البلع')['emergency'])->toBeTrue()
        ->and($detector->assess('لا أستطيع النُّفْس')['emergency'])->toBeTrue();

    // Everyday sentences stay non-emergency (word boundaries).
    expect($detector->assess('learn English at the language center')['emergency'])->toBeFalse()
        ->and($detector->assess('I baked a loaf of bread')['emergency'])->toBeFalse()
        ->and($detector->assess('what are your heart checkup prices?')['emergency'])->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| 2. Triage + first-aid prompt rules
*/

test('symptom message activates triage mode with bounded non-diagnostic questions', function () {
    safetyConfigure();
    safetyFakeGemini();

    $this->postJson('/api/chatbot', safetyPayload([
        'message' => "I've had a fever and a cough for three days",
    ]))->assertOk();

    $instruction = safetySystemInstruction();

    expect($instruction)->toContain('TRIAGE MODE')
        ->toContain('AT MOST 3')
        ->toContain('NEVER state or imply a diagnosis as fact')
        ->toContain('only to judge URGENCY');
});

test('plain faq message does not activate triage mode', function () {
    safetyConfigure();
    safetyFakeGemini();

    $this->postJson('/api/chatbot', safetyPayload())->assertOk();

    expect(safetySystemInstruction())->not->toContain('TRIAGE MODE');
});

test('conservative first-aid rules forbid medication, dosages and risky remedies', function () {
    safetyConfigure();
    safetyFakeGemini();

    $this->postJson('/api/chatbot', safetyPayload())->assertOk();

    $instruction = safetySystemInstruction();

    expect($instruction)->toContain('CONSERVATIVE FIRST-AID GUIDANCE')
        ->toContain('NEVER recommend or suggest any medication')
        ->toContain('any dosage')
        ->toContain('risky home remedies')
        ->toContain('does not replace professional care');
});

/*
|--------------------------------------------------------------------------
| 3. Database-grounded doctor recommendations
*/

test('doctor recommendations only reference specialties the clinic actually has', function () {
    safetyConfigure();
    safetyFakeGemini();

    Doctor::factory()->create(['name' => 'Dr. Cardia One', 'specialty' => 'Cardiology']);
    Doctor::factory()->create(['name' => 'Dr. Derma One', 'specialty' => 'Dermatology']);

    $this->postJson('/api/chatbot', safetyPayload([
        'message' => 'I have chest tightness and palpitations, which doctor should I see?',
    ]))->assertOk();

    $instruction = safetySystemInstruction();

    // Cardiology is matched from the REAL specialty list…
    expect($instruction)->toContain('DOCTOR RECOMMENDATIONS')
        ->toContain('actually offers: Cardiology')
        ->toContain('NOT a diagnosis')
        // …and unrelated specialties are not suggested for this concern.
        ->and($instruction)->not->toContain('actually offers: Cardiology, Dermatology')
        ->and($instruction)->not->toContain('actually offers: Dermatology');
});

test('concern with no matching clinic specialty instructs the model to say it is not listed', function () {
    safetyConfigure();
    safetyFakeGemini();

    Doctor::factory()->create(['specialty' => 'Cardiology']);

    $this->postJson('/api/chatbot', safetyPayload([
        'message' => 'I have a bad toothache since yesterday',
    ]))->assertOk();

    $instruction = safetySystemInstruction();

    expect($instruction)->toContain('does not match any specialty this clinic lists')
        ->toContain('do NOT name or invent a specialty the clinic does not offer');
});

test('mapper unit — never returns a specialty outside the clinic list', function () {
    $mapper = app(ConcernSpecialtyMapper::class);

    expect($mapper->match(['Cardiology'], 'my stomach hurts a lot'))->toBe([])
        ->and($mapper->match(['Cardiology'], 'chest pain and palpitations'))->toBe(['Cardiology'])
        ->and($mapper->match(['cardiology'], 'CHEST PAIN'))->toBe(['cardiology'])
        ->and($mapper->match([], 'chest pain'))->toBe([])
        ->and($mapper->match(['Cardiology'], 'أعاني من ألم في الصدر'))->toBe(['Cardiology']);
});

/*
|--------------------------------------------------------------------------
| 4. Hours vs availability + privacy
*/

test('the assistant may never claim a doctor is available for booking', function () {
    safetyConfigure();
    safetyFakeGemini();

    $this->postJson('/api/chatbot', safetyPayload())->assertOk();

    $instruction = safetySystemInstruction();

    expect($instruction)->toContain('Never state that a doctor "is available"')
        ->toContain('verify real-time appointment availability')
        ->toContain('NOT real-time availability');
});

test('emails and phone numbers are redacted before the request reaches Gemini', function () {
    safetyConfigure();
    safetyFakeGemini();

    $this->postJson('/api/chatbot', safetyPayload([
        'message' => 'Call me at 01001234567 or email sam@example.com about the prices',
    ]))->assertOk();

    $sentText = safetySentBody()['contents'][0]['parts'][0]['text'];

    expect($sentText)->not->toContain('01001234567')
        ->not->toContain('sam@example.com')
        ->toContain('[redacted]');
});

test('replayed history is redacted too, while prices and years stay intact', function () {
    safetyConfigure();
    safetyFakeGemini();

    $this->postJson('/api/chatbot', safetyPayload([
        'message' => 'How much is the 450 EGP service from 2024?',
        'history' => [
            ['role' => 'user', 'text' => 'my national id is 28501011234567'],
            ['role' => 'model', 'text' => 'Please share only what is needed.'],
        ],
    ]))->assertOk();

    $body = safetySentBody();
    $allText = json_encode($body, JSON_UNESCAPED_UNICODE);

    expect($allText)->not->toContain('28501011234567')
        ->toContain('[redacted]')
        // Business facts must survive redaction.
        ->toContain('450')
        ->toContain('2024');
});

test('redactor unit — conservative on normal numbers, strict on identifiers', function () {
    $redactor = app(PHIRedactor::class);

    expect($redactor->redact('the price is 450 EGP in 2024'))->toBe('the price is 450 EGP in 2024')
        ->and($redactor->redact('call 0100 123 4567 now'))->toContain('[redacted]')
        ->and($redactor->redact('mail me at a.b+c@mail.co.uk'))->toContain('[redacted]')
        ->and($redactor->redact('id 2-850-1011-234567'))->toContain('[redacted]');
});

/*
|--------------------------------------------------------------------------
| 5. Hallucination resistance
*/

test('with an empty clinic database the prompt contains no doctors and forbids inventing them', function () {
    safetyConfigure();
    safetyFakeGemini();

    $this->postJson('/api/chatbot', safetyPayload())->assertOk();

    $instruction = safetySystemInstruction();

    expect($instruction)->toContain('"doctors": []')
        ->toContain('"specialties": []')
        ->toContain('Never invent doctors');
});

test('normal replies keep the backward-compatible response contract', function () {
    safetyConfigure();
    safetyFakeGemini('Sure — here are our services.');

    $this->postJson('/api/chatbot', safetyPayload())
        ->assertOk()
        ->assertJson([
            'reply' => 'Sure — here are our services.',
            'model' => 'gemini-test-model',
            'escalation' => null,
        ])
        ->assertJsonStructure(['reply', 'model', 'escalation']);
});
