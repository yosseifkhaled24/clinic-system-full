<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\ContactMessage;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Review;
use App\Models\Service;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([

            'doctors_count' => Doctor::count(),

            'patients_count' => Patient::count(),

            'services_count' => Service::count(),

            'appointments_count' => Appointment::count(),

            'appointments_today' => Appointment::whereDate(
                'appointment_date',
                today()
            )->count(),

            'pending_count' => Appointment::where('status', 'pending')->count(),

            'completed_count' => Appointment::where('status', 'completed')->count(),

            'cancelled_count' => Appointment::where('status', 'cancelled')->count(),

            'reviews_count' => Review::count(),

            'messages_count' => ContactMessage::count(),

        ]);
    }
}