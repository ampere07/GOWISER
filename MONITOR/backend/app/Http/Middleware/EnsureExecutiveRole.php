<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use App\Support\Permissions;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The executive role gate, without the read-only rule.
 *
 * EnsureExecutiveAccess ('executive') carries two guarantees welded together:
 * an active session, and that the request is a GET. That second half is what
 * makes MONITOR safe to point at production databases, and it is the reason
 * that middleware cannot guard MikroTik RADIUS — those endpoints exist to write
 * to a router.
 *
 * This one keeps the first half, adds the role check the module needs, and drops
 * the method restriction. It answers exactly one question: is the signed-in
 * user's *role* one of the executive roles.
 *
 * ── Why the role, and not only a permission ───────────────────────────
 *
 * The same argument the Group Overview makes, applied to something sharper.
 * Module and action permissions are editable per role on the Roles screen, so a
 * permission alone can be granted to anyone by anyone who can edit roles. The
 * ability to re-shape every subscriber's bandwidth and disconnect a region is
 * not access that should be acquirable that way, so it is pinned to the role
 * list in Permissions::EXECUTIVE_ROLES, which only a deploy changes.
 *
 * Both gates apply: this middleware and the module permission, and the write
 * grants on top of that. See routes/api.php.
 *
 * Denials are recorded, for the same reason EnsurePermission records them — the
 * frontend hides the tab, so a request that reaches here at all did not come
 * from the menu.
 */
class EnsureExecutiveRole
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

        if (!$user->isExecutiveRole()) {
            AuditLog::record(
                $request,
                'denied',
                'section',
                'mikrotik-radius',
                'Blocked ' . $request->method() . ' ' . $request->path()
                    . " — role [{$user->roleName()}] is not an executive role"
            );

            return response()->json([
                'status' => 'error',
                'message' => 'This module is restricted to executive roles.',
                'missing' => [Permissions::MODULE_MIKROTIK],
            ], 403);
        }

        return $next($request);
    }
}
