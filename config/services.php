<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    'stripe' => [
      'secret' => env('STRIPE_SECRET_KEY'),
     'public' => env('STRIPE_PUBLIC_KEY'),
     'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
     'frontend_url' => env('FRONTEND_URL'),
   ],

    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        // Note: legacy models (e.g. gemini-2.5-*) return 404 for newer
        // API keys; verified working default. Any model listed for the
        // key in the Gemini API can be set here via GEMINI_MODEL.
        'model' => env('GEMINI_MODEL', 'gemini-3.1-flash-lite'),
        // REST surface: 'generate_content' (legacy
        // /v1beta/models/{model}:generateContent) or 'interactions'
        // (POST /v1beta/interactions — the Interactions API, docs:
        // ai.google.dev/gemini-api/docs/interactions-overview).
        // Both are fully supported; switch only after a real request
        // against the chosen model succeeds.
        'api' => env('GEMINI_API', 'generate_content'),
        'timeout' => (int) env('GEMINI_TIMEOUT', 30),
        // Bounded retry for transient upstream failures (503 UNAVAILABLE /
        // 500 / 429) with exponential backoff + jitter. Tests set these
        // to 0 to stay fast; production defaults follow Google's guidance.
        'retry_backoff_ms' => (int) env('GEMINI_RETRY_BACKOFF_MS', 400),
        'retry_max_ms' => (int) env('GEMINI_RETRY_MAX_MS', 2000),
    ],

];
