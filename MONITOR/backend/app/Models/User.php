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
        'preferences',
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
        'preferences' => 'array',
    ];

    /**
     * How often each auto-refreshing screen re-reads itself, in seconds.
     *
     * Zero means off. The defaults are deliberately different: the Group
     * Overview fans out across every monitored database and is read at a glance
     * a few times a day, so it starts off; MikroTik RADIUS describes live
     * sessions an operator is in the middle of changing and is stale within a
     * minute, so it starts at sixty seconds — frequent enough to be current,
     * long enough not to hammer a router that is also serving authentication.
     */
    public const REFRESH_DEFAULTS = [
        'overview_refresh' => 0,
        'mikrotik_refresh' => 60,
    ];

    /** Intervals the Settings screen offers, and the only ones accepted. */
    public const REFRESH_CHOICES = [0, 10, 30, 60, 300];

    /**
     * The refresh intervals this session runs on, with the defaults filled in.
     *
     * ── Why these are portal-wide and no longer per-user ──────────────
     *
     * They used to live in this row's `preferences` column, on the argument that
     * a NOC operator watching a wall display and an executive who opens the page
     * twice a day want opposite things. In practice the number decides how often
     * MONITOR fans out across every monitored database, and on the MikroTik
     * screen how often it reaches routers that are simultaneously serving live
     * authentication — so it is a statement about production load, and the total
     * was the sum of a dozen private choices nobody could see in one place.
     *
     * Read through AppSetting so one value governs every account. The signature
     * is unchanged, which is why every caller of this still reads correctly; the
     * per-user column is simply no longer consulted. Rows that still hold one
     * are harmless and are left alone rather than migrated away — the column may
     * carry another screen's preference later.
     *
     * ── Why it is not called preferences() ────────────────────────────
     *
     * A method named for a column is a trap in Eloquent: on a database where the
     * column does not exist yet, `$this->preferences` misses the attribute bag,
     * falls through to the relation resolver, finds this method and dies with
     * "must return a relationship instance".
     *
     * @return array<string,int>
     */
    public function refreshPreferences(): array
    {
        return AppSetting::refreshIntervals();
    }

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
        // Super Admin and Executive hold everything this build knows about,
        // whatever their stored map happens to say. See
        // Permissions::FULL_ACCESS_ROLES for why this is a short-circuit rather
        // than a seeded list — chiefly that a permission added in a later build
        // would otherwise be missing from a role whose whole point is not to
        // have gaps.
        if (in_array($this->roleName(), Permissions::FULL_ACCESS_ROLES, true)) {
            return Permissions::all();
        }

        $permissions = $this->role?->permissions;

        return is_array($permissions) ? $permissions : [];
    }

    /** Whether this user's role bypasses the permission map entirely. */
    public function hasFullAccess(): bool
    {
        return in_array($this->roleName(), Permissions::FULL_ACCESS_ROLES, true);
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
