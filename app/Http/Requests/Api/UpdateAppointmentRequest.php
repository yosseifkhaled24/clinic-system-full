<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [

            'doctor_id' => 'sometimes|required|exists:doctors,id',

            'service_id' => 'sometimes|required|exists:services,id',

            'appointment_date' => 'sometimes|required|date|after_or_equal:today',

            'notes' => 'nullable|string|max:1000',

            'status' => 'sometimes|required|in:pending,arrived,in_progress,completed,cancelled,no_show',

            'payment_status' => 'sometimes|required|in:paid,unpaid',

            'payment_method' => 'nullable|in:cash,visa,insurance',

            'paid_at' => 'nullable|date',
        ];
    }
}