<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * One RADIUS server MONITOR may talk to, as configured in Administration.
 *
 * Ported from GOWISER's `radius_config` — same table name and same columns, so
 * an operator who has configured one system recognises the other. See the
 * migration for why this moved out of the environment and what replaced the
 * deploy gate it lost.
 *
 * ── The password ──────────────────────────────────────────────────────
 *
 * Encrypted at rest by the `encrypted` cast, and never serialised: it is in
 * `$hidden`, so a stray `toArray()` or a model returned straight from a
 * controller cannot leak it. Read it deliberately, through `password`, or not at
 * all.
 *
 * ── Ordering is failover order ────────────────────────────────────────
 *
 * Rows are ordered by id and the position is the identity: #1 is `primary`, #2
 * is `secondary`, matching the keys config/mikrotik.php has always used and the
 * #1/#2 convention in GOWISER's RadiusServerResolver. A read is served by the
 * first server that answers; a write is applied to the server the record was
 * found on, because User Manager ids are per-server.
 */
class RadiusServer extends Model
{
    protected $table = 'radius_config';

    protected $fillable = [
        'ssl_type',
        'ip',
        'port',
        'username',
        'password',
        'label',
        'is_active',
        'updated_by',
    ];

    protected $hidden = ['password'];

    protected $casts = [
        'password' => 'encrypted',
        'is_active' => 'boolean',
    ];

    /**
     * How many servers may be configured.
     *
     * Two, matching GOWISER and the primary/secondary pair the client has always
     * assumed. A third would silently never be reached by anything that talks
     * about "the secondary", and a cap is a better answer than a surprise.
     */
    public const MAX_SERVERS = 2;

    /** Position keys, in failover order. */
    public const KEYS = ['primary', 'secondary'];

    private const CACHE_KEY = 'radius:servers';

    /** Seconds. Short: this is read on every RADIUS call, and edited rarely. */
    private const CACHE_TTL = 300;

    /**
     * The active servers in failover order, shaped for UserManagerClient.
     *
     * Cached because it is read on every User Manager request and changes a few
     * times a year. Writes call `forget()`, so an operator never sees their own
     * change fail to take effect — which on this screen would mean pointing a
     * disconnect at the old host.
     *
     * @return array<int,array{key:string,label:string,url:string,username:string,password:string}>
     */
    public static function fleet(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function (): array {
            try {
                $rows = static::query()
                    ->where('is_active', true)
                    ->orderBy('id')
                    ->limit(self::MAX_SERVERS)
                    ->get();
            } catch (QueryException $e) {
                // The table is missing, which on a box that has pulled this code
                // but not run the migration is the *expected* state — and the
                // right behaviour there is to fall through to the environment
                // variables, which is exactly what an empty fleet does. Failing
                // instead would take MikroTik RADIUS down on every deploy for
                // the length of the migration.
                Log::warning('radius_config unreadable; falling back to config/mikrotik.php', [
                    'error' => $e->getMessage(),
                ]);

                return [];
            }

            $fleet = [];

            foreach ($rows as $index => $row) {
                $url = $row->baseUrl();

                // A row with no host reaches nothing. Skipped rather than
                // returned, so the client's "configured" check stays honest.
                if ($url === null) {
                    continue;
                }

                $key = self::KEYS[$index] ?? 'radius-' . ($index + 1);

                $fleet[] = [
                    'key' => $key,
                    'label' => $row->label ?: ucfirst($key) . ' RADIUS',
                    'url' => $url,
                    'username' => (string) $row->username,
                    'password' => (string) $row->password,
                ];
            }

            return $fleet;
        });
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * The REST base URL — "https://10.0.0.2:443".
     *
     * Null when there is no host to build one from. The port is omitted when it
     * is the scheme's default, because RouterOS presents its certificate for the
     * bare host and an explicit :443 is one more thing to get wrong.
     */
    public function baseUrl(): ?string
    {
        $host = trim((string) $this->ip);

        if ($host === '') {
            return null;
        }

        $scheme = strtolower(trim((string) $this->ssl_type)) === 'http' ? 'http' : 'https';
        $port = trim((string) $this->port);

        $default = $scheme === 'https' ? '443' : '80';

        return $port === '' || $port === $default
            ? "{$scheme}://{$host}"
            : "{$scheme}://{$host}:{$port}";
    }

    /**
     * The row as the API reports it — everything except the secret.
     *
     * `has_password` rather than the password, or a row of asterisks: the first
     * is a fact the form needs (whether to require the field), the second is a
     * value that would be submitted back as if it were real.
     */
    public function toPublicArray(int $position): array
    {
        return [
            'id' => $this->id,
            'position' => $position,
            'key' => self::KEYS[$position - 1] ?? 'radius-' . $position,
            'label' => $this->label,
            'ssl_type' => $this->ssl_type,
            'ip' => $this->ip,
            'port' => $this->port,
            'username' => $this->username,
            'has_password' => trim((string) $this->getRawOriginal('password')) !== '',
            'is_active' => (bool) $this->is_active,
            'base_url' => $this->baseUrl(),
            'updated_by' => $this->updated_by,
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
