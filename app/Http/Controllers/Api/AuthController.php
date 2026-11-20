<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    /**
     * SEC-04: failed-login budget per normalized email + IP.
     *
     * 5 failures / 60s, cleared by a successful login. The key is
     * normalized (lowercase + trimmed) so trivial variations of the same
     * address share one bucket, and it always includes the client IP so
     * one attacker cannot lock out a legitimate user from elsewhere.
     */
    private const LOGIN_MAX_ATTEMPTS = 5;

    private const LOGIN_DECAY_SECONDS = 60;

    /**
     * Register a new user (SEC-03).
     *
     * The account is created UNVERIFIED and NO API token is issued —
     * tokens only exist after the email address is confirmed. The
     * verification notification is sent inside a try/catch: an SMTP
     * failure must never 500 the request or lose the account. The
     * response reports notification_sent=false so the client never sees
     * a false "email sent" success, and the resend endpoint provides a
     * safe retry path. Logs carry the user id and failure reason only —
     * never passwords, tokens or the verification link.
     */
    public function register(Request $request): JsonResponse
    {
        // SEC-15: uniqueness is intentionally NOT part of the validation
        // rules. Field-keyed `unique:*` failures ("email has already been
        // taken") were an account-enumeration oracle: an attacker could
        // probe which emails/phones belong to existing accounts. The
        // database constraints stay in place (users.email is UNIQUE); the
        // phone rule remains enforced here because the users table has no
        // phone index (adding one would touch production data — out of
        // scope). Both duplicate cases answer with the SAME generic 422
        // below, so neither response nor timing distinguishes them.
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc,dns', 'max:255'],
            'phone' => ['required', 'string', 'max:20'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $duplicate = User::where('email', $validated['email'])
            ->orWhere('phone', $validated['phone'])
            ->exists();

        if ($duplicate) {
            return $this->genericRegistrationConflict();
        }

        try {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'],
                'password' => Hash::make($validated['password']),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Race between the check above and the INSERT (users.email is
            // UNIQUE at the database level) — same generic answer.
            return $this->genericRegistrationConflict();
        }

        // SEC-10: `role` is no longer mass assignable (a submitted
        // "role=admin" is not part of $validated and can never reach this
        // line). Registration always creates a normal account through a
        // direct, explicit assignment on the server side.
        $user->role = 'user';
        $user->save();

        $notificationSent = $this->sendVerificationNotification($user);

        return response()->json([
            'message' => $notificationSent
                ? 'User registered successfully. Check your inbox to verify your email address.'
                : 'User registered successfully, but the verification email could not be sent. '
                    . 'You can request it again from the login page once the mail service is available.',
            'verification_required' => true,
            'notification_sent' => $notificationSent,
            'user' => $user,
        ], 201);
    }

    /**
     * SEC-15: identical response for duplicate email AND duplicate phone.
     * No `errors` object (which would key the message to a field) and no
     * per-case wording — the frontend renders `message` directly.
     */
    private function genericRegistrationConflict(): JsonResponse
    {
        return response()->json([
            'message' => 'Registration failed. Please check your details and try again.',
        ], 422);
    }

    /**
     * Login user (SEC-03 + SEC-04).
     *
     * Unverified accounts are rejected with a consistent, machine-
     * readable response the frontend can render as a verification-
     * required state. The failed-attempt counter is only advanced for
     * invalid credentials and is cleared by a successful login, so
     * legitimate users are never permanently locked out.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $throttleKey = $this->loginThrottleKey($request);

        if (RateLimiter::tooManyAttempts($throttleKey, self::LOGIN_MAX_ATTEMPTS)) {
            return $this->tooManyAttemptsResponse($throttleKey);
        }

        if (! Auth::attempt($validated)) {
            RateLimiter::hit($throttleKey, self::LOGIN_DECAY_SECONDS);

            // Identical response whether the account exists or not —
            // the throttle never becomes an enumeration oracle.
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        /** @var User $user */
        $user = Auth::user();

        // Correct credentials but the address is not confirmed yet.
        // Not counted as a failed attempt (the password was right) and
        // the counter is left untouched — the user already knows their
        // own account state through this explicit response.
        if (! $user->hasVerifiedEmail()) {
            Auth::logout();

            return response()->json([
                'message' => 'Please verify your email before logging in.',
                'code' => 'email_not_verified',
            ], 403);
        }

        // Successful login clears the failed-attempt counter (SEC-04).
        RateLimiter::clear($throttleKey);

        // Keep only one active token
        $user->tokens()->delete();

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful.',
            'token' => $token,
            'user' => $user,
        ]);
    }

    /**
     * Verify the email address from the signed link in the notification
     * (SEC-03).
     *
     * The route is public and unauthenticated (the link itself proves
     * ownership): the id + hash must match the account AND the URL must
     * carry a valid, unexpired signature. Every failure path redirects
     * to the frontend /verify-email state page with an explicit status
     * (invalid | expired | verified) instead of leaking a stack trace —
     * invalid and expired links can never confirm anything, and a
     * repeat visit is idempotent.
     */
    public function verifyEmail(Request $request, string $id, string $hash): RedirectResponse
    {
        $frontendUrl = rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/');

        $user = User::find($id);

        if (! $user || ! hash_equals(sha1($user->getEmailForVerification()), (string) $hash)) {
            return redirect()->away($frontendUrl . '/verify-email?status=invalid');
        }

        if (! URL::hasValidSignature($request)) {
            // hasValidSignature = correct HMAC AND not expired; when it
            // fails, signatureHasNotExpired() tells the two cases apart
            // (it is public on the URL generator in Laravel 12). Neither
            // path ever verifies the account.
            $status = URL::signatureHasNotExpired($request) ? 'invalid' : 'expired';

            return redirect()->away($frontendUrl . '/verify-email?status=' . $status);
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        return redirect()->away($frontendUrl . '/verify-email?status=verified');
    }

    /**
     * Resend the verification email (SEC-03 + SEC-04).
     *
     * Public but credential-gated: the caller must present a valid
     * email + password pair for an UNVERIFIED account, which prevents
     * both account enumeration (the response is uniform for every
     * outcome) and email bombing (only the account owner can trigger a
     * send). The route is throttled per identity + IP. Resending never
     * creates or duplicates users — it only reads the existing row.
     */
    public function resendVerification(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if ($user
            && ! $user->hasVerifiedEmail()
            && Hash::check($validated['password'], $user->password)) {
            $this->sendVerificationNotification($user);
        }

        return response()->json([
            'message' => 'If an unverified account exists for these credentials, a verification email has been sent.',
        ], 202);
    }

    /**
     * Logout current device.
     *
     * SEC-13: `currentAccessToken()` returns one of two things:
     *  - a `PersonalAccessToken` when the caller authenticated with a
     *    Bearer token — the token row is revoked (this device only);
     *  - a `TransientToken` when the caller authenticated through a
     *    stateful/session guard — TransientToken has no `delete()` method,
     *    so calling it would fatal. Session callers simply end the request
     *    with the same success contract.
     *
     * Only the CURRENT token is ever revoked; other users' tokens (and the
     * same user's tokens on other devices, when stateful) are untouched.
     * The response contract (200 + message) is unchanged for the frontend.
     */
    public function logout(Request $request)
    {
        $accessToken = $request->user()?->currentAccessToken();

        if ($accessToken instanceof PersonalAccessToken) {
            $accessToken->delete();
        }

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    /**
     * Authenticated user.
     */
    public function user(Request $request)
    {
        return response()->json($request->user());
    }

    /**
     * Update the authenticated user's own profile (F-01).
     *
     * The settings page sends {name?, email?, phone?}; every field is
     * optional (`sometimes`) so an untouched form field is simply never
     * sent. The response is the bare updated user object — byte-for-byte
     * the same shape GET /user answers with, which is what
     * profile.service.ts unwraps.
     *
     * Email keeps the users.email UNIQUE constraint enforced through the
     * validator (self is ignored), and `dns` matches the registration
     * rule so both paths accept the same addresses.
     *
     * `role` is deliberately absent: it is not mass assignable (SEC-10)
     * and is never read from the request, so a caller cannot promote
     * themselves here.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'string',
                'email:rfc,dns',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'phone' => ['sometimes', 'string', 'max:20'],
        ]);

        $user->fill($validated);
        $user->save();

        return response()->json($user);
    }

    /**
     * Change the authenticated user's own password (F-01).
     *
     * `current_password` is checked by hand against the caller's own row
     * instead of the `current_password` validation rule: that rule reads
     * the DEFAULT guard's user, which is null under `auth:sanctum` with a
     * Bearer token (and in Sanctum::actingAs tests), so it would reject
     * every correct password.
     *
     * A wrong current password answers with the standard 422 validation
     * payload (`errors.current_password`) that the settings page already
     * renders through getErrorMessage(). The password is never echoed
     * back and never logged. Existing tokens are left alone so the
     * caller is not logged out of the device they are using.
     */
    public function updatePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $request->user();

        if (! Hash::check((string) $request->input('current_password'), $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $user->password = Hash::make((string) $request->input('password'));
        $user->save();

        return response()->json([
            'message' => 'Password changed successfully.',
        ]);
    }

    /**
     * Normalized throttle identity: same email in any casing/whitespace
     * form maps to one bucket, combined with the client IP.
     */
    private function loginThrottleKey(Request $request): string
    {
        $email = strtolower(trim((string) $request->input('email')));

        return 'login|' . $email . '|' . $request->ip();
    }

    /**
     * Uniform 429 with Retry-After — identical whether or not the
     * account exists.
     */
    private function tooManyAttemptsResponse(string $key): JsonResponse
    {
        $retryAfter = RateLimiter::availableIn($key);

        return response()->json([
            'message' => 'Too many attempts. Please try again in ' . $retryAfter . ' seconds.',
        ], 429, ['Retry-After' => (string) $retryAfter]);
    }

    /**
     * Fire the verification notification without ever letting a mail
     * failure break the request. Operational logging only: user id and
     * the failure reason — never credentials, tokens or links.
     */
    private function sendVerificationNotification(User $user): bool
    {
        try {
            $user->sendEmailVerificationNotification();

            return true;
        } catch (\Throwable $e) {
            Log::error('Email verification notification failed.', [
                'user_id' => $user->id,
                'exception' => $e::class . ': ' . $e->getMessage(),
            ]);

            return false;
        }
    }
}
