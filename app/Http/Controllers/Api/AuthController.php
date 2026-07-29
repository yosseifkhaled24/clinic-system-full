<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Register a new user.
     */
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc,dns', 'max:255', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:20', 'unique:users,phone'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'password' => Hash::make($validated['password']),
            'role' => 'user',
        ]);

        /*
         |-------------------------------------------------------------
         | Email Verification
         |-------------------------------------------------------------
         | Make sure User model implements MustVerifyEmail
         | and your project is configured with SMTP.
         */
        if (method_exists($user, 'sendEmailVerificationNotification')) {
            $user->sendEmailVerificationNotification();
        }

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'message' => 'User registered successfully. Please verify your email.',
            'token'   => $token,
            'user'    => $user,
        ], 201);
    }

    /**
     * Login user.
     */
    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (!Auth::attempt($validated)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        /** @var User $user */
        $user = Auth::user();

        /*
         |-------------------------------------------------------------
         | Prevent unverified users from logging in
         |-------------------------------------------------------------
         */

        if (
            method_exists($user, 'hasVerifiedEmail') &&
            ! $user->hasVerifiedEmail()
        ) {

            Auth::logout();

            return response()->json([
                'message' => 'Please verify your email before logging in.',
            ], 403);
        }

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
     * Logout current device.
     */
    public function logout(Request $request)
    {
        if ($request->user()?->currentAccessToken()) {
            $request->user()->currentAccessToken()->delete();
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
}