<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

class AppointmentFactory extends Factory
{
    protected $model = Appointment::class;

    public function definition(): array
    {
        $service = Service::factory();

        $paymentStatus = fake()->randomElement([
            'paid',
            'unpaid',
        ]);

        return [

            'patient_id' => Patient::factory(),

            'user_id' => function (array $attributes) {
                return Patient::find($attributes['patient_id'])->user_id;
            },

            'doctor_id' => Doctor::factory(),

            'service_id' => $service,

            'service_price' => fake()->numberBetween(150, 1000),

            'payment_status' => $paymentStatus,

            'payment_method' => $paymentStatus === 'paid'
                ? fake()->randomElement([
                    'cash',
                    'visa',
                    'insurance',
                ])
                : null,

            'paid_at' => $paymentStatus === 'paid'
                ? fake()->dateTimeBetween('-30 days', 'now')
                : null,

            'appointment_date' => fake()
                ->dateTimeBetween('-20 days', '+20 days')
                ->format('Y-m-d'),

            'queue_number' => fake()->numberBetween(1, 30),

            'status' => fake()->randomElement([
                'pending',
                'arrived',
                'in_progress',
                'completed',
                'cancelled',
            ]),

            'notes' => fake()->boolean(70)
                ? fake()->sentence()
                : null,
        ];
    }
}