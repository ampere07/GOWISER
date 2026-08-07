<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Permissions;
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
     * Saves the portal-wide refresh intervals.
     *
     * ── Why this now needs an administrative grant ────────────────────
     *
     * It used to save against the calling account and was deliberately open to
     * any session, on the argument that choosing how often your own dashboard
     * reloads is nobody else's business. It is now one value for the whole
     * portal, which changes who it belongs to: this number decides how often
     * MONITOR fans out across every monitored database and how often it reaches
     * routers serving live authentication, and that is a production-load
     * decision rather than a personal preference. So it sits behind
     * `action.settings.manage`, alongside the other settings that change the
     * portal for everyone.
     *
     * The accepted values are a fixed list, not a range. An arbitrary integer
     * here is a poll interval against every monitored database — "2" would be a
     * denial-of-service someone typed by accident.
     */
    public function updatePreferences(Request $request)
    {
        $user = $request->user();

        if (!$user->can_(Permissions::ACTION_SETTINGS_MANAGE)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Changing the refresh interval is restricted — it applies to every account.',
                'missing' => [Permissions::ACTION_SETTINGS_MANAGE],
            ], 403);
        }

        $validated = $request->validate([
            'overview_refresh' => ['required', 'integer', Rule::in(User::REFRESH_CHOICES)],
            'mikrotik_refresh' => ['required', 'integer', Rule::in(User::REFRESH_CHOICES)],
        ], [
            'overview_refresh.in' => 'Choose one of the offered refresh intervals.',
            'mikrotik_refresh.in' => 'Choose one of the offered refresh intervals.',
        ]);

        $before = AppSetting::refreshIntervals();

        $after = AppSetting::putRefreshIntervals(
            array_map('intval', $validated),
            $user->username
        );

        // Audited, unlike the per-user setting it replaced. It now changes the
        // load every account puts on production, so "who turned this to ten
        // seconds" is a question somebody will eventually need answered.
        AuditLog::record(
            $request,
            'settings.auto_refresh',
            AppSetting::class,
            'auto_refresh',
            'Portal-wide auto-refresh intervals changed',
            AuditLog::diff($before, $after)
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Refresh interval saved for every account.',
            'data' => ['preferences' => $after],
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
            // Whether the role bypasses the permission map outright. The list
            // above already contains everything for such a role, so this is not
            // what the UI gates on — it is what stops a control being hidden by
            // a *frontend-only* id that has no counterpart in the PHP catalogue
            // and so can never appear in `permissions`.
            'is_full_access' => $user->hasFullAccess(),
            // How often the auto-refreshing screens re-read themselves, in
            // seconds. Sent with the session rather than fetched separately so a
            // dashboard never opens on the default interval and then jumps to the
            // user's a moment later.
            'preferences' => $user->refreshPreferences(),
        ];
    }
}
