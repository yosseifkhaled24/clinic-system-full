<?php

namespace App\Services;

/**
 * Versioned, reviewed first-aid knowledge base for the clinic chatbot
 * (English + Arabic).
 *
 * Purpose & safety model:
 *  - The chatbot stays an INFORMATIONAL navigation assistant. This KB lets
 *    it relay a small set of well-established, low-risk basic-aid measures
 *    for common situations instead of a blanket refusal — while the
 *    deterministic EmergencyDetector still short-circuits true emergencies
 *    BEFORE any of this is considered.
 *  - Every entry is conservative by construction: general comfort/first-aid
 *    measures only. NO medication, NO dosages, NO substances to ingest or
 *    apply, NO claims about the cause of symptoms. Each entry ends with an
 *    explicit "when to seek professional care" limit.
 *  - Every entry carries its medical SOURCES and a REVIEW date so the
 *    guidance is auditable and maintainable (see
 *    docs/clinic-chatbot-medical-guidance.md for the full review process).
 *  - When a message does not clearly match an entry, match() returns null
 *    and the prompt block is empty — the model is instructed (in the block)
 *    to say reliable guidance is unavailable and recommend professional
 *    evaluation instead of improvising.
 *
 * Usage: GeminiChatbotService injects promptBlock() into the system
 * instruction only when the user's message matches a category, so plain
 * FAQ traffic never sees this content.
 */
class FirstAidGuidance
{
    /** Review date shared by every entry (bump when re-validating the KB). */
    public const REVIEWED_AT = '2026-10-27';

    /**
     * Category => conservative guidance.
     *
     *  - keywords: how to recognise the situation (EN + AR; matched on
     *    normalized text, ASCII with leading word boundary, AR substring).
     *  - guidance: the measures that may be relayed (en/ar bullet lists).
     *  - seek_care: the escalation/limits sentence (en/ar).
     *  - sources: reviewed references the measures derive from.
     *
     * @var array<string, array{
     *     keywords: list<string>,
     *     guidance: array{en: list<string>, ar: list<string>},
     *     seek_care: array{en: string, ar: string},
     *     sources: list<string>
     * }>
     */
    protected const ENTRIES = [
        'minor_burns' => [
            'keywords' => [
                'minor burn', 'small burn', 'burned my', 'burnt my', 'scalded',
                'hot water burn', 'burn on my', ' got burned', ' بحرقة', 'حرقة بسيطة',
                'احترقت', 'سبب لي حرق',
            ],
            'guidance' => [
                'en' => [
                    'Cool the burned area under cool (not ice-cold) running water for about 20 minutes.',
                    'Cover it loosely with a clean, dry cloth or a sterile dressing.',
                    'Do not apply ice, toothpaste, butter, or any home substances to the burn.',
                ],
                'ar' => [
                    'ضع المنطقة المحروقة تحت ماء جارٍ بارد (ليس ثلجياً) لمدة حوالي 20 دقيقة.',
                    'غطّها برفق بقطعة قماش نظيفة وجافة أو ضمادة معقمة.',
                    'لا تضع ثلجاً أو معجون أسنان أو زبدة أو أي مواد منزلية على الحرق.',
                ],
            ],
            'seek_care' => [
                'en' => 'Seek urgent medical care for deep, large, white/black/brown, face/hand/joint, chemical or electrical burns, or burns in young children — and call emergency services immediately if there is breathing difficulty or loss of consciousness.',
                'ar' => 'اطلب رعاية طبية عاجلة للحروق العميقة أو الواسعة أو البيضاء أو السوداء، أو في الوجه أو اليدين أو المفاصل، أو الحروق الكهربائية أو الكاشمة، أو حروق الأطفال الصغار — واتصل بالطوارئ فوراً عند صعوبة التنفس أو فقدان الوعي.',
            ],
            'sources' => [
                'American Red Cross — First Aid/CPR/AED participant manual (burns)',
                'NHS inform — Burns and scalds: first aid',
                'Mayo Clinic — Burns: first aid',
            ],
        ],

        'nosebleed' => [
            'keywords' => [
                'nosebleed', 'nose bleed', 'nose is bleeding', 'nose was bleeding',
                'bleeding from my nose', 'نزيف من الأنف', 'نزيف الانف', 'أنفي ينزف', 'الانف ينزف',
            ],
            'guidance' => [
                'en' => [
                    'Sit up and lean slightly forward, and pinch the soft part of the nose continuously for 10 to 15 minutes.',
                    'Breathe through the mouth; avoid blowing or picking the nose afterwards.',
                    'Do not tilt the head back and do not lie flat.',
                ],
                'ar' => [
                    'اجلس منتصباً وأميل قليلاً إلى الأمام، واضغط على الجزء اللين من الأنف باستمرار من 10 إلى 15 دقيقة.',
                    'تنفس من الفم، وتجنب نفخ الأنف أو تقليبه بعد ذلك.',
                    'لا تُمل الرأس للخلف ولا تستلقِ.',
                ],
            ],
            'seek_care' => [
                'en' => 'Keep firm pressure for a full 20 minutes without releasing it, and seek urgent medical care if bleeding continues beyond 20 to 30 minutes, keeps returning, follows a head injury, or causes faintness — call emergency services for heavy bleeding that will not stop.',
                'ar' => 'استمر بالضغط القوي لمدة 20 دقيقة كاملة دون رفع اليد، واطلب رعاية طبية عاجلة إذا استمر النزيف أكثر من 20 إلى 30 دقيقة أو تكرر أو حدث بعد إصابة في الرأس أو سبب إغماءً — واتصل بالطوارئ عند نزيف غزير لا يتوقف.',
            ],
            'sources' => [
                'NHS inform — Nosebleed (epistaxis)',
                'Mayo Clinic — Nosebleeds: first aid',
                'American Red Cross — First Aid manual (nosebleed)',
            ],
        ],

        'minor_wound' => [
            'keywords' => [
                'cut my finger', 'cut my hand', 'small cut', 'minor cut', 'scraped my',
                'scrape on', 'grazed', 'a graze', 'scratch wound', 'bled from a cut',
                'جرح صغير', 'خدش', 'تجرحت', 'قطع في يدي', 'جرح سطحي',
            ],
            'guidance' => [
                'en' => [
                    'Wash your hands if possible, then rinse the wound under clean running water and gently clean around it with mild soap.',
                    'Apply gentle continuous pressure with a clean cloth until the bleeding slows, then cover with a clean dry dressing.',
                    'Do not apply substances such as toothpaste, spices, or raw materials to the wound.',
                ],
                'ar' => [
                    'اغسل يديك إن أمكن، ثم اشطف الجرح تحت ماء نظيف جارٍ ونظّف محيطه بصابون لطيف.',
                    'اضغط على الجرح بقطعة قماش نظيفة بضغط مستمر حتى يهدأ النزيف، ثم غطّه بضمادة نظيفة وجافة.',
                    'لا تضع مواد مثل معجون الأسنان أو التوابل على الجرح.',
                ],
            ],
            'seek_care' => [
                'en' => 'Seek medical care if bleeding does not slow after 10 minutes of firm pressure, or the wound is deep, wide, gaping, dirty, or caused by a bite, or a recent tetanus vaccination is not on record — emergencies need immediate care.',
                'ar' => 'اطلب رعاية طبية إذا لم يهدأ النزيف بعد 10 دقائق من الضغط القوي، أو كان الجرح عميقاً أو واسعاً أو متسعاً أو متسخاً أو ناتجاً عن لدغة، أو لم يكن هناك تطعيم مضاد للكزاز مؤخراً — والحالات الطارئة تتطلب رعاية فورية.',
            ],
            'sources' => [
                'NHS inform — Cuts and grazes',
                'Mayo Clinic — Cuts and scrapes: first aid',
                'American Red Cross — First Aid manual (wound care)',
            ],
        ],

        'sprain_strain' => [
            'keywords' => [
                'sprained', 'a sprain', 'sprain my', 'twisted my ankle', 'twisted my knee',
                'pulled a muscle', 'pulled muscle', 'strained muscle',
                'التواء', 'التواء الكاحل', 'شد عضلي', 'تمزق عضلي',
            ],
            'guidance' => [
                'en' => [
                    'Rest the injured limb and stop any activity that causes pain.',
                    'Apply a cold pack wrapped in a cloth for 15 to 20 minutes every 2 to 3 hours in the first days.',
                    'Support the limb with a bandage that is snug but not tight, and keep it raised when possible.',
                ],
                'ar' => [
                    'أرح الطرف المصاب وتجنب أي نشاط يسبب الألم.',
                    'ضع كمادة باردة ملفوفة بقماش لمدة 15 إلى 20 دقيقة كل 2 إلى 3 ساعات خلال الأيام الأولى.',
                    'ثبّت الطرف برباط مريح غير ضاغط كثيراً، وارفعه قدر الإمكان.',
                ],
            ],
            'seek_care' => [
                'en' => 'Get same-day medical assessment if the limb looks deformed, cannot bear weight, feels numb, or pain and swelling keep increasing — a fracture needs professional care.',
                'ar' => 'اطلب تقييماً طبياً في نفس اليوم إذا بدا الطرف مشوهاً أو لم يستطع حمل الوزن أو خدر أو استمر تزايد الألم والتورم — فقد يكون هناك كسر يحتاج رعاية متخصصة.',
            ],
            'sources' => [
                'NHS inform — Sprains and strains',
                'Mayo Clinic — Sprains and strains: first aid',
                'American Red Cross — First Aid manual (musculoskeletal injuries)',
            ],
        ],

        'mild_dehydration' => [
            'keywords' => [
                'dehydrated', 'dehydration', 'very thirsty and weak', 'not urinating',
                'dry mouth and weak', 'جفاف', 'عطش شديد', 'قلة التبول',
            ],
            'guidance' => [
                'en' => [
                    'Drink small sips of clean water or an oral rehydration solution frequently, rather than large amounts at once.',
                    'Rest in a cool place and avoid heavy activity until you feel better.',
                ],
                'ar' => [
                    'اشرب رشفات صغيرة من ماء نظيف أو محلول لإعادة الترطيب بكميات متكررة بدلاً من شرب كميات كبيرة دفعة واحدة.',
                    'استرح في مكان بارد وتجنب المجهود الشديد حتى تتحسن حالتك.',
                ],
            ],
            'seek_care' => [
                'en' => 'Seek urgent medical care if there is no urination for many hours, repeated vomiting, dizziness on standing, or confusion — and always for dehydration in a young child, an older adult, or a pregnant person.',
                'ar' => 'اطلب رعاية عاجلة إذا لم يكن هناك تبول لساعات كثيرة، أو استمر القئ، أو دوخة عند الوقوف، أو ارتباك — ودائماً في حالة الجفاف لدى الأطفال الصغار أو كبار السن أو الحوامل.',
            ],
            'sources' => [
                'World Health Organization — Oral rehydration salts / dehydration care',
                'NHS inform — Dehydration',
                'Mayo Clinic — Dehydration: first aid',
            ],
        ],

        'mild_fever' => [
            'keywords' => [
                'a fever', 'fever since', 'fever for', 'feverish', 'high temperature',
                'temperature is high', 'درجة حرارة عالية', 'حرارة مرتفعة', 'حمى', 'عندي حرارة',
            ],
            'guidance' => [
                'en' => [
                    'Rest and drink plenty of fluids in small, frequent amounts.',
                    'Keep clothing light and the room comfortably cool; a lukewarm (not cold) compress can help if you feel very uncomfortable.',
                ],
                'ar' => [
                    'استرح واشرب سوائل بكميات صغيرة ومتكررة.',
                    'البس ملابس خفيفة وابقَ في غرفة مريحة البرودة؛ يمكن استخدام كمادة فاترة (وليست باردة جداً) عند الشعور بحرارة شديدة.',
                ],
            ],
            'seek_care' => [
                'en' => 'Seek prompt medical care for a very high temperature, a fever lasting more than 3 days, or fever with a stiff neck, rash, confusion, breathing difficulty or severe headache — and urgent care immediately for any infant under 3 months with a fever.',
                'ar' => 'اطلب رعاية طبية سريعة إذا كانت الحرارة مرتفعة جداً أو استمرت أكثر من 3 أيام، أو صاحبها تيبّس الرقبة أو طفح أو ارتباك أو صعوبة تنفس أو صداع شديد — واتصل بالطوارئ فوراً للرضيع أقل من 3 أشهر مع أي حرارة.',
            ],
            'sources' => [
                'NHS inform — Fever in adults',
                'Mayo Clinic — Fever: first aid',
                'World Health Organization — Fever care guidance',
            ],
        ],

        'mild_headache' => [
            'keywords' => [
                'my head hurts', 'head ache', 'a headache', 'headaches', 'صداع', 'صداع في راسي', 'أعاني من صداع',
            ],
            'guidance' => [
                'en' => [
                    'Rest in a quiet, dim room and drink water.',
                    'Avoid screens, bright light, loud noise and skipped meals until it eases.',
                ],
                'ar' => [
                    'استرح في غرفة هادئة ومظلمة قليلاً واشرب ماءً.',
                    'تجنّب الشاشات والضوء الساطع والضوضاء وتجاوز الوجبات حتى يهدأ الصداع.',
                ],
            ],
            'seek_care' => [
                'en' => 'Seek emergency care if the headache is sudden and the worst ever, follows a head injury, or comes with fever with a stiff neck, weakness or numbness, confusion, vision changes or a rash — these need immediate professional assessment.',
                'ar' => 'اطلب رعاية عاجلة إذا كان الصداع مفاجئاً وأشده من أي مرة سابقة، أو حدث بعد إصابة في الرأس، أو صاحبه حرارة مع تيبّس الرقبة أو تنميل أو ضعف أو ارتباك أو تغير في الرؤية أو طفح — هذه حالات تحتاج تقييماً فورياً.',
            ],
            'sources' => [
                'NHS inform — Headache',
                'Mayo Clinic — Headache: when to seek care',
                'World Health Organization — Headache disorder guidance (basic triage)',
            ],
        ],

        'insect_bite_sting' => [
            'keywords' => [
                'insect bite', 'bug bite', 'bee sting', 'wasp sting', 'stung by a', 'stung by an',
                'لدغة', 'لدغة حشرة', 'لبش نحلة', 'لسعة نحلة',
            ],
            'guidance' => [
                'en' => [
                    'Move away from the insect and wash the area with soap and clean water.',
                    'Apply a cool wrapped compress for about 15 to 20 minutes to reduce swelling.',
                    'Do not scratch or apply home substances; if a stinger remains, gently scrape it out with a flat edge.',
                ],
                'ar' => [
                    'ابتعد عن الحشرة واغسل المكان بالصابون والماء النظيف.',
                    'ضع كمادة باردة ملفوفة لمدة حوالي 15 إلى 20 دقيقة لتقليل التورم.',
                    'لا تفرك أو تضع مواد منزلية؛ إذا بقيت الإبرة انزعها بحافة مسطحة برفق.',
                ],
            ],
            'seek_care' => [
                'en' => 'Call emergency services immediately for breathing or swallowing difficulty, swelling of the face, lips or throat, widespread hives, dizziness or collapse after a sting — these can be a severe allergic reaction. Seek medical care for many stings, a sting inside the mouth, or increasing redness, warmth or pus (possible infection).',
                'ar' => 'اتصل بالإسعاف فوراً عند صعوبة التنفس أو البلع أو تورم الوجه أو الشفاه أو الحلق أو انتشار الحكة أو دوخة أو إغماء بعد اللدغة — فقد تكون حساسية شديدة. اطلب رعاية طبية عند لدغات متعددة أو لدغة داخل الفم أو احمرار متزايد ودفء أو صديد (احتمال عدوى).',
            ],
            'sources' => [
                'American Red Cross — First Aid manual (bites and stings)',
                'NHS inform — Insect bites and stings',
                'Mayo Clinic — Bee sting: first aid',
            ],
        ],
    ];

    /**
     * Matched entry (with its category key) for this message, or null when
     * the situation is not covered — in which case callers must NOT
     * improvise guidance (the prompt block tells the model to say so).
     *
     * @return array{category: string, keywords: list<string>, guidance: array{en: list<string>, ar: list<string>}, seek_care: array{en: string, ar: string}, sources: list<string>}|null
     */
    public function match(string $message): ?array
    {
        $text = $this->normalize($message);

        foreach (self::ENTRIES as $category => $entry) {
            foreach ($entry['keywords'] as $keyword) {
                if ($this->contains($text, $keyword)) {
                    return array_merge(['category' => $category], $entry);
                }
            }
        }

        return null;
    }

    /**
     * The full KB (for audits and tests).
     *
     * @return array<string, array{keywords: list<string>, guidance: array{en: list<string>, ar: list<string>}, seek_care: array{en: string, ar: string}, sources: list<string>}>
     */
    public function entries(): array
    {
        return self::ENTRIES;
    }

    /**
     * Prompt block injected when (and only when) the message matches a KB
     * category. Empty string otherwise, so FAQ traffic stays lean.
     */
    public function promptBlock(string $message, string $language = 'en'): string
    {
        $entry = $this->match($message);

        if ($entry === null) {
            return '';
        }

        $language = $language === 'ar' ? 'ar' : 'en';
        $guidance = implode("\n", array_map(
            fn (string $line): string => '- '.$line,
            $entry['guidance'][$language]
        ));
        $sources = implode('; ', $entry['sources']);

        if ($language === 'ar') {
            return <<<TEXT
REVIEWED FIRST-AID KNOWLEDGE BASE (situation detected: {$entry['category']}; reviewed {$this->reviewDate()}):
{$guidance}
- حدود الإرشاد: {$entry['seek_care']['ar']}
إذا سأل المستخدم عن ماذا يفعل، يمكنك نقل الإجراءات المذكورة أعلاه فقط باختصار. لا تُضف أي دواء أو جرعة أو علاج منزلي آخر مهما كان.
إذا لم تكن الحالة واضحة أو لم تُغطَّ هنا، قل بصدق أن الإرشاد الموثوق غير متوفر ونصح بتقييم طبي مهني بدلاً من التخمين. هذا الإرشاد لا يغني عن الرعاية الطبية.
المصادر التي تمت مراجعتها: {$sources}
TEXT;
        }

        return <<<TEXT
REVIEWED FIRST-AID KNOWLEDGE BASE (situation detected: {$entry['category']}; reviewed {$this->reviewDate()}):
{$guidance}
- Limits: {$entry['seek_care']['en']}
If the user asks what to do, you may relay ONLY the measures above, briefly. Do NOT add any medication, dosage, or additional home remedy.
If the situation is unclear or not covered here, honestly say reliable guidance is unavailable and recommend appropriate professional evaluation instead of improvising. This guidance never replaces professional care.
Sources reviewed: {$sources}
TEXT;
    }

    /** Review date of the KB (single source of truth). */
    public function reviewDate(): string
    {
        return self::REVIEWED_AT;
    }

    protected function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        $text = (string) preg_replace('/[\x{064B}-\x{0652}\x{0640}]/u', '', $text);

        return strtr($text, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ؤ' => 'و', 'ئ' => 'ي']);
    }

    protected function contains(string $normalizedText, string $keyword): bool
    {
        $keyword = $this->normalize($keyword);

        if ($keyword === '') {
            return false;
        }

        if (preg_match('/^[\x00-\x7F]+$/u', $keyword)) {
            return (bool) preg_match('/\b'.preg_quote($keyword, '/').'/u', $normalizedText);
        }

        return str_contains($normalizedText, $keyword);
    }
}
