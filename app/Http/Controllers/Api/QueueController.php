<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;

class QueueController extends Controller
{
    public function today($doctorId): JsonResponse
    {
        $appointments = Appointment::with('patient')
            ->where('doctor_id', $doctorId)
            ->whereDate('appointment_date', Carbon::today())
            ->orderBy('queue_number')
            ->get();

        return response()->json([
            'current' => $appointments->firstWhere('status', 'in_progress'),

            'waiting' => $appointments->whereIn('status', [
                'pending',
                'arrived'
            ])->values(),

            'finished' => $appointments->whereIn('status', [
                'completed',
                'cancelled',
                'no_show'
            ])->values(),
        ]);
    }
}