<?php

namespace App\Services;

use App\Models\SiteConnection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Resolves a source key sent by the frontend (?source=isabela) into a database
 * connection, and nothing else.
 *
 * Sources come from the `site_connections` table and nowhere else — that table is
 * what the Databases page writes, so adding a database is a row, not a deploy.
 * There is deliberately no .env-defined alternative: two competing lists of which
 * databases exist is how a portal ends up reading one and reporting the other.
 *
 * The point of routing every lookup through here is that a caller can never hand
 * an arbitrary string to DB::connection(). Only registered keys resolve, and every
 * connection is fitted with a guard that rejects anything but a read.
 */
class SourceRegistry
{
    /**
     * Connection names already fitted with the read-only guard. Laravel caches
     * connection instances, so the callback must only be registered once per
     * name or it stacks up on every request.
     *
     * @var array<string, true>
     */
    private static array $guarded = [];

    /** @var array<string, array>|null */
    private ?array $cache = null;

    /**
     * Maps a site's schema profile to the driver that knows how to query it.
     *
     * `sync` is the profile key the seeder uses for the GOWISER schema; both
     * names are accepted so a row created either way resolves.
     */
    private const PROFILE_DRIVERS = [
        'sync' => 'gowiser',
        'gowiser' => 'gowiser',
        'netmanager' => 'netmanager',
    ];

    /**
     * Every enabled database, in display order.
     *
     * A failure is swallowed on purpose: MONITOR's own database being briefly
     * unavailable, or the table not yet migrated, must yield an empty list rather
     * than a stack trace on every dashboard.
     *
     * @return array<string, array{key:string,label:string,driver:string,scope:?array}>
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        try {
            $rows = SiteConnection::where('enabled', true)
                ->orderBy('sort_order')
                ->orderBy('label')
                ->get();
        } catch (\Throwable $e) {
            report($e);

            return $this->cache = [];
        }

        $sources = [];

        foreach ($rows as $row) {
            $driver = self::PROFILE_DRIVERS[$row->profile_key] ?? null;

            // A row naming a structure no driver can query would fail
            // confusingly at query time, so it is left out of the list entirely.
            if ($driver === null) {
                continue;
            }

            $sources[$row->key] = [
                'key' => $row->key,
                'label' => $row->label,
                'driver' => $driver,
                'scope' => $row->scope,
                'model' => $row,
            ];
        }

        return $this->cache = $sources;
    }

    public function keys(): array
    {
        return array_keys($this->all());
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    /**
     * Normalises a caller-supplied key.
     *
     * With none given, the first database in display order answers — which is why
     * the Databases page lets you reorder, and says that the top row is the
     * default.
     */
    public function resolveKey(?string $key): string
    {
        $key = $key !== null ? trim($key) : '';

        if ($key !== '') {
            if (!$this->has($key)) {
                throw new InvalidArgumentException("Unknown monitoring source [{$key}].");
            }

            return $key;
        }

        $available = $this->keys();

        if ($available === []) {
            throw new InvalidArgumentException(
                'No databases are configured yet. Add one on the Databases page.'
            );
        }

        return $available[0];
    }

    public function label(string $key): string
    {
        return $this->all()[$key]['label'] ?? $key;
    }

    /** Which schema driver this source's database uses. */
    public function driverName(string $key): string
    {
        $this->assertKnown($key);

        return $this->all()[$key]['driver'];
    }

    /**
     * Row-level scope for sources that share one database, separated by a column
     * rather than by their own schema: ['column' => 'organization_id', 'value' => 3].
     *
     * Null when the source has its own database, which is the usual case.
     */
    public function scope(string $key): ?array
    {
        $this->assertKnown($key);

        $scope = $this->all()[$key]['scope'] ?? null;

        return ($scope && !empty($scope['column'])) ? $scope : null;
    }

    /**
     * A live connection to the source, guarded so nothing but reads can ever be
     * issued through it.
     *
     * Registered with Laravel at runtime rather than living in
     * config/database.php, which is what lets an operator add a database from the
     * configuration page without a deploy.
     */
    public function connection(string $key): ConnectionInterface
    {
        $this->assertKnown($key);

        /** @var SiteConnection $site */
        $site = $this->all()[$key]['model'];
        $name = 'site_' . $key;

        Config::set("database.connections.{$name}", $site->connectionConfig());

        // Drop any cached handle so an edited credential takes effect without
        // restarting the process.
        if (!isset(self::$guarded[$name])) {
            DB::purge($name);
        }

        $connection = DB::connection($name);

        // Belt and braces: even if someone grants these credentials write
        // access, this application will not use it.
        if (!isset(self::$guarded[$name])) {
            $connection->beforeExecuting(function ($query) {
                if (!preg_match('/^\s*(select|show|describe|explain|with)\b/i', $query)) {
                    throw new InvalidArgumentException(
                        'Only read queries may be issued against a monitored source.'
                    );
                }
            });

            self::$guarded[$name] = true;
        }

        return $connection;
    }

    /**
     * Clears the memoised source list.
     *
     * Called after the configuration page writes, so the next request sees the
     * change without waiting for the process to recycle.
     */
    public function flush(): void
    {
        $this->cache = null;
    }

    private function assertKnown(string $key): void
    {
        if (!$this->has($key)) {
            throw new InvalidArgumentException("Unknown monitoring source [{$key}].");
        }
    }
}
