<?php

namespace App\Models;

use App\Support\Permissions;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use HasFactory;

    protected $primaryKey = 'id';
    protected $table = 'roles';

    protected $fillable = [
        'role_name',
        'description',
        'permissions',
        'site_scope',
        'is_system',
        'is_superadmin',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected $casts = [
        'permissions' => 'array',
        'site_scope' => 'array',
        'is_system' => 'boolean',
        'is_superadmin' => 'boolean',
    ];

    public function users()
    {
        return $this->hasMany(User::class, 'role_id', 'id');
    }

    /**
     * What this role actually grants, as `section.verb` strings.
     *
     * The single place that decides — User::effectivePermissions() and the last-administrator
     * guard in UserController both defer to it, so a superadmin cannot be judged by one rule in
     * one place and a different rule in another.
     */
    public function effectivePermissions(): array
    {
        if ($this->is_superadmin) {
            return Permissions::all();
        }

        return Permissions::expand(is_array($this->permissions) ? $this->permissions : []);
    }
}
