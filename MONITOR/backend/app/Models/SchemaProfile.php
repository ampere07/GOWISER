<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SchemaProfile extends Model
{
    use HasFactory;

    protected $table = 'schema_profiles';

    protected $fillable = [
        'key',
        'label',
        'description',
        'definition',
        'is_system',
    ];

    protected $casts = [
        'definition' => 'array',
        'is_system' => 'boolean',
    ];

    public function connections()
    {
        return $this->hasMany(SiteConnection::class, 'profile_key', 'key');
    }

    /** Dataset names this profile actually maps. */
    public function datasetKeys(): array
    {
        return array_keys($this->definition['datasets'] ?? []);
    }
}
