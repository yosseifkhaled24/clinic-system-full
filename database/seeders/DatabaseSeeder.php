<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\ContactMessage;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\Patient;
use App\Models\Review;
use App\Models\Service;
use App\Models\Specialty;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Admin
        |--------------------------------------------------------------------------
        */

        User::create([
            'name' => 'Admin',
            'email' => 'admin@test.com',
            'phone' => '01000000000',
            'password' => bcrypt('12345678'),
            'role' => 'admin',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Users
        |--------------------------------------------------------------------------
        */

        $users = User::factory(30)->create();

        /*
        |--------------------------------------------------------------------------
        | Specialties + Doctors
        |--------------------------------------------------------------------------
        */

        // Specialties are seeded FIRST from their own idempotent seeder,
        // then each doctor is assigned a REAL specialty record by id
        // (deterministic round-robin — no random strings, no hardcoded
        // IDs). The Doctor::saving() hook mirrors each linked specialty's
        // canonical name into doctors.specialty.
        $this->call(SpecialtySeeder::class);

        $specialties = Specialty::whereIn('name', SpecialtySeeder::NAMES)
            ->orderBy('id')
            ->get();

        $doctors = $specialties
            ->map(fn (Specialty $specialty) => Doctor::factory()->create([
                'specialty_id' => $specialty->id,
            ]))
            ->collect();

        $images = File::files(database_path('seed-images/doctors'));

        foreach ($doctors as $doctor) {

            $image = fake()->randomElement($images);

            $doctor
                ->addMedia($image->getPathname())
                ->preservingOriginal()
                ->toMediaCollection('doctors');
        }

        /*
        |--------------------------------------------------------------------------
        | Services
        |--------------------------------------------------------------------------
        */

        $services = Service::factory(8)->create();

        /*
        |--------------------------------------------------------------------------
        | Patients
        |--------------------------------------------------------------------------
        */

        foreach ($users as $user) {

            Patient::create([
                'user_id'       => $user->id,
                'name'          => $user->name,
                'phone'         => $user->phone,
                'email'         => $user->email,
                'gender'        => fake()->randomElement(['male', 'female']),
                'date_of_birth' => fake()->date(),
                'address'       => fake()->address(),
                'notes'         => fake()->sentence(),
            ]);
        }

        $patients = Patient::all();

        /*
        |--------------------------------------------------------------------------
        | Doctor Schedules
        |--------------------------------------------------------------------------
        */

        $days = [
            'Saturday',
            'Sunday',
            'Monday',
            'Tuesday',
            'Wednesday',
            'Thursday',
        ];

        foreach ($doctors as $doctor) {

            foreach ($days as $day) {

                $start = fake()->randomElement([
                    '08:00:00',
                    '09:00:00',
                    '10:00:00',
                ]);

                $end = match ($start) {
                    '08:00:00' => '16:00:00',
                    '09:00:00' => '17:00:00',
                    default => '18:00:00',
                };

                DoctorSchedule::create([
                    'doctor_id' => $doctor->id,
                    'day' => $day,
                    'start_time' => $start,
                    'end_time' => $end,
                    'is_available' => true,
                    'max_patients' => fake()->numberBetween(20, 40),
                ]);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Contact Messages
        |--------------------------------------------------------------------------
        */

        ContactMessage::factory(15)->create();

        /*
        |--------------------------------------------------------------------------
        | Appointments
        |--------------------------------------------------------------------------
        */
                $appointments = collect();

        foreach ($patients as $patient) {

            $count = fake()->numberBetween(1, 5);

            for ($i = 0; $i < $count; $i++) {

                $doctor = $doctors->random();
                $service = $services->random();

                $date = Carbon::parse(
                    fake()->dateTimeBetween('-30 days', '+30 days')
                )->format('Y-m-d');

                $queueNumber = Appointment::where('doctor_id', $doctor->id)
                    ->whereDate('appointment_date', $date)
                    ->count() + 1;

                $status = fake()->randomElement([
                    'pending',
                    'arrived',
                    'in_progress',
                    'completed',
                    'cancelled',
                ]);

                $paymentStatus = fake()->randomElement([
                    'paid',
                    'unpaid',
                ]);

                $appointment = Appointment::create([

                    'patient_id' => $patient->id,

                    'user_id' => $patient->user_id,

                    'doctor_id' => $doctor->id,

                    'service_id' => $service->id,

                    'appointment_date' => $date,

                    'queue_number' => $queueNumber,

                    'service_price' => $service->price,

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

                    'status' => $status,

                    'notes' => fake()->boolean(70)
                        ? fake()->sentence()
                        : null,
                ]);

                $appointments->push($appointment);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Reviews
        |--------------------------------------------------------------------------
        */

        foreach ($appointments as $appointment) {

            if ($appointment->status !== 'completed') {
                continue;
            }

            $doctor = $doctors->firstWhere('id', $appointment->doctor_id);

            if (!$doctor || !$doctor->allow_reviews) {
                continue;
            }

            Review::create([

                'user_id' => $appointment->user_id,

                'doctor_id' => $appointment->doctor_id,

                'appointment_id' => $appointment->id,

                'rating' => fake()->numberBetween(3, 5),

                'comment' => fake()->randomElement([
                    'Excellent service.',
                    'Very professional doctor.',
                    'Highly recommended.',
                    'Friendly staff.',
                    'Everything went smoothly.',
                    'Great experience.',
                ]),
            ]);
        }
    }
}