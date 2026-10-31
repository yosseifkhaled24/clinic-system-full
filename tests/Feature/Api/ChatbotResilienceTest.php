<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| Gemini resilience tests — fully offline
|--------------------------------------------------------------------------
|
| Covers the bounded-retry layer added after real production evidence
| (storage/logs/laravel.log, 2026-10-28): repeated upstream
| HTTP 503 {"status":"UNAVAILABLE"} from gemini-3.1-flash-lite, plus one
| cURL-28 timeout on 2026-10-27.
|
| Policy under test (official Gemini API error guidance):
|   - 503 UNAVAILABLE / 500 / 429: bounded retry, exponential backoff
|     with jitter, total attempts capped, latency deadline respected,
|   - timeouts/connection errors: NOT retried (latency budget),
|   - 4xx configuration errors (401 auth, 404 model, 400 request):
|     NOT retried, classified + logged distinctly,
|   - user-facing messages stay static and safe (no upstream bodies,
|     no keys, no message content in logs).
|
*/

const RES_TEST_KEY = 'gemini_res_test_key_2468024680';

function resConfigure(int $backoffMs = 0): void
{
    config(['services.gemini.key' => RES_TEST_KEY]);
    config(['services.gemini.model' => 'gemini-3.1-flash-lite']);
    config(['services.gemini.retry_backoff_ms' => $backoffMs]);
    config(['services.gemini.retry_max_ms' => $backoffMs]);
}

function resGeminiBody(string $reply = 'Recovered answer'): array
{
    return [
        'candidates' => [[
            'content' => ['role' => 'model', 'parts' => [['text' => $reply]]],
            'finishReason' => 'STOP',
        ]],
    ];
}

function resErrorBody(int $code, string $status): array
{
    return ['error' => [
        'code' => $code,
        'status' => $status,
        'message' => 'upstream detail that must never leak '.$status,
    ]];
}

function resPayload(array $overrides = []): array
{
    return array_merge(['message' => 'What services are available?', 'language' => 'en'], $overrides);
}

/*
|--------------------------------------------------------------------------
| 1. Transient failures that recover on retry
*/

test('a transient 503 UNAVAILABLE is retried and recovers on the next attempt', function () {
    resConfigure();

    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::sequence()
            ->push(resErrorBody(503, 'UNAVAILABLE'), 503)
            ->push(resGeminiBody('Recovered after 503'), 200),
    ]);

    $response = $this->postJson('/api/chatbot', resPayload());

    $response->assertOk()->assertJson([
        'reply' => 'Recovered after 503',
        'model' => 'gemini-3.1-flash-lite',
        'escalation' => null,
    ]);

    // Exactly one retry — the failure did not reach the user.
    Http::assertSentCount(2);
});

test('a transient 500 internal error is retried and recovers', function () {
    resConfigure();

    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::sequence()
            ->push(resErrorBody(500, 'INTERNAL'), 500)
            ->push(resGeminiBody('Recovered after 500'), 200),
    ]);

    $this->postJson('/api/chatbot', resPayload())
        ->assertOk()
        ->assertJson(['reply' => 'Recovered after 500']);

    Http::assertSentCount(2);
});

test('a transient 429 rate limit is retried and recovers', function () {
    resConfigure();

    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::sequence()
            ->push(resErrorBody(429, 'RESOURCE_EXHAUSTED'), 429)
            ->push(resGeminiBody('Recovered after 429'), 200),
    ]);

    $this->postJson('/api/chatbot', resPayload())
        ->assertOk()
        ->assertJson(['reply' => 'Recovered after 429']);

    Http::assertSentCount(2);
});

/*
|--------------------------------------------------------------------------
| 2. Bounded attempts — never an infinite retry loop
*/

test('a persistent 503 stops after the bounded attempt limit with a friendly temporary message', function () {
    resConfigure();
    Log::spy();

    // Same 503 every time — mirrors the real incident.
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(resErrorBody(503, 'UNAVAILABLE'), 503),
    ]);

    $response = $this->postJson('/api/chatbot', resPayload());

    $response->assertStatus(503)->assertJson([
        'message' => 'The assistant is temporarily unavailable right now. Please try again in a few moments.',
    ]);

    // Bounded: 1 initial attempt + 2 retries, then give up.
    Http::assertSentCount(3);

    // No upstream body / key leakage to the client.
    expect($response->getContent())
        ->not->toContain(RES_TEST_KEY)
        ->not->toContain('upstream detail');

    // Two retry warnings (after attempts 1 and 2) + one final error.
    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message) => $message === 'chatbot.gemini_retry')
        ->twice();

    Log::shouldHaveReceived('error')
        ->withArgs(fn ($message) => $message === 'chatbot.gemini_api_error')
        ->once();
});

test('a persistent 429 keeps the existing busy message and stops after bounded attempts', function () {
    resConfigure();

    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(resErrorBody(429, 'RESOURCE_EXHAUSTED'), 429),
    ]);

    $this->postJson('/api/chatbot', resPayload())
        ->assertStatus(503)
        ->assertJson(['message' => 'The assistant is busy right now. Please try again in a moment.']);

    Http::assertSentCount(3);
});

/*
|--------------------------------------------------------------------------
| 3. Failures that must NOT be retried
*/

test('timeouts are not retried — one attempt, then the reachability message', function () {
    resConfigure();
    Log::spy();

    // First attempt times out; any SECOND attempt would succeed. So the
    // final 503 (instead of a 200 "recovered" reply) proves no retry
    // happened, and $attempts pins the count to exactly one. (A thrown
    // ConnectionException records no HTTP request, so assertSentCount
    // cannot be used here.)
    $attempts = 0;
    Http::fake(function () use (&$attempts) {
        $attempts++;

        if ($attempts === 1) {
            throw new ConnectionException('cURL error 28: Operation timed out after 30005 milliseconds with 0 bytes received');
        }

        return Http::response(resGeminiBody('Should not be reached'), 200);
    });

    $response = $this->postJson('/api/chatbot', resPayload());

    // Retrying a full-timeout attempt would blow the latency budget.
    $response->assertStatus(503)->assertJson([
        'message' => 'The assistant could not reach the AI service. Please try again.',
    ]);

    expect($attempts)->toBe(1);
    Http::assertNothingSent();

    Log::shouldHaveReceived('error')
        ->withArgs(fn ($message) => $message === 'chatbot.gemini_connection_failed')
        ->once();
});

test('an unknown-model 404 is not retried and is classified as a model error', function () {
    resConfigure();
    Log::spy();

    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(resErrorBody(404, 'NOT_FOUND'), 404),
    ]);

    $this->postJson('/api/chatbot', resPayload())
        ->assertStatus(503)
        ->assertJson(['message' => 'The assistant is temporarily unavailable right now. Please try again later.']);

    Http::assertSentCount(1);

    Log::shouldHaveReceived('error')
        ->withArgs(fn ($message) => $message === 'chatbot.gemini_model_error')
        ->once();
});

test('an invalid-key 401 is not retried and is classified as an auth error', function () {
    resConfigure();
    Log::spy();

    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(resErrorBody(401, 'API_KEY_INVALID'), 401),
    ]);

    $response = $this->postJson('/api/chatbot', resPayload());

    $response->assertStatus(503)->assertJson([
        'message' => 'The assistant is not authorized to reach the AI service. Please contact the clinic.',
    ]);

    Http::assertSentCount(1);

    expect($response->getContent())->not->toContain(RES_TEST_KEY);

    Log::shouldHaveReceived('error')
        ->withArgs(fn ($message) => $message === 'chatbot.gemini_auth_error')
        ->once();
});

test('an invalid-request 400 fails fast without retries', function () {
    resConfigure();

    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(resErrorBody(400, 'INVALID_ARGUMENT'), 400),
    ]);

    $this->postJson('/api/chatbot', resPayload())->assertStatus(503);

    Http::assertSentCount(1);
});

/*
|--------------------------------------------------------------------------
| 4. Backoff behavior + safe logging
*/

test('retry logging carries only safe diagnostic metadata', function () {
    resConfigure();
    Log::spy();

    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(resErrorBody(503, 'UNAVAILABLE'), 503),
    ]);

    $this->postJson('/api/chatbot', resPayload(['message' => 'secret symptom note 01001234567']))
        ->assertStatus(503);

    $logged = [];
    Log::shouldHaveReceived('warning')->withArgs(function ($message, $context) use (&$logged) {
        if ($message === 'chatbot.gemini_retry') {
            $logged[] = $context;
        }

        return true;
    });

    expect($logged)->toHaveCount(2);

    foreach ($logged as $context) {
        $encoded = json_encode($context);

        // Metadata only: no key, no phone/message content, bounded fields.
        expect($encoded)
            ->not->toContain(RES_TEST_KEY)
            ->not->toContain('01001234567')
            ->not->toContain('secret symptom note');

        expect($context)->toHaveKeys(['model', 'attempt', 'status', 'backoff_ms'])
            ->and($context['model'])->toBe('gemini-3.1-flash-lite')
            ->and($context['status'])->toBe(503);
    }
});

test('backoff delays stay bounded within the configured cap', function () {
    resConfigure();

    $service = new class extends App\Services\GeminiChatbotService
    {
        public function __construct()
        {
        }

        public function delay(int $attempt, int $base, int $max): int
        {
            return $this->retryDelayMs($attempt, $base, $max);
        }
    };

    foreach ([1, 2, 3, 4, 10] as $attempt) {
        $delay = $service->delay($attempt, 400, 2000);

        expect($delay)->toBeGreaterThanOrEqual(0)
            ->and($delay)->toBeLessThanOrEqual(2000);
    }

    // Jitter floor: exponential base respected (attempt 1 >= base/2).
    expect($service->delay(1, 400, 2000))->toBeGreaterThanOrEqual(200)
        ->and($service->delay(1, 0, 0))->toBe(0);
});

test('a successful first attempt sends exactly one request (no added latency)', function () {
    resConfigure();

    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(resGeminiBody('All good'), 200),
    ]);

    $this->postJson('/api/chatbot', resPayload())
        ->assertOk()
        ->assertJson(['reply' => 'All good', 'escalation' => null]);

    Http::assertSentCount(1);
});
