<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PatientHistoryController extends Controller
{
    public function show(Request $request, Patient $patient): JsonResponse
    {
        $user = $request->user();

        if ($user->role !== 'admin' && $patient->user_id !== $user->id) {
            return response()->json([
                'message' => 'Forbidden',
            ], 403);
        }

        $appointments = $patient->appointments()
            ->with([
                'doctor',
                'service',
                'review'
            ])
            ->latest('appointment_date')
            ->get();

        $history = $appointments->map(function ($appointment) {
            return [
                'appointment_id' => $appointment->id,

                'date' => $appointment->appointment_date?->toDateString(),

                'queue_number' => $appointment->queue_number,

                'doctor' => [
                    'id' => $appointment->doctor?->id,
                    'name' => $appointment->doctor?->name,
                    'specialty' => $appointment->doctor?->specialty,
                ],

                'service' => [
                    'id' => $appointment->service?->id,
                    'name' => $appointment->service?->name,
                ],

                'price' => $appointment->service_price,

                'payment' => [
                    'status' => $appointment->payment_status,
                    'method' => $appointment->payment_method,
                    'paid_at' => $appointment->paid_at,
                ],

                'appointment_status' => $appointment->status,

                'review' => $appointment->review
                    ? [
                        'rating' => $appointment->review->rating,
                        'comment' => $appointment->review->comment,
                    ]
                    : null,

                'notes' => $appointment->notes,
            ];
        });

        return response()->json([

            'patient' => [
                'id' => $patient->id,
                'name' => $patient->name,
                'phone' => $patient->phone,
                'email' => $patient->email,
                'gender' => $patient->gender,
                'date_of_birth' => $patient->date_of_birth,
                'address' => $patient->address,
            ],

            'statistics' => [
                'total_visits' => $appointments->count(),

                'completed_visits' => $appointments
                    ->where('status', 'completed')
                    ->count(),

                'cancelled_visits' => $appointments
                    ->where('status', 'cancelled')
                    ->count(),

                'no_show_visits' => $appointments
                    ->where('status', 'no_show')
                    ->count(),

                'total_paid' => $appointments
                    ->where('payment_status', 'paid')
                    ->sum('service_price'),

                'last_visit' => optional(
                    $appointments->first()
                )?->appointment_date?->toDateString(),
            ],

            'history' => $history

        ]);
    }
}