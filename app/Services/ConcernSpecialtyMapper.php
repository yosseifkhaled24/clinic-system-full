<?php

namespace App\Services;

/**
 * Maps a visitor's stated concern (symptom-ish keywords, EN + AR) to the
 * specialties the clinic ACTUALLY has in its database, so doctor
 * recommendations stay grounded in real data.
 *
 * Safety properties:
 *  - Pure keyword orientation, never a diagnosis: the caller must always
 *    frame the result to Gemini as "general orientation, not diagnosis".
 *  - The result is intersected with `ClinicContextService::specialties()`
 *    (real `doctors.specialty` values), so a specialty the clinic does
 *    not offer can NEVER appear in the prompt.
 *  - If nothing matches, callers must tell the user the clinic does not
 *    list a matching specialty — never invent one.
 *
 * Future compatibility: when specialties become their own table, the
 * caller still just passes the authoritative specialty list into
 * match(); the keyword map stays unchanged.
 */
class ConcernSpecialtyMapper
{
    /**
     * Category keyword => fragments that recognise the corresponding
     * specialty name in the clinic's data.
     *
     * Keywords match on normalized text: ASCII with word boundaries,
     * Arabic as substrings.
     *
     * @var array<string, array{keywords: list<string>, fragments: list<string>}>
     */
    protected const CATEGORIES = [
        'cardiology' => [
            'keywords' => ['chest pain', 'chest tightness', 'palpitation', 'blood pressure', 'cardiologist',
                'ألم في الصدر', 'خفقان', 'ضغط الدم', 'قلبي', 'أمراض القلب'],
            'fragments' => ['cardiolog', 'heart', 'قلب'],
        ],
        'dermatology' => [
            'keywords' => ['skin rash', 'rash', 'itchy', 'itching', 'acne', 'eczema', 'skin problem', 'skin tag',
                'حكة', 'طفح', 'حبوب', 'الجلد', 'مشاكل الجلد'],
            'fragments' => ['dermatolog', 'skin', 'جلد'],
        ],
        'dentistry' => [
            'keywords' => ['toothache', 'tooth pain', 'sore tooth', 'tooth', 'teeth', 'dentist', 'gum', 'molar', 'bleeding gums',
                'سنّ', 'سنان', 'أسنان', 'اسنان', 'لثة', 'ضرس', 'أضراس', 'اعراض الاسنان'],
            'fragments' => ['dent', 'سنان', 'اسنان', 'أسنان'],
        ],
        'pediatrics' => [
            'keywords' => ['my child', 'my kid', 'my baby', 'my infant', 'toddler', 'children', 'child fever', 'pediatric',
                'طفلي', 'طفل', 'رضيع', 'أطفال', 'ابني', 'ابنتي'],
            'fragments' => ['pediatr', 'child', 'اطفال', 'أطفال'],
        ],
        'orthopedics' => [
            'keywords' => ['joint pain', 'back pain', 'knee pain', 'bone pain', 'fracture', 'arthritis', 'sprain', 'orthopedic',
                'ألم الظهر', 'ألم الركبة', 'ألم المفصل', 'كسر', 'روماتيزم', 'العضلات', 'مفصل'],
            'fragments' => ['orthop', 'bone', 'عظم'],
        ],
        'neurology' => [
            'keywords' => ['migraine', 'headache', 'dizziness', 'vertigo', 'numbness', 'tingling', 'nerve', 'memory loss',
                'صداع', 'دوخة', 'دوار', 'تنميل', 'خدر', 'عصي'],
            'fragments' => ['neurolog', 'عصب'],
        ],
        'ent' => [
            'keywords' => ['sore throat', 'ear pain', 'earache', 'runny nose', 'nasal', 'sinus', 'tinnitus', 'hearing loss', 'tonsil',
                'ألم الأذن', 'ألم الاذن', 'ألم الحلق', 'احتقان', 'أنف', 'اذن', 'أذين'],
            'fragments' => ['ent', 'ear', 'nose', 'أنف', 'اذن', 'أذن'],
        ],
        'ophthalmology' => [
            'keywords' => ['eye pain', 'blurred vision', 'vision', 'eyesight', 'red eye', 'ophthalmolog',
                'ألم العين', 'ضعف النظر', 'الرؤية', 'النظر'],
            'fragments' => ['ophthalm', 'eye', 'عين'],
        ],
        'obgyn' => [
            'keywords' => ['pregnan', 'menstrual', 'period pain', 'gynecolog', 'obstetric', 'miscarriage',
                'حمل', 'ولادة', 'دورة شهرية', 'نسا'],
            'fragments' => ['gyn', 'obst', 'نسا'],
        ],
        'gastroenterology' => [
            'keywords' => ['stomach pain', 'stomach hurts', 'my stomach hurts', 'stomach ache', 'stomach cramps',
                'abdominal pain', 'diarrhea', 'vomiting', 'nausea', 'heartburn', 'constipation', 'gastric',
                'ألم المعدة', 'ألم البطن', 'معدتي توجع', 'إسهال', 'اسهال', 'قئ', 'قيء', 'غثيان', 'معدة'],
            'fragments' => ['gastro', 'digest', 'معده', 'معدة', 'هضم'],
        ],
        'urology' => [
            'keywords' => ['urin', 'bladder', 'kidney stone', 'painful urination',
                'تبول', 'مسالك', 'كلى', 'البول'],
            'fragments' => ['urolog', 'kidney', 'مسالك'],
        ],
        'pulmonology' => [
            'keywords' => ['cough', 'asthma', 'wheezing', 'chest congestion', 'breathless',
                'سعال', 'ربو', 'أزيز', 'اخنقة'],
            'fragments' => ['pulmon', 'lung', 'صدر'],
        ],
        'general_medicine' => [
            // Includes generic "I don't feel well" phrasing (EN + AR) so any
            // health concern activates the safety/triage prompt — broad by
            // design, never symptom-specific.
            'keywords' => ['fever', 'checkup', 'check up', 'fatigue', 'general practitioner',
                'i feel sick', 'i dont feel well', "i don't feel well", 'not feeling well',
                'i feel unwell', 'feeling ill', 'i have symptoms', 'my symptoms',
                'something is wrong with me', 'sore all over',
                'حمى', 'فحص عام', 'تعب', 'إرهاق', 'ارهاق', 'عندي أعراض', 'أشعر بتوعك',
                'أنا تعبان', 'انا تعبان', 'مش حاسس حالي', 'لست بخير'],
            'fragments' => ['general', 'family', 'باطنه', 'باطنة', 'عام'],
        ],
        'allergy' => [
            'keywords' => ['allergy', 'allergic', 'hives', 'hay fever', 'sneezing',
                'swollen from an allergy', 'حساسيه', 'حساسية', 'نحاسة', 'عطس متكرر'],
            'fragments' => ['allerg', 'immunolog'],
        ],
        'psychiatry' => [
            'keywords' => ['anxiety', 'depression', 'insomnia', 'panic', 'stress', 'sad all the time',
                'قلق', 'اكتئاب', 'توتر', 'أرق', 'صداع نفسي'],
            'fragments' => ['psychiatr', 'psycholog', 'نفسي'],
        ],
    ];

    /**
     * The categories the user's concern may point to (diagnostic-free
     * keyword orientation only).
     *
     * @return list<string>
     */
    public function categories(string $message): array
    {
        $text = $this->normalize($message);
        $matched = [];

        foreach (self::CATEGORIES as $category => $definition) {
            foreach ($definition['keywords'] as $keyword) {
                if ($this->contains($text, $keyword)) {
                    $matched[] = $category;
                    break;
                }
            }
        }

        return array_values(array_unique($matched));
    }

    public function mentionsHealthConcern(string $message): bool
    {
        return $this->categories($message) !== [];
    }

    /**
     * Intersects concern categories with the clinic's REAL specialty
     * list (exact strings from the database) — capped and deduplicated.
     *
     * General-exclusion rule: when the concern matches ANY specific
     * category (cardiology, dermatology, ...), the catch-all
     * "general_medicine" category is ignored, so the user gets pointed at
     * the most relevant specialty instead of a vague general suggestion.
     * General medicine is only used when nothing more specific matched.
     *
     * @param  list<string>  $clinicSpecialties
     * @return list<string>
     */
    public function match(array $clinicSpecialties, string $message): array
    {
        $text = $this->normalize($message);
        $matchedCategories = [];

        foreach (self::CATEGORIES as $category => $definition) {
            foreach ($definition['keywords'] as $keyword) {
                if ($this->contains($text, $keyword)) {
                    $matchedCategories[$category] = $definition;
                    break;
                }
            }
        }

        if ($matchedCategories === [] || $clinicSpecialties === []) {
            return [];
        }

        // Specific categories win; general medicine only when nothing else matched.
        $specific = array_diff_key($matchedCategories, ['general_medicine' => true]);
        $candidates = $specific !== [] ? array_values($specific) : array_values($matchedCategories);

        $matched = [];

        foreach ($clinicSpecialties as $specialty) {
            if (! is_string($specialty) || trim($specialty) === '') {
                continue;
            }

            $normalizedSpecialty = $this->normalize($specialty);

            foreach ($candidates as $definition) {
                foreach ($definition['fragments'] as $fragment) {
                    if ($this->contains($normalizedSpecialty, $fragment)) {
                        $matched[] = trim($specialty);
                        continue 3;
                    }
                }
            }
        }

        return array_slice(array_values(array_unique($matched)), 0, 3);
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
