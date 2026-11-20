<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

class StripeWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $secret = config('services.stripe.webhook_secret');

        if (!$secret) {

            // SEC-06: fail safely (503, not a stack-trace 500) and say
            // plainly that online payments are not configured. Signature
            // validation is never weakened to accommodate a missing
            // secret — without it, no delivery can be trusted.
            return response()->json([
                'message' => 'Online payments are not configured (webhook secret missing).',
                'code' => 'payments_not_configured',
            ], 503);
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

        // Idempotency fast path: a replayed delivery, or an appointment
        // already confirmed through the admin cash flow, is acknowledged
        // without reprocessing (re-checked again under the row lock below).
        if ($appointment->payment_status === 'paid') {

            return response()->json([
                'received' => true,
            ]);
        }

        // SEC-09: the appointment's CURRENT status is validated
        // independently of the checkout-time eligibility check, inside one
        // transaction with the row locked. A valid signature alone can
        // therefore never authorize payment for an appointment that became
        // un-payable after checkout was created, and no branch of this
        // handler ever writes to `status` — a late delivery must not
        // corrupt appointment history.
        $result = DB::transaction(function () use ($appointment, $session, $event) {
            /** @var Appointment $locked */
            $locked = Appointment::whereKey($appointment->id)->lockForUpdate()->first();

            // Idempotency re-check under the lock: duplicate deliveries and
            // a racing admin cash confirmation are acknowledged untouched.
            if ($locked->payment_status === 'paid') {
                return ['received' => true];
            }

            // Re-validate the amount against the price as it is NOW: an
            // authorized admin service change may have committed between
            // the pre-transaction check and this lock.
            if ((int) ($session->amount_total ?? 0) !== (int) round($locked->service_price * 100)) {
                return ['error' => 'Payment amount or currency mismatch.', 'status' => 400];
            }

            // D2: the checkout session is created by this app with
            // payment_method_types => ['card'], so a completed, signature-
            // verified session is definitionally a Stripe card payment. The
            // provenance is the signed session config — not an inference
            // from payment_status — so 'stripe' is trustworthy. paid_at is
            // set once and never rewritten by replayed deliveries (the
            // idempotency guard above).
            if (in_array($locked->status, Appointment::PAYABLE_STATUSES, true)) {
                $locked->update([
                    'payment_status' => 'paid',
                    'payment_method' => 'stripe',
                    'paid_at' => now(),
                ]);

                return ['received' => true];
            }

            if ($locked->status === 'completed') {
                // Services were rendered and this charge settles the visit:
                // record the payment and clear any "completed but never
                // paid" investigation flag — the row is now settled
                // (mirrors AppointmentController::complete()).
                $locked->update([
                    'payment_status' => 'paid',
                    'payment_method' => 'stripe',
                    'paid_at' => now(),
                    'needs_investigation' => false,
                ]);

                return ['received' => true];
            }

            // Late delivery for a cancelled / no-show appointment: Stripe
            // genuinely collected the money before the appointment became
            // un-payable, so discarding the delivery (or pretending no
            // payment happened) would hide real revenue. Record the
            // payment truthfully, leave the appointment status completely
            // untouched, flag the row for reconciliation (refund review by
            // staff) and emit a structured warning that carries identifiers
            // only — never the signature, secrets or session payload.
            $locked->update([
                'payment_status' => 'paid',
                'payment_method' => 'stripe',
                'paid_at' => now(),
                'needs_investigation' => true,
            ]);

            Log::warning('Stripe webhook: payment collected for an appointment that is no longer payable. Recorded and flagged for reconciliation; appointment status left untouched.', [
                'appointment_id' => $locked->id,
                'appointment_status' => $locked->status,
                'stripe_event_id' => $event->id ?? null,
            ]);

            return ['received' => true];
        });

        if (isset($result['error'])) {
            return response()->json([
                'message' => $result['error'],
            ], $result['status'] ?? 400);
        }

        return response()->json($result);
    }
}