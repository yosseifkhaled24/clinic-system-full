<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreAppointmentRequest;
use App\Http\Requests\Api\UpdateAppointmentRequest;
use App\Models\Appointment;
use App\Models\DoctorSchedule;
use App\Models\Patient;
use App\Models\Service;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Exports\TodayAppointmentsExport;
use Maatwebsite\Excel\Facades\Excel;

class AppointmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Appointment::with([ 'doctor','patient','service',]);
        if ($request->user()->role !== 'admin') {
            $query->where('user_id', $request->user()->id);
        }

        $appointments = $query
            ->latest()
            ->get()
            ->map(function (Appointment $appointment) {
                return $this->formatAppointment($appointment);
            });

        return response()->json($appointments);
    }

    public function show(Request $request, Appointment $appointment): JsonResponse
    {
        if (
            $request->user()->role !== 'admin' &&
            $appointment->user_id !== $request->user()->id
        ) {
            return response()->json([
                'message' => 'Forbidden'
            ], 403);
        }

        return response()->json(
            $this->formatAppointment(
              $appointment->load(['doctor', 'patient', 'service',])
            )
        );
    }

    public function store(StoreAppointmentRequest $request): JsonResponse
    {
        $user = $request->user();
        $service = Service::findOrFail($request->service_id);

        if ($user->role === 'admin') {

            if ($request->filled('patient_id')) {

                $patient = Patient::findOrFail($request->patient_id);

            } else {

                $patient = Patient::firstOrCreate(

                    [
                        'phone' => $request->phone,
                    ],

                    [
                        'name' => $request->name,
                        'email' => $request->email,
                        'gender' => $request->gender,
                    ]

                );
            }

        } else {

            $patient = $user->patient;

            if (!$patient) {

                $patient = Patient::create([
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                ]);
            }
        }

        $day = Carbon::parse($request->appointment_date)->format('l');

        $schedule = DoctorSchedule::where('doctor_id', $request->doctor_id)
            ->where('day', $day)
            ->where('is_available', true)
            ->first();

        if (!$schedule) {
            return response()->json([
                'message' => 'Doctor is not available on this day.'
            ], 422);
        }

        $alreadyBooked = Appointment::where('patient_id', $patient->id)
            ->where('doctor_id', $request->doctor_id)
            ->whereDate('appointment_date', $request->appointment_date)
            ->exists();

        if ($alreadyBooked) {
            return response()->json([
                'message' => 'Patient already booked this doctor on this day.'
            ], 422);
        }

        $todayCount = Appointment::where('doctor_id', $request->doctor_id)
            ->whereDate('appointment_date', $request->appointment_date)
            ->count();

        if ($todayCount >= $schedule->max_patients) {
            return response()->json([
                'message' => 'This day is fully booked.'
            ], 422);
        }

        $queueNumber = Appointment::where('doctor_id', $request->doctor_id)
            ->whereDate('appointment_date', $request->appointment_date)
            ->max('queue_number');

        $queueNumber = $queueNumber ? $queueNumber + 1 : 1;

        $appointment = Appointment::create([
    'patient_id' => $patient->id,

    'user_id' => $user->role === 'admin'
        ? $patient->user_id
        : $user->id,

    'doctor_id' => $request->doctor_id,

    'service_id' => $service->id,

    'appointment_date' => $request->appointment_date,

    'queue_number' => $queueNumber,

    'service_price' => $service->price,

    'payment_status' => 'unpaid',

    'payment_method' => null,

    'paid_at' => null,

    'status' => 'pending',

    'notes' => $request->notes,
]);

        return response()->json([
            'message' => 'Appointment booked successfully.',
            'queue_number' => $queueNumber,
           'data' => $this->formatAppointment(
    $appointment->load([
        'doctor',
        'patient',
        'service',
    ])
),  
            $request->user()->role,
        ], 201);
    }
        public function update(UpdateAppointmentRequest $request, Appointment $appointment): JsonResponse
    {
       if (
           $request->user()->role !== 'admin' &&
           $appointment->user_id !== $request->user()->id
        ) {
            return response()->json([
                'message' => 'Forbidden'
            ], 403);
        }

        $appointment->update($request->validated());

        return response()->json(
            $this->formatAppointment(
                $appointment->refresh()->load([
    'doctor',
    'patient',
    'service',
])
            )
        );
    }

    public function updateStatus(Request $request, Appointment $appointment): JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|in:pending,arrived,in_progress,completed,cancelled,no_show',
        ]);

        $appointment->update([
            'status' => $data['status'],
        ]);

        return response()->json([
            'message' => 'Appointment status updated successfully.',
            'data' => $this->formatAppointment(
               $appointment->refresh()->load([
    'doctor',
    'patient',
    'service',
])
            ),
        ]);
    }

    public function destroy(Request $request, Appointment $appointment): JsonResponse
    {
        if (
            $request->user()->role !== 'admin' &&
            $appointment->user_id !== $request->user()->id
        ) {
            return response()->json([
                'message' => 'Forbidden'
            ], 403);
        }

        $appointment->update([
            'status' => 'cancelled'
        ]);

        return response()->json([
            'message' => 'Appointment cancelled successfully.'
        ]);
    }

    private function formatAppointment(Appointment $appointment): array
    {
        return [
            'id' => $appointment->id,

            'doctor' => [
                'id' => $appointment->doctor->id,
                'name' => $appointment->doctor->name,
                'specialty' => $appointment->doctor->specialty,
            ],
               'service' => [
                'id' => $appointment->service->id,
                'name' => $appointment->service->name,
                'price' => $appointment->service_price,
          ],
          'payment' => [
    'status' => $appointment->payment_status,
    'method' => $appointment->payment_method,
    'paid_at' => $appointment->paid_at,
],

            'patient' => [
                'id' => $appointment->patient->id,
                'name' => $appointment->patient->name,
                'phone' => $appointment->patient->phone,
                'email' => $appointment->patient->email,
            ],

            'queue_number' => $appointment->queue_number,

            'appointment_date' => $appointment->appointment_date,

            'status' => $appointment->status,

            'notes' => $appointment->notes,

            'created_at' => $appointment->created_at?->toDateTimeString(),

            'updated_at' => $appointment->updated_at?->toDateTimeString(),
        ];
    }
    public function export(Request $request)
 {
    return Excel::download(
        new TodayAppointmentsExport(
            $request->from,
            $request->to
        ),
        'appointments.xlsx'
    );
 }
}