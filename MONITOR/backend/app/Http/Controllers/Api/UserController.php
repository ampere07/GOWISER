<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * User administration for the monitoring portal.
 *
 * Writes, which needs saying explicitly in this codebase: MONITOR is read-only against the
 * MONITORED databases, and that guarantee is enforced at the connection level in the source
 * registry. It has always written to its OWN database — site_connections is the precedent. This
 * controller is the same category: MONITOR's own `users` table, never a monitored system's.
 *
 * A MONITOR account is not a GOWISER account. Creating a user here grants access to the
 * executive dashboards and nothing else, and cannot be used to log into the operations system.
 */
class UserController extends Controller
{
    public function index(Request $request)
    {
        $users = User::with('role')
            ->orderBy('username')
            ->get()
            ->map(fn (User $user) => $this->present($user));

        return response()->json([
            'status' => 'success',
            'data' => ['users' => $users],
        ]);
    }

    /**
     * Roles available to assign.
     *
     * Served from here rather than a roles controller because assigning one is the only thing
     * this app does with a role — there is no role editor yet. Permission counts are included so
     * whoever picks a role can see how much access it carries before granting it.
     */
    public function roles(Request $request)
    {
        $roles = Role::orderBy('role_name')->get()->map(fn (Role $role) => [
            'id' => (int) $role->id,
            'name' => $role->role_name,
            'description' => $role->description,
            // The effective grants, not the stored list — otherwise Superadmin, whose access
            // comes from a flag rather than a list, would advertise "0 permissions" in the
            // picker while granting everything.
            'permissions' => $role->effectivePermissions(),
            'is_system' => (bool) $role->is_system,
            'is_superadmin' => (bool) $role->is_superadmin,
        ]);

        return response()->json([
            'status' => 'success',
            'data' => ['roles' => $roles],
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'username' => ['required', 'string', 'max:255', Rule::unique('users', 'username')],
            'email_address' => ['required', 'email', 'max:255', Rule::unique('users', 'email_address')],
            // 12 characters, because every account this creates can read the whole company's
            // financials. Not composition rules — length is what actually resists guessing, and
            // composition rules mostly produce written-down passwords.
            'password' => ['required', 'string', 'min:12'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'middle_initial' => ['nullable', 'string', 'max:6'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:50'],
            // Nullable, but a user with no role holds no permissions and can therefore see
            // nothing — permissionList() returns [] and every guard fails closed. That is a
            // deliberate, usable state: create the account now, grant access separately.
            'role_id' => ['nullable', 'integer', Rule::exists('roles', 'id')],
            'active' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $user = new User();
        $user->username = $data['username'];
        $user->email_address = $data['email_address'];
        // Assigned to password_hash, NOT password: the model's setPasswordHashAttribute mutator
        // hashes it. Hashing here as well would double-hash and lock the account out silently.
        $user->password_hash = $data['password'];
        $user->first_name = $data['first_name'] ?? null;
        $user->middle_initial = $data['middle_initial'] ?? null;
        $user->last_name = $data['last_name'] ?? null;
        $user->contact_number = $data['contact_number'] ?? null;
        $user->role_id = $data['role_id'] ?? null;
        $user->active = $data['active'] ?? true;
        $user->save();

        $user->load('role');

        AuditLog::record(
            $request,
            'user.created',
            'user',
            $user->id,
            "Created monitoring user {$user->username}",
            [
                'username' => $user->username,
                'email_address' => $user->email_address,
                'role_id' => $user->role_id,
                'role' => $user->role?->role_name,
                'active' => $user->active,
                // The password is deliberately absent, hashed or otherwise. An audit trail is
                // read by more people than the users table is.
            ]
        );

        return response()->json([
            'status' => 'success',
            'message' => 'User created',
            'data' => ['user' => $this->present($user)],
        ], 201);
    }

    public function update(Request $request, User $user)
    {
        $validator = Validator::make($request->all(), [
            // Ignoring the current row, or saving a user without touching their name would
            // collide with themselves.
            'username' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('users', 'username')->ignore($user->id)],
            'email_address' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email_address')->ignore($user->id)],
            // Optional on update, and only applied when non-empty: an edit form that posts a
            // blank password field means "leave it alone", not "set it to blank".
            'password' => ['nullable', 'string', 'min:12'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'middle_initial' => ['nullable', 'string', 'max:6'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:50'],
            'role_id' => ['nullable', 'integer', Rule::exists('roles', 'id')],
            'active' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $intendedActive = array_key_exists('active', $data) ? (bool) $data['active'] : (bool) $user->active;
        $intendedRoleId = array_key_exists('role_id', $data) ? $data['role_id'] : $user->role_id;

        if ($guard = $this->refuseIfSuperadminEscalation($request, $user, $intendedRoleId)) {
            return $guard;
        }

        if ($guard = $this->refuseIfSelfLockout($request, $user, $intendedActive, $intendedRoleId)) {
            return $guard;
        }

        if ($guard = $this->refuseIfLastAdministrator($user, $intendedActive, $intendedRoleId)) {
            return $guard;
        }

        $before = $this->present($user);

        foreach (['username', 'email_address', 'first_name', 'middle_initial', 'last_name', 'contact_number'] as $field) {
            if (array_key_exists($field, $data)) {
                $user->{$field} = $data[$field];
            }
        }

        if (array_key_exists('role_id', $data)) {
            $user->role_id = $data['role_id'];
        }

        if (array_key_exists('active', $data)) {
            $user->active = (bool) $data['active'];
        }

        // Mutator hashes on assignment — see store(). Skipped entirely when blank so the existing
        // hash survives an edit that was not about the password.
        if (!empty($data['password'])) {
            $user->password_hash = $data['password'];
        }

        $user->save();
        $user->load('role');

        AuditLog::record(
            $request,
            'user.updated',
            'user',
            $user->id,
            "Updated monitoring user {$user->username}",
            [
                'before' => $before,
                'after' => $this->present($user),
                // Recorded as a flag, never a value.
                'password_changed' => !empty($data['password']),
            ]
        );

        return response()->json([
            'status' => 'success',
            'message' => 'User updated',
            'data' => ['user' => $this->present($user)],
        ]);
    }

    public function destroy(Request $request, User $user)
    {
        if ($guard = $this->refuseIfSuperadminEscalation($request, $user, $user->role_id)) {
            return $guard;
        }

        if ($guard = $this->refuseIfSelfLockout($request, $user, false, $user->role_id, true)) {
            return $guard;
        }

        if ($guard = $this->refuseIfLastAdministrator($user, false, $user->role_id, true)) {
            return $guard;
        }

        $snapshot = $this->present($user);
        $username = $user->username;

        $user->delete();

        AuditLog::record(
            $request,
            'user.deleted',
            'user',
            $snapshot['id'],
            "Deleted monitoring user {$username}",
            ['deleted' => $snapshot]
        );

        return response()->json([
            'status' => 'success',
            'message' => 'User deleted',
        ]);
    }

    /**
     * Keeps Super Admin a closed set: only a Super Admin can grant it, or touch an account
     * that holds it.
     *
     * Creation is already superadmin-only at the route. Without this, editing would be the way
     * around that gate — a role holding `users.edit` could assign the superadmin role to an
     * account it controls and manufacture unrestricted access from a lesser permission.
     *
     * The reverse is guarded too, and matters just as much: a lesser administrator must not be
     * able to demote, suspend or delete a Super Admin. That would let them neutralise the one
     * account that can undo their changes, which is the same attack run backwards.
     *
     * Returns 403 rather than 422 — this is an authorisation refusal, not a malformed request.
     */
    private function refuseIfSuperadminEscalation(Request $request, User $target, $intendedRoleId)
    {
        if ($request->user()->isSuperadmin()) {
            return null;
        }

        if ($target->isSuperadmin()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Only a Super Admin can modify a Super Admin account.',
            ], 403);
        }

        $intendedRole = $intendedRoleId !== null ? Role::find($intendedRoleId) : null;

        if ($intendedRole?->is_superadmin) {
            return response()->json([
                'status' => 'error',
                'message' => 'Only a Super Admin can assign the Super Admin role.',
            ], 403);
        }

        return null;
    }

    /**
     * Stops an administrator locking themselves out.
     *
     * Deleting or suspending your own account, or dropping your own role, ends your session's
     * access the moment it saves — and MONITOR has no role editor, so the way back is an
     * administrator editing the database by hand. Cheap to prevent, expensive to recover from.
     */
    private function refuseIfSelfLockout(
        Request $request,
        User $target,
        bool $intendedActive,
        $intendedRoleId,
        bool $deleting = false
    ) {
        if ((int) $request->user()->id !== (int) $target->id) {
            return null;
        }

        if ($deleting) {
            return response()->json([
                'status' => 'error',
                'message' => 'You cannot delete your own account.',
            ], 422);
        }

        if (!$intendedActive) {
            return response()->json([
                'status' => 'error',
                'message' => 'You cannot suspend your own account.',
            ], 422);
        }

        if ((int) $intendedRoleId !== (int) $target->role_id) {
            return response()->json([
                'status' => 'error',
                'message' => 'You cannot change your own role. Ask another administrator.',
            ], 422);
        }

        return null;
    }

    /**
     * Stops the last account that can administer users being removed.
     *
     * There is no role editor in MONITOR: permissions live in the roles table and are edited
     * directly in the database. So if the only active user holding `users.edit` is deleted,
     * suspended, or moved to a role without it, nobody can ever manage users through the app
     * again — an irreversible lockout from inside the UI.
     *
     * Evaluated on the state the request WOULD produce, not the current one, so it catches the
     * change before it is written rather than reporting it afterwards.
     */
    private function refuseIfLastAdministrator(
        User $target,
        bool $intendedActive,
        $intendedRoleId,
        bool $deleting = false
    ) {
        // Whether a role — identified by id — carries user administration. Asks the Role itself
        // so a superadmin is recognised here exactly as it is at login.
        $roleAdministers = function ($roleId): bool {
            if ($roleId === null) {
                return false;
            }

            $role = Role::find($roleId);

            return $role !== null
                && Permissions::granted($role->effectivePermissions(), 'users.edit');
        };

        // The target counts only if the request leaves it active AND on an administering role.
        $targetSurvivesAsAdmin = !$deleting
            && $intendedActive
            && $roleAdministers($intendedRoleId);

        if ($targetSurvivesAsAdmin) {
            return null;
        }

        $othersAdminister = User::with('role')
            ->where('id', '!=', $target->id)
            ->where('active', true)
            ->get()
            ->contains(fn (User $candidate) => $candidate->can_('users.edit'));

        if ($othersAdminister) {
            return null;
        }

        return response()->json([
            'status' => 'error',
            'message' => $deleting
                ? 'This is the last account that can manage users. Grant another user the permission before deleting it.'
                : 'This is the last account that can manage users. Grant another user the permission before changing it.',
        ], 422);
    }

    /** Never includes password_hash — the model hides it, and this shape is explicit anyway. */
    private function present(User $user): array
    {
        return [
            'id' => (int) $user->id,
            'username' => $user->username,
            'email' => $user->email_address,
            'full_name' => $user->full_name,
            'contact_number' => $user->contact_number,
            'role' => $user->role?->role_name,
            'role_id' => $user->role_id !== null ? (int) $user->role_id : null,
            'is_superadmin' => $user->isSuperadmin(),
            'active' => (bool) $user->active,
            'last_login' => $user->last_login?->toDateTimeString(),
            'permissions' => $user->effectivePermissions(),
        ];
    }
}
