<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [

            'doctor_id' => 'required|exists:doctors,id',

            'service_id' => 'required|exists:services,id',

            'appointment_date' => 'required|date|after_or_equal:today',

            'notes' => 'nullable|string|max:1000',

            'patient_id' => 'nullable|exists:patients,id',

            'email' => 'nullable|email',

            'gender' => 'nullable|in:male,female',
        ];

        if ($this->user()->role === 'admin') {

            $rules['name'] = 'required_without:patient_id|string|max:255';

            $rules['phone'] = 'required_without:patient_id|string|max:20';

        } else {

            $rules['name'] = 'nullable|string|max:255';

            $rules['phone'] = 'nullable|string|max:20';
        }

        return $rules;
    }
}