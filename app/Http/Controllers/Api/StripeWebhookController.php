<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use Illuminate\Http\Request;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

class StripeWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $secret = config('services.stripe.webhook_secret');

        if (!$secret) {

            return response()->json([
                'message' => 'Webhook secret is not configured.'
            ], 500);
        }

        $payload = $request->getContent();

        $signature = $request->header('Stripe-Signature');

        try {

            $event = Webhook::constructEvent(
                $payload,
                $signature,
                $secret
            );

        } catch (SignatureVerificationException $e) {

            return response()->json([
                'message' => 'Invalid signature.'
            ], 400);

        } catch (\Exception $e) {

            return response()->json([
                'message' => 'Webhook Error.'
            ], 400);
        }

        if ($event->type !== 'checkout.session.completed') {

            return response()->json([
                'received' => true,
            ]);
        }

        $session = $event->data->object;

        // Only confirm sessions Stripe reports as actually paid
        // (async payment methods can complete a session before the
        // money settles).
        if (($session->payment_status ?? null) !== 'paid') {

            return response()->json([
                'received' => true,
            ]);
        }

        $appointment = Appointment::find(
            $session->metadata->appointment_id ?? null
        );

        // Orphan metadata: acknowledge so Stripe does not retry forever.
        if (!$appointment) {

            return response()->json([
                'received' => true,
            ]);
        }

        // The charge must match what this appointment owes, in the
        // currency the checkout session was created with.
        $expectedAmount = (int) round($appointment->service_price * 100);
        $currency = strtolower((string) ($session->currency ?? ''));

        if ((int) ($session->amount_total ?? 0) !== $expectedAmount
            || $currency !== 'egp') {

            return response()->json([
                'message' => 'Payment amount or currency mismatch.'
            ], 400);
        }

        // Idempotency: a replayed delivery, or an appointment already
        // confirmed through the admin cash flow, must not be reprocessed.
        if ($appointment->payment_status === 'paid') {

            return response()->json([
                'received' => true,
            ]);
        }

        // D2: the checkout session is created by this app with
        // payment_method_types => ['card'], so a completed, signature-
        // verified session is definitionally a Stripe card payment. The
        // provenance is the signed session config — not an inference from
        // payment_status — so 'stripe' is trustworthy. paid_at is set once
        // and never rewritten by replayed deliveries (idempotency guard
        // above).
        $appointment->update([
            'payment_status' => 'paid',
            'payment_method' => 'stripe',
            'paid_at' => now(),
        ]);

        return response()->json([
            'received' => true,
        ]);
    }
}