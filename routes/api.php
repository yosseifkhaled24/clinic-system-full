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
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\StripeWebhookController;
use App\Http\Controllers\Api\ChatbotController;
use App\Http\Controllers\Api\SpecialtyController;

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

// Public doctor availability (smart booking): per-day working/capacity
// report for a date range. Same public read-only status as the schedules
// endpoint above; the booking write path stays the final authority.
Route::get(
    '/doctors/{doctor}/availability',
    [AppointmentController::class, 'availability']
);

// Public Services
Route::apiResource('services', ServiceController::class)
    ->only(['index', 'show']);

// Public Specialties (read-only reference list; writes are admin-only
// below). Same public/protected split as doctors and services.
Route::apiResource('specialties', SpecialtyController::class)
    ->only(['index', 'show']);

// Public Reviews
Route::apiResource('reviews', ReviewController::class)
    ->only(['index', 'show']);

// Public AI chatbot (Gemini clinic assistant).
// Throttled like the other public write endpoints (contact = unthrottled,
// checkout = 20/min, webhook = 60/min): 20 messages/minute per user/IP.
Route::post('/chatbot', [ChatbotController::class, 'store'])
    ->middleware('throttle:20,1');

// Batch 8: throttle the webhook as defense in depth behind the
// Stripe signature verification (Stripe retries stay far below this).
Route::post(
    '/stripe/webhook',
    [StripeWebhookController::class, 'handle']
)->middleware('throttle:60,1');

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
     )->middleware('admin');

    // E: daily revenue export (completed + paid only, server-computed
    // totals + per-method breakdown). Three segments, so it can never
    // collide with the /appointments/{appointment} resource below.
    Route::get(
        '/appointments/revenue/export',
        [AppointmentController::class, 'exportRevenue']
    )->middleware('admin');

    // Queue
    // All-doctors overview for the admin queue control center. Defined
    // before /queue/{doctor} (one segment vs two — no route conflict);
    // the per-doctor endpoint keeps its existing {current, waiting,
    // finished} contract untouched.
    Route::get('/queue', [QueueController::class, 'all'])
        ->middleware('admin');
    Route::get('/queue/{doctor}', [QueueController::class, 'today'])
        ->middleware('admin');

    // Patient Search
    Route::get('/patients/search', [PatientController::class, 'search'])
        ->middleware('admin');
    
    // Appointment Status
    Route::patch(
        '/appointments/{appointment}/status',
        [AppointmentController::class, 'updateStatus']
    )->middleware('admin');
    Route::patch(
    '/appointments/{appointment}/payment',
    [AppointmentController::class, 'updatePayment']
)->middleware('admin');
    // A/B: atomic complete + payment (single transaction, no duplicate
    // payment, no repeated completion). Same admin guard as status/payment.
    Route::patch(
        '/appointments/{appointment}/complete',
        [AppointmentController::class, 'complete']
    )->middleware('admin');
     Route::get(
     '/patients/{patient}/history',
      [PatientHistoryController::class, 'show']
    );
  

    // Resources
    Route::apiResource('patients', PatientController::class)
        ->except(['store'])
        ->middleware('admin');
    // Batch 8: throttle checkout per user to prevent Stripe session spam.
    Route::post(
      '/payments/checkout',
      [PaymentController::class, 'checkout']
    )->middleware('throttle:20,1');
    Route::apiResource('appointments', AppointmentController::class);

    // Schedule reads are admin-only (consumed exclusively by the admin
    // dashboard; the public site uses /doctors/{doctor}/schedules).
    Route::apiResource('schedules', DoctorScheduleController::class)
        ->only(['store', 'update', 'destroy'])
        ->middleware('admin');
    Route::apiResource('schedules', DoctorScheduleController::class)
        ->only(['index', 'show'])
        ->middleware('admin');

    // Protected Reviews
    Route::apiResource('reviews', ReviewController::class)
        ->except(['index', 'show']);

    // Protected Doctors
    Route::apiResource('doctors', DoctorController::class)
        ->except(['index', 'show'])
        ->middleware('admin');

    // Protected Specialties (admin CRUD)
    Route::apiResource('specialties', SpecialtyController::class)
        ->except(['index', 'show'])
        ->middleware('admin');

    // Protected Services
    Route::apiResource('services', ServiceController::class)
        ->except(['index', 'show'])
        ->middleware('admin');

    // Contact Messages
    Route::apiResource('contact-messages', ContactMessageController::class)
        ->only(['index', 'show', 'destroy'])
        ->middleware('admin');
});