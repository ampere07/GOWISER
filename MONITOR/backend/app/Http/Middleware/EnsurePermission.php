<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Granular permission gate, applied per route: `permission:action.payables.toggle`.
 *
 * Several ids may be listed; the caller needs *all* of them. Requiring all
 * rather than any is the safer reading — a route that both writes and exposes
 * money should not be reachable by holding only one of the two.
 *
 * Deliberately separate from EnsureExecutiveAccess, which answers "is this a
 * live session issuing a read". This one answers "may this person do this
 * specific thing", which is the question the brief's action permissions ask and
 * which no amount of session validity settles.
 *
 * A denial is recorded. An access attempt someone was not entitled to make is
 * exactly the event an audit trail exists for, and it is invisible everywhere
 * else — the frontend hides the control, so a request that reaches here at all
 * did not come from the UI.
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions)
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

        $held = $user->permissionList();
        $missing = array_values(array_diff($permissions, $held));

        if ($missing !== []) {
            AuditLog::record(
                $request,
                'denied',
                'permission',
                implode(',', $missing),
                'Blocked ' . $request->method() . ' ' . $request->path()
                    . ' — missing ' . implode(', ', $missing)
            );

            return response()->json([
                'status' => 'error',
                'message' => 'Your role does not permit this action.',
                // Named so the frontend can say which permission to ask for,
                // rather than leaving the user to guess at a flat 403.
                'missing' => $missing,
            ], 403);
        }

        return $next($request);
    }
}
