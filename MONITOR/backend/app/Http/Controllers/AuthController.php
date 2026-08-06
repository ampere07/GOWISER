<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;

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

    /**
     * Saves this user's own refresh intervals.
     *
     * On the auth group rather than behind `action.settings.manage`: this is the
     * one setting on the Settings page that belongs to the person rather than to
     * the portal, and requiring an administrative grant to choose how often your
     * own dashboard reloads would mean nobody but an administrator could.
     *
     * The accepted values are a fixed list, not a range. An arbitrary integer
     * here is a poll interval against eight monitored databases and, on the
     * MikroTik screen, against routers serving live authentication — "2" would be
     * a denial-of-service someone typed by accident.
     */
    public function updatePreferences(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'overview_refresh' => ['required', 'integer', Rule::in(User::REFRESH_CHOICES)],
            'mikrotik_refresh' => ['required', 'integer', Rule::in(User::REFRESH_CHOICES)],
        ], [
            'overview_refresh.in' => 'Choose one of the offered refresh intervals.',
            'mikrotik_refresh.in' => 'Choose one of the offered refresh intervals.',
        ]);

        // Merged over whatever is stored rather than replacing it, so a future
        // preference added by another screen is not wiped by this form.
        DB::transaction(function () use ($user, $validated) {
            $stored = $user->getAttributes()['preferences'] ?? null;
            $stored = is_string($stored) ? json_decode($stored, true) : $stored;

            $user->preferences = array_merge(
                is_array($stored) ? $stored : [],
                array_map('intval', $validated)
            );

            $user->save();
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Refresh interval saved.',
            'data' => ['preferences' => $user->fresh()->refreshPreferences()],
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
            'role' => $user->roleName(),
            // Cast: MySQL hands this back as an int, SQLite as a string, and
            // the frontend types it as a number.
            'role_id' => $user->role_id !== null ? (int) $user->role_id : null,
            // The effective list — role plus per-user grants, minus per-user
            // denials. What the frontend hides on must be what the middleware
            // enforces on, or a control appears that every click then rejects.
            'permissions' => $user->permissionList(),
            // Governs the consolidated executive view, which needs an executive
            // role on top of the module permission. Sent so the sidebar can hide
            // the tab rather than offering a page that 403s on open.
            'is_executive_role' => $user->isExecutiveRole(),
            // How often the auto-refreshing screens re-read themselves, in
            // seconds. Sent with the session rather than fetched separately so a
            // dashboard never opens on the default interval and then jumps to the
            // user's a moment later.
            'preferences' => $user->refreshPreferences(),
        ];
    }
}
