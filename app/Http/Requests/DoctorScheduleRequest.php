<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DoctorScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
          {
             return [

                        'doctor_id' => 'required|exists:doctors,id',

                        'day' => [
                            'required',
                            Rule::in([
                                'Saturday',
                                'Sunday',
                                'Monday',
                                'Tuesday',
                                'Wednesday',
                                'Thursday',
                                'Friday',
                            ]),
                            Rule::unique('doctor_schedules')
                                ->where(function ($query) {
                                    return $query->where('doctor_id', $this->doctor_id);
                                })
                                ->ignore($this->schedule),
                        ],

                        'start_time' => 'required|date_format:H:i',

                        'end_time' => 'required|date_format:H:i|after:start_time',

                        'is_available' => 'required|boolean',

                        'max_patients' => 'required|integer|min:1|max:500',
          ];
         }
}