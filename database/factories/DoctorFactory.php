<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class DoctorFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'د. ' . fake()->name(),
            'specialty' => fake()->randomElement([
                'Cardiology',
                'Dermatology',
                'Dentistry',
                'Pediatrics',
                'Orthopedics',
                'Neurology',
            ]),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '011' . fake()->numerify('#######'),
            'bio' => fake()->paragraph(),
            'allow_reviews' => true,
        ];
    }
}