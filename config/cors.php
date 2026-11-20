<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS) Configuration — SEC-16
|--------------------------------------------------------------------------
|
| The application previously fell back to the framework-shipped defaults,
| which allow ANY origin (`allowed_origins => ['*']`). This file makes
| the policy explicit and pinned to the origins the product actually
| runs on:
|
|   - http://localhost:3000    (local Next.js dev server)
|   - http://127.0.0.1:3000    (local Next.js dev server, IP form)
|   - FRONTEND_URL             (the configured production origin — read
|                               from the environment, never hard-coded;
|                               omitted entirely when unset so no
|                               production domain is invented here)
|
| Authentication is stateless Bearer tokens (localStorage), so
| `supports_credentials` stays FALSE and no cookies are involved —
| enabling credentials would require an entirely different, cookie-based
| auth architecture (explicitly out of scope: SEC-19 Option A).
|
| `allowed_methods` / `allowed_headers` enumerate exactly what the API
| needs instead of wildcarding them; browsers may cache preflight
| results (max_age) — kept at the framework default of 0 to preserve
| current behavior.
|
*/

$frontendOrigin = rtrim((string) env('FRONTEND_URL'), '/');

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter([
        'http://localhost:3000',
        'http://127.0.0.1:3000',
        $frontendOrigin !== '' ? $frontendOrigin : null,
    ])),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Requested-With'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
