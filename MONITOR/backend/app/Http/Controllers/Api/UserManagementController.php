<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * User Management and Permission Mapping.
 *
 * Assigns executive roles and, where a role is not quite the right shape, a
 * per-user override. Overrides exist so an exception does not have to become a
 * new role — a portal with eleven near-identical roles is one nobody can audit.
 *
 * Every write is recorded. Granting someone the Financial module changes what
 * they can see of the business, and "who gave them access" has to have an answer
 * that does not depend on someone remembering.
 *
 * Two protections against locking the portal out of its own administration:
 * a user cannot strip their own user-management permission, and the last active
 * account holding it cannot be deactivated.
 */
class UserManagementController extends Controller
{
    /** Roles that ship with the app and must keep existing. */
    private const PROTECTED_ROLES = ['Super Admin', 'Executive'];

    /**
     * Every user, their role, and their effective permission list.
     *
     * The effective list is computed rather than left for the frontend to derive:
     * the deny-wins-over-grant rule is the sort of thing two implementations
     * disagree about, and the screen that shows someone's access must show what
     * the middleware will actually enforce.
     */
    public function index()
    {
        $users = User::with('role')->orderBy('username')->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'users' => $users->map(fn (User $user) => $this->present($user))->all(),
                'roles' => Role::orderBy('role_name')->get()->map(fn (Role $role) => [
                    'id' => (int) $role->id,
                    'name' => $role->role_name,
                    'description' => $role->description,
                    // The *effective* map, not the stored column. For Super
                    // Admin and Executive the two differ — their saved list is
                    // ignored at enforcement time — and showing the column would
                    // report a narrower role than the middleware grants.
                    'permissions' => Permissions::effective(
                        $role->role_name,
                        is_array($role->permissions) ? $role->permissions : []
                    ),
                    'full_access' => Permissions::isFullAccessRole($role->role_name),
                    'is_system' => (bool) $role->is_system,
                    'user_count' => $users->where('role_id', $role->id)->count(),
                ])->all(),
                'catalogue' => Permissions::catalogue(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        if (($data['password'] ?? '') === '') {
            throw ValidationException::withMessages([
                'password' => 'A password is required when adding a user.',
            ]);
        }

        $user = User::create([
            'username' => $data['username'],
            'email_address' => $data['email_address'],
            // Assigned raw: User::setPasswordHashAttribute hashes it.
            'password_hash' => $data['password'],
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
            'contact_number' => $data['contact_number'] ?? null,
            'role_id' => $data['role_id'],
            'permission_overrides' => $this->overrides($data),
            'active' => $data['active'] ?? true,
        ]);

        AuditLog::record(
            $request,
            'user.created',
            User::class,
            $user->id,
            "Created user [{$user->username}] with role " . $this->roleName($data['role_id']),
            ['role_id' => ['from' => null, 'to' => $data['role_id']]]
        );

        return response()->json([
            'status' => 'success',
            'message' => 'User created.',
            'data' => ['user' => $this->present($user->fresh('role'))],
        ], 201);
    }

    public function update(Request $request, User $user)
    {
        $data = $this->validated($request, $user);
        $actor = $request->user();

        $before = [
            'role_id' => $user->role_id,
            'active' => $user->active,
            'overrides' => $user->overrides(),
        ];

        // An administrator who removes their own ability to administer users has
        // no way back in without a database edit. Refusing is kinder than the
        // alternative, and the message says what to do instead.
        if ($actor && (int) $actor->id === (int) $user->id) {
            $wouldLose = !in_array(
                Permissions::ACTION_USERS_MANAGE,
                $this->effectiveFor($data),
                true
            );

            if ($wouldLose) {
                throw ValidationException::withMessages([
                    'role_id' => 'You cannot remove your own user-management access. Ask another administrator.',
                ]);
            }
        }

        if (($data['active'] ?? true) === false && $this->isLastAdministrator($user)) {
            throw ValidationException::withMessages([
                'active' => 'This is the last active account that can manage users. Grant another one first.',
            ]);
        }

        $user->fill([
            'username' => $data['username'],
            'email_address' => $data['email_address'],
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
            'contact_number' => $data['contact_number'] ?? null,
            'role_id' => $data['role_id'],
            'permission_overrides' => $this->overrides($data),
            'active' => $data['active'] ?? true,
        ]);

        // An empty password means "leave it alone", so a rename need not retype
        // it — and keeping it unset keeps it out of the audit diff too.
        if (($data['password'] ?? '') !== '') {
            $user->password_hash = $data['password'];
        }

        $user->save();

        AuditLog::record(
            $request,
            'user.updated',
            User::class,
            $user->id,
            "Updated user [{$user->username}]"
                . ((int) $before['role_id'] !== (int) $data['role_id']
                    ? ' — role now ' . $this->roleName($data['role_id'])
                    : ''),
            AuditLog::diff(
                ['role_id' => $before['role_id'], 'active' => $before['active'], 'overrides' => $before['overrides']],
                [
                    'role_id' => (int) $data['role_id'],
                    'active' => (bool) ($data['active'] ?? true),
                    'overrides' => $this->overrides($data),
                    // Recorded as a change, never as a value: the trail shows
                    // *that* a credential changed, not what it changed to.
                    'password' => ($data['password'] ?? '') !== '' ? 'changed' : null,
                ]
            )
        );

        return response()->json([
            'status' => 'success',
            'message' => 'User updated.',
            'data' => ['user' => $this->present($user->fresh('role'))],
        ]);
    }

    public function destroy(Request $request, User $user)
    {
        $actor = $request->user();

        if ($actor && (int) $actor->id === (int) $user->id) {
            throw ValidationException::withMessages([
                'user' => 'You cannot delete your own account.',
            ]);
        }

        if ($this->isLastAdministrator($user)) {
            throw ValidationException::withMessages([
                'user' => 'This is the last active account that can manage users.',
            ]);
        }

        $username = $user->username;
        $id = $user->id;

        $user->delete();

        AuditLog::record($request, 'user.deleted', User::class, $id, "Deleted user [{$username}]");

        return response()->json(['status' => 'success', 'message' => 'User deleted.']);
    }

    /**
     * Reshapes a role's permission map.
     *
     * System roles keep their name — deleting or renaming Super Admin out from
     * under the last administrator is the failure mode this guards — but most of
     * them stay editable, because a deployment's idea of what a Finance Admin
     * sees is legitimately its own.
     *
     * The two full-access roles are the exception, and are refused outright
     * rather than accepted and ignored. Their map is not consulted at
     * enforcement time (see Permissions::FULL_ACCESS_ROLES), so a saved edit
     * would change nothing while the screen showed it as having taken — which is
     * the worst of the three possible behaviours. An administrator who wants a
     * narrower role should make one, which is what this screen is for.
     */
    public function updateRole(Request $request, Role $role)
    {
        $data = $request->validate([
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string'],
        ]);

        if (Permissions::isFullAccessRole($role->role_name)) {
            throw ValidationException::withMessages([
                'permissions' => "[{$role->role_name}] holds every permission by definition and cannot be narrowed. "
                    . 'Create a role with the access you want and move the accounts onto it.',
            ]);
        }

        $before = ['permissions' => is_array($role->permissions) ? $role->permissions : []];
        $permissions = Permissions::sanitise($data['permissions']);

        $role->fill([
            'description' => $data['description'] ?? $role->description,
            'permissions' => $permissions,
        ])->save();

        AuditLog::record(
            $request,
            'role.updated',
            Role::class,
            $role->id,
            "Updated permission map for role [{$role->role_name}]",
            AuditLog::diff($before, ['permissions' => $permissions])
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Role permissions updated.',
            'data' => [
                'role' => [
                    'id' => (int) $role->id,
                    'name' => $role->role_name,
                    'description' => $role->description,
                    'permissions' => $permissions,
                    'full_access' => false,
                    'is_system' => (bool) $role->is_system,
                ],
            ],
        ]);
    }

    // ── Internals ────────────────────────────────────────────────────────

    private function validated(Request $request, ?User $user = null): array
    {
        return $request->validate([
            'username' => [
                'required', 'string', 'max:100',
                Rule::unique('users', 'username')->ignore($user?->id),
            ],
            'email_address' => [
                'required', 'email', 'max:191',
                Rule::unique('users', 'email_address')->ignore($user?->id),
            ],
            // Required on create, optional on edit — enforced by the caller,
            // because "required unless editing" is clearer as a branch than as a
            // rule string.
            'password' => ['nullable', 'string', 'min:10', 'max:191'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'contact_number' => ['nullable', 'string', 'max:50'],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')],
            'active' => ['nullable', 'boolean'],
            'grant' => ['nullable', 'array'],
            'grant.*' => ['string'],
            'deny' => ['nullable', 'array'],
            'deny.*' => ['string'],
        ]);
    }

    /**
     * Normalises the override pair, dropping it entirely when empty.
     *
     * Null rather than {"grant":[],"deny":[]} so a user with no exception has no
     * override record at all — which is what makes the management screen's "has
     * custom access" indicator mean something.
     */
    private function overrides(array $data): ?array
    {
        $grant = Permissions::sanitise($data['grant'] ?? []);
        $deny = Permissions::sanitise($data['deny'] ?? []);

        if ($grant === [] && $deny === []) {
            return null;
        }

        // A permission in both lists is a contradiction the UI should not have
        // allowed. Deny wins, consistently with User::permissionList, and the
        // grant is dropped rather than left to confuse the next reader.
        return ['grant' => array_values(array_diff($grant, $deny)), 'deny' => $deny];
    }

    /** The permission list a submitted form would produce, without saving it. */
    private function effectiveFor(array $data): array
    {
        $role = Role::find($data['role_id']);

        // Through Permissions::effective rather than off the column, so this
        // preview agrees with User::permissionList for the full-access roles —
        // whose stored map is not what gets enforced. Two answers to "what will
        // this account be able to do" is the one thing this screen must not have.
        $base = Permissions::effective(
            $role?->role_name,
            is_array($role?->permissions) ? $role->permissions : []
        );

        $overrides = $this->overrides($data) ?? ['grant' => [], 'deny' => []];

        return array_values(array_diff(
            array_merge($base, $overrides['grant']),
            $overrides['deny']
        ));
    }

    /**
     * Whether this is the only active account left that can manage users.
     *
     * Checked against the *effective* list, not the role's, so an account holding
     * the permission through an override still counts — and one denied it through
     * an override correctly does not.
     */
    private function isLastAdministrator(User $user): bool
    {
        if (!$user->can_(Permissions::ACTION_USERS_MANAGE)) {
            return false;
        }

        $others = User::with('role')
            ->where('active', true)
            ->where('id', '<>', $user->id)
            ->get()
            ->filter(fn (User $other) => $other->can_(Permissions::ACTION_USERS_MANAGE));

        return $others->isEmpty();
    }

    private function roleName($roleId): string
    {
        return Role::find($roleId)?->role_name ?? 'none';
    }

    private function present(User $user): array
    {
        return [
            'id' => (int) $user->id,
            'username' => $user->username,
            'email' => $user->email_address,
            'full_name' => $user->full_name,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'contact_number' => $user->contact_number,
            'role_id' => $user->role_id !== null ? (int) $user->role_id : null,
            'role' => $user->role?->role_name ?? 'None',
            'active' => (bool) $user->active,
            'last_login' => $user->last_login?->toDateTimeString(),
            'overrides' => $user->overrides(),
            'has_overrides' => $user->permission_overrides !== null,
            // What the middleware will actually enforce, deny rule applied.
            'permissions' => $user->permissionList(),
        ];
    }
}
