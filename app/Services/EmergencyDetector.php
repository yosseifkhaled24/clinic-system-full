<?php

namespace App\Services;

/**
 * DETERMINISTIC emergency detection for the clinic chatbot (English +
 * Arabic). Critical safety behavior must not depend on the LLM, so this
 * keyword/phrase screen runs BEFORE any Gemini call: a positive match
 * short-circuits the request with a fixed escalation reply and the model
 * is never consulted.
 *
 * Design choices:
 *  - High recall over precision: a false positive only produces an
 *    "call emergency services" message (harmless), while a false
 *    negative could delay urgent care.
 *  - Phrases are matched on normalized text (lowercase, Arabic diacritics
 *    stripped, alef/hamza forms unified, apostrophes removed, whitespace
 *    collapsed), so "Can't BREATHE" and "لا أستطيع التنَفّس" both match.
 *  - ASCII keywords use word boundaries (so "heart" does not match
 *    "heartburn" and "ear" does not match "learn"); Arabic has no word
 *    boundaries, so Arabic phrases match as substrings.
 *  - The combination rule from the product spec is implemented
 *    explicitly: breathing OR swallowing difficulty together with
 *    facial / lip / tongue / throat swelling = immediate escalation
 *    (anaphylaxis pattern, can become life-threatening within minutes).
 *
 * LIMITATIONS (by design, and stated to the model in the prompt too):
 *  - Keyword matching is a SAFETY NET for the most obvious red flags, NOT
 *    a complete or reliable medical triage system. It cannot understand
 *    context, severity or novel phrasing, so:
 *      * unusual wording of a real emergency may be missed — the LLM's
 *        own emergency guidance (system-instruction rule 2) remains as a
 *        backstop, and the model is told to escalate whenever it cannot
 *        judge urgency;
 *      * over-matching is accepted on the emergency tier (a false
 *        positive only yields "call emergency services"), while the
 *        urgent tier below stays conservative to avoid noise.
 *  - Nothing here diagnoses anything; matched categories are only used to
 *    pick the wording of escalation notices.
 *
 * Two escalation tiers feed the chatbot prompt:
 *  - assess()   => EMERGENCY: deterministic short-circuit before Gemini.
 *  - urgent()   => SAME-DAY (urgent but NOT emergency): a prompt notice
 *    only; the conversation continues, and emergencies always win because
 *    assess() runs first.
 */
class EmergencyDetector
{
    /**
     * Single-trigger red flags: each category escalates on its own.
     *
     * @var array<string, list<string>>
     */
    protected const RED_FLAGS = [
        'chest_pain' => [
            'chest pain', 'pain in my chest', 'chest pressure', 'tightness in chest',
            'ألم في الصدر', 'ألم بصدري', 'وجع الصدر', 'ضغط الصدر', 'ضيق في الصدر',
        ],
        'breathing' => [
            'difficulty breathing', 'breathing difficulty', 'hard to breathe',
            'cant breathe', 'cannot breathe', 'not breathing', 'struggling to breathe',
            'shortness of breath', 'labored breathing',
            'ضيق تنفس', 'ضيق النفس', 'ضيق في التنفس', 'صعوبة التنفس', 'صعوبة في التنفس',
            'صعوبة تنفس',
            'لا أستطيع التنفس', 'لا يستطيع التنفس', 'لا أستطيع النفس', 'لا يستطيع النفس',
            'تنفس صعب', 'تنفّس صعب',
        ],
        'swallowing' => [
            'difficulty swallowing', 'swallowing difficulty', 'cant swallow',
            'cannot swallow', 'trouble swallowing',
            'صعوبة البلع', 'صعوبة بلع', 'صعوبة في البلع', 'لا أستطيع البلع', 'لا يستطيع البلع', 'صعب البلع',
        ],
        // Airway-adjacent swelling escalates on its own; face/lip/mouth
        // swelling escalates only via the combination rule below.
        'airway_swelling' => [
            'swollen throat', 'swollen tongue', 'throat swelling', 'tongue swelling',
            'تورم الحلق', 'تورم في الحلق', 'تورم اللسان', 'تورم في اللسان',
            'حلق متورم', 'لسان متورم',
        ],
        'unconscious' => [
            'unconscious', 'unresponsive', 'not responding', 'passed out',
            'blacked out', 'lost consciousness',
            'فاقد للوعي', 'فقدان الوعي', 'مغمى عليه', 'غير واعٍ', 'لا يستيقظ',
        ],
        'seizure' => [
            'seizure', 'convulsion', 'having fits',
            'تشنج', 'تشنجات', 'نوبة صرع', 'نوبة الصرع',
        ],
        'stroke' => [
            'face drooping', 'slurred speech', 'sudden numbness', 'arm weakness',
            'cant speak properly', 'one side of the face',
            'تنميل مفاجئ', 'ترهل الوجه', 'الوجه مائل', 'صعوبة الكلام المفاجئة', 'جلطة',
        ],
        'severe_bleeding' => [
            'heavy bleeding', 'bleeding is heavy', 'bleeding wont stop', 'bleeding will not stop',
            'bleeding is not stopping', 'bleeding does not stop', 'bleeding doesnt stop',
            'cant stop the bleeding', 'cant stop bleeding', 'blood wont stop',
            'is bleeding a lot', 'bleeding too much',
            'نزيف شديد', 'نزيف غزير', 'النزيف شديد', 'النزيف لا يتوقف', 'لا يتوقف النزيف',
            'خروج دم كثير', 'أنزف كثير', 'انزف كثير',
        ],
        'choking' => [
            'choking', 'choked on', 'cant breathe while eating',
            'اختناق', 'خانقني', 'عالق في الحلق',
        ],
        'poisoning' => [
            'poisoning', 'poisoned', 'overdose', 'swallowed poison', 'ingested poison',
            'تسمم', 'تسممت', 'جرعة زائدة', 'تناولت سم',
        ],
        'self_harm' => [
            'kill myself', 'end my life', 'suicide', 'suicidal', 'hurt myself',
            'self harm', 'انتحار', 'أنهي حياتي', 'أؤذي نفسي', 'أذي نفسي', 'اقتل نفسي',
        ],
        'severe_burn' => [
            'on fire', 'severe burns', 'badly burned', 'third degree burn',
            'اشتعال', 'حروق شديدة', 'حروق بالغة', 'احترقت بشدة',
        ],
        'head_injury' => [
            'hit my head', 'head injury', 'blow to the head', 'fell and hit my head',
            'ضربة في الرأس', 'إصابة في الرأس', 'سقطت على رأسي', 'سقط على رأسه',
        ],
        'cardiac_arrest_signs' => [
            'blue lips', 'blue face', 'not breathing', 'lips turned blue',
            'الشفاه زرقاء', 'شفاه زرقاء', 'الوجه أزرق', 'لون الأزرق',
        ],
        'severe_headache' => [
            'worst headache', 'thunderclap headache', 'sudden severe headache',
            'صداع شديد مفاجئ', 'أشد صداع', 'صداع مفاجئ شديد',
        ],
        'generic_emergency' => [
            'medical emergency', 'this is an emergency', 'emergency help now',
            'حالة طارئة', 'حالة طبية طارئة', 'طوارئ الآن',
        ],
    ];

    /**
     * Swelling that alone is NOT decisive (may be a non-airway reaction)
     * but becomes an emergency when combined with airway symptoms.
     *
     * @var list<string>
     */
    protected const REACTIVE_SWELLING = [
        'swollen face', 'swollen lips', 'swollen lip', 'swollen mouth',
        'face is swelling', 'facial swelling', 'lip swelling', 'mouth swelling',
        'swelling around the eyes',
        'تورم الوجه', 'تورم في الوجه', 'تورم الشفاه', 'تورم شفاه', 'تورم الفم',
        'الوجه متورم', 'شفاه متورمة', 'الشفاه متورمة', 'فمي متورم',
    ];

    /**
     * Categories that, together with REACTIVE_SWELLING, trigger the
     * breathing/swallowing + swelling escalation rule.
     *
     * @var list<string>
     */
    protected const AIRWAY_CATEGORIES = ['breathing', 'swallowing'];

    /**
     * URGENT (same-day) signs — concerning but NOT automatic emergencies.
     *
     * These never short-circuit the conversation; they only inject a
     * "seek same-day professional care" notice into the prompt. Kept
     * deliberately conservative (phrased as severity/time qualifiers, not
     * bare symptom words) so ordinary FAQ phrasing never triggers them.
     * Medically reviewed red-flag patterns (persistent vomiting, confusion,
     * pregnancy bleeding, blood in urine/stool, high/persistent fever,
     * sudden vision loss, severe abdominal pain) are covered per common
     * triage guidance from NHS/Mayo/WHO patient-facing pages; see
     * docs/clinic-chatbot-medical-guidance.md.
     *
     * @var array<string, list<string>>
     */
    protected const URGENT_SIGNS = [
        'high_or_persistent_fever' => [
            'high fever', 'very high temperature', 'temperature is very high',
            'fever for days', 'fever for three days', 'fever since three days',
            'حمى شديدة', 'حرارة مرتفعة جدا', 'حرارة عالية جدا', 'حرارة منذ عدة ايام',
        ],
        'severe_abdominal_pain' => [
            'severe stomach pain', 'severe abdominal pain', 'cant stop stomach pain',
            'stomach pain i cannot', 'ألم شديد في المعدة', 'ألم شديد في البطن', 'ألم بطن شديد',
        ],
        'blood_in_urine_or_stool' => [
            'blood in my urine', 'blood in urine', 'blood when i urinate', 'blood in my stool',
            'blood in stool', 'bloody stool', 'دم في البول', 'دم في البراز', 'بول فيه دم',
        ],
        'pregnancy_bleeding' => [
            'pregnant and bleeding', 'bleeding during pregnancy', 'bleeding while pregnant',
            'miscarriage symptoms', 'نزيف أثناء الحمل', 'حامل ونزيف', 'نزيف وحمل',
        ],
        'persistent_vomiting' => [
            'cant stop vomiting', 'cannot stop vomiting', 'vomiting nonstop', 'vomiting for days',
            'استفراغ مستمر', 'قيء مستمر', 'أقيء باستمرار', 'استفراغ منذ ايام',
        ],
        'confusion' => [
            'very confused', 'seems confused', 'acting confused', 'mental confusion',
            'acting strangely', 'ارتباك شديد', 'يبدو مرتباكا', 'حالة ارتباك',
        ],
        'sudden_vision_loss' => [
            'lost my vision', 'loss of vision', 'sudden vision loss', 'cant see suddenly',
            'فقدان الرؤية', 'فقدت الرؤية', 'ضياع البصر المفاجئ',
        ],
    ];

    /**
     * @return array{emergency: bool, categories: list<string>}
     */
    public function assess(string $message): array
    {
        $text = $this->normalize($message);
        $categories = [];

        foreach (self::RED_FLAGS as $category => $phrases) {
            foreach ($phrases as $phrase) {
                if ($this->contains($text, $phrase)) {
                    $categories[] = $category;
                    break;
                }
            }
        }

        // Combination rule: airway difficulty + facial/oral swelling.
        $airway = array_intersect($categories, self::AIRWAY_CATEGORIES) !== [];
        $swelling = $this->matchesAny($text, self::REACTIVE_SWELLING);

        if ($airway && $swelling) {
            $categories[] = 'airway_swelling_combination';
        }

        return [
            'emergency' => $categories !== [],
            'categories' => array_values(array_unique($categories)),
        ];
    }

    /**
     * Same-day urgency screen (urgent but NOT emergency). Never overrides
     * assess(): callers MUST run assess() first and only consult urgent()
     * when the message is not an emergency.
     *
     * @return array{urgent: bool, categories: list<string>}
     */
    public function urgent(string $message): array
    {
        $text = $this->normalize($message);
        $categories = [];

        foreach (self::URGENT_SIGNS as $category => $phrases) {
            foreach ($phrases as $phrase) {
                if ($this->contains($text, $phrase)) {
                    $categories[] = $category;
                    break;
                }
            }
        }

        return [
            'urgent' => $categories !== [],
            'categories' => array_values(array_unique($categories)),
        ];
    }

    /**
     * True when the text is written in Arabic script (used to pick the
     * language of the deterministic escalation reply).
     */
    public function containsArabic(string $text): bool
    {
        return (bool) preg_match('/\p{Arabic}/u', $text);
    }

    protected function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');

        // Strip Arabic diacritics/tatweel, unify hamza forms, apostrophes.
        $text = (string) preg_replace('/[\x{064B}-\x{0652}\x{0640}]/u', '', $text);
        $text = strtr($text, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا',
            'ى' => 'ي', 'ؤ' => 'و', 'ئ' => 'ي',
            "'" => '', '’' => '', '`' => '',
        ]);
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return $text;
    }

    protected function contains(string $normalizedText, string $phrase): bool
    {
        $phrase = $this->normalize($phrase);

        if ($phrase === '') {
            return false;
        }

        // ASCII phrases need a leading word boundary ("ear" must not match
        // "learn"); a leading-only boundary keeps plural recall
        // ("seizures", "chest pains"). Arabic matches as a substring.
        if (preg_match('/^[\x00-\x7F]+$/u', $phrase)) {
            return (bool) preg_match('/\b'.preg_quote($phrase, '/').'/u', $normalizedText);
        }

        return str_contains($normalizedText, $phrase);
    }

    /**
     * @param  list<string>  $phrases
     */
    protected function matchesAny(string $normalizedText, array $phrases): bool
    {
        foreach ($phrases as $phrase) {
            if ($this->contains($normalizedText, $phrase)) {
                return true;
            }
        }

        return false;
    }
}
