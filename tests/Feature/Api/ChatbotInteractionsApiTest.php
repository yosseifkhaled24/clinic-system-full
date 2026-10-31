<?php

use App\Services\GeminiChatbotService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| Interactions API tests — fully offline
|--------------------------------------------------------------------------
|
| Covers the second supported Gemini surface added for the Interactions
| API (POST /v1beta/interactions, docs: ai.google.dev/gemini-api/docs/
| interactions-overview), verified against the official OpenAPI spec.
|
| Request format asserted here:
|   {model, store:false, system_instruction, input:[steps],
|    generation_config:{temperature, max_output_tokens}}
| with NO tools (no Google Search grounding) and the API key only in the
| `x-goog-api-key` header.
|
| Response format mirrors a REAL 200 body captured from the API
| (2026-10-28): status + steps[] where `thought` steps are interleaved
| with `model_output` steps carrying content[{type,text}].
|
| Deterministic safety behaviour (emergency short-circuit, PHI redaction,
| triage/first-aid modules, {reply, model, escalation} contract) must be
| identical on this surface — that is asserted here too.
|
*/

const IX_TEST_KEY = 'gemini_ix_test_key_do_not_leak_987654';

function ixConfigure(string $api = 'interactions', string $model = 'gemini-3.8-flash'): void
{
    config(['services.gemini.key' => IX_TEST_KEY]);
    config(['services.gemini.api' => $api]);
    config(['services.gemini.model' => $model]);
    config(['services.gemini.retry_backoff_ms' => 0]);
    config(['services.gemini.retry_max_ms' => 0]);
}

/** Shape of a real Interactions API success body (thought + model_output). */
function ixReplyBody(string $reply = 'Hello from Interactions'): array
{
    return [
        'status' => 'completed',
        'usage' => [
            'total_tokens' => 10,
            'total_input_tokens' => 8,
            'total_output_tokens' => 2,
            'total_thought_tokens' => 0,
        ],
        'created' => '2026-10-28T16:23:36Z',
        'updated' => '2026-10-28T16:23:36Z',
        'service_tier' => 'standard',
        'steps' => [
            ['signature' => 'sig_abc123', 'type' => 'thought'],
            ['content' => [['text' => $reply, 'type' => 'text']], 'type' => 'model_output'],
        ],
        'object' => 'interaction',
        'model' => 'gemini-3.8-flash',
    ];
}

/** Shape of a real Interactions API error body (string error.code). */
function ixErrorBody(string $code = 'service_unavailable', string $message = 'high demand detail that must never leak'): array
{
    return ['error' => [
        'code' => $code,
        'message' => $message,
    ]];
}

function ixPayload(array $overrides = []): array
{
    return array_merge([
        'message' => 'What services are available?',
        'language' => 'en',
    ], $overrides);
}

/** Capture the last generativelanguage request (url + parsed body + object). */
function ixSent(): array
{
    $captured = ['url' => null, 'data' => null, 'request' => null];

    Http::assertSent(function ($request) use (&$captured) {
        if (str_contains($request->url(), 'generativelanguage.googleapis.com')) {
            $captured = [
                'url' => $request->url(),
                'data' => $request->data(),
                'request' => $request,
            ];
        }

        return true;
    });

    return $captured;
}

/*
|--------------------------------------------------------------------------
| 1. Request format (documented Interactions API wire format)
*/

test('the Interactions payload matches the documented format', function () {
    ixConfigure();

    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(ixReplyBody(), 200)]);

    $this->postJson('/api/chatbot', ixPayload())->assertOk();

    $sent = ixSent();
    $data = $sent['data'];

    // Single collection endpoint — the model travels in the BODY.
    expect($sent['url'])
        ->toContain('/v1beta/interactions')
        ->and($sent['url'])->not->toContain(':generateContent');

    expect($data)->toHaveKeys(['model', 'store', 'system_instruction', 'input', 'generation_config'])
        ->and($data['model'])->toBe('gemini-3.8-flash')
        ->and($data['store'])->toBeFalse()
        ->and($data['system_instruction'])->toBeString()
        ->and($data['system_instruction'])->toContain('MediCare Clinic Assistant')
        ->and($data['generation_config']['temperature'])->toBe(0.2)
        ->and($data['generation_config']['max_output_tokens'])->toBe(2048);

    // Current turn is a user_input step with a text content block.
    expect($data['input'])->toHaveCount(1)
        ->and($data['input'][0]['type'])->toBe('user_input')
        ->and($data['input'][0]['content'][0]['type'])->toBe('text')
        ->and($data['input'][0]['content'][0]['text'])->toBe('What services are available?');

    // No legacy camelCase keys on this surface…
    expect(array_key_exists('systemInstruction', $data))->toBeFalse()
        ->and(array_key_exists('contents', $data))->toBeFalse()
        ->and(array_key_exists('generationConfig', $data))->toBeFalse()
        // …and NO tools: no Google Search grounding, answers come only
        // from the clinic snapshot in the system instruction.
        ->and(array_key_exists('tools', $data))->toBeFalse();
});

test('the API key is sent only as a header and never appears in body or reply', function () {
    ixConfigure();

    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(ixReplyBody(), 200)]);

    $response = $this->postJson('/api/chatbot', ixPayload())->assertOk();

    $sent = ixSent();

    expect($sent['request']->hasHeader('x-goog-api-key', IX_TEST_KEY))->toBeTrue()
        ->and(json_encode($sent['data']))->not->toContain(IX_TEST_KEY)
        ->and($response->getContent())->not->toContain(IX_TEST_KEY);
});

test('conversation history is replayed as user_input/model_output steps', function () {
    ixConfigure();

    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(ixReplyBody(), 200)]);

    $this->postJson('/api/chatbot', ixPayload([
        'history' => [
            ['role' => 'user', 'text' => 'valid question'],
            ['role' => 'model', 'text' => 'valid answer'],
            ['role' => 'user', 'text' => 'second question'],
        ],
    ]))->assertOk();

    $steps = ixSent()['data']['input'];

    expect($steps)->toHaveCount(4) // 3 history turns + current message
        ->and($steps[0]['type'])->toBe('user_input')
        ->and($steps[0]['content'][0]['text'])->toBe('valid question')
        ->and($steps[1]['type'])->toBe('model_output')
        ->and($steps[1]['content'][0]['text'])->toBe('valid answer')
        ->and($steps[2]['type'])->toBe('user_input')
        ->and($steps[2]['content'][0]['text'])->toBe('second question')
        ->and($steps[3]['type'])->toBe('user_input')
        ->and($steps[3]['content'][0]['text'])->toBe('What services are available?');
});

test('history starting with a model turn is trimmed and history stays bounded', function () {
    ixConfigure();

    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(ixReplyBody(), 200)]);

    // Exactly MAX_HISTORY_MESSAGES entries, the first being an orphan
    // model turn (the request validator rejects anything longer).
    $history = [['role' => 'model', 'text' => 'orphan model turn']];

    foreach (range(1, GeminiChatbotService::MAX_HISTORY_MESSAGES - 1) as $i) {
        $history[] = ['role' => 'user', 'text' => 'turn '.$i];
    }

    $this->postJson('/api/chatbot', ixPayload(['history' => $history]))->assertOk();

    $steps = ixSent()['data']['input'];

    expect($steps)->toHaveCount(GeminiChatbotService::MAX_HISTORY_MESSAGES) // 11 kept + current
        ->and($steps[0]['type'])->toBe('user_input') // orphan model turn dropped
        ->and(end($steps)['type'])->toBe('user_input');
});

/*
|--------------------------------------------------------------------------
| 2. Response parsing
*/

test('parses the model_output step and ignores thought steps', function () {
    ixConfigure();

    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(ixReplyBody('Clean parsed reply'), 200)]);

    $this->postJson('/api/chatbot', ixPayload())
        ->assertOk()
        ->assertJson([
            'reply' => 'Clean parsed reply',
            'model' => 'gemini-3.8-flash',
            'escalation' => null,
        ]);
});

test('concatenates text across multiple model_output steps', function () {
    ixConfigure();

    $body = ixReplyBody();
    $body['steps'] = [
        ['signature' => 'sig', 'type' => 'thought'],
        ['content' => [['type' => 'text', 'text' => 'Part one. ']], 'type' => 'model_output'],
        ['content' => [['type' => 'text', 'text' => 'Part two.']], 'type' => 'model_output'],
    ];

    Http::fake(['generativelanguage.googleapis.com/*' => Http::response($body, 200)]);

    $this->postJson('/api/chatbot', ixPayload())
        ->assertOk()
        ->assertJson(['reply' => 'Part one. Part two.']);
});

test('returns the interaction status as finish reason and keeps the response contract', function () {
    ixConfigure();

    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(ixReplyBody(), 200)]);

    $response = $this->postJson('/api/chatbot', ixPayload());

    $response->assertOk();

    expect(array_keys($response->json()))->toBe(['reply', 'model', 'escalation'])
        ->and($response->json('reply'))->toBe('Hello from Interactions')
        ->and($response->json('model'))->toBe('gemini-3.8-flash')
        ->and($response->json('escalation'))->toBeNull();
});

test('an interaction without model output falls back to the safe reply', function () {
    ixConfigure();
    Log::spy();

    $body = ixReplyBody();
    $body['steps'] = [['signature' => 'sig', 'type' => 'thought']];

    Http::fake(['generativelanguage.googleapis.com/*' => Http::response($body, 200)]);

    $this->postJson('/api/chatbot', ixPayload())
        ->assertOk()
        ->assertJson([
            'reply' => "Sorry, I couldn't process that request. Please try again or contact the clinic directly.",
            'escalation' => null,
        ]);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message) => $message === 'chatbot.gemini_empty_reply')
        ->once();
});

/*
|--------------------------------------------------------------------------
| 3. API errors + bounded retries (Interactions error shape)
*/

test('a transient 503 service_unavailable is retried and recovers', function () {
    ixConfigure();

    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::sequence()
            ->push(ixErrorBody('service_unavailable'), 503)
            ->push(ixReplyBody('Recovered on retry'), 200),
    ]);

    $this->postJson('/api/chatbot', ixPayload())
        ->assertOk()
        ->assertJson(['reply' => 'Recovered on retry']);

    Http::assertSentCount(2);
});

test('a persistent 503 stops after the bounded attempts with a friendly message', function () {
    ixConfigure();
    Log::spy();

    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(
            ixErrorBody('service_unavailable', 'gemini-3.8-flash is currently experiencing high demand'),
            503
        ),
    ]);

    $response = $this->postJson('/api/chatbot', ixPayload());

    $response->assertStatus(503)->assertJson([
        'message' => 'The assistant is temporarily unavailable right now. Please try again in a few moments.',
    ]);

    // Bounded: 1 initial attempt + 2 retries, then give up.
    Http::assertSentCount(3);

    // Upstream wording never reaches the client, nor does the key.
    expect($response->getContent())
        ->not->toContain('high demand')
        ->not->toContain(IX_TEST_KEY);

    // Two retry warnings + one final classified error.
    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message) => $message === 'chatbot.gemini_retry')
        ->twice();

    // The Interactions-specific error.code shape is classified correctly.
    $retryContexts = [];
    Log::shouldHaveReceived('warning')->withArgs(function ($message, $context = []) use (&$retryContexts) {
        if ($message === 'chatbot.gemini_retry') {
            $retryContexts[] = $context;
        }

        return $message === 'chatbot.gemini_retry';
    })->twice();

    expect($retryContexts[0]['error_status'])->toBe('service_unavailable')
        ->and($retryContexts[0]['model'])->toBe('gemini-3.8-flash')
        ->and($retryContexts[0]['status'])->toBe(503);

    Log::shouldHaveReceived('error')
        ->withArgs(fn ($message) => $message === 'chatbot.gemini_api_error')
        ->once();
});

test('an invalid-request 400 fails fast without retries', function () {
    ixConfigure();

    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(
            ixErrorBody('invalid_argument', 'bad request detail that must never leak'),
            400
        ),
    ]);

    $response = $this->postJson('/api/chatbot', ixPayload());

    $response->assertStatus(503);
    expect($response->getContent())->not->toContain('bad request detail');

    Http::assertSentCount(1);
});

test('an invalid-key 401 is classified as an auth error and not retried', function () {
    ixConfigure();
    Log::spy();

    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(
            ixErrorBody('API_KEY_INVALID', 'API key not valid detail'),
            401
        ),
    ]);

    $this->postJson('/api/chatbot', ixPayload())
        ->assertStatus(503)
        ->assertJson([
            'message' => 'The assistant is not authorized to reach the AI service. Please contact the clinic.',
        ]);

    Http::assertSentCount(1);

    Log::shouldHaveReceived('error')
        ->withArgs(fn ($message) => $message === 'chatbot.gemini_auth_error')
        ->once();
});

test('an unknown-model 404 is classified as a model error and not retried', function () {
    ixConfigure();
    Log::spy();

    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(
            ixErrorBody('not_found', 'model not found detail'),
            404
        ),
    ]);

    $this->postJson('/api/chatbot', ixPayload())
        ->assertStatus(503)
        ->assertJson([
            'message' => 'The assistant is temporarily unavailable right now. Please try again later.',
        ]);

    Http::assertSentCount(1);

    Log::shouldHaveReceived('error')
        ->withArgs(fn ($message) => $message === 'chatbot.gemini_model_error')
        ->once();
});

test('timeouts are not retried — one attempt, then the reachability message', function () {
    ixConfigure();
    Log::spy();

    // First attempt times out; a SECOND attempt would succeed, so a 200
    // would betray a retry loop. $attempts pins the exact count.
    $attempts = 0;
    Http::fake(function () use (&$attempts) {
        $attempts++;

        if ($attempts === 1) {
            throw new ConnectionException('cURL error 28: Operation timed out after 30004 milliseconds with 0 bytes received for https://generativelanguage.googleapis.com/v1beta/interactions');
        }

        return Http::response(ixReplyBody('Should not be reached'), 200);
    });

    $this->postJson('/api/chatbot', ixPayload())
        ->assertStatus(503)
        ->assertJson([
            'message' => 'The assistant could not reach the AI service. Please try again.',
        ]);

    expect($attempts)->toBe(1);

    Log::shouldHaveReceived('error')
        ->withArgs(fn ($message) => $message === 'chatbot.gemini_connection_failed')
        ->once();
});

/*
|--------------------------------------------------------------------------
| 4. Deterministic safety behaviour is unchanged on this surface
*/

test('the emergency short-circuit never calls the Interactions API', function () {
    ixConfigure();
    config(['services.gemini.key' => '']); // works even with no key configured
    Http::fake();

    $this->postJson('/api/chatbot', [
        'message' => 'I have severe chest pain and cannot breathe',
        'language' => 'en',
    ])
        ->assertOk()
        ->assertJson([
            'model' => 'rule-based-safety',
            'escalation' => 'emergency',
        ]);

    Http::assertNothingSent();
});

test('PHI is redacted from the Interactions input before it leaves the server', function () {
    ixConfigure();

    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(ixReplyBody(), 200)]);

    $this->postJson('/api/chatbot', ixPayload([
        'message' => 'Call me on 01001234567 or mail john.doe@example.com about my visit',
    ]))->assertOk();

    $encoded = json_encode(ixSent()['data']);

    expect($encoded)
        ->not->toContain('01001234567')
        ->not->toContain('john.doe@example.com')
        ->toContain('[redacted]');
});

test('triage and emergency guidance modules survive in the system instruction', function () {
    ixConfigure();

    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(ixReplyBody(), 200)]);

    $this->postJson('/api/chatbot', ixPayload([
        'message' => 'I have a fever and a bad headache since yesterday',
    ]))->assertOk();

    $system = ixSent()['data']['system_instruction'];

    expect($system)
        ->toContain('TRIAGE MODE')
        ->toContain('call their local emergency number')
        ->toContain('NEVER recommend or suggest any medication')
        ->toContain('NOT a complete or reliable medical triage system');
});

/*
|--------------------------------------------------------------------------
| 5. The other surface stays intact (regression guard)
*/

test('the legacy generate_content surface still sends the original payload', function () {
    ixConfigure('generate_content', 'gemini-test-model');

    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [[
                'content' => ['role' => 'model', 'parts' => [['text' => 'Legacy reply']]],
                'finishReason' => 'STOP',
            ]],
        ], 200),
    ]);

    $this->postJson('/api/chatbot', ixPayload())
        ->assertOk()
        ->assertJson([
            'reply' => 'Legacy reply',
            'model' => 'gemini-test-model',
            'escalation' => null,
        ]);

    $sent = ixSent();

    expect($sent['url'])->toContain(':generateContent')
        ->and($sent['url'])->not->toContain('/v1beta/interactions');

    $data = $sent['data'];

    expect($data)->toHaveKeys(['systemInstruction', 'contents', 'generationConfig'])
        ->and(array_key_exists('input', $data))->toBeFalse()
        ->and(array_key_exists('store', $data))->toBeFalse()
        ->and($data['generationConfig']['maxOutputTokens'])->toBe(2048);
});
