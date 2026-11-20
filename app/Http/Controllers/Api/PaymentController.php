<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Services\StripeService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

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

        // SEC-06: only live appointments may enter checkout. Cancelled and
        // no-show bookings are rejected server-side regardless of what the
        // client sends; completed visits are settled via the admin cash
        // flow (same approved eligible set as Appointment::PAYABLE_STATUSES).
        if (! in_array($appointment->status, Appointment::PAYABLE_STATUSES, true)) {
            return response()->json([
                'message' => 'This appointment can no longer be paid online.',
                'code' => 'appointment_not_payable',
            ], 422);
        }

        // SEC-06: without the webhook secret no signed confirmation can
        // ever reach this server — refuse to take money we could not
        // confirm. Never invent or hardcode a secret here; the operator
        // must configure STRIPE_WEBHOOK_SECRET (documented in .env.example).
        if (! config('services.stripe.webhook_secret')) {
            return response()->json([
                'message' => 'Online payments are not configured. Please contact the clinic or pay in person.',
                'code' => 'payments_not_configured',
            ], 503);
        }

        $session = $stripe->createCheckoutSession([
            'appointment_id' => $appointment->id,

            'service_name' => $appointment->service->name,

            // Stripe يستقبل المبلغ بأصغر وحدة (قرش)
            'amount' => (int) ($appointment->service_price * 100),

            // SEC-06: session_id={CHECKOUT_SESSION_ID} lets the success
            // page query the REAL payment state from the backend instead
            // of trusting the redirect.
            'success_url' => config('services.stripe.frontend_url') . '/payment/success?session_id={CHECKOUT_SESSION_ID}',

            'cancel_url' => config('services.stripe.frontend_url') . '/payment/cancel',
        ]);

        return response()->json([
            'message' => 'Checkout session created successfully.',
            'checkout_url' => $session->url,
        ]);
    }

    /**
     * SEC-06: real payment status for a Stripe checkout session.
     *
     * Read-only, server-side, ownership-checked. This endpoint NEVER
     * marks an appointment as paid — only the signature-verified webhook
     * (or the admin cash flow) may do that, so a browser redirect can
     * never confirm payment by itself. It exists so the success page can
     * honestly display one of:
     *
     *   confirmed — the appointment is recorded as paid (webhook landed,
     *               possibly just now; delayed webhooks are handled by
     *               the pending state below until they arrive).
     *   pending   — Stripe has not reported the session as paid yet, OR
     *               Stripe reports paid but the signed webhook has not
     *               been processed yet (delayed delivery).
     *   failed    — the session expired / was not completed.
     */
    public function paymentStatus(Request $request, StripeService $stripe): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => ['required', 'string', 'max:255'],
        ]);

        if (! config('services.stripe.webhook_secret')) {
            return response()->json([
                'message' => 'Online payments are not configured.',
                'code' => 'payments_not_configured',
            ], 503);
        }

        try {
            $session = $stripe->getCheckoutSession($validated['session_id']);
        } catch (\Stripe\Exception\InvalidRequestException) {
            return response()->json([
                'message' => 'Payment session not found.',
            ], 404);
        } catch (\Throwable $e) {
            // Operational logging only — no session ids, keys or payload.
            Log::error('Stripe session lookup failed.', [
                'exception' => $e::class,
            ]);

            return response()->json([
                'message' => 'Unable to verify the payment status right now. Please try again shortly.',
            ], 503);
        }

        $appointmentId = $session->metadata->appointment_id ?? null;
        $appointment = $appointmentId ? Appointment::find($appointmentId) : null;

        if (! $appointment) {
            return response()->json([
                'message' => 'Payment session not found.',
            ], 404);
        }

        // Ownership server-side: the session must belong to the caller.
        if ($request->user()->role !== 'admin' && $appointment->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Forbidden',
            ], 403);
        }

        // Already recorded as paid (signed webhook or admin cash flow).
        if ($appointment->payment_status === 'paid') {
            return response()->json([
                'status' => 'confirmed',
                'appointment_id' => $appointment->id,
                'appointment_status' => $appointment->status,
                'payment_method' => $appointment->payment_method,
            ]);
        }

        // Stripe took the money but the signed webhook has not landed
        // yet (delayed delivery). Honest "pending": the redirect alone
        // must never confirm payment.
        if (($session->payment_status ?? null) === 'paid') {
            return response()->json([
                'status' => 'pending',
                'appointment_id' => $appointment->id,
                'appointment_status' => $appointment->status,
                'note' => 'Payment received; confirmation is still processing.',
            ]);
        }

        // An expired session can no longer be completed.
        if (($session->status ?? null) === 'expired') {
            return response()->json([
                'status' => 'failed',
                'appointment_id' => $appointment->id,
                'appointment_status' => $appointment->status,
            ]);
        }

        // Still open / not completed at Stripe.
        return response()->json([
            'status' => 'pending',
            'appointment_id' => $appointment->id,
            'appointment_status' => $appointment->status,
        ]);
    }
}