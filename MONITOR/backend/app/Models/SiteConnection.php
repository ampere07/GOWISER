<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class SiteConnection extends Model
{
    use HasFactory;

    protected $table = 'site_connections';

    protected $fillable = [
        'key',
        'label',
        'profile_key',
        'driver',
        'host',
        'port',
        'database',
        'username',
        'password',
        'timezone',
        'overrides',
        'scope',
        'enabled',
        'sort_order',
    ];

    protected $casts = [
        'overrides' => 'array',
        'scope' => 'array',
        'enabled' => 'boolean',
        'last_checked_at' => 'datetime',
    ];

    // Never serialise the credential, even by accident.
    protected $hidden = ['password'];

    public function profile()
    {
        return $this->belongsTo(SchemaProfile::class, 'profile_key', 'key');
    }

    /**
     * Encrypted at rest with APP_KEY. Assigning null or '' leaves the stored
     * value untouched so an edit form can omit the password field rather than
     * blanking it every time someone renames a site.
     */
    public function setPasswordAttribute($value)
    {
        if ($value === null || $value === '') {
            return;
        }

        $this->attributes['password'] = Crypt::encryptString($value);
    }

    public function plainPassword(): string
    {
        $stored = $this->attributes['password'] ?? null;

        if (!$stored) {
            return '';
        }

        try {
            return Crypt::decryptString($stored);
        } catch (\Throwable $e) {
            // Wrong APP_KEY, or the row predates encryption. Failing loudly
            // here would take the whole dashboard down, so report and let the
            // connection attempt produce the user-facing error.
            report($e);

            return '';
        }
    }

    /**
     * Laravel connection config for this site. Marked read-only at the driver
     * level as a third line of defence behind the SQL guard and the grant.
     */
    public function connectionConfig(): array
    {
        return [
            'driver' => $this->driver ?: 'mysql',
            'host' => $this->host,
            'port' => $this->port ?: 3306,
            'database' => $this->database,
            'username' => $this->username,
            'password' => $this->plainPassword(),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => false,
            'engine' => null,
            'timezone' => $this->timezone ?: '+08:00',
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                \PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ];
    }
}
