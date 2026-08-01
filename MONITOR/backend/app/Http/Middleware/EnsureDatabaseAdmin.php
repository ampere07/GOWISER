<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Guards the one part of MONITOR that writes.
 *
 * Everything else in this API is read-only and sits behind
 * EnsureExecutiveAccess, which rejects any method other than GET. The Databases
 * configuration page cannot live behind that guard, so it gets its own — and the
 * distinction is worth being explicit about:
 *
 *   EnsureExecutiveAccess   reads, against the *monitored* databases
 *   EnsureDatabaseAdmin     writes, against *MONITOR's own* database only
 *
 * The read-only guarantee on monitored sources is untouched by this: it is
 * enforced at the connection level in SourceRegistry::connection(), which no
 * request can bypass regardless of which middleware it came through. What this
 * middleware permits is editing MONITOR's own `site_connections` rows.
 *
 * Requires the 'databases' permission specifically rather than any admin-ish
 * role. These rows hold credentials for every monitored database, so the bar is
 * a permission a role has to be granted deliberately.
 */
class EnsureDatabaseAdmin
{
    public function handle(Request $request, Closure $next)
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

        if (!$user->can_('databases')) {
            return response()->json([
                'status' => 'error',
                'message' => 'You do not have permission to manage database connections.',
            ], 403);
        }

        return $next($request);
    }
}
