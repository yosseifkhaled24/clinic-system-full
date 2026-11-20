<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\ContactMessage;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\Patient;
use App\Models\Review;
use App\Models\Service;
use App\Models\Specialty;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Production-safe, showcase-oriented demo data for the clinic system.
 *
 * SAFETY CONTRACT (why this seeder may run against a deployed database)
 * ---------------------------------------------------------------------
 *  - NO Faker / require-dev packages: every value below is a predefined
 *    constant. The only non-precomputed values are the one-time random
 *    32-character passwords of the demo patient accounts (deliberately
 *    unusable — requirement: no hardcoded login credentials) and the
 *    auto-increment/foreign keys of rows this seeder itself creates.
 *  - NO admin account, ever: demo users are created with role 'user'
 *    (mirroring AuthController::register) and an unknown random password,
 *    so nobody can sign in as them.
 *  - NEVER touches real people: appointments are created ONLY for the 12
 *    patients identified by the demo.clinic.patient.*@example.test
 *    addresses defined here. Existing patients, doctors, services,
 *    appointments, reviews, users and messages are only REUSED through
 *    firstOrCreate/findOrCreate — never updated, overwritten or deleted.
 *  - NO destructive operations: no truncate/delete/drop/migrate:fresh.
 *    All writes happen inside one DB::transaction, so a failure rolls
 *    everything back and leaves the database exactly as it was.
 *  - Idempotent: re-running creates zero duplicates (see keys below).
 *    - specialties  -> Specialty::findOrCreateByName() (case-insensitive)
 *    - users        -> firstOrCreate on e-mail (demo.clinic.patient.N@…)
 *    - doctors      -> firstOrCreate on e-mail (demo.clinic.doctor.N@…)
 *    - services     -> firstOrCreate on name
 *    - schedules    -> firstOrCreate on (doctor_id, day)  [DB unique index]
 *    - appointments -> firstOrCreate on (patient_id, doctor_id, date);
 *                      queue_number is computed as max+1 over ALL
 *                      statuses for that doctor/day (same rule the
 *                      booking controller uses), so a real booking that
 *                      already occupies a demo slot can never collide
 *                      with the unique (doctor, date, queue) index.
 *    - reviews      -> firstOrCreate on appointment_id   [DB unique index]
 *    - messages     -> firstOrCreate on e-mail (demo.clinic.contact.N@…)
 *  - Dates are anchored: on the first run the anchor is "today"; later
 *    runs derive the same anchor from the earliest existing demo
 *    appointment, so every offset resolves to the identical calendar
 *    date and firstOrCreate matches instead of duplicating.
 *
 * REVIEW ELIGIBILITY mirrors ReviewController::store exactly: a review
 * is only written for a COMPLETED + PAID appointment whose doctor allows
 * reviews, one review per appointment.
 *
 * IMAGES: attached best-effort AFTER the transaction commits, only to
 * demo doctors that have no image yet, reusing the existing seed-images
 * (originals preserved, Spatie stores each copy under its own media id,
 * so nothing existing is overwritten). A media failure never fails the
 * seeder and never rolls back the committed demo data.
 *
 * Run with:  php artisan db:seed --class=DemoSeeder
 * (add --force when APP_ENV=production).
 */
class DemoSeeder extends Seeder
{
    /**
     * The 12 fictional demo patients, in a stable order.
     * Index order defines: e-mail demo.clinic.patient.N@example.test,
     * phone 01000000001…01000000012 (all-zero middle digits — clearly
     * fictional and structurally indistinguishable from nothing real).
     *
     * @var list<array{name: string, gender: string}>
     */
    private const PATIENTS = [
        ['name' => 'فاطمة أحمد سيد', 'gender' => 'female'],
        ['name' => 'محمد حسن عبد الله', 'gender' => 'male'],
        ['name' => 'نور الدين سالم إبراهيم', 'gender' => 'male'],
        ['name' => 'ياسمين عادل مصطفى', 'gender' => 'female'],
        ['name' => 'حسن مصطفى الشناوي', 'gender' => 'male'],
        ['name' => 'دينا شعبان الديب', 'gender' => 'female'],
        ['name' => 'أيمن قطب الدين', 'gender' => 'male'],
        ['name' => 'سلمى الطاهر منصور', 'gender' => 'female'],
        ['name' => 'طارق زكي عبد الرحمن', 'gender' => 'male'],
        ['name' => 'مريم عصام الحلواني', 'gender' => 'female'],
        ['name' => 'خالد منصور الجندي', 'gender' => 'male'],
        ['name' => 'هبة رأفت السيد', 'gender' => 'female'],
    ];

    /**
     * The 6 demo doctors, ordered to match SpecialtySeeder::NAMES
     * (Cardiology, Dermatology, Dentistry, Pediatrics, Orthopedics,
     * Neurology) so each doctor links to its specialty by index.
     *
     * @var list<array{name: string, bio: string}>
     */
    private const DOCTORS = [
        [
            'name' => 'د. أحمد الشريف',
            'bio' => 'استشاري أمراض القلب والشرايين، خبرة أكثر من ١٥ عاماً في التشخيص والعلاج التداخل.',
        ],
        [
            'name' => 'د. منى عبدالرحمن',
            'bio' => 'اختصاصية أمراض الجلدية والتجميل الطبي، تقدم علاجات البشرة والشعر بأحدث الوسائل.',
        ],
        [
            'name' => 'د. خالد الحسن',
            'bio' => 'طبيب أسنان متخصص في تجميل وتركيبات الأسنان وعلاج الجذور.',
        ],
        [
            'name' => 'د. سارة محمود',
            'bio' => 'اختصاصية طب الأطفال وحديثي الولادة، متابعة النمو واللقاحات.',
        ],
        [
            'name' => 'د. عمر الفقي',
            'bio' => 'استشاري العظام والمفاصل وإصابات الملاعب، وعلاج آلام الظهر المزمنة.',
        ],
        [
            'name' => 'د. ليلى سليم',
            'bio' => 'اختصاصية الأمراض العصبية، تشخيص وعلاج صداع النوم وأمراض الأعصاب.',
        ],
    ];

    /**
     * 8 demo services with realistic EGP prices.
     * Keys match Service::$fillable; firstOrCreate is keyed on name, so a
     * real service that already carries the same name is reused untouched.
     *
     * @var list<array{name: string, description: string, price: float}>
     */
    private const SERVICES = [
        ['name' => 'كشف عام', 'description' => 'فحص شامل وتشخيص الحالة الصحية العامة.', 'price' => 500.00],
        ['name' => 'استشارة قلب', 'description' => 'تقييم وتشخيص أمراض القلب والشرايين.', 'price' => 800.00],
        ['name' => 'تحليل دم شامل', 'description' => 'مجموعة تحاليل شاملة لفحص الحالة الصحية.', 'price' => 350.00],
        ['name' => 'أشعة سينية', 'description' => 'تصوير تشخيصي بالأشعة السينية للكشف على الكسور والالتهابات.', 'price' => 450.00],
        ['name' => 'جلسة علاج طبيعي', 'description' => 'جلسات إعادة التأهيل والعلاج الطبيعي للعمود الفقري والمفاصل.', 'price' => 600.00],
        ['name' => 'تنظيف وتجميل أسنان', 'description' => 'تنظيف الأسنان وإزالة الجير وتلميعها.', 'price' => 700.00],
        ['name' => 'متابعة أمراض الأطفال', 'description' => 'كشف ومتابعة نمو الأطفال ومواعيد لقاحاتهم.', 'price' => 400.00],
        ['name' => 'قياس ضغط وسكر', 'description' => 'قياس ضغط الدم وسكر الدم مع استشارة مختصرة.', 'price' => 250.00],
    ];

    /**
     * 5 fictional contact messages. E-mails are
     * demo.clinic.contact.N@example.test (the .test TLD is reserved by
     * RFC 2606 and can never receive mail).
     *
     * @var list<array{name: string, subject: string, message: string}>
     */
    private const CONTACT_MESSAGES = [
        [
            'name' => 'أحمد السيد',
            'subject' => 'استفسار عن مواعيد العيادة',
            'message' => 'السلام عليكم، أرغب في معرفة مواعيد عمل العيادة في نهاية الأسبوع، وهل يلزم حجز موعد مسبق للكشف العام؟',
        ],
        [
            'name' => 'منى عبدالكريم',
            'subject' => 'حجز موعد لاستشارة قلب',
            'message' => 'أرغب في حجز موعد لوالدي مع أستاذ القلب، لو سمحتوا أرسلوا لي أقرب المواعيد المتاحة.',
        ],
        [
            'name' => 'سامي فؤاد',
            'subject' => 'سؤال عن أسعار التحاليل',
            'message' => 'هل أسعار التحاليل المنشورة شاملة رسوم أخذ العينة وتسليم النتياج؟ وفي أي أيام تُجرى التحاليل؟',
        ],
        [
            'name' => 'هدى نبيل',
            'subject' => 'طلب نسخة من التقارير الطبية',
            'message' => 'أحتاج نسخة من تقرير الزيارة السابقة، كيف يمكنني استلامها من العيادة؟',
        ],
        [
            'name' => 'كريم مراد',
            'subject' => 'اقتراح تحسين الخدمة',
            'message' => 'أقترح إضافة خدمة تذكير المواعيد إلكترونياً برسالة نصية قبل الزيارة بيوم، شكراً لكم.',
        ],
    ];

    /** Working days the clinic schedules (Friday is off — mirrors DatabaseSeeder). */
    private const DAYS = ['Saturday', 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday'];

    private const SCHEDULE_STARTS = ['08:00:00', '09:00:00', '10:00:00'];
    private const SCHEDULE_ENDS = ['16:00:00', '17:00:00', '18:00:00'];

    /** Per-doctor daily capacity (25–40), deterministic by doctor index. */
    private const MAX_PATIENTS = [25, 30, 35, 40, 30, 25];

    private const PAID_METHODS = ['cash', 'visa', 'insurance'];

    private const REVIEW_RATINGS = [5, 4, 5, 3, 4, 5, 4, 5];

    private const REVIEW_COMMENTS = [
        'تجربة ممتازة، الطبيب شرح الحالة بوضوح تام.',
        'انتظار قليل والخدمة ممتازة من أول خطوة.',
        'الطبيب ناصح والمتابعة بعد الزيارة كانت ممتازة.',
        'العيادة نظيفة والموظفون ودودون جداً.',
        'شرح مفصل للتشخيص والعلاج، أنصح بزيارته.',
        'موعد دقيق والخدمة جدارة بكل احترام.',
        'تم الرد على كل استفساراتي بالكامل.',
        'تجربة جيدة وسهلة الحجز عبر النظام.',
    ];

    /** Cycled appointment notes; index 3 yields null (some rows without notes). */
    private const APPOINTMENT_NOTES = [
        'موعد متابعة دوري.',
        'الشكاوى: ألم بسيط يتحسن تدريجياً.',
        'يحتاج تحاليل إضافية قبل الزيارة القادمة.',
        null,
        'تمت مناقشة الطبيب خطة العلاج.',
    ];

    /**
     * 25 demo appointments (within the required 20–30).
     *
     * 'offset'   = days relative to the run's anchor date.
     * 'settled'  = paid (only ever true for completed visits; every
     *              cancelled/no-show/upcoming visit stays unpaid, which
     *              keeps the data consistent with the cancellation and
     *              payment guards in AppointmentController).
     *
     * Exactly 8 rows are completed + settled → exactly 8 eligible reviews.
     * One completed row is deliberately UNPAID (offset -3): it is the
     * "completed but never collected" case the end-of-day cleanup flags,
     * and it demonstrates the paid requirement of review eligibility.
     *
     * Every (doctor, date) pair below is unique, no patient has two
     * appointments on the same date, and all offsets are distinct per
     * doctor — so queue numbers, the unique (doctor, date, queue) index
     * and the "no double active booking per patient/doctor/date" rule
     * all hold by construction.
     *
     * @var list<array{doctor: int, patient: int, offset: int, status: string, settled: bool}>
     */
    private const APPOINTMENTS = [
        // Completed + paid (review-eligible)
        ['doctor' => 0, 'patient' => 0, 'offset' => -40, 'status' => 'completed', 'settled' => true],
        ['doctor' => 1, 'patient' => 1, 'offset' => -35, 'status' => 'completed', 'settled' => true],
        ['doctor' => 2, 'patient' => 2, 'offset' => -30, 'status' => 'completed', 'settled' => true],
        ['doctor' => 3, 'patient' => 3, 'offset' => -25, 'status' => 'completed', 'settled' => true],
        ['doctor' => 4, 'patient' => 4, 'offset' => -20, 'status' => 'completed', 'settled' => true],
        ['doctor' => 5, 'patient' => 5, 'offset' => -14, 'status' => 'completed', 'settled' => true],
        ['doctor' => 0, 'patient' => 6, 'offset' => -10, 'status' => 'completed', 'settled' => true],
        ['doctor' => 1, 'patient' => 7, 'offset' => -7, 'status' => 'completed', 'settled' => true],
        // Completed but UNPAID — ineligible for a review (money never collected)
        ['doctor' => 2, 'patient' => 0, 'offset' => -3, 'status' => 'completed', 'settled' => false],
        // Cancelled / no-show — always unpaid per the cancellation guards
        ['doctor' => 2, 'patient' => 8, 'offset' => -33, 'status' => 'cancelled', 'settled' => false],
        ['doctor' => 3, 'patient' => 9, 'offset' => -18, 'status' => 'cancelled', 'settled' => false],
        ['doctor' => 4, 'patient' => 10, 'offset' => -5, 'status' => 'cancelled', 'settled' => false],
        ['doctor' => 5, 'patient' => 11, 'offset' => -28, 'status' => 'no_show', 'settled' => false],
        ['doctor' => 0, 'patient' => 1, 'offset' => -12, 'status' => 'no_show', 'settled' => false],
        // In the clinic right now (anchor day)
        ['doctor' => 0, 'patient' => 2, 'offset' => 0, 'status' => 'arrived', 'settled' => false],
        ['doctor' => 1, 'patient' => 3, 'offset' => 0, 'status' => 'arrived', 'settled' => false],
        ['doctor' => 2, 'patient' => 4, 'offset' => 0, 'status' => 'arrived', 'settled' => false],
        ['doctor' => 3, 'patient' => 5, 'offset' => 0, 'status' => 'in_progress', 'settled' => false],
        ['doctor' => 4, 'patient' => 6, 'offset' => 0, 'status' => 'in_progress', 'settled' => false],
        // Upcoming bookings (unpaid until the visit is settled)
        ['doctor' => 5, 'patient' => 7, 'offset' => 1, 'status' => 'pending', 'settled' => false],
        ['doctor' => 0, 'patient' => 8, 'offset' => 2, 'status' => 'pending', 'settled' => false],
        ['doctor' => 1, 'patient' => 9, 'offset' => 3, 'status' => 'pending', 'settled' => false],
        ['doctor' => 2, 'patient' => 10, 'offset' => 5, 'status' => 'pending', 'settled' => false],
        ['doctor' => 3, 'patient' => 11, 'offset' => 7, 'status' => 'pending', 'settled' => false],
        ['doctor' => 4, 'patient' => 0, 'offset' => 14, 'status' => 'pending', 'settled' => false],
    ];

    /** Fixed demo addresses, cycled by patient index. */
    private const ADDRESSES = [
        '١٥ شارع التحرير، وسط البلد، القاهرة',
        '٧ شارع النيل، المعادي، القاهرة',
        '٢٢ شارع الجمهورية، الإسكندرية',
        '٤ شارع الهرم، الجيزة',
        '١٠ شارع مكرم عبيد، مدينة نصر، القاهرة',
        '٣ شارع البحر، طنطا، الغربية',
    ];

    /** Marker proving the row is fictional demo data, not a real contact. */
    private const DEMO_NOTE = 'بيانات تجريبية وهمية أُنشئت لغرض العرض فقط — رقم هاتف غير حقيقي.';

    public function run(): void
    {
        $created = [
            'specialties' => 0,
            'services' => 0,
            'users' => 0,
            'patients' => 0,
            'doctors' => 0,
            'schedules' => 0,
            'appointments' => 0,
            'reviews' => 0,
            'contact_messages' => 0,
        ];

        /** @var list<Doctor> $demoDoctors */
        $demoDoctors = DB::transaction(function () use (&$created) {
            // ---------------------------------------------------------
            // 1) Specialties — the clinic's canonical 6 (reused if present)
            // ---------------------------------------------------------
            $specialties = [];

            foreach (SpecialtySeeder::NAMES as $name) {
                $existed = Specialty::query()
                    ->whereRaw('LOWER(name) = LOWER(?)', [Specialty::normalizeName($name)])
                    ->exists();

                $specialties[] = Specialty::findOrCreateByName($name);

                if (! $existed) {
                    $created['specialties']++;
                }
            }

            // ---------------------------------------------------------
            // 2) Services — 8 realistic EGP-priced demo services
            // ---------------------------------------------------------
            $services = [];

            foreach (self::SERVICES as $spec) {
                $service = Service::firstOrCreate(['name' => $spec['name']], $spec);

                if ($service->wasRecentlyCreated) {
                    $created['services']++;
                }

                $services[] = $service;
            }

            // ---------------------------------------------------------
            // 3) Users — one account per demo patient.
            //    Random, never-printed, unusable password (the model's
            //    `hashed` cast hashes it once); role pinned to 'user'
            //    exactly like AuthController::register — NEVER admin.
            // ---------------------------------------------------------
            $users = [];

            foreach (self::PATIENTS as $index => $patient) {
                $user = User::firstOrCreate(
                    ['email' => $this->patientEmail($index)],
                    [
                        'name' => $patient['name'],
                        'phone' => $this->patientPhone($index),
                        'password' => Str::random(32),
                    ]
                );

                if ($user->wasRecentlyCreated) {
                    // Not mass assignable (SEC-10) — direct writes, create only.
                    $user->email_verified_at = now();
                    $user->role = 'user';
                    $user->save();

                    $created['users']++;
                }

                $users[] = $user;
            }

            // ---------------------------------------------------------
            // 4) Patients — linked ONLY to the demo users above
            // ---------------------------------------------------------
            $patients = [];

            foreach (self::PATIENTS as $index => $patient) {
                $record = Patient::firstOrCreate(
                    ['email' => $this->patientEmail($index)],
                    [
                        'user_id' => $users[$index]->id,
                        'name' => $patient['name'],
                        'phone' => $this->patientPhone($index),
                        'gender' => $patient['gender'],
                        'date_of_birth' => sprintf(
                            '%04d-%02d-%02d',
                            1970 + (($index * 3) % 30),
                            ($index % 12) + 1,
                            ($index % 27) + 1
                        ),
                        'address' => self::ADDRESSES[$index % count(self::ADDRESSES)],
                        'notes' => self::DEMO_NOTE,
                    ]
                );

                if ($record->wasRecentlyCreated) {
                    $created['patients']++;
                }

                $patients[] = $record;
            }

            // ---------------------------------------------------------
            // 5) Doctors — linked by specialty_id; the Doctor::saving()
            //    hook mirrors the canonical name into the legacy string.
            // ---------------------------------------------------------
            $doctors = [];

            foreach (self::DOCTORS as $index => $doctor) {
                $record = Doctor::firstOrCreate(
                    ['email' => $this->doctorEmail($index)],
                    [
                        'name' => $doctor['name'],
                        'specialty_id' => $specialties[$index]->id,
                        'phone' => $this->doctorPhone($index),
                        'bio' => $doctor['bio'],
                        'allow_reviews' => true,
                    ]
                );

                if ($record->wasRecentlyCreated) {
                    $created['doctors']++;
                }

                $doctors[] = $record;
            }

            // ---------------------------------------------------------
            // 6) Schedules — 6 doctors × 6 working days (unique per
            //    (doctor_id, day), so firstOrCreate can never duplicate)
            // ---------------------------------------------------------
            foreach ($doctors as $doctorIndex => $doctor) {
                foreach (self::DAYS as $dayIndex => $day) {
                    $slot = ($doctorIndex + $dayIndex) % count(self::SCHEDULE_STARTS);

                    $schedule = DoctorSchedule::firstOrCreate(
                        [
                            'doctor_id' => $doctor->id,
                            'day' => $day,
                        ],
                        [
                            'start_time' => self::SCHEDULE_STARTS[$slot],
                            'end_time' => self::SCHEDULE_ENDS[$slot],
                            'is_available' => true,
                            'max_patients' => self::MAX_PATIENTS[$doctorIndex],
                        ]
                    );

                    if ($schedule->wasRecentlyCreated) {
                        $created['schedules']++;
                    }
                }
            }

            // ---------------------------------------------------------
            // 7) Appointments — ONLY for the 12 demo patients, anchored
            //    so re-runs resolve to the exact same calendar dates.
            // ---------------------------------------------------------
            $demoPatientIds = array_map(fn (Patient $p) => $p->id, $patients);
            $demoDoctorIds = array_map(fn (Doctor $d) => $d->id, $doctors);

            $earliestOffset = min(array_column(self::APPOINTMENTS, 'offset'));

            $existingMin = Appointment::query()
                ->whereIn('patient_id', $demoPatientIds)
                ->whereIn('doctor_id', $demoDoctorIds)
                ->min('appointment_date');

            $anchor = $existingMin !== null
                ? Carbon::parse($existingMin)->subDays($earliestOffset)->toDateString()
                : now()->toDateString();

            $appointments = [];

            foreach (self::APPOINTMENTS as $index => $spec) {
                $doctor = $doctors[$spec['doctor']];
                $patient = $patients[$spec['patient']];
                $date = Carbon::parse($anchor)->addDays($spec['offset'])->toDateString();

                $appointment = Appointment::firstOrCreate(
                    [
                        // Stable key: demo patient × demo doctor × date.
                        'patient_id' => $patient->id,
                        'doctor_id' => $doctor->id,
                        'appointment_date' => $date,
                    ],
                    [
                        'user_id' => $patient->user_id,
                        'service_id' => $services[$spec['doctor'] % count($services)]->id,
                        // max+1 over ALL statuses — same never-recycle rule
                        // the booking controller applies; demo doctors have
                        // no real bookings, so this starts at 1.
                        'queue_number' => Appointment::query()
                            ->where('doctor_id', $doctor->id)
                            ->whereDate('appointment_date', $date)
                            ->max('queue_number') + 1,
                        'service_price' => $services[$spec['doctor'] % count($services)]->price ?? 0,
                        'payment_status' => $spec['settled'] ? 'paid' : 'unpaid',
                        'payment_method' => $spec['settled']
                            ? self::PAID_METHODS[$index % count(self::PAID_METHODS)]
                            : null,
                        // Paid visits were settled on the (past) visit day.
                        'paid_at' => $spec['settled']
                            ? Carbon::parse($date)->setTime(13, 0)
                            : null,
                        'status' => $spec['status'],
                        'notes' => self::APPOINTMENT_NOTES[$index % count(self::APPOINTMENT_NOTES)],
                    ]
                );

                if ($appointment->wasRecentlyCreated) {
                    $created['appointments']++;
                }

                $appointments[$index] = $appointment;
            }

            // ---------------------------------------------------------
            // 8) Reviews — ONLY eligible completed + paid demo
            //    appointments, one per appointment (DB unique index).
            // ---------------------------------------------------------
            $reviewIndex = 0;

            foreach (self::APPOINTMENTS as $index => $spec) {
                if ($spec['status'] !== 'completed' || ! $spec['settled']) {
                    continue;
                }

                $appointment = $appointments[$index];
                $doctor = $doctors[$spec['doctor']];

                if (! $doctor->allow_reviews || $appointment->user_id === null) {
                    continue;
                }

                $review = Review::firstOrCreate(
                    ['appointment_id' => $appointment->id],
                    [
                        'user_id' => $appointment->user_id,
                        'doctor_id' => $appointment->doctor_id,
                        'rating' => self::REVIEW_RATINGS[$reviewIndex % count(self::REVIEW_RATINGS)],
                        'comment' => self::REVIEW_COMMENTS[$reviewIndex % count(self::REVIEW_COMMENTS)],
                    ]
                );

                if ($review->wasRecentlyCreated) {
                    $created['reviews']++;
                }

                $reviewIndex++;
            }

            // ---------------------------------------------------------
            // 9) Contact messages — 5 fictional Arabic messages
            // ---------------------------------------------------------
            foreach (self::CONTACT_MESSAGES as $index => $message) {
                $contact = ContactMessage::firstOrCreate(
                    ['email' => sprintf('demo.clinic.contact.%d@example.test', $index + 1)],
                    $message
                );

                if ($contact->wasRecentlyCreated) {
                    $created['contact_messages']++;
                }
            }

            return $doctors;
        });

        // ---------------------------------------------------------
        // 10) Images — best-effort, AFTER commit: only demo doctors with
        //     no image yet, originals preserved, each Spatie copy lands
        //     under its own media id (never overwrites existing files).
        //     A failure warns and never fails the seeder.
        // ---------------------------------------------------------
        $imagesAttached = $this->attachDoctorImages($demoDoctors);

        $this->command?->info(sprintf(
            'DemoSeeder done — new rows: %d specialties, %d services, %d users, %d patients, %d doctors, %d schedules, %d appointments, %d reviews, %d contact messages, %d doctor images. Existing rows were reused unchanged (nothing updated or deleted).',
            $created['specialties'],
            $created['services'],
            $created['users'],
            $created['patients'],
            $created['doctors'],
            $created['schedules'],
            $created['appointments'],
            $created['reviews'],
            $created['contact_messages'],
            $imagesAttached
        ));
    }

    /**
     * Attach one of the existing seed images to each demo doctor that
     * does not have one yet. Deterministic (stable image order, index
     * round-robin), idempotent (skips doctors already in the collection)
     * and non-destructive (preservingOriginal, no deletes, no overwrites).
     */
    private function attachDoctorImages(array $doctors): int
    {
        $attached = 0;

        try {
            $images = File::files(database_path('seed-images/doctors'));

            if ($images === []) {
                return 0;
            }

            usort($images, fn ($a, $b) => strcmp($a->getFilename(), $b->getFilename()));

            $count = count($images);

            foreach (array_values($doctors) as $index => $doctor) {
                $hasImage = $doctor->media()
                    ->where('collection_name', 'doctors')
                    ->exists();

                if ($hasImage) {
                    continue;
                }

                $doctor
                    ->addMedia($images[$index % $count]->getPathname())
                    ->preservingOriginal()
                    ->toMediaCollection('doctors');

                $attached++;
            }
        } catch (\Throwable $e) {
            $this->command?->warn(
                'DemoSeeder: doctor images skipped (' . $e->getMessage() . '). Core demo data is already committed.'
            );
        }

        return $attached;
    }

    private function patientEmail(int $index): string
    {
        return sprintf('demo.clinic.patient.%d@example.test', $index + 1);
    }

    private function doctorEmail(int $index): string
    {
        return sprintf('demo.clinic.doctor.%d@example.test', $index + 1);
    }

    /**
     * Deliberately fictional: 010/011 + six zeros + serial. The all-zero
     * middle block makes these unmistakably fake demo numbers that cannot
     * be mistaken for real contact information.
     */
    private function patientPhone(int $index): string
    {
        return '010' . '000000' . str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
    }

    private function doctorPhone(int $index): string
    {
        return '011' . '000000' . str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
    }
}
