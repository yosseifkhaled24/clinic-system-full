<?php

namespace Database\Factories;

use App\Models\Doctor;
use Illuminate\Database\Eloquent\Factories\Factory;

class DoctorScheduleFactory extends Factory
{
    public function definition(): array
    {
        static $days = [
            'Saturday',
            'Sunday',
            'Monday',
            'Tuesday',
            'Wednesday',
            'Thursday'
        ];

        return [
            'doctor_id' => Doctor::factory(),
            'day' => fake()->randomElement($days),
            'is_available' => true,
            'max_patients' => fake()->numberBetween(20, 40),
        ];
    }
}