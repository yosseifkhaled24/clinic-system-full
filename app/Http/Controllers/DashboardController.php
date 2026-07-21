<?php

namespace App\Http\Controllers;

use App\Models\Doctor;
use App\Models\Service;
use App\Models\Appointment;
use App\Models\Review;
use App\Models\ContactMessage;

class DashboardController extends Controller
{
    public function index()
    {
        return view('admin.dashboard', [
            'doctorsCount' => Doctor::count(),
            'servicesCount' => Service::count(),
            'appointmentsCount' => Appointment::count(),
            'reviewsCount' => Review::count(),
            'messagesCount' => ContactMessage::count(),
        ]);
    }
}