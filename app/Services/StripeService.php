<?php

namespace App\Services;

use Stripe\StripeClient;

class StripeService
{
    protected StripeClient $stripe;

    public function __construct()
    {
        $this->stripe = new StripeClient(
            config('services.stripe.secret')
        );
    }

    public function createCheckoutSession(array $data)
    {
        return $this->stripe->checkout->sessions->create([
            'payment_method_types' => ['card'],

            'mode' => 'payment',

            'line_items' => [[
                'price_data' => [
                    'currency' => 'egp',

                    'product_data' => [
                        'name' => $data['service_name'],
                    ],

                    'unit_amount' => $data['amount'],
                ],

                'quantity' => 1,
            ]],

            'success_url' => $data['success_url'],

            'cancel_url' => $data['cancel_url'],

            'metadata' => [
                'appointment_id' => $data['appointment_id'],
            ],
        ]);
    }
}