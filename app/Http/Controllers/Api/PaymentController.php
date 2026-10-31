<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Services\StripeService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class PaymentController extends Controller
{
    public function checkout(Request $request, StripeService $stripe): JsonResponse
    {
        $request->validate([
            'appointment_id' => 'required|exists:appointments,id',
        ]);

        $appointment = Appointment::with('service')
            ->findOrFail($request->appointment_id);

        // التأكد أن المستخدم يملك الحجز (إلا إذا كان Admin)
        if (
            $request->user()->role !== 'admin' &&
            $appointment->user_id !== $request->user()->id
        ) {
            return response()->json([
                'message' => 'Forbidden',
            ], 403);
        }

        // منع الدفع مرتين
        if ($appointment->payment_status === 'paid') {
            return response()->json([
                'message' => 'This appointment has already been paid.',
            ], 400);
        }

        $session = $stripe->createCheckoutSession([
            'appointment_id' => $appointment->id,

            'service_name' => $appointment->service->name,

            // Stripe يستقبل المبلغ بأصغر وحدة (قرش)
            'amount' => (int) ($appointment->service_price * 100),

            'success_url' => config('services.stripe.frontend_url') . '/payment/success',

            'cancel_url' => config('services.stripe.frontend_url') . '/payment/cancel',
        ]);

        return response()->json([
            'message' => 'Checkout session created successfully.',
            'checkout_url' => $session->url,
        ]);
    }
}