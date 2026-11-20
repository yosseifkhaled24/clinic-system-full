<?php

use App\Mail\BookingConfirmationMail;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

/*
 |--------------------------------------------------------------------------
 | Part B — booking confirmation e-mail
 |--------------------------------------------------------------------------
 |
 | When a patient successfully creates an appointment, store() sends
 | one confirmation e-mail to the PATIENT's registered address. The
 | guarantees these tests lock:
 |
 |  - sent ONLY after the booking transaction committed and ONLY on the
 |    success path (a rejected booking mails nothing),
 |  - NEVER from an admin back-office create (rule 10), and never again
 |    for retries (duplicate POST = 422) or unrelated updates
 |    (status/payment changes),
 |  - a delivery failure is caught + logged (appointment id + exception
 |    ONLY) and the booking still answers 201 — mail can never roll
 |    back a successful booking,
 |  - content: patient name, doctor name, service name (when present),
 |    appointment date and status, rendered from the Blade template in
 |    the language selected by Accept-Language (en/ar),
 |  - a patient row without an e-mail address simply skips the send.
 |
 | Mail::fake() is used for every delivery assertion — no real e-mail
 | is ever sent (phpunit.xml also pins MAIL_MAILER=array). Helper
 | names carry the `bcm` prefix so they can never collide with the
 | helpers of the other feature files when the whole suite loads.
 */

function bcmPatientUser(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => 'user',
        'email' => 'bcm-' . uniqid() . '@gmail.com',
        'name' => 'Bcm Patient',
    ], $overrides));
}

function bcmSchedule(Doctor $doctor, string $date): DoctorSchedule
{
    return DoctorSchedule::factory()->create([
        'doctor_id' => $doctor->id,
        'day' => Carbon::parse($date)->format('l'),
        'start_time' => '09:00',
        'end_time' => '15:00',
        'is_available' => true,
        'max_patients' => 30,
    ]);
}

/** [doctor, service, bookable date] with ASCII names the assertions can match. */
function bcmSetup(): array
{
    $doctor = Doctor::factory()->create(['name' => 'Dr. Bcm Doctor']);
    $service = Service::factory()->create(['name' => 'Bcm Service']);
    $date = now()->addDays(3)->toDateString();

    bcmSchedule($doctor, $date);

    return [$doctor, $service, $date];
}

function bcmBooking(Doctor $doctor, Service $service, string $date): array
{
    return [
        'doctor_id' => $doctor->id,
        'service_id' => $service->id,
        'appointment_date' => $date,
        'notes' => 'Booking confirmation test.',
    ];
}

/*
 | ---------------------------------------------------------------------------
 | Delivery rules
 | ---------------------------------------------------------------------------
 */

test('a successful patient booking sends one confirmation e-mail to the registered patient address', function () {
    Mail::fake();

    [$doctor, $service, $date] = bcmSetup();
    $user = bcmPatientUser();

    Sanctum::actingAs($user);

    $this->postJson('/api/appointments', bcmBooking($doctor, $service, $date))
        ->assertStatus(201);

    Mail::assertSent(BookingConfirmationMail::class, function (BookingConfirmationMail $mail) use ($user, $doctor, $service, $date) {
        $html = $mail->render();

        return $mail->hasTo($user->email)
            && $mail->envelope()->subject === 'Your appointment has been booked'
            // Required content: patient, doctor, service, date, status.
            && str_contains($html, 'Bcm Patient')
            && str_contains($html, 'Dr. Bcm Doctor')
            && str_contains($html, 'Bcm Service')
            && str_contains($html, Carbon::parse($date)->locale('en')->translatedFormat('l, j F Y'))
            && str_contains($html, 'Pending');
    });
});

test('a rejected booking sends no confirmation e-mail', function () {
    Mail::fake();

    [, $service, $date] = bcmSetup();
    $user = bcmPatientUser();

    Sanctum::actingAs($user);

    // This doctor has no schedule on that day → controlled 422.
    $unavailable = Doctor::factory()->create();

    $this->postJson('/api/appointments', bcmBooking($unavailable, $service, $date))
        ->assertStatus(422);

    Mail::assertNothingSent();
    expect(Appointment::count())->toBe(0);
});

test('appointments created through the admin back-office send no e-mail', function () {
    Mail::fake();

    [$doctor, $service, $date] = bcmSetup();

    Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

    $this->postJson('/api/appointments', array_merge(
        bcmBooking($doctor, $service, $date),
        ['name' => 'Walk-in Patient', 'phone' => '01000000009']
    ))->assertStatus(201);

    Mail::assertNothingSent();
});

test('a duplicate booking attempt never sends a second e-mail', function () {
    Mail::fake();

    [$doctor, $service, $date] = bcmSetup();
    $user = bcmPatientUser();

    Sanctum::actingAs($user);

    $this->postJson('/api/appointments', bcmBooking($doctor, $service, $date))->assertStatus(201);

    // The first request cached a null `patient` relation on the shared
    // actingAs instance. Production resolves a FRESH User per request
    // (token auth), so that stale cache can never occur live — drop it
    // here so the second request re-reads the row the first booking
    // created instead of trying to insert a duplicate patient.
    $user->unsetRelation('patient');

    // Same patient/doctor/day → controlled 422, no second row, no mail.
    $this->postJson('/api/appointments', bcmBooking($doctor, $service, $date))->assertStatus(422);

    Mail::assertSent(BookingConfirmationMail::class, 1);
});

test('unrelated appointment updates never resend the confirmation', function () {
    Mail::fake();

    [$doctor, $service, $date] = bcmSetup();
    $user = bcmPatientUser();

    Sanctum::actingAs($user);

    $booking = $this->postJson('/api/appointments', bcmBooking($doctor, $service, $date))
        ->assertStatus(201);

    $appointmentId = $booking->json('data.id');

    Mail::assertSent(BookingConfirmationMail::class, 1);

    // Admin changes the status — an unrelated update, not a new booking.
    Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

    $this->patchJson('/api/appointments/' . $appointmentId . '/status', ['status' => 'arrived'])
        ->assertOk();

    Mail::assertSent(BookingConfirmationMail::class, 1);
});

test('an e-mail addressless patient still books successfully and simply gets no mail', function () {
    Mail::fake();

    [$doctor, $service, $date] = bcmSetup();
    $user = bcmPatientUser();

    // Pre-existing patient row without an e-mail address.
    Patient::factory()->create([
        'user_id' => $user->id,
        'email' => null,
        'phone' => '012' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
    ]);

    Sanctum::actingAs($user);

    $this->postJson('/api/appointments', bcmBooking($doctor, $service, $date))
        ->assertStatus(201);

    Mail::assertNothingSent();
});

/*
 | ---------------------------------------------------------------------------
 | Failure isolation (rule 7 + 8)
 | ---------------------------------------------------------------------------
 */

test('a transport failure is logged with the appointment id only and never rolls back the booking', function () {
    // Spy (not a strict mock): unrelated framework logging stays harmless,
    // while the exact Part B log line is still verified below.
    Log::spy();
    Mail::shouldReceive('to')
        ->once()
        ->andThrow(new RuntimeException('SMTP transport unavailable'));

    [$doctor, $service, $date] = bcmSetup();
    $user = bcmPatientUser();

    Sanctum::actingAs($user);

    $this->postJson('/api/appointments', bcmBooking($doctor, $service, $date))
        ->assertStatus(201)
        ->assertJsonPath('message', 'Appointment booked successfully.');

    // The booking survived the mail failure — no rollback, no 500.
    $appointment = Appointment::firstOrFail();

    expect($appointment->user_id)->toBe($user->id);

    Log::shouldHaveReceived('error', function (...$args) use ($appointment) {
        $message = $args[0] ?? null;
        $context = $args[1] ?? null;

        return $message === 'Booking confirmation e-mail failed.'
            && is_array($context)
            // ONLY these two keys: no password, no token, no patient
            // payload, no e-mail body.
            && array_keys($context) === ['appointment_id', 'exception']
            && ($context['appointment_id'] ?? null) === $appointment->id
            && str_contains((string) ($context['exception'] ?? ''), 'SMTP transport unavailable');
    });
});

/*
 | ---------------------------------------------------------------------------
 | Language (rule 4) + template rendering
 | ---------------------------------------------------------------------------
 */

test('the e-mail language follows the Accept-Language header', function () {
    Mail::fake();

    [$doctor, $service, $date] = bcmSetup();
    $user = bcmPatientUser();

    Sanctum::actingAs($user);

    // Per-request headers: withHeaders() would stick for every later
    // request in this test and wrongly translate the second booking too.
    $this->postJson('/api/appointments', bcmBooking($doctor, $service, $date), ['Accept-Language' => 'ar'])
        ->assertStatus(201);

    Mail::assertSent(BookingConfirmationMail::class, function (BookingConfirmationMail $mail) {
        $html = $mail->render();

        return $mail->locale === 'ar'
            && str_contains($html, 'تم حجز موعدك')
            && str_contains($html, 'dir="rtl"')
            && str_contains($html, 'قيد الانتظار');
    });

    // English is the default when the header is absent. Same shared
    // actingAs instance as above: drop the relation the first booking
    // cached so the second request re-reads the existing patient row.
    $user->unsetRelation('patient');
    Sanctum::actingAs($user);
    $second = bcmSetup();

    $this->postJson('/api/appointments', bcmBooking($second[0], $second[1], $second[2]))
        ->assertStatus(201);

    Mail::assertSent(BookingConfirmationMail::class, function (BookingConfirmationMail $mail) {
        return $mail->locale === 'en'
            && str_contains($mail->render(), 'dir="ltr"');
    });

    // Exactly two bookings, exactly two mails — one per booking.
    Mail::assertSent(BookingConfirmationMail::class, 2);
});

test('the confirmation template renders every required field in English and Arabic', function () {
    Mail::fake();

    [$doctor, $service, $date] = bcmSetup();
    $user = bcmPatientUser();

    Sanctum::actingAs($user);

    $this->postJson('/api/appointments', bcmBooking($doctor, $service, $date))
        ->assertStatus(201);

    $appointment = Appointment::where('user_id', $user->id)->firstOrFail();

    $english = (new BookingConfirmationMail($appointment, 'en'))->render();

    expect($english)
        ->toContain('Bcm Patient')
        ->toContain('Dr. Bcm Doctor')
        ->toContain('Bcm Service')
        ->toContain(Carbon::parse($date)->locale('en')->translatedFormat('l, j F Y'))
        ->toContain('Pending')
        ->toContain('dir="ltr"');

    $arabic = (new BookingConfirmationMail($appointment, 'ar'))->render();

    expect($arabic)
        ->toContain('تم حجز موعدك')
        ->toContain('قيد الانتظار')
        ->toContain('dir="rtl"');
});
