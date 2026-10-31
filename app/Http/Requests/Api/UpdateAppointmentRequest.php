<?php

namespace App\Http\Requests\Api;

use App\Models\Appointment;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isAdmin = $this->user()->role === 'admin';

        // The forward-only rule (after_or_equal:today) applies ONLY when
        // the date is actually changing. An unchanged past date lets an
        // admin edit notes/status on historical appointments without
        // tripping a rule meant for new bookings.
        $appointment = $this->route('appointment');
        $dateUnchanged = $appointment instanceof Appointment
            && $this->filled('appointment_date')
            && Carbon::parse($this->input('appointment_date'))->toDateString()
                === $appointment->appointment_date->toDateString();

        $adminDateRule = $dateUnchanged
            ? 'sometimes|required|date'
            : 'sometimes|required|date|after_or_equal:today';

        return [

            // D4: only admins may change the doctor. Non-admins editing
            // their own appointment (notes) get 422 if they send
            // doctor_id at all — the controller never sees it.
            'doctor_id' => $isAdmin
                ? 'sometimes|required|exists:doctors,id'
                : 'prohibited',

            'service_id' => 'sometimes|required|exists:services,id',

            // D4: only admins may change the date. For admins the
            // forward-only rule still blocks booking into the past, while
            // leaving an unchanged date untouched lets admins edit
            // past-dated appointments (notes, status, backfills) freely.
            'appointment_date' => $isAdmin
                ? $adminDateRule
                : 'prohibited',

            'notes' => 'nullable|string|max:1000',

            // Status changes through the generic update are admin-only
            // (staff). Non-admins get 422 if they try; patients cancel
            // their own appointments via DELETE, which is guarded in
            // the controller.
            'status' => $isAdmin
                ? 'sometimes|required|in:pending,arrived,in_progress,completed,cancelled,no_show'
                : 'prohibited',

            // payment_status / payment_method / paid_at are intentionally
            // absent: payment may only be confirmed through the dedicated
            // admin PATCH /appointments/{id}/payment endpoint or the
            // signature-verified Stripe webhook. Client-sent values for
            // these keys are ignored (not updated).
        ];
    }
}