<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ServiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement([
                'كشف باطنة',
                'كشف قلب',
                'كشف أطفال',
                'كشف جلدية',
                'كشف أسنان',
                'استشارة',
                'جلسة متابعة',
                'تحاليل'
            ]),
            'description' => fake()->sentence(),
            'price' => fake()->numberBetween(150, 1000),
        ];
    }
}