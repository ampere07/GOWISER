<?php

namespace App\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Resolves a source key sent by the frontend (?source=gowiser) into a database
 * connection, and nothing else.
 *
 * The point of routing every source lookup through here is that a caller can
 * never hand an arbitrary string to DB::connection(). Only keys registered in
 * config/monitor.php resolve; anything else throws.
 */
class SourceRegistry
{
    /**
     * @return array<string, array{key:string,label:string,connection:string}>
     */
    public function all(): array
    {
        $sources = [];

        foreach (config('monitor.sources', []) as $key => $definition) {
            if (!($definition['enabled'] ?? false)) {
                continue;
            }

            $sources[$key] = [
                'key' => $key,
                'label' => $definition['label'] ?? $key,
                'connection' => $definition['connection'],
                'driver' => $definition['driver'] ?? $key,
            ];
        }

        return $sources;
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
     * Normalises a caller-supplied key, falling back to the configured default.
     */
    public function resolveKey(?string $key): string
    {
        $key = $key !== null ? trim($key) : '';

        if ($key !== '' && $this->has($key)) {
            return $key;
        }

        if ($key !== '') {
            throw new InvalidArgumentException("Unknown monitoring source [{$key}].");
        }

        $default = config('monitor.default_source');

        if (!$this->has($default)) {
            $available = $this->keys();

            if (empty($available)) {
                throw new InvalidArgumentException('No monitoring sources are enabled.');
            }

            return $available[0];
        }

        return $default;
    }

    public function label(string $key): string
    {
        return $this->all()[$key]['label'] ?? $key;
    }

    /** Which schema driver this source's database uses. */
    public function driverName(string $key): string
    {
        if (!$this->has($key)) {
            throw new InvalidArgumentException("Unknown monitoring source [{$key}].");
        }

        return $this->all()[$key]['driver'];
    }

    /**
     * Connection names already fitted with the read-only guard. Laravel caches
     * connection instances, so the callback must only be registered once per
     * name or it stacks up on every request.
     *
     * @var array<string, true>
     */
    private static array $guarded = [];

    public function connection(string $key): ConnectionInterface
    {
        if (!$this->has($key)) {
            throw new InvalidArgumentException("Unknown monitoring source [{$key}].");
        }

        $name = $this->all()[$key]['connection'];
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
}
