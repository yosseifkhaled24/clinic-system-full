<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class SpecialtyFactory extends Factory
{
    public function definition(): array
    {
        return [
            // Unique two-word names: no collisions within a test run, so
            // a suite can create as many specialties as it needs while
            // staying clearly distinguishable from the real clinic list.
            'name' => ucfirst(fake()->unique()->words(2, true)),
        ];
    }
}
