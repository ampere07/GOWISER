<?php

namespace App\Models;

use App\Support\Permissions;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\HasApiTokens;

/**
 * A MONITOR user. Deliberately separate from the GOWISER users table: a
 * GOWISER account must not grant access to the executive dashboards, and an
 * executive account must not be usable to log into the operations system.
 *
 * Column names mirror GOWISER's users table so the two codebases read the same.
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $primaryKey = 'id';
    protected $table = 'users';

    protected $fillable = [
        'username',
        'password_hash',
        'email_address',
        'first_name',
        'middle_initial',
        'last_name',
        'contact_number',
        'role_id',
        'permission_overrides',
        'darkmode',
        'last_login',
        'active',
    ];

    protected $hidden = [
        'password_hash',
        'remember_token',
    ];

    protected $casts = [
        'active' => 'boolean',
        'last_login' => 'datetime',
        'permission_overrides' => 'array',
    ];

    protected $appends = [
        'full_name',
    ];

    public function getAuthPassword()
    {
        return $this->password_hash;
    }

    public function setPasswordHashAttribute($value)
    {
        $this->attributes['password_hash'] = Hash::make($value);
    }

    public function getFullNameAttribute()
    {
        $name = trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? ''));

        return $name !== '' ? $name : $this->username;
    }

    public function role()
    {
        return $this->belongsTo(Role::class, 'role_id', 'id');
    }

    /**
     * The role's own permission list, before any per-user override.
     *
     * An empty list means "no access", never "all access".
     */
    public function rolePermissions(): array
    {
        $permissions = $this->role?->permissions;

        return is_array($permissions) ? $permissions : [];
    }

    /**
     * Everything this user may do: the role's list, plus per-user grants, minus
     * per-user denials.
     *
     * Deny is applied last and wins over both the grant list and the role. That
     * ordering is what makes an override usable as a restriction — "this analyst
     * keeps the Financial tab but not the revenue figures" — rather than only as
     * an extension, which would leave a restriction expressible only by inventing
     * another role.
     *
     * @return string[]
     */
    public function permissionList(): array
    {
        $overrides = is_array($this->permission_overrides) ? $this->permission_overrides : [];

        $granted = array_merge(
            $this->rolePermissions(),
            Permissions::sanitise($overrides['grant'] ?? [])
        );

        $denied = Permissions::sanitise($overrides['deny'] ?? []);

        return array_values(array_unique(array_diff($granted, $denied)));
    }

    /** The raw override record, in the shape the management screen edits. */
    public function overrides(): array
    {
        $overrides = is_array($this->permission_overrides) ? $this->permission_overrides : [];

        return [
            'grant' => Permissions::sanitise($overrides['grant'] ?? []),
            'deny' => Permissions::sanitise($overrides['deny'] ?? []),
        ];
    }

    /** Lower-cased role name, or 'viewer' for a user with no role attached. */
    public function roleName(): string
    {
        return $this->role ? strtolower(trim($this->role->role_name)) : 'viewer';
    }

    /**
     * Whether this user's *role* is one the consolidated executive view is
     * intended for.
     *
     * Deliberately separate from the module permission and checked in addition to
     * it: that view puts every company's money on one screen, and a custom role
     * should not acquire it merely by being granted a module id.
     */
    public function isExecutiveRole(): bool
    {
        return in_array($this->roleName(), Permissions::EXECUTIVE_ROLES, true);
    }

    /**
     * Site keys this user may look at, or null for unrestricted.
     *
     * Null rather than "all" on purpose: the caller must decide what
     * unrestricted means, and an empty array stays a genuine "no sites".
     */
    public function allowedSites(): ?array
    {
        $scope = $this->role?->site_scope;

        return is_array($scope) ? $scope : null;
    }

    public function can_(string $permission): bool
    {
        return in_array($permission, $this->permissionList(), true);
    }
}
