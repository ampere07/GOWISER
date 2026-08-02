<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Per-section authorisation for the dashboards.
 *
 * WHAT THIS FIXES. Until now the reporting and executive endpoints sat behind EnsureExecutiveAccess
 * alone, which checks that the session belongs to an active user and that the request is a read.
 * It never consulted the role's permissions. The sidebar hid sections a role could not see, but
 * that was presentation only: a user granted just `subscriber-analytics` could call
 * GET /api/reporting/financial directly and receive the whole financial payload. Every executive
 * figure in this app was one URL away from any authenticated account.
 *
 * Applied as `permission:financial.view` — the argument is the exact grant required, so the same
 * middleware gates a page and its export separately.
 *
 * Fails closed. No session, no role, or an empty permission list all deny; an executive portal
 * should refuse rather than guess.
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permission)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (!$user->active) {
            Auth::logout();

            return response()->json([
                'status' => 'suspended',
                'message' => 'Your account is suspended. Please contact the administrator.',
            ], 403);
        }

        if (!$user->can_($permission)) {
            // Logged because a denial here is either a misconfigured role or someone probing an
            // endpoint the UI never offered them; both are worth being able to see after the fact.
            Log::warning('Monitor permission denied', [
                'user_id' => $user->id,
                'username' => $user->username,
                'required' => $permission,
                'path' => $request->path(),
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'You do not have permission to view this section.',
                // Named so the frontend can show which grant is missing rather than a bare 403.
                'required_permission' => $permission,
            ], 403);
        }

        return $next($request);
    }
}
