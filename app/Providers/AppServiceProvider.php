<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         |------------------------------------------------------------------
         | SEC-03 — verification emails point at the Next.js frontend
         |------------------------------------------------------------------
         | The signed URL is still generated for the backend API route
         | (the signature therefore covers id, hash and expiry exactly as
         | Laravel intends); only the link the patient CLICKS is re-hosted
         | on FRONTEND_URL/verify-email, which renders the localized
         | success/expired/invalid state page. No token, hash or secret is
         | ever exposed beyond the signed link itself.
         */
        VerifyEmail::toMailUsing(function ($notifiable, string $temporarySignedUrl): MailMessage {
            $query = [];
            parse_str((string) parse_url($temporarySignedUrl, PHP_URL_QUERY), $query);

            // Route parameters ({id}/{hash}) travel in the PATH of the
            // signed backend URL. Surface them as query parameters (in
            // front of the original ones, whose order is preserved) so
            // the frontend status page can rebuild the exact signed
            // backend request: it moves id/hash back into the path and
            // keeps the remaining query string byte-for-byte, which is
            // what the HMAC was computed over.
            $path = (string) parse_url($temporarySignedUrl, PHP_URL_PATH);
            preg_match('#/verify/([^/]+)/([^/]+)$#', $path, $matches);

            $frontendQuery = [
                'id' => $matches[1] ?? '',
                'hash' => $matches[2] ?? '',
            ] + $query;

            $frontendVerifyUrl = rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/')
                . '/verify-email?' . http_build_query($frontendQuery);

            return (new MailMessage)
                ->subject('Verify your email address')
                ->line('Welcome to the clinic! Please confirm your email address to activate your account.')
                ->action('Verify Email Address', $frontendVerifyUrl)
                ->line('This link expires in 60 minutes. If it has expired, request a new one from the login page.')
                ->line('If you did not create an account, no further action is required.');
        });

        /*
         |------------------------------------------------------------------
         | SEC-04 — authentication rate limiting
         |------------------------------------------------------------------
         | Login throttling lives in AuthController::login (it must only
         | count FAILED attempts and must clear on success); these named
         | limiters cover the two remaining abuse surfaces:
         |
         |  - register:               3/min per IP — slows automated
         |                            account creation without punishing
         |                            a single person.
         |  - verification-resend:    3/min per normalized email + IP —
         |                            stops verification-email bombing.
         |
         | Responses are uniform (429 + Retry-After) and never reveal
         | whether an account exists. The limits are short-windowed, so a
         | legitimate user is never permanently locked out.
         */
        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinute(3)
                ->by('register|' . $request->ip());
        });

        RateLimiter::for('verification-resend', function (Request $request) {
            $email = Str::lower(trim((string) $request->input('email')));

            return Limit::perMinute(3)
                ->by('verification-resend|' . $email . '|' . $request->ip());
        });

        /*
         |------------------------------------------------------------------
         | SEC-11 — public contact submission
         |------------------------------------------------------------------
         | 5 submissions/minute per IP: enough for a genuine visitor, useless
         | for inbox flooding. Keyed by IP only (there is no account on this
         | endpoint), answered with the standard uniform 429 + Retry-After.
         */
        RateLimiter::for('contact', function (Request $request) {
            return Limit::perMinute(5)
                ->by('contact|' . $request->ip());
        });
    }
}
