<?php

use App\Services\GeminiChatbotService;
use App\Models\Doctor;
use App\Models\Service;

/*
|--------------------------------------------------------------------------
| Live Gemini smoke test (opt-in)
|--------------------------------------------------------------------------
|
| This is the only test that touches the real Gemini API. It is skipped
| unless you explicitly enable it:
|
|   GEMINI_LIVE_TEST=1 php artisan test --filter=ChatbotLive
|
| It requires a valid GEMINI_API_KEY in .env and network access.
| It performs exactly ONE real API call, so it also exercises the
| configured GEMINI_MODEL.
|
*/

test('real Gemini API answers a clinic question end-to-end', function () {
    if (! config('services.gemini.key')) {
        $this->markTestSkipped('GEMINI_API_KEY is not configured.');
    }

    // Make the assertion meaningful: real clinic data must reach the model.
    Doctor::factory()->create([
        'name' => 'Dr. Live Smoke Test',
        'specialty' => 'Cardiology',
    ]);

    $result = app(GeminiChatbotService::class)->chat(
        'Which specialties does this clinic have? Answer in one short sentence.',
        [],
        'en'
    );

    expect($result['reply'])->not->toBeEmpty()
        ->and($result['model'])->toBe((string) config('services.gemini.model'));
})->skip(
    env('GEMINI_LIVE_TEST') !== '1',
    'Set GEMINI_LIVE_TEST=1 to run against the real Gemini API.'
);

test('real Gemini API answers through the HTTP endpoint in Arabic', function () {
    if (! config('services.gemini.key')) {
        $this->markTestSkipped('GEMINI_API_KEY is not configured.');
    }

    Service::factory()->create([
        'name' => 'فحص القلب بالسونار',
        'description' => 'فحص دوري لوظائف القلب.',
        'price' => 450,
    ]);

    $response = $this->postJson('/api/chatbot', [
        'message' => 'ما هي الخدمات المتاحة في العيادة؟ أجب باختصار.',
        'language' => 'ar',
        'history' => [
            ['role' => 'user', 'text' => 'مرحبا'],
            ['role' => 'model', 'text' => 'أهلاً بك! كيف يمكنني مساعدتك؟'],
        ],
    ]);

    $response->assertOk()->assertJsonStructure(['reply', 'model']);

    $reply = $response->json('reply');

    // The answer must come back in Arabic (real model, real multi-turn).
    expect($reply)->not->toBeEmpty()
        ->and(preg_match('/\p{Arabic}/u', $reply))->toBe(1);
})->skip(
    env('GEMINI_LIVE_TEST') !== '1',
    'Set GEMINI_LIVE_TEST=1 to run against the real Gemini API.'
);
