<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ReviewFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'doctor_id' => Doctor::factory(),
            'appointment_id' => Appointment::factory(),
            'rating' => fake()->numberBetween(3,5),
            'comment' => fake()->sentence(),
        ];
    }
}