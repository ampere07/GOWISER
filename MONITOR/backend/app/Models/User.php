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
     * This user's refresh intervals, with the defaults filled in.
     *
     * Always complete, so no caller has to know what the fallback is — one place
     * that knows is what stops the dashboard and the settings form disagreeing
     * about what "default" means.
     *
     * ── Why it is not called preferences() ────────────────────────────
     *
     * A method named for a column is a trap in Eloquent: on a database where the
     * column does not exist yet, `$this->preferences` misses the attribute bag,
     * falls through to the relation resolver, finds this method and dies with
     * "must return a relationship instance". That is exactly the state a box is
     * in between deploying this code and running the migration, so the name is
     * kept distinct and the attribute is read out of the raw bag below.
     *
     * @return array<string,int>
     */
    public function refreshPreferences(): array
    {
        // getAttributes() rather than the magic property: it never consults the
        // relation resolver, so this is safe before the migration has run.
        $raw = $this->getAttributes()['preferences'] ?? null;

        $stored = is_string($raw) ? json_decode($raw, true) : $raw;
        $stored = is_array($stored) ? $stored : [];

        $out = [];

        foreach (self::REFRESH_DEFAULTS as $key => $default) {
            $value = $stored[$key] ?? null;

            // An unrecognised interval falls back rather than being honoured. A
            // stale value from an older build — or a hand-edited row — must not
            // put a two-second poll on eight databases.
            $out[$key] = in_array($value, self::REFRESH_CHOICES, true) ? (int) $value : $default;
        }

        return $out;
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
