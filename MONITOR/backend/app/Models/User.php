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
    ];

    protected $appends = [
        'full_name',
    ];

    /** Memoised expansion of the role's stored permissions. Not a database column. */
    private ?array $effectivePermissions = null;

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
     * The role's permission list exactly as stored. Raw — may hold bare section ids from before
     * verbs existed. Callers wanting to make an access decision want effectivePermissions().
     *
     * An empty list means "no access", never "all access".
     */
    public function permissionList(): array
    {
        $permissions = $this->role?->permissions;

        return is_array($permissions) ? $permissions : [];
    }

    /**
     * The stored list expanded into `section.verb` grants.
     *
     * Memoised per instance: this runs on every guarded request, and the answer cannot change
     * within one request — a role edit takes effect on the next one.
     */
    public function effectivePermissions(): array
    {
        if ($this->effectivePermissions !== null) {
            return $this->effectivePermissions;
        }

        // Delegated to the role, which is the single place that decides — including the
        // superadmin case, where the grant list is generated from the section map rather than
        // stored, so a section added later is covered with no migration.
        //
        // No role at all means no permissions: an account can sign in and see nothing, and every
        // guard fails closed rather than guessing.
        return $this->effectivePermissions = $this->role?->effectivePermissions() ?? [];
    }

    /**
     * Does this user hold the permission?
     *
     * Accepts both `financial.export` and a bare `databases`, the latter meaning "any verb on
     * that section" — which is what the pre-existing call sites pass.
     */
    public function can_(string $permission): bool
    {
        return Permissions::granted($this->effectivePermissions(), $permission);
    }

    /**
     * Does this account hold the unrestricted role?
     *
     * Read from the role's flag rather than inferred from holding every permission: a role that
     * happens to list all of them today is still an ordinary role, and should not silently gain
     * superadmin-only powers such as creating other accounts.
     */
    public function isSuperadmin(): bool
    {
        return (bool) $this->role?->is_superadmin;
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

}
