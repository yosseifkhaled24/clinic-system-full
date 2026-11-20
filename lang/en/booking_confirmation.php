<?php

/*
 |--------------------------------------------------------------------------
 | Booking confirmation e-mail (English)
 |--------------------------------------------------------------------------
 |
 | Used by App\Mail\BookingConfirmationMail. Keys are read through
 | trans('booking_confirmation', [], $locale), so every string the
 | patient sees lives here instead of inside the mailable.
 |
 | :name / :clinic are replaced at render time (patient name and
 | config('app.name')). The `statuses` map mirrors the appointment
 | status enum; an unknown status falls back to its raw value.
 |
 */

return [
    'subject' => 'Your appointment has been booked',

    'greeting' => 'Hello :name,',

    'intro' => 'Your appointment has been confirmed. Here are the details:',

    'patient' => 'Patient',
    'doctor' => 'Doctor',
    'service' => 'Service',
    'date' => 'Date',
    'status' => 'Status',

    'closing' => 'Please arrive a few minutes early. If you need to change or cancel this appointment, contact the clinic.',

    'footer' => 'This is an automated message from :clinic. No reply is needed.',

    'statuses' => [
        'pending' => 'Pending',
        'arrived' => 'Arrived',
        'in_progress' => 'In progress',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        'no_show' => 'No show',
    ],
];
