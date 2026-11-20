<?php

namespace App\Mail;

use App\Models\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Booking confirmation e-mail (Part B).
 *
 * Sent by AppointmentController::store() ONLY after the booking
 * transaction has committed, and only for patient-initiated bookings —
 * never from an admin back-office create, and never from update(),
 * status or payment changes (those are "unrelated updates").
 *
 * Rendering is fully data-driven:
 *  - every user-visible string comes from lang/{en,ar}/booking_confirmation.php
 *    selected by the request's Accept-Language (English fallback),
 *  - the clinic name comes from config('app.name') — never hardcoded,
 *  - the service row is omitted entirely when the booking has no
 *    service ("if available"),
 *  - no patient phone, address, notes or payment data is included.
 *
 * The mailable is sent synchronously with Mail::to()->send(); delivery
 * failures are caught by the caller so a broken SMTP connection can
 * never roll back a booking that already succeeded.
 */
class BookingConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    /** Only these two locales are shipped; anything else falls back to English. */
    private const SUPPORTED_LOCALES = ['en', 'ar'];

    /**
     * `$locale` is INHERITED from Illuminate\Mail\Mailable (it is the
     * property `withLocale()` reads while rendering), so it is assigned
     * here instead of being re-declared — re-declaring it would be a
     * property-compatibility fatal against the untyped parent.
     */
    public function __construct(
        public Appointment $appointment,
        string $locale = 'en',
    ) {
        $this->locale = in_array($locale, self::SUPPORTED_LOCALES, true)
            ? $locale
            : 'en';
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->string('subject'),
        );
    }

    public function content(): Content
    {
        $strings = $this->strings();

        $patientName = (string) ($this->appointment->patient?->name ?? '');
        $doctorName = (string) ($this->appointment->doctor?->name ?? '');
        $serviceName = $this->appointment->service?->name;

        $rows = [
            $strings['patient'] => $patientName,
            $strings['doctor'] => $doctorName,
        ];

        // "Service name, if available" — the row simply disappears when
        // the booking carries no service relation.
        if ($serviceName !== null && $serviceName !== '') {
            $rows[$strings['service']] = (string) $serviceName;
        }

        $rows[$strings['date']] = $this->formatDate();
        $rows[$strings['status']] = $this->statusLabel();

        return new Content(
            view: 'mail.booking-confirmation',
            with: [
                'locale' => $this->locale,
                'strings' => $strings,
                'clinicName' => (string) config('app.name'),
                'greeting' => str_replace(
                    ':name',
                    $patientName,
                    (string) $strings['greeting']
                ),
                'rows' => $rows,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function strings(): array
    {
        $lines = trans('booking_confirmation', [], $this->locale);

        if (! is_array($lines)) {
            // Locale file missing/unreadable → English, never a raw key.
            $lines = trans('booking_confirmation', [], 'en');
        }

        if (! is_array($lines)) {
            $lines = [];
        }

        $defaults = [
            'subject' => 'Your appointment has been booked',
            'greeting' => 'Hello :name,',
            'intro' => 'Your appointment has been confirmed. Here are the details:',
            'patient' => 'Patient',
            'doctor' => 'Doctor',
            'service' => 'Service',
            'date' => 'Date',
            'status' => 'Status',
            'closing' => '',
            'footer' => '',
            'statuses' => [],
        ];

        // A key missing from one locale must not blank the e-mail.
        $lines = array_replace($defaults, $lines);

        if (! is_array($lines['statuses'])) {
            $lines['statuses'] = [];
        }

        return $lines;
    }

    private function string(string $key): string
    {
        return (string) ($this->strings()[$key] ?? '');
    }

    /**
     * Calendar date rendered in the e-mail's own language: Carbon ships
     * full Arabic month/weekday names, so `ar` gets a native date rather
     * than an English one behind Arabic labels.
     */
    private function formatDate(): string
    {
        $date = $this->appointment->appointment_date;

        if ($date === null) {
            return '';
        }

        return $date
            ->copy()
            ->locale($this->locale)
            ->translatedFormat('l, j F Y');
    }

    private function statusLabel(): string
    {
        $status = (string) $this->appointment->status;

        $statuses = $this->strings()['statuses'];

        return (string) ($statuses[$status] ?? $status);
    }
}
