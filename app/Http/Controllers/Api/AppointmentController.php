<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreAppointmentRequest;
use App\Http\Requests\Api\UpdateAppointmentRequest;
use App\Mail\BookingConfirmationMail;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\Patient;
use App\Models\Service;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Exports\TodayAppointmentsExport;
use App\Exports\DailyRevenueExport;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class AppointmentController extends Controller
{
    /**
     * SEC-07: rejection message when an admin tries to change the service
     * of an appointment that has already been paid. Recalculating the
     * price after money was collected would desynchronize the payment
     * record from what was actually purchased, so the change is refused
     * instead of silently altering history.
     */
    private const PAID_SERVICE_CHANGE_MESSAGE = 'The service of a paid appointment cannot be changed: the recorded payment must stay consistent with the service that was purchased.';

    /**
     * Part C: ceiling for one GET /appointments/recent response.
     *
     * A clinic never books 20 appointments inside a single 15-second
     * polling window, and the cap keeps one poll's payload bounded even
     * if a cursor is left untouched for days. The client advances its
     * cursor to the last id it processed, so anything above the cap is
     * delivered on the following polls instead of being dropped.
     */
    private const RECENT_LIMIT = 20;

    /**
     * Paginated appointment list (Phase 2 — appointments pagination).
     *
     * The endpoint used to answer with a bare JSON array of every row the
     * caller could see. It now always answers with a paginated envelope:
     *
     *   {
     *     "data": [ ...formatAppointment() rows... ],
     *     "current_page": 1, "per_page": 15, "total": 120, "last_page": 8,
     *     "from": 1, "to": 15, "path": ".../api/appointments",
     *     "prev_page_url": null, "next_page_url": ".../api/appointments?page=2"
     *   }
     *
     * Limits: `page` defaults to 1, `per_page` defaults to 15 and is
     * capped at 50. Values outside that contract (non-integer, below 1,
     * or per_page above 50) are rejected with 422 so a client can never
     * ask for an unbounded payload. A page past the end answers 200 with
     * an empty `data` (standard paginator behaviour), not an error.
     *
     * Backward compatibility — every consumer of the old array was traced
     * and updated in this same change:
     *   - frontend appointmentsService.getAll() now walks every page, so
     *     the patient-facing "My Appointments" and Profile surfaces still
     *     receive the COMPLETE history (never just the first 15 rows);
     *   - the admin appointments page and the dashboard home read the
     *     envelope directly for their page, filters and counts;
     *   - the four feature tests that decoded the root array now read
     *     `data`.
     *
     * Unchanged: 401 for guests, visibility (non-admins stay scoped to
     * their own user_id, admins see everything), the eager-loaded
     * relations (doctor/patient/service — exactly the three
     * formatAppointment() reads, so no extra queries are introduced), the
     * formatted field set, and the ordering.
     *
     * Ordering gains a deterministic `id` tie-breaker. The admin sort key
     * (appointment_date, doctor_id, queue_number) is unique per row, but
     * the patient sort (latest() = created_at DESC) can tie, and a tie
     * resolved differently per query would repeat or skip a row at a page
     * boundary.
     *
     * `search` and `status` are additive optional filters that move the
     * admin page's browser-side filtering server-side so it keeps working
     * across every page instead of only over the rows already loaded:
     *   - `search` reproduces the old client filter exactly — a
     *     case-insensitive substring match over patient name, doctor name
     *     OR status;
     *   - `status` reproduces the status dropdown exactly (exact match).
     * Both are applied after the visibility scope, so they can never
     * widen what a caller is allowed to see.
     *
     * `date` (F-02) is a third additive filter: one YYYY-MM-DD calendar
     * day, used by the dashboard's "Today's Appointments" card so the
     * server — not the browser — decides which rows are "today". It is
     * applied exactly like search/status (after the visibility scope,
     * AND-combined) and, when absent, the response is byte-identical to
     * what every existing consumer already receives.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:50'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            // F-02: optional server-side day filter, YYYY-MM-DD. Purely
            // additive — every existing consumer omits it and keeps the
            // exact rows it had before. A malformed value is a 422, like
            // the page / per_page bounds above, so a typo can never
            // silently widen or drop rows.
            'date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'status' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in([
                    'pending',
                    'arrived',
                    'in_progress',
                    'completed',
                    'cancelled',
                    'no_show',
                ]),
            ],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 15);
        $page = (int) ($validated['page'] ?? 1);

        $query = Appointment::with(['doctor', 'patient', 'service']);

        if ($request->user()->role !== 'admin') {
            $query->where('user_id', $request->user()->id);
        }

        // D: the admin list is chronological (oldest day first) with
        // queue-number order inside each doctor/day, so the table renders
        // in true clinic order. Patients keep the newest-first ordering
        // they had before. The final id tie-breaker makes both orders
        // total, so pagination can never duplicate or drop a row.
        $query = $request->user()->role === 'admin'
            ? $query->orderBy('appointment_date')->orderBy('doctor_id')->orderBy('queue_number')->orderBy('id')
            : $query->latest()->orderBy('id', 'desc');

        $search = trim((string) ($validated['search'] ?? ''));

        if ($search !== '') {
            // '!' is the LIKE escape character: it is a plain character
            // in both the MySQL and SQLite string dialects (a backslash
            // would need doubling in MySQL but not in SQLite), and it
            // escapes itself plus % and _ so a literal "100%" typed into
            // the search box matches exactly, like String.includes() did
            // when this filter ran in the browser.
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';

            $query->where(function (Builder $builder) use ($like) {
                $builder
                    ->whereRaw("appointments.status LIKE ? ESCAPE '!'", [$like])
                    ->orWhereHas(
                        'patient',
                        fn (Builder $patient) => $patient->whereRaw("patients.name LIKE ? ESCAPE '!'", [$like])
                    )
                    ->orWhereHas(
                        'doctor',
                        fn (Builder $doctor) => $doctor->whereRaw("doctors.name LIKE ? ESCAPE '!'", [$like])
                    );
            });
        }

        $status = $validated['status'] ?? null;

        if ($status !== null && $status !== '') {
            $query->where('appointments.status', $status);
        }

        // F-02: server-side day filter. Applied AFTER the visibility
        // scope and alongside search/status (AND), so it can only narrow
        // what the caller may already see. `whereDate` matches on the
        // calendar day only, exactly like DashboardController's
        // appointments_today, so the dashboard's "Today's Appointments"
        // card and its "Today" counter can never disagree.
        $date = $validated['date'] ?? null;

        if ($date !== null && $date !== '') {
            $query->whereDate('appointments.appointment_date', $date);
        }

        $paginator = $query
            ->paginate($perPage, ['*'], 'page', $page)
            ->withQueryString();

        $paginator->setCollection(
            $paginator->getCollection()->map(
                fn (Appointment $appointment) => $this->formatAppointment($appointment)
            )
        );

        return response()->json([
            'data' => $paginator->items(),
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
            'path' => $paginator->path(),
            'prev_page_url' => $paginator->previousPageUrl(),
            'next_page_url' => $paginator->nextPageUrl(),
        ]);
    }

    /**
     * Admin-only "what was booked since I last looked" feed (Part C —
     * the dashboard's new-appointment toast).
     *
     * GET /api/appointments/recent?after_id=<int>
     *
     *   200 {
     *     "data": [                       // id ASC, at most RECENT_LIMIT
     *       { "id": 141, "created_at": "...", "patient_name": "...", "doctor_name": "..." }
     *     ],
     *     "latest_id": 141                // MAX(appointments.id); 0 on an empty table
     *   }
     *
     * Contract guarantees:
     *  - `after_id` omitted (or null) answers an EMPTY `data`: that call
     *    only establishes the baseline, so the dashboard can never show
     *    pre-existing bookings as "new" on first load.
     *  - with `after_id`, only rows strictly newer than the cursor are
     *    returned; the client advances its cursor to the last id it
     *    processed, so repeated polls can neither repeat nor lose a row.
     *  - the cursor is an id, never a timestamp: no clock/timezone skew,
     *    no duplicates across a slow poll.
     *  - only names the admin dashboard already renders are exposed —
     *    no phone, no email, no notes, no payment data.
     *  - GET /api/appointments (the paginated envelope) is untouched.
     *
     * Guarded by the `admin` middleware in routes/api.php, so a patient
     * token answers 403 before this method runs.
     */
    public function recent(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'after_id' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ]);

        // A cursor of null/absent means "baseline me", not "give me
        // everything since id 0" — that distinction is what keeps old
        // appointments out of the very first poll.
        $hasCursor = array_key_exists('after_id', $validated)
            && $validated['after_id'] !== null;

        $rows = $hasCursor
            ? Appointment::with(['patient:id,name', 'doctor:id,name'])
                ->where('id', '>', (int) $validated['after_id'])
                ->orderBy('id')
                ->limit(self::RECENT_LIMIT)
                ->get()
            : collect();

        return response()->json([
            'data' => $rows->map(fn (Appointment $appointment) => [
                'id' => $appointment->id,
                'created_at' => $appointment->created_at?->toIso8601String(),
                'patient_name' => $appointment->patient?->name,
                'doctor_name' => $appointment->doctor?->name,
            ])->values(),
            'latest_id' => (int) Appointment::max('id'),
        ]);
    }

    public function show(Request $request, Appointment $appointment): JsonResponse
    {
        if (
            $request->user()->role !== 'admin' &&
            $appointment->user_id !== $request->user()->id
        ) {
            return response()->json([
                'message' => 'Forbidden'
            ], 403);
        }

        return response()->json(
            $this->formatAppointment(
              $appointment->load(['doctor', 'patient', 'service',])
            )
        );
    }

    /**
     * Public availability for smart booking.
     *
     * GET /api/doctors/{doctor}/availability?from=Y-m-d&to=Y-m-d
     *
     * One entry per calendar day in the window, describing whether the
     * doctor actually works that day and how much of the daily capacity
     * is left:
     *
     *   {
     *     "doctor": {id, name, specialty},
     *     "from": "YYYY-MM-DD", "to": "YYYY-MM-DD",
     *     "days": [{date, day, is_past, is_working_day, start_time,
     *               end_time, max_patients, booked, remaining,
     *               is_bookable}, ...]
     *   }
     *
     * Read-only companion to store()'s booking checks — it mirrors the
     * exact same rules (a working day = a DoctorSchedule row with
     * is_available = true, matching findAvailableSchedule(); booked =
     * ACTIVE appointments only via the shared active() scope, so
     * cancelled and no_show rows release their slot, D1). The booking
     * write path below stays the single authority: a schedule change
     * after this endpoint answers simply surfaces the existing
     * controlled 422 at submit time.
     *
     * Timezone: "today" comes from now() in the application timezone
     * (Africa/Cairo) — identical to StoreAppointmentRequest's
     * after_or_equal:today rule, so `is_past` and the booking gate can
     * never disagree. Past days are REPORTED (is_past = true,
     * is_bookable = false) rather than rejected, so a stale deep link
     * degrades gracefully; store() still rejects past bookings.
     *
     * Public like /doctors/{doctor}/schedules: aggregate data only, no
     * patient information. Exactly two queries regardless of window
     * length (schedules + ranged appointments, grouped in memory).
     */
    public function availability(Request $request, $doctorId): JsonResponse
    {
        $doctor = Doctor::find($doctorId);

        if (! $doctor) {
            return response()->json([
                'message' => 'Doctor not found',
            ], 404);
        }

        $data = $request->validate([
            'from' => 'sometimes|nullable|date_format:Y-m-d',
            'to' => 'sometimes|nullable|date_format:Y-m-d|after_or_equal:from',
        ]);

        $from = Carbon::parse($data['from'] ?? now()->toDateString())->startOfDay();

        // Default window: 30 days starting today. Hard cap: 62 days, so
        // the public endpoint can never be turned into an unbounded scan.
        $to = isset($data['to'])
            ? Carbon::parse($data['to'])->startOfDay()
            : $from->copy()->addDays(29);

        // Signed-diff safe (Carbon 3 returns negative diffs for reversed
        // order): $from->diffInDays($to) is the window length in days.
        if ($from->diffInDays($to) > 61) {
            return response()->json([
                'message' => 'The date range cannot exceed 62 days.',
            ], 422);
        }

        // Two reads total: the doctor's available weekday schedules and
        // the ACTIVE appointments inside the window, grouped per date in
        // memory (no per-day queries — no N+1). DATE() comparison (works
        // on MySQL and SQLite alike) because the date cast stores the
        // column as 'Y-m-d 00:00:00', which a plain BETWEEN with
        // date-string bounds would clip at the upper edge.
        $schedules = DoctorSchedule::query()
            ->where('doctor_id', $doctor->id)
            ->where('is_available', true)
            ->get()
            ->keyBy('day');

        $bookedPerDate = Appointment::query()
            ->active()
            ->where('doctor_id', $doctor->id)
            ->whereRaw(
                'DATE(appointment_date) BETWEEN ? AND ?',
                [$from->toDateString(), $to->toDateString()]
            )
            ->get(['appointment_date'])
            ->countBy(fn (Appointment $appointment) => $appointment->appointment_date->toDateString());

        $today = now()->toDateString();

        $days = [];

        for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
            $dateString = $date->toDateString();
            $schedule = $schedules->get($date->format('l'));
            $booked = (int) ($bookedPerDate[$dateString] ?? 0);
            $isPast = $dateString < $today;
            $remaining = $schedule
                ? max(0, (int) $schedule->max_patients - $booked)
                : 0;

            $days[] = [
                'date' => $dateString,
                'day' => $date->format('l'),
                'is_past' => $isPast,
                'is_working_day' => $schedule !== null,
                'start_time' => $schedule?->start_time,
                'end_time' => $schedule?->end_time,
                'max_patients' => $schedule?->max_patients,
                'booked' => $booked,
                'remaining' => $remaining,
                'is_bookable' => $schedule !== null && ! $isPast && $remaining > 0,
            ];
        }

        return response()->json([
            'doctor' => [
                'id' => $doctor->id,
                'name' => $doctor->name,
                'specialty' => $doctor->specialty,
            ],
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'days' => $days,
        ]);
    }

    public function store(StoreAppointmentRequest $request): JsonResponse
    {
        $user = $request->user();
        $service = Service::findOrFail($request->service_id);

        if ($user->role === 'admin') {

            if ($request->filled('patient_id')) {

                $patient = Patient::findOrFail($request->patient_id);

            } else {

                $patient = Patient::firstOrCreate(

                    [
                        'phone' => $request->phone,
                    ],

                    [
                        'name' => $request->name,
                        'email' => $request->email,
                        'gender' => $request->gender,
                    ]

                );
            }

        } else {

            $patient = $user->patient;

            if (!$patient) {

                $patient = Patient::create([
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                ]);
            }
        }

        $day = Carbon::parse($request->appointment_date)->format('l');

        $status = $request->validated('status') ?? 'pending';

        // Phase 2: every booking decision (availability, duplicate, capacity,
        // queue assignment) runs inside one transaction that first takes a
        // lock on the doctor row. On MySQL this serializes concurrent
        // bookings for the same doctor (SELECT ... FOR UPDATE); on SQLite
        // the lock is a no-op but writes are serialized anyway.
        try {
            $result = DB::transaction(function () use ($request, $patient, $user, $service, $day, $status) {
                Doctor::whereKey($request->doctor_id)->lockForUpdate()->first();

                $schedule = $this->findAvailableSchedule($request->doctor_id, $day);

                if (!$schedule) {
                    return ['error' => 'Doctor is not available on this day.'];
                }

                if ($this->hasActiveBooking($patient->id, $request->doctor_id, $request->appointment_date)) {
                    return ['error' => 'Patient already booked this doctor on this day.'];
                }

                if ($this->activeCount($request->doctor_id, $request->appointment_date) >= $schedule->max_patients) {
                    return ['error' => 'This day is fully booked.'];
                }

                // Queue numbers are never recycled: continue after the
                // highest number ever issued for this doctor/day, even if
                // earlier appointments were cancelled or no-showed.
                $queueNumber = $this->nextQueueNumber($request->doctor_id, $request->appointment_date);

                $appointment = Appointment::create([
                    'patient_id' => $patient->id,

                    'user_id' => $user->role === 'admin'
                        ? $patient->user_id
                        : $user->id,

                    'doctor_id' => $request->doctor_id,

                    'service_id' => $service->id,

                    'appointment_date' => $request->appointment_date,

                    'queue_number' => $queueNumber,

                    'service_price' => $service->price,

                    'payment_status' => 'unpaid',

                    'payment_method' => null,

                    'paid_at' => null,

                    // D3: admin-selected create status, defaulting to
                    // 'pending' when the form does not send one (backward
                    // compatibility). Non-admins can never reach here with a
                    // status — the request rules prohibit it.
                    'status' => $status,

                    'notes' => $request->notes,
                ]);

                // D5: a create that lands directly on in_progress demotes
                // the doctor's previous current appointment that day.
                if ($status === 'in_progress') {
                    $this->demotePreviousInProgress($appointment);
                }

                return ['appointment' => $appointment, 'queue_number' => $queueNumber];
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent request slipped past the pre-checks. Map the
            // driver-level violation back onto the same controlled 422s the
            // pre-checks use, preserving the existing {message} contract.
            return response()->json([
                'message' => $this->bookingConflictMessage($patient->id, $request->doctor_id, $request->appointment_date),
            ], 422);
        }

        if (isset($result['error'])) {
            return response()->json([
                'message' => $result['error'],
            ], 422);
        }

        $appointment = $result['appointment'];
        $queueNumber = $result['queue_number'];

        // Part B: the confirmation e-mail is dispatched ONLY here —
        // after DB::transaction() has committed and only on the success
        // path. A delivery failure is swallowed inside
        // sendBookingConfirmation(), so it can never roll back, delay or
        // invalidate a booking that already succeeded, and the 201 below
        // is returned exactly as before.
        $this->sendBookingConfirmation($request, $appointment);

        return response()->json([
            'message' => 'Appointment booked successfully.',
            'queue_number' => $queueNumber,
           'data' => $this->formatAppointment(
    $appointment->load([
        'doctor',
        'patient',
        'service',
    ])
),
        ], 201);
    }

    /**
     * Part B — booking confirmation e-mail.
     *
     * Guarantees:
     *  - called only from store() AFTER the transaction committed, so a
     *    booking that failed (422 / unique violation) never mails;
     *  - admin back-office creates are skipped: only a patient booking
     *    for themselves notifies (Part B rule 10), and update(),
     *    updateStatus() and updatePayment() never call this at all, so
     *    retries and unrelated updates cannot resend;
     *  - duplicate submissions never reach here — hasActiveBooking()
     *    rejects the second POST with 422 before any row is written;
     *  - the recipient is the PATIENT's registered address; a patient
     *    row without an e-mail address simply skips the send;
     *  - any transport failure is caught and logged with the
     *    appointment id + exception class/message only — never the
     *    password, token, e-mail body or any other patient detail.
     */
    private function sendBookingConfirmation(Request $request, Appointment $appointment): void
    {
        if ($request->user()?->role === 'admin') {
            return;
        }

        $recipient = trim((string) ($appointment->patient?->email ?? ''));

        if ($recipient === '') {
            return;
        }

        try {
            Mail::to($recipient)->send(
                new BookingConfirmationMail(
                    $appointment->load(['doctor', 'patient', 'service']),
                    $this->bookingLocale($request),
                )
            );
        } catch (\Throwable $e) {
            Log::error('Booking confirmation e-mail failed.', [
                'appointment_id' => $appointment->id,
                'exception' => $e::class . ': ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Part B — e-mail language.
     *
     * Read from the standard Accept-Language header (which the frontend
     * sets from its own language toggle), first match wins among the two
     * shipped locales, otherwise APP_LOCALE, otherwise English. No new
     * request field and no API-shape change were needed for this.
     */
    private function bookingLocale(Request $request): string
    {
        $header = strtolower((string) $request->header('Accept-Language', ''));

        foreach (explode(',', $header) as $part) {
            $code = strtolower(trim(explode(';', $part)[0]));

            if (str_starts_with($code, 'ar')) {
                return 'ar';
            }

            if (str_starts_with($code, 'en')) {
                return 'en';
            }
        }

        $fallback = (string) config('app.locale', 'en');

        return in_array($fallback, ['ar', 'en'], true) ? $fallback : 'en';
    }
        public function update(UpdateAppointmentRequest $request, Appointment $appointment): JsonResponse
    {
       if (
           $request->user()->role !== 'admin' &&
           $appointment->user_id !== $request->user()->id
        ) {
            return response()->json([
                'message' => 'Forbidden'
            ], 403);
        }

        $data = $request->validated();

        // SEC-07: a service change is an admin-only, price-relevant
        // operation. The new price is always re-read from the trusted
        // server-side Service record — service_price is deliberately not
        // part of the validation rules, so a client-supplied price is
        // never seen here. Nothing is written unless the service actually
        // changed; an unchanged service keeps its historical price.
        $serviceChanged = array_key_exists('service_id', $data)
            && (int) $data['service_id'] !== (int) $appointment->service_id;

        if ($serviceChanged) {
            $servicePrice = Service::whereKey((int) $data['service_id'])->value('price');

            if ($servicePrice === null) {
                return response()->json([
                    'message' => 'Service not found.',
                ], 422);
            }

            $data['service_price'] = $servicePrice;
        }

        $newDoctorId = $data['doctor_id'] ?? $appointment->doctor_id;
        $newDate = isset($data['appointment_date'])
            ? Carbon::parse($data['appointment_date'])->toDateString()
            : $appointment->appointment_date->toDateString();

        // A reschedule is any change of doctor or date. Only admins can
        // trigger one (D4 — the request rules prohibit doctor_id /
        // appointment_date for non-admins), and it re-runs every booking
        // check against the TARGET doctor/day.
        $isReschedule = (int) $newDoctorId !== (int) $appointment->doctor_id
            || $newDate !== $appointment->appointment_date->toDateString();

        if ($isReschedule) {
            try {
                $result = DB::transaction(function () use ($appointment, $data, $newDoctorId, $newDate, $serviceChanged) {
                    Doctor::whereKey($newDoctorId)->lockForUpdate()->first();

                    // SEC-07: before any write, lock the appointment row and
                    // re-check the payment state, so a payment recorded
                    // concurrently (Stripe webhook or admin cash flow) can
                    // never be followed by a service/price change.
                    if ($serviceChanged) {
                        $locked = Appointment::whereKey($appointment->id)->lockForUpdate()->first();

                        if ($locked->payment_status === 'paid') {
                            return ['error' => self::PAID_SERVICE_CHANGE_MESSAGE];
                        }
                    }

                    $day = Carbon::parse($newDate)->format('l');

                    $schedule = $this->findAvailableSchedule($newDoctorId, $day);

                    if (!$schedule) {
                        return ['error' => 'Doctor is not available on this day.'];
                    }

                    // The duplicate rule targets the PATIENT, so compare
                    // against the appointment's own patient and ignore the
                    // row being moved.
                    if ($this->hasActiveBooking(
                        $appointment->patient_id,
                        $newDoctorId,
                        $newDate,
                        $appointment->id
                    )) {
                        return ['error' => 'Patient already booked this doctor on this day.'];
                    }

                    if ($this->activeCount($newDoctorId, $newDate, $appointment->id) >= $schedule->max_patients) {
                        return ['error' => 'This day is fully booked.'];
                    }

                    // Moving to a different doctor/day reassigns the queue
                    // number as max+1 on the target day (counting every
                    // status so numbers are never reused). A notes-only or
                    // status-only edit keeps the existing number.
                    $data['queue_number'] = $this->nextQueueNumber($newDoctorId, $newDate, $appointment->id);

                    $appointment->update($data);

                    // D5: an admin edit that sets in_progress demotes the
                    // doctor's previous current appointment that day.
                    if (($data['status'] ?? null) === 'in_progress') {
                        $this->demotePreviousInProgress($appointment);
                    }

                    return ['appointment' => $appointment];
                });
            } catch (UniqueConstraintViolationException) {
                return response()->json([
                    'message' => $this->bookingConflictMessage(
                        $appointment->patient_id,
                        $newDoctorId,
                        $newDate,
                        $appointment->id
                    ),
                ], 422);
            }

            if (isset($result['error'])) {
                return response()->json([
                    'message' => $result['error'],
                ], 422);
            }
        } else {
            // Not a reschedule: apply the remaining fields (notes, service,
            // status) exactly as before.
            //
            // G: this branch now also runs in one transaction so that a
            // status change to in_progress and the demotion of the doctor's
            // previous current appointment are atomic (matching the other
            // three write paths).
            $result = DB::transaction(function () use ($appointment, $data, $serviceChanged) {
                // SEC-07: lock the row and re-check the payment state under
                // the lock before applying a service change, so a payment
                // recorded concurrently can never be followed by a
                // service/price change (mirrors the reschedule branch).
                if ($serviceChanged) {
                    $locked = Appointment::whereKey($appointment->id)->lockForUpdate()->first();

                    if ($locked->payment_status === 'paid') {
                        return ['error' => self::PAID_SERVICE_CHANGE_MESSAGE];
                    }
                }

                $appointment->update($data);

                if (($data['status'] ?? null) === 'in_progress') {
                    $this->demotePreviousInProgress($appointment);
                }

                return ['appointment' => $appointment];
            });

            if (isset($result['error'])) {
                return response()->json([
                    'message' => $result['error'],
                ], 422);
            }
        }

        return response()->json(
            $this->formatAppointment(
                $appointment->refresh()->load([
    'doctor',
    'patient',
    'service',
])
            )
        );
    }

    public function updateStatus(Request $request, Appointment $appointment): JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|in:pending,arrived,in_progress,completed,cancelled,no_show',
        ]);

        // D5: moving to in_progress happens in one transaction so that any
        // previous current appointment for that doctor/day is atomically
        // demoted to 'arrived'.
        DB::transaction(function () use ($appointment, $data) {
            $appointment->update([
                'status' => $data['status'],
            ]);

            if ($data['status'] === 'in_progress') {
                $this->demotePreviousInProgress($appointment);
            }
        });

        return response()->json([
            'message' => 'Appointment status updated successfully.',
            'data' => $this->formatAppointment(
               $appointment->refresh()->load([
    'doctor',
    'patient',
    'service',
])
            ),
        ]);
    }
    public function updatePayment(Request $request, Appointment $appointment): JsonResponse
{
    $request->validate([
        'payment_method' => [
            'required',
            // D2: the approved method list, matching the widened DB enum.
            Rule::in([
                'cash',
                'visa',
                'insurance',
                'stripe',
                'instapay',
                'vodafone_cash',
            ]),
        ],
    ]);

    if ($appointment->payment_status === 'paid') {
        return response()->json([
            'message' => 'Appointment already paid.'
        ], 422);
    }

    $appointment->update([
        'payment_status' => 'paid',
        'payment_method' => $request->payment_method,
        'paid_at' => now(),
    ]);

    return response()->json([
        'message' => 'Payment updated successfully.',
        'data' => $this->formatAppointment(
            $appointment->refresh()->load([
                'doctor',
                'patient',
                'service',
            ])
        ),
    ]);
}

    /**
     * A/B: Complete + payment in ONE transaction.
     *
     * Staff clicks "Complete" -> a payment dialog collects the method ->
     * this endpoint records the payment and marks the appointment
     * completed atomically. Guarantees:
     *
     *  - the row is locked (SELECT ... FOR UPDATE on MySQL; a serialized
     *    write on SQLite) so a concurrent payment/completion can never
     *    double-record;
     *  - an appointment already paid (cash earlier, or Stripe online via
     *    the webhook) is completed WITHOUT recording a second payment —
     *    paid_at / payment_method stay untouched;
     *  - an unpaid appointment requires a payment_method;
     *  - a completed appointment is rejected with 422 (no repeated
     *    completion);
     *  - clearing needs_investigation: a now-completed AND paid row is
     *    no longer under investigation.
     *
     * Stripe / online flows are preserved: the checkout + webhook path is
     * untouched and simply makes this endpoint's "already paid" branch
     * the one that fires.
     */
    public function complete(Request $request, Appointment $appointment): JsonResponse
    {
        $data = $request->validate([
            'payment_method' => [
                'sometimes',
                'nullable',
                // D2: the approved method list, matching the widened DB enum.
                Rule::in([
                    'cash',
                    'visa',
                    'insurance',
                    'stripe',
                    'instapay',
                    'vodafone_cash',
                ]),
            ],
        ]);

        $paymentMethod = $data['payment_method'] ?? null;

        try {
            $result = DB::transaction(function () use ($appointment, $paymentMethod) {
                /** @var Appointment $locked */
                $locked = Appointment::whereKey($appointment->id)->lockForUpdate()->first();

                if ($locked->status === 'completed') {
                    return ['error' => 'Appointment already completed.'];
                }

                if ($locked->payment_status !== 'paid') {
                    if (!$paymentMethod) {
                        return ['error' => 'Payment method is required to complete this appointment.'];
                    }

                    $locked->update([
                        'payment_status' => 'paid',
                        'payment_method' => $paymentMethod,
                        'paid_at' => now(),
                    ]);
                }

                $locked->update([
                    'status' => 'completed',
                    'needs_investigation' => false,
                ]);

                return ['appointment' => $locked];
            });
        } catch (UniqueConstraintViolationException) {
            return response()->json([
                'message' => 'Appointment already completed.',
            ], 422);
        }

        if (isset($result['error'])) {
            return response()->json([
                'message' => $result['error'],
            ], 422);
        }

        return response()->json([
            'message' => 'Appointment completed successfully.',
            'data' => $this->formatAppointment(
                $result['appointment']->refresh()->load([
                    'doctor',
                    'patient',
                    'service',
                ])
            ),
        ]);
    }

    /**
     * SEC-08: cancellation with status/payment guards.
     *
     * Ownership is still checked first (403 for unrelated users, exactly
     * as before). After that:
     *
     *  - ADMIN: keeps the existing workflow — the admin dashboard offers
     *    Cancel on every row and the approved business rule is "admin can
     *    cancel any appointment" (locked by AppointmentAuthorizationTest),
     *    so no new restriction is imposed on staff.
     *  - PATIENT (non-admin): may cancel only an appointment that is
     *    still active AND unpaid — pending/arrived only (the D7 rule the
     *    patient UI has always shown), which at minimum blocks completed,
     *    paid, already-cancelled and no-show rows, plus in-progress
     *    (mid-visit) per the existing UI rule. Rejections are 422 with an
     *    explicit message; nothing is written, so historical status and
     *    payment records are never silently altered.
     *
     * The status decision and the write happen inside one transaction with
     * the row locked, so cancellation is atomic with respect to a
     * concurrent completion/payment, and capacity (which is derived from
     * the active() scope) is only ever released by a cancellation that
     * actually succeeded.
     */
    public function destroy(Request $request, Appointment $appointment): JsonResponse
    {
        $isAdmin = $request->user()->role === 'admin';

        if (! $isAdmin && $appointment->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Forbidden'
            ], 403);
        }

        $result = DB::transaction(function () use ($appointment, $isAdmin) {
            /** @var Appointment $locked */
            $locked = Appointment::whereKey($appointment->id)->lockForUpdate()->first();

            if (! $isAdmin) {
                if ($locked->payment_status === 'paid') {
                    return ['error' => 'A paid appointment cannot be cancelled online. Please contact the clinic about the payment.'];
                }

                if (! in_array($locked->status, ['pending', 'arrived'], true)) {
                    return ['error' => 'Only appointments that are still pending or arrived can be cancelled.'];
                }
            }

            $locked->update([
                'status' => 'cancelled'
            ]);

            return ['cancelled' => true];
        });

        if (isset($result['error'])) {
            return response()->json([
                'message' => $result['error'],
            ], 422);
        }

        return response()->json([
            'message' => 'Appointment cancelled successfully.'
        ]);
    }

    /**
     * The doctor's available schedule row for a weekday name ('Monday'...).
     */
    private function findAvailableSchedule(int $doctorId, string $day): ?DoctorSchedule
    {
        return DoctorSchedule::where('doctor_id', $doctorId)
            ->where('day', $day)
            ->where('is_available', true)
            ->first();
    }

    /**
     * Whether the patient already holds an ACTIVE booking for this
     * doctor/day. Cancelled and no-show rows do not block rebooking (D1).
     */
    private function hasActiveBooking(int $patientId, int $doctorId, string $date, ?int $ignoreId = null): bool
    {
        return Appointment::query()
            ->active()
            ->where('patient_id', $patientId)
            ->where('doctor_id', $doctorId)
            ->whereDate('appointment_date', $date)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();
    }

    /**
     * Active occupancy of a doctor/day for the capacity check (D1: only
     * pending/arrived/in_progress/completed rows consume a slot).
     */
    private function activeCount(int $doctorId, string $date, ?int $ignoreId = null): int
    {
        return (int) Appointment::query()
            ->active()
            ->where('doctor_id', $doctorId)
            ->whereDate('appointment_date', $date)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->count();
    }

    /**
     * Next queue number for a doctor/day: max+1 over ALL statuses so a
     * cancelled appointment's number is never reused.
     */
    private function nextQueueNumber(int $doctorId, string $date, ?int $ignoreId = null): int
    {
        $max = Appointment::where('doctor_id', $doctorId)
            ->whereDate('appointment_date', $date)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->max('queue_number');

        return $max ? $max + 1 : 1;
    }

    /**
     * D5: demote every other in_progress appointment for this doctor/day
     * to 'arrived', keeping exactly one current appointment. Callers run
     * this inside a transaction.
     */
    private function demotePreviousInProgress(Appointment $current): void
    {
        Appointment::where('doctor_id', $current->doctor_id)
            ->whereDate('appointment_date', $current->appointment_date)
            ->where('status', 'in_progress')
            ->whereKeyNot($current->id)
            ->update(['status' => 'arrived']);
    }

    /**
     * Which controlled 422 message a lost booking race should surface,
     * reusing the exact strings the pre-checks return.
     */
    private function bookingConflictMessage(int $patientId, int $doctorId, string $date, ?int $ignoreId = null): string
    {
        if ($this->hasActiveBooking($patientId, $doctorId, $date, $ignoreId)) {
            return 'Patient already booked this doctor on this day.';
        }

        $schedule = $this->findAvailableSchedule($doctorId, Carbon::parse($date)->format('l'));

        if (!$schedule || $this->activeCount($doctorId, $date, $ignoreId) >= $schedule->max_patients) {
            return 'This day is fully booked.';
        }

        // Fall back to the availability message if none of the above fit.
        return 'Doctor is not available on this day.';
    }

    private function formatAppointment(Appointment $appointment): array
    {
        return [
            'id' => $appointment->id,

            'doctor' => [
                'id' => $appointment->doctor->id,
                'name' => $appointment->doctor->name,
                'specialty' => $appointment->doctor->specialty,
            ],
               'service' => [
                'id' => $appointment->service->id,
                'name' => $appointment->service->name,
                'price' => $appointment->service_price,
          ],
          'payment' => [
    'status' => $appointment->payment_status,
    'method' => $appointment->payment_method,
    'paid_at' => $appointment->paid_at,
],

            'patient' => [
                'id' => $appointment->patient->id,
                'name' => $appointment->patient->name,
                'phone' => $appointment->patient->phone,
                'email' => $appointment->patient->email,
            ],

            'queue_number' => $appointment->queue_number,

            // appointments.appointment_date is a DATE column; serialize it
            // as a plain YYYY-MM-DD string instead of an ISO datetime.
            'appointment_date' => $appointment->appointment_date?->toDateString(),

            'status' => $appointment->status,

            'notes' => $appointment->notes,

            // C: set by the end-of-day cleanup when a COMPLETED appointment
            // was never paid. Additive key — existing consumers ignore it.
            'needs_investigation' => (bool) $appointment->needs_investigation,

            'created_at' => $appointment->created_at?->toDateTimeString(),

            'updated_at' => $appointment->updated_at?->toDateTimeString(),
        ];
    }
    public function export(Request $request)
 {
    $request->validate([
        'from' => 'nullable|required_with:to|date_format:Y-m-d',
        'to' => 'sometimes|nullable|date_format:Y-m-d|after_or_equal:from',
    ]);

    return Excel::download(
        new TodayAppointmentsExport(
            $request->from,
            $request->to
        ),
        'appointments.xlsx'
    );
 }

    /**
     * E: daily revenue export (server-computed).
     *
     * Only COMPLETED + PAID appointments are counted — the amounts come
     * from the server-stored service_price and the server-recorded payment
     * state (cash/Stripe/webhook), never from frontend input. Defaults to
     * the Africa/Cairo clinic day (the app timezone); ?date= overrides it.
     */
    public function exportRevenue(Request $request)
    {
        $request->validate([
            'date' => 'nullable|date_format:Y-m-d',
        ]);

        $date = $request->date ?: now()->toDateString();

        return Excel::download(
            new DailyRevenueExport($request->date),
            'revenue-' . $date . '.xlsx'
        );
    }
}