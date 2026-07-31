<?php

namespace App\Services\Connector;

use App\Models\SchemaProfile;
use App\Models\SiteConnection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Turns a site_connections row into a live, read-only database connection and
 * a set of resolved dataset mappings.
 *
 * Connections are registered with Laravel at runtime rather than living in
 * config/database.php, which is what lets an operator add a site from the
 * admin screen without a deploy.
 */
class ConnectionManager
{
    /** @var array<string,true> connection names already given the read guard */
    private static array $guarded = [];

    /** @var array<string,SiteConnection> */
    private array $sites = [];

    /** @var array<string,SchemaProfile> */
    private array $profiles = [];

    /**
     * @return SiteConnection[] keyed by site key, enabled only
     */
    public function sites(): array
    {
        if ($this->sites === []) {
            $this->sites = SiteConnection::where('enabled', true)
                ->orderBy('sort_order')
                ->orderBy('label')
                ->get()
                ->keyBy('key')
                ->all();
        }

        return $this->sites;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->sites());
    }

    public function site(string $key): SiteConnection
    {
        if (!$this->has($key)) {
            throw new InvalidArgumentException("Unknown site [{$key}].");
        }

        return $this->sites()[$key];
    }

    public function keys(): array
    {
        return array_keys($this->sites());
    }

    /**
     * Normalises a caller-supplied key, falling back to the first enabled site.
     */
    public function resolveKey(?string $key): string
    {
        $key = $key !== null ? trim($key) : '';

        if ($key !== '') {
            if (!$this->has($key)) {
                throw new InvalidArgumentException("Unknown site [{$key}].");
            }

            return $key;
        }

        $available = $this->keys();

        if ($available === []) {
            throw new RuntimeException('No site connections are configured yet.');
        }

        return $available[0];
    }

    /**
     * A live connection to the site, registered on first use and guarded so
     * nothing but reads can ever be issued through it.
     */
    public function connection(string $key): ConnectionInterface
    {
        $site = $this->site($key);
        $name = 'site_' . $site->key;

        Config::set("database.connections.{$name}", $site->connectionConfig());

        // Drop any cached handle so an edited credential takes effect without
        // restarting the process.
        if (!isset(self::$guarded[$name])) {
            DB::purge($name);
        }

        $connection = DB::connection($name);

        if (!isset(self::$guarded[$name])) {
            $connection->beforeExecuting(function ($query) {
                if (!preg_match('/^\s*(select|show|describe|explain|with)\b/i', $query)) {
                    throw new InvalidArgumentException(
                        'Only read queries may be issued against a monitored site.'
                    );
                }
            });

            self::$guarded[$name] = true;
        }

        return $connection;
    }

    /**
     * Tries the connection and reports the outcome, recording it on the row so
     * the admin list can show which sites are healthy.
     */
    public function test(SiteConnection $site): array
    {
        $name = 'site_test_' . $site->key;
        Config::set("database.connections.{$name}", $site->connectionConfig());
        DB::purge($name);

        try {
            $tables = DB::connection($name)->select('SHOW TABLES');

            $site->forceFill([
                'last_status' => 'ok',
                'last_error' => null,
                'last_checked_at' => now(),
            ])->save();

            return [
                'ok' => true,
                'tables' => count($tables),
                'message' => 'Connected. ' . count($tables) . ' tables visible.',
            ];
        } catch (\Throwable $e) {
            $site->forceFill([
                'last_status' => 'failed',
                'last_error' => $e->getMessage(),
                'last_checked_at' => now(),
            ])->save();

            return ['ok' => false, 'tables' => 0, 'message' => $e->getMessage()];
        } finally {
            DB::purge($name);
        }
    }

    /**
     * Table and column names on the site, so the mapping admin can offer real
     * choices instead of asking someone to type column names from memory.
     */
    public function introspect(string $key, ?string $table = null): array
    {
        $db = $this->connection($key);
        $site = $this->site($key);

        if ($table === null) {
            $rows = $db->select(
                'SELECT table_name AS name, table_rows AS approx_rows
                 FROM information_schema.tables
                 WHERE table_schema = ? ORDER BY table_name',
                [$site->database]
            );

            return ['tables' => array_map(fn ($r) => [
                'name' => $r->name,
                'approx_rows' => (int) $r->approx_rows,
            ], $rows)];
        }

        $rows = $db->select(
            'SELECT column_name AS name, data_type AS type, is_nullable AS nullable
             FROM information_schema.columns
             WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position',
            [$site->database, $table]
        );

        return ['table' => $table, 'columns' => array_map(fn ($r) => [
            'name' => $r->name,
            'type' => $r->type,
            'nullable' => $r->nullable === 'YES',
        ], $rows)];
    }

    // ── Mapping resolution ───────────────────────────────────────────────

    public function profile(string $profileKey): SchemaProfile
    {
        if (!isset($this->profiles[$profileKey])) {
            $profile = SchemaProfile::where('key', $profileKey)->first();

            if (!$profile) {
                throw new RuntimeException("Schema profile [{$profileKey}] does not exist.");
            }

            $this->profiles[$profileKey] = $profile;
        }

        return $this->profiles[$profileKey];
    }

    /**
     * The site's definition: its profile, with any per-site overrides merged
     * on top. Overrides are a partial document — a site that only moved one
     * column declares only that column.
     */
    public function definitionFor(string $key): array
    {
        $site = $this->site($key);
        $base = $this->profile($site->profile_key)->definition ?? [];

        return $this->mergeDeep($base, $site->overrides ?? []);
    }

    /** Canonical datasets this site can actually answer. */
    public function datasetsFor(string $key): array
    {
        $definition = $this->definitionFor($key);
        $available = [];

        foreach ($definition['datasets'] ?? [] as $dataset => $spec) {
            if (($spec['enabled'] ?? true) === false) {
                continue;
            }

            if (empty($spec['table'])) {
                continue;
            }

            // A dataset with a missing required field would fail at query time
            // with a confusing error, so treat it as unavailable instead.
            $required = config("datasets.{$dataset}.required", []);
            $fields = $spec['fields'] ?? [];

            foreach ($required as $field) {
                if (empty($fields[$field])) {
                    continue 2;
                }
            }

            $available[] = $dataset;
        }

        return $available;
    }

    public function supports(string $key, string $dataset): bool
    {
        return in_array($dataset, $this->datasetsFor($key), true);
    }

    public function map(string $key, string $dataset): DatasetMap
    {
        $definition = $this->definitionFor($key);
        $spec = $definition['datasets'][$dataset] ?? null;

        if (!$spec || empty($spec['table'])) {
            throw new RuntimeException(
                "This site does not map the [{$dataset}] dataset."
            );
        }

        $site = $this->site($key);
        $scope = $site->scope;

        return new DatasetMap(
            dataset: $dataset,
            table: $spec['table'],
            alias: $spec['alias'] ?? 'src',
            fields: $spec['fields'] ?? [],
            filters: $spec['filters'] ?? [],
            joins: $spec['joins'] ?? [],
            scope: ($scope && !empty($scope['column'])) ? $scope : null
        );
    }

    /**
     * Recursive merge where a scalar or list in the override replaces the base
     * outright. Lists must replace rather than append: a site correcting a
     * filter needs the old one gone, not both applied.
     */
    private function mergeDeep(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && $this->isAssoc($value)) {
                $base[$key] = $this->mergeDeep($base[$key], $value);
                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }

    private function isAssoc(array $array): bool
    {
        return $array !== [] && array_keys($array) !== range(0, count($array) - 1);
    }
}
