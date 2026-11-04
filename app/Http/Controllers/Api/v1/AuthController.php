<?php

namespace App\Http\Controllers\Api\v1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends Controller
{
    private const MAX_LOGIN_ATTEMPTS = 2;
    private const LOCKOUT_SECONDS = 60;

    /**
     * Register a new customer.
     */
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $email = mb_strtolower(trim($validated['email']));

        $user = User::create([
            'name' => trim($validated['name']),
            'email' => $email,
            'password' => $validated['password'],
            'role' => 'customer',
        ]);

        $token = auth('api')->login($user);

        return response()->json([
            'success' => true,
            'message' => 'Registration successful',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
            'access_token' => $token,
            'token_type' => 'bearer',
        ], 201);
    }

    /**
     * Login with temporary lockout after failed attempts.
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Normalize email before authentication and rate limiting.
        $credentials['email'] = mb_strtolower(
            trim($credentials['email'])
        );

        // Build a unique key for this email and IP combination.
        $key = 'login:' . hash(
                'sha256',
                $credentials['email'] . '|' . $request->ip()
            );

        // Reject requests while the user is temporarily locked out.
        if (RateLimiter::tooManyAttempts(
            $key,
            self::MAX_LOGIN_ATTEMPTS
        )) {
            return response()->json([
                'success' => false,
                'message' => 'Too many failed login attempts. Please try again later.',
                'errors' => null,
                'retry_after' => RateLimiter::availableIn($key),
            ], 429);
        }

        // Attempt authentication using the configured API guard.
        $token = auth('api')->attempt($credentials);

        if (! $token) {
            RateLimiter::hit($key, self::LOCKOUT_SECONDS);

            return response()->json([
                'success' => false,
                'message' => 'Invalid email or password.',
                'errors' => null,
                'remaining_attempts' => RateLimiter::remaining(
                    $key,
                    self::MAX_LOGIN_ATTEMPTS
                ),
            ], 401);
        }

        // Clear failed attempts after successful authentication.
        RateLimiter::clear($key);

        $user = auth('api')->user();

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'access_token' => $token,
            'token_type' => 'bearer',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
        ]);
    }

    /**
     * Return the authenticated user.
     */
    public function me(Request $request)
    {
        $user = $request->user('api');

        return response()->json([
            'success' => true,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
        ]);
    }

    /**
     * Logout and invalidate the current JWT.
     */
    public function logout()
    {
        auth('api')->logout();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
        ]);
    }
}
