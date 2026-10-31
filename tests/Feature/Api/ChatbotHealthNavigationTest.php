<?php

use App\Models\Doctor;
use App\Services\EmergencyDetector;
use App\Services\FirstAidGuidance;
use App\Services\ConcernSpecialtyMapper;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Health-navigation tests — fully offline
|--------------------------------------------------------------------------
|
| General-purpose (not symptom-specific) coverage for the healthcare
| navigation layer:
|   - emergency escalation: positive AND negative cases per category
|     (EN + AR), including the airway+swelling combination rule,
|   - the second-tier "urgent" (same-day) screen and its prompt notice,
|   - the reviewed first-aid knowledge base: structure, sources, review
|     dates, banned medication/dosage terms (EN + AR), injection rules,
|   - specialty mapping breadth (EN + AR) incl. the general-exclusion
|     rule and generic "I don't feel well" phrasing,
|   - ambiguous messages and Arabic/English behavior,
|   - false-positive resistance of the emergency screen.
|
*/

const NAV_TEST_KEY = 'gemini_nav_test_key_13579113579';

function navConfigure(?string $key = NAV_TEST_KEY): void
{
    config(['services.gemini.key' => $key]);
    config(['services.gemini.model' => 'gemini-test-model']);
}

function navGeminiBody(string $reply = 'OK from model'): array
{
    return [
        'candidates' => [[
            'content' => ['role' => 'model', 'parts' => [['text' => $reply]]],
            'finishReason' => 'STOP',
        ]],
    ];
}

function navFakeGemini(string $reply = 'OK from model'): void
{
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(navGeminiBody($reply)),
    ]);
}

function navPayload(array $overrides = []): array
{
    return array_merge([
        'message' => 'What services are available?',
        'language' => 'en',
    ], $overrides);
}

function navSystemInstruction(): ?string
{
    $captured = null;

    Http::assertSent(function ($request) use (&$captured) {
        if (str_contains($request->url(), 'generativelanguage.googleapis.com')) {
            $captured = $request->data();
        }

        return true;
    });

    return $captured['systemInstruction']['parts'][0]['text'] ?? null;
}

/*
|--------------------------------------------------------------------------
| 1. Emergency escalation — positive cases per category (EN + AR)
*/

test('emergency screen escalates every red-flag category in english', function () {
    $detector = app(EmergencyDetector::class);

    $positives = [
        'chest_pain' => 'I have crushing chest pain since an hour',
        'breathing' => 'my mother is struggling to breathe right now',
        // Airway difficulty + facial swelling = the anaphylaxis combination
        // rule (both the base category and the combination category match).
        'swallowing' => 'I cannot swallow and my face is swelling',
        'airway_swelling_combination' => 'I cannot breathe and my face is swelling',
        'unconscious' => 'my father is unconscious on the floor',
        'seizure' => 'she is having a seizure right now',
        'stroke' => 'he has face drooping and slurred speech right now',
        'severe_bleeding' => 'the bleeding is heavy and will not stop',
        'choking' => 'my son is choking on food',
        'poisoning' => 'my child swallowed poison from under the sink',
        'self_harm' => 'I want to hurt myself tonight',
        'severe_burn' => 'his clothes are on fire after the accident',
        'head_injury' => 'I fell and hit my head badly',
        'severe_headache' => 'this is the worst headache of my life',
        'generic_emergency' => 'please help this is a medical emergency',
    ];

    foreach ($positives as $category => $message) {
        $result = $detector->assess($message);

        expect($result['emergency'], "expected emergency for: {$message}")->toBeTrue()
            ->and($result['categories'], "expected category {$category} for: {$message}")->toContain($category);
    }
});

test('emergency screen escalates red-flag categories in arabic', function () {
    $detector = app(EmergencyDetector::class);

    $positives = [
        'chest_pain' => 'عندي ألم في الصدر شديد منذ ساعة',
        'breathing' => 'زوجتي تواجه صعوبة في التنفس الآن',
        'swallowing' => 'لا أستطيع البلع مع تورم الشفاه',
        'unconscious' => 'طفلي مغمى عليه على الأرض',
        'seizure' => 'ابني يعاني من تشنج الآن',
        'severe_bleeding' => 'النزيف غزير ولا يتوقف',
        'self_harm' => 'أريد أن أنهي حياتي الليلة',
        'generic_emergency' => 'أحتاج مساعدة هذه حالة طارئة',
    ];

    foreach ($positives as $category => $message) {
        $result = $detector->assess($message);

        expect($result['emergency'], "expected emergency for: {$message}")->toBeTrue()
            ->and($result['categories'])->toContain($category);
    }
});

test('emergency message short-circuits the API before any Gemini call even for a mixed urgent+emergency message', function () {
    navConfigure();
    Http::fake();

    $response = $this->postJson('/api/chatbot', navPayload([
        'message' => 'I have a very high fever since two days and now I cannot breathe',
    ]));

    $response->assertOk()->assertJson(['escalation' => 'emergency', 'model' => 'rule-based-safety']);
    Http::assertNothingSent();
});

/*
|--------------------------------------------------------------------------
| 2. False-positive resistance (negative cases per category)
*/

test('emergency screen does not escalate everyday medical or clinic sentences', function () {
    $detector = app(EmergencyDetector::class);

    $negatives = [
        'The chest X-ray room is on the first floor',                       // chest
        'Do you offer a heart checkup package?',                            // heart
        'We learned about heartburn triggers in a class',                   // heartburn
        'Do you do breathing tests at the clinic?',                         // breathing
        'My gums bleed a little when I brush them',                         // bleeding
        'I saw a child having a tantrum in the waiting room',               // fits
        'The elevator stopped and I felt dizzy for a second',               // dizzy
        'How much does a full body scan cost?',                             // body
        'I am baking bread at home right now',                              // bread
    ];

    foreach ($negatives as $message) {
        expect($detector->assess($message)['emergency'], "unexpected emergency for: {$message}")->toBeFalse();
    }
});

test('emergency screen does not escalate everyday arabic sentences', function () {
    $detector = app(EmergencyDetector::class);

    $negatives = [
        'هل توجد خدمة أشعة للصدر في العيادة؟',                             // chest mention, no red flag
        'ابني يعاني من طفح جلدي على ذراعه',                                // rash
        'ما زلت أعاني من ألم أسنان منذ يومين',                              // toothache
        'أريد حجز موعد لفحص القلب',                                        // booking, no red flag
        'هل تعمل العيادة يوم الجمعة؟',                                     // hours
    ];

    foreach ($negatives as $message) {
        expect($detector->assess($message)['emergency'], "unexpected emergency for: {$message}")->toBeFalse();
    }
});

/*
|--------------------------------------------------------------------------
| 3. Urgent (same-day) tier
*/

test('urgent screen detects same-day signs but never emergencies', function () {
    $detector = app(EmergencyDetector::class);

    $urgents = [
        ['high_or_persistent_fever', 'I have a very high fever since two days'],
        ['severe_abdominal_pain', 'my grandmother has severe stomach pain since last night'],
        ['blood_in_urine_or_stool', 'I noticed blood in my urine this morning'],
        ['pregnancy_bleeding', 'my wife is pregnant and bleeding a little'],
        ['persistent_vomiting', 'she cannot stop vomiting since yesterday'],
        ['confusion', 'my father seems confused and is acting strangely today'],
        ['sudden_vision_loss', 'I lost my vision in one eye suddenly'],
        ['high_or_persistent_fever', 'عندي حمى شديدة منذ يومين'],
    ];

    foreach ($urgents as [$category, $message]) {
        $urgent = $detector->urgent($message);

        expect($urgent['urgent'], "expected urgent for: {$message}")->toBeTrue()
            ->and($urgent['categories'], "expected category {$category} for: {$message}")->toContain($category)
            // Urgent must NEVER be an emergency (tiers are separate).
            ->and($detector->assess($message)['emergency'], "urgent must not be emergency: {$message}")->toBeFalse();
    }
});

test('urgent screen stays quiet on ordinary sentences (precision)', function () {
    $detector = app(EmergencyDetector::class);

    $negatives = [
        'I am confused about the pricing plans of your services',
        'The weather is very hot today',
        'Do you have a leaflet about measuring body temperature at home?',
        'ما زلت أعاني من حمى منخفضة',                        // low fever description ≠ high/persistent
        'أنا محتار بين تخصصين',                              // "confused" between choices
    ];

    foreach ($negatives as $message) {
        expect($detector->urgent($message)['urgent'], "unexpected urgent for: {$message}")->toBeFalse();
    }
});

test('an urgent (non-emergency) message still reaches Gemini with a same-day prompt notice and null escalation', function () {
    navConfigure();
    navFakeGemini();

    $response = $this->postJson('/api/chatbot', navPayload([
        'message' => 'I have a very high fever since two days',
    ]));

    $response->assertOk()->assertJson([
        'model' => 'gemini-test-model',
        'escalation' => null,
    ]);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'generativelanguage'));

    $instruction = navSystemInstruction();

    expect($instruction)->toContain('URGENT (SAME-DAY) NOTICE')
        ->toContain('high_or_persistent_fever')
        ->toContain('NOT a diagnosis')
        ->toContain('not wait for a routine appointment');
});

test('plain faq messages get no urgency notice and no triage mode', function () {
    navConfigure();
    navFakeGemini();

    $this->postJson('/api/chatbot', navPayload())->assertOk();

    $instruction = navSystemInstruction();

    expect($instruction)->not->toContain('URGENT (SAME-DAY) NOTICE')
        ->not->toContain('TRIAGE MODE')
        ->not->toContain('REVIEWED FIRST-AID KNOWLEDGE BASE');
});

/*
|--------------------------------------------------------------------------
| 4. Reviewed first-aid knowledge base
*/

test('first-aid kb entries are complete, dated and free of medication or dosage terms', function () {
    $kb = app(FirstAidGuidance::class);
    $entries = $kb->entries();

    expect($entries)->not->toBeEmpty()
        ->and($kb->reviewDate())->toMatch('/^\d{4}-\d{2}-\d{2}$/');

    // Broad coverage: several distinct symptom categories, not one topic.
    $expectedCategories = [
        'minor_burns', 'nosebleed', 'minor_wound', 'sprain_strain',
        'mild_dehydration', 'mild_fever', 'mild_headache', 'insect_bite_sting',
    ];

    expect(array_diff($expectedCategories, array_keys($entries)))->toBeEmpty();

    $banned = [
        // EN medication / dosage vocabulary.
        'paracetamol', 'ibuprofen', 'aspirin', 'acetaminophen', 'antihistamine',
        'antibiotic', 'painkiller', 'pain killer', 'tablet', 'capsule', 'syrup',
        'dosage', 'dose', 'medicine', 'medication', 'pill',
        // AR medication / dosage vocabulary.
        'باراسيتامول', 'اسبرين', 'مضاد حيوي', 'جرعة', 'حبوب', 'أدوية', 'ادوية', 'دواء',
    ];

    foreach ($entries as $category => $entry) {
        expect($entry['guidance']['en'], "empty EN guidance: {$category}")->not->toBeEmpty()
            ->and($entry['guidance']['ar'], "empty AR guidance: {$category}")->not->toBeEmpty()
            ->and($entry['seek_care']['en'], "missing EN seek-care: {$category}")->not->toBe('')
            ->and($entry['seek_care']['ar'], "missing AR seek-care: {$category}")->not->toBe('')
            ->and($entry['sources'], "missing sources: {$category}")->not->toBeEmpty();

        foreach ($entry['sources'] as $source) {
            expect(strlen($source))->toBeGreaterThan(10);
        }

        $allText = mb_strtolower(implode(' ', array_merge(
            $entry['guidance']['en'],
            $entry['guidance']['ar'],
            [$entry['seek_care']['en'], $entry['seek_care']['ar']]
        )));

        foreach ($banned as $term) {
            expect($allText, "banned term '{$term}' found in {$category}")->not->toContain($term);
        }
    }
});

test('first-aid kb matches many different situations in english and arabic', function () {
    $kb = app(FirstAidGuidance::class);

    $matches = [
        'I scalded my hand with hot water' => 'minor_burns',
        'my son has a nosebleed that started ten minutes ago' => 'nosebleed',
        'I cut my finger while cooking' => 'minor_wound',
        'I twisted my ankle playing football' => 'sprain_strain',
        'I feel dehydrated after walking in the heat' => 'mild_dehydration',
        'my daughter has had a fever since this morning' => 'mild_fever',
        'I have had a headache since yesterday' => 'mild_headache',
        'I was stung by a bee in the garden' => 'insect_bite_sting',
        'أصبت بحرقة بسيطة من الماء الساخن' => 'minor_burns',
        'عندي نزيف من الأنف' => 'nosebleed',
        'طفلي عنده حمى منذ الصباح' => 'mild_fever',
        'تلسعت لدغة نحلة في يدي' => 'insect_bite_sting',
    ];

    foreach ($matches as $message => $category) {
        $match = $kb->match($message);

        expect($match, "expected KB match '{$category}' for: {$message}")->not->toBeNull()
            ->and($match['category'])->toBe($category);
    }

    // Not covered => null (the model must say guidance is unavailable).
    expect($kb->match('What are your visiting hours?'))->toBeNull()
        ->and($kb->match('How do I book an appointment?'))->toBeNull()
        ->and($kb->match('أريد معرفة أسعار الفحوصات'))->toBeNull();
});

test('first-aid kb prompt block is injected with sources and review date only for covered situations', function () {
    navConfigure();
    navFakeGemini();

    $this->postJson('/api/chatbot', navPayload([
        'message' => 'I scalded my hand with hot water, what should I do?',
    ]))->assertOk();

    $instruction = navSystemInstruction();

    expect($instruction)->toContain('REVIEWED FIRST-AID KNOWLEDGE BASE')
        ->toContain('minor_burns')
        ->toContain(app(FirstAidGuidance::class)->reviewDate())
        ->toContain('American Red Cross')
        ->toContain('Sources reviewed')
        ->toContain('never replaces professional care');
});

test('first-aid kb prompt block is translated to arabic for arabic messages', function () {
    navConfigure();
    navFakeGemini();

    $this->postJson('/api/chatbot', navPayload([
        'message' => 'أصبت بحرقة بسيطة من الماء الساخن، ماذا أفعل؟',
        'language' => 'ar',
    ]))->assertOk();

    $instruction = navSystemInstruction();

    expect($instruction)->toContain('REVIEWED FIRST-AID KNOWLEDGE BASE')
        ->toContain('minor_burns')
        ->toContain('المحروقة')
        ->toContain('إرشاد الموثوق غير متوفر')
        ->toContain(app(FirstAidGuidance::class)->reviewDate());
});

test('first-aid kb prompt block never suggests medication and demands honesty when uncovered', function () {
    $kb = app(FirstAidGuidance::class);

    $block = $kb->promptBlock('I scalded my hand with hot water', 'en');

    expect($block)->toContain('Do NOT add any medication, dosage, or additional home remedy')
        ->toContain('honestly say reliable guidance is unavailable')
        ->toContain('recommend appropriate professional evaluation instead of improvising');
});

/*
|--------------------------------------------------------------------------
| 5. Specialty mapping breadth (EN + AR) + general exclusion
*/

test('mapper covers many concern categories in english and arabic against real clinic specialties', function () {
    $mapper = app(ConcernSpecialtyMapper::class);

    $clinic = [
        'Cardiology', 'Dermatology', 'Dentistry', 'Pediatrics', 'Orthopedics',
        'Neurology', 'ENT', 'Ophthalmology', 'Obstetrics & Gynecology',
        'Gastroenterology', 'Urology', 'Pulmonology', 'General Medicine',
        'Psychiatry', 'Allergy & Immunology',
    ];

    $cases = [
        'I have chest pain and palpitations' => ['Cardiology'],
        'عندي حكة وطفح جلدي على ذراعي' => ['Dermatology'],
        'my child has a cough and needs a checkup' => ['Pediatrics', 'Pulmonology'],
        'which doctor should I see for my back pain' => ['Orthopedics'],
        'أعاني من إسهال مستمر' => ['Gastroenterology'],
        'I want an eye examination, my vision is blurry' => ['Ophthalmology'],
        'i am sneezing all the time because of an allergy' => ['Allergy & Immunology'],
        'my wife is pregnant and needs a checkup' => ['Obstetrics & Gynecology'],
        'I have trouble urinating and pain' => ['Urology'],
    ];

    foreach ($cases as $message => $expected) {
        expect($mapper->match($clinic, $message), "mapping failed for: {$message}")->toBe($expected);
    }
});

test('mapper prefers specific specialties over the general one (general-exclusion rule)', function () {
    $mapper = app(ConcernSpecialtyMapper::class);

    // Specific + general both match -> only the specific specialty is returned.
    expect($mapper->match(['General Medicine', 'Cardiology'], "I have chest pain and I don't feel well"))
        ->toBe(['Cardiology']);

    // Only generic malaise -> general medicine is a legitimate orientation.
    expect($mapper->match(['General Medicine', 'Cardiology'], "I don't feel well today"))
        ->toBe(['General Medicine']);

    // Generic phrasing with no general specialty listed -> honest empty result.
    expect($mapper->match(['Cardiology'], "I don't feel well today"))->toBe([]);
});

test('mapper exposes matched concern categories and triage activates for generic malaise too', function () {
    $mapper = app(ConcernSpecialtyMapper::class);

    expect($mapper->categories('I have a skin rash and itching'))->toContain('dermatology')
        ->and($mapper->categories('أعاني من صداع شديد'))->toContain('neurology')
        ->and($mapper->mentionsHealthConcern("I don't feel well today"))->toBeTrue()
        ->and($mapper->mentionsHealthConcern('أنا تعبان جداً اليوم'))->toBeTrue()
        ->and($mapper->mentionsHealthConcern('What services are available?'))->toBeFalse()
        ->and($mapper->mentionsHealthConcern('How do I book an appointment?'))->toBeFalse();
});

test('generic malaise message activates triage adaptation rules without inventing a specialty', function () {
    navConfigure();
    navFakeGemini();

    Doctor::factory()->create(['specialty' => 'Cardiology']);

    $this->postJson('/api/chatbot', navPayload([
        'message' => "I don't feel well today",
    ]))->assertOk();

    $instruction = navSystemInstruction();

    expect($instruction)->toContain('TRIAGE MODE')
        ->toContain('Never give a blanket refusal as the default answer')
        ->toContain('Adapt your reply to the concern the user actually described')
        ->toContain('does not match any specialty this clinic lists');
});

/*
|--------------------------------------------------------------------------
| 6. Ambiguous messages + language behavior + prompt honesty
*/

test('ambiguous short symptom messages still activate triage and never an emergency', function () {
    navConfigure();
    navFakeGemini();

    $ambiguous = [
        'I feel sick',
        'عندي أعراض',
        'something is wrong with me',
    ];

    navFakeGemini();

    foreach ($ambiguous as $message) {
        $response = $this->postJson('/api/chatbot', navPayload(['message' => $message]));

        $response->assertOk()->assertJson(['escalation' => null]);

        $instruction = navSystemInstruction();

        expect($instruction, "no triage for: {$message}")->toContain('TRIAGE MODE')
            ->toContain('SAFETY NOTE')
            ->toContain('NOT a complete or reliable medical triage system');
    }
});

test('the prompt tells the model the deterministic screen is only a safety net', function () {
    navConfigure();
    navFakeGemini();

    $this->postJson('/api/chatbot', navPayload())->assertOk();

    $instruction = navSystemInstruction();

    expect($instruction)->toContain('simple deterministic keyword screen')
        ->toContain('NOT a complete or reliable medical triage system')
        ->toContain('recommend professional evaluation instead of guessing');
});

test('arabic symptom messages get arabic-preferred instructions while english stays english', function () {
    navConfigure();
    navFakeGemini();

    $this->postJson('/api/chatbot', navPayload([
        'message' => 'عندي صداع وأعراض غير مريحة',
        'language' => 'ar',
    ]))->assertOk();

    expect(navSystemInstruction())->toContain('Arabic (العربية)');

    Http::fake();
    navFakeGemini();

    $this->postJson('/api/chatbot', navPayload([
        'message' => 'I have uncomfortable symptoms and a headache',
    ]))->assertOk();

    expect(navSystemInstruction())->toContain('prefer English');
});

test('kb guidance and urgency detection compose without breaking the api contract', function () {
    navConfigure();
    navFakeGemini('Here is some general guidance.');

    $response = $this->postJson('/api/chatbot', navPayload([
        'message' => 'she cannot stop vomiting since yesterday and has a fever since this morning',
    ]));

    $response->assertOk()
        ->assertJsonStructure(['reply', 'model', 'escalation'])
        ->assertJson([
            'reply' => 'Here is some general guidance.',
            'model' => 'gemini-test-model',
            'escalation' => null,
        ]);

    $instruction = navSystemInstruction();

    // Both the reviewed KB and the urgent tier can appear together…
    expect($instruction)->toContain('REVIEWED FIRST-AID KNOWLEDGE BASE')
        ->toContain('mild_fever')
        ->toContain('URGENT (SAME-DAY) NOTICE')
        ->toContain('persistent_vomiting')
        // …and neither may weaken the non-diagnosis / no-medication rules.
        ->toContain('NEVER state or imply a diagnosis as fact')
        ->toContain('NEVER recommend or suggest any medication');
});
