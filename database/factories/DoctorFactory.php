<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class DoctorFactory extends Factory
{
    /**
     * Stable pool of demo specialties.
     *
     * The factory only sets the legacy `specialty` STRING — the
     * Doctor::saving() hook resolves it against the real specialties
     * table (case-insensitive find-or-create) and links the row, so every
     * factory-created doctor is associated with a valid specialty record
     * by default. Resolving lazily in the hook (instead of eagerly here)
     * matters: definition() runs BEFORE overrides are applied, so an
     * eager lookup would create phantom specialty rows whenever a test
     * overrides `specialty` with its own value.
     *
     * Override behavior stays predictable and always consistent:
     *  - ['specialty' => 'X']    → the hook links to X (creating it if new).
     *  - ['specialty_id' => $id] → the hook wins by rule order and mirrors
     *    the linked row's canonical name into the string column, so the
     *    pair can never be inconsistent.
     */
    private const SPECIALTY_POOL = [
        'Cardiology',
        'Dermatology',
        'Dentistry',
        'Pediatrics',
        'Orthopedics',
        'Neurology',
    ];

    public function definition(): array
    {
        return [
            'name' => 'د. ' . fake()->name(),
            'specialty' => fake()->randomElement(self::SPECIALTY_POOL),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '011' . fake()->numerify('#######'),
            'bio' => fake()->paragraph(),
            'allow_reviews' => true,
        ];
    }
}
