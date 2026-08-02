<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Restricts an action to accounts holding the superadmin role.
 *
 * Stricter than a permission grant, and deliberately so. Creating an account is the one operation
 * that can manufacture access rather than merely exercise it, so it is gated on WHO the caller is
 * rather than on a permission a role could be given. A role granted `users.create` still cannot
 * create accounts unless it is the superadmin role.
 *
 * Checked server-side on every request, so hiding the button in the UI is a convenience rather
 * than the control — an unauthorised POST straight to the endpoint is refused here with a 403.
 */
class EnsureSuperadmin
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

        if (!$user->isSuperadmin()) {
            Log::warning('Monitor superadmin action denied', [
                'user_id' => $user->id,
                'username' => $user->username,
                'role' => $user->role?->role_name,
                'path' => $request->path(),
                'method' => $request->getMethod(),
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Only a Super Admin can perform this action.',
            ], 403);
        }

        return $next($request);
    }
}
