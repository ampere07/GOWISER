<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Session login for the monitoring portal.
 *
 * Authenticates against MONITOR's own users table only. A GOWISER account
 * cannot be used here, and vice versa.
 */
class AuthController extends Controller
{
    public function login(Request $request)
    {
        $identifier = trim((string) $request->input('email'));
        $password = (string) $request->input('password');

        if ($identifier === '' || $password === '') {
            return response()->json([
                'status' => 'error',
                'message' => 'Email/username and password are required',
            ], 400);
        }

        // Throttle per identifier+IP. Executive accounts are few and their
        // usernames are guessable, so this is the main brute-force defence.
        $throttleKey = strtolower($identifier) . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Too many login attempts. Try again in '
                    . RateLimiter::availableIn($throttleKey) . ' seconds.',
            ], 429);
        }

        $user = User::where('email_address', $identifier)
            ->orWhere('username', $identifier)
            ->first();

        if (!$user || !Hash::check($password, $user->password_hash)) {
            RateLimiter::hit($throttleKey, 300);

            Log::warning('Monitor login failed', [
                'identifier' => $identifier,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Invalid credentials',
            ], 401);
        }

        if (!$user->active) {
            return response()->json([
                'status' => 'suspended',
                'message' => 'Your account is suspended. Please contact the administrator.',
            ], 403);
        }

        RateLimiter::clear($throttleKey);

        Auth::login($user);
        $request->session()->regenerate();

        $user->load('role');
        $user->last_login = now();
        $user->save();

        Log::info('Monitor user authenticated', [
            'user_id' => $user->id,
            'username' => $user->username,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Login successful',
            'data' => [
                'user' => $this->presentUser($user),
            ],
        ]);
    }

    public function me(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $user->load('role');

        return response()->json([
            'status' => 'success',
            'data' => [
                'user' => $this->presentUser($user),
            ],
        ]);
    }

    public function logout(Request $request)
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'status' => 'success',
            'message' => 'Logged out',
        ]);
    }

    private function presentUser(User $user): array
    {
        return [
            'id' => $user->id,
            'username' => $user->username,
            'email' => $user->email_address,
            'full_name' => $user->full_name,
            'role' => $user->role ? strtolower($user->role->role_name) : 'viewer',
            // Cast: MySQL hands this back as an int, SQLite as a string, and
            // the frontend types it as a number.
            'role_id' => $user->role_id !== null ? (int) $user->role_id : null,
            'permissions' => $user->permissionList(),
        ];
    }
}
