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
        $payload = $request->getContent();

        $signature = $request->header('Stripe-Signature');

        try {

            $event = Webhook::constructEvent(
                $payload,
                $signature,
                env('STRIPE_WEBHOOK_SECRET')
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

        if ($event->type === 'checkout.session.completed') {

            $session = $event->data->object;

            $appointment = Appointment::find(
                $session->metadata->appointment_id
            );

            if ($appointment) {

                $appointment->update([
                    'payment_status' => 'paid',
                    'payment_method' => 'card',
                    'paid_at' => now(),
                ]);
            }
        }

        return response()->json([
            'received' => true,
        ]);
    }
}