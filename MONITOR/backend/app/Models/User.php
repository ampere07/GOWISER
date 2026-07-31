<?php

namespace App\Models;

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
     * Sections of the dashboard this user may see. Falls back to the role's
     * permission list; an empty list means "no access", never "all access".
     */
    public function permissionList(): array
    {
        $permissions = $this->role?->permissions;

        return is_array($permissions) ? $permissions : [];
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
