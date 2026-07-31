<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Every monitoring endpoint is read-only and executive-scoped.
 *
 * Two guarantees are enforced here rather than per-controller:
 *  1. The session belongs to an active user of this app (MONITOR has its own
 *     users table; a GOWISER session is meaningless here).
 *  2. The request is a read. The monitoring API never writes to the GOWISER
 *     databases, so anything other than GET/HEAD/OPTIONS is rejected outright.
 */
class EnsureExecutiveAccess
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

        if (!in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return response()->json([
                'status' => 'error',
                'message' => 'The monitoring API is read-only.',
            ], 405);
        }

        return $next($request);
    }
}
