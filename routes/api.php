<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DoctorController;
use App\Http\Controllers\Api\PatientController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\DoctorScheduleController;
use App\Http\Controllers\Api\ContactMessageController;
use App\Http\Controllers\Api\QueueController;
use App\Http\Controllers\Api\PatientHistoryController;

/*
|--------------------------------------------------------------------------
| Public APIs
|--------------------------------------------------------------------------
*/

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Public Contact
Route::post('/contact-messages', [ContactMessageController::class, 'store']);

// Public Doctors
Route::apiResource('doctors', DoctorController::class)
    ->only(['index', 'show']);
  


// Public Doctor Schedules
Route::get(
    '/doctors/{doctor}/schedules',
    [DoctorScheduleController::class, 'doctorSchedules']
);

// Public Services
Route::apiResource('services', ServiceController::class)
    ->only(['index', 'show']);

// Public Reviews
Route::apiResource('reviews', ReviewController::class)
    ->only(['index', 'show']);

/*
|--------------------------------------------------------------------------
| Protected APIs
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {

    // Authentication
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Dashboard (Admin)
    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->middleware('admin');
     // Export Appointments
    Route::get(
      '/appointments/export',
      [AppointmentController::class, 'export']
     );

    // Queue
    Route::get('/queue/{doctor}', [QueueController::class, 'today']);

    // Patient Search
    Route::get('/patients/search', [PatientController::class, 'search']);

    // Appointment Status
    Route::patch(
        '/appointments/{appointment}/status',
        [AppointmentController::class, 'updateStatus']
    );
     Route::get(
     '/patients/{patient}/history',
      [PatientHistoryController::class, 'show']
    );
  

    // Resources
    Route::apiResource('patients', PatientController::class);

    Route::apiResource('appointments', AppointmentController::class);

    Route::apiResource('schedules', DoctorScheduleController::class);

    // Protected Reviews
    Route::apiResource('reviews', ReviewController::class)
        ->except(['index', 'show']);

    // Protected Doctors
    Route::apiResource('doctors', DoctorController::class)
        ->except(['index', 'show']);

    // Protected Services
    Route::apiResource('services', ServiceController::class)
        ->except(['index', 'show']);

    // Contact Messages
    Route::apiResource('contact-messages', ContactMessageController::class)
        ->except(['store']);
});