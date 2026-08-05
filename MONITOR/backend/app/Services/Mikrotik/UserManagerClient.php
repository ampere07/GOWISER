<?php

namespace App\Services\Mikrotik;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * A thin, read-mostly client for Mikrotik's User Manager REST API.
 *
 * MONITOR is otherwise a consolidator: it reads branch databases and writes only
 * to its own tables. This is the one place it reaches out and changes something
 * on live infrastructure, so the surface is kept deliberately small — list users,
 * groups, profiles and sessions; change a group's rate limit or pool; terminate
 * sessions. Nothing creates a subscriber, nothing deletes one, and nothing here
 * can change a password.
 *
 * ── Failover, and why a write is pinned to one server ─────────────────
 *
 * A read is served by the first server that answers. A write is not: User
 * Manager record ids (`.id`) are per-server, so a group id read from the
 * secondary means nothing on the primary and PATCHing it there would either fail
 * or, worse, modify a different record that happens to share the id. Every write
 * therefore locates its target first and applies the change to the server it was
 * found on, exactly as GOWISER's own RADIUS service does.
 *
 * ── Unreachable is not empty ──────────────────────────────────────────
 *
 * Every method distinguishes "the servers said there are none" from "no server
 * answered". A caller that conflated them would render an empty group list
 * during an outage, and an operator would reasonably conclude the groups had
 * been deleted. Reads return a payload carrying `reachable` and the per-server
 * errors; writes throw.
 */
class UserManagerClient
{
    /** User Manager collections this client is allowed to touch. */
    private const PATHS = [
        'users' => '/rest/user-manager/user',
        'groups' => '/rest/user-manager/user-group',
        'profiles' => '/rest/user-manager/profile',
        'sessions' => '/rest/user-manager/session',
        'limitations' => '/rest/user-manager/limitation',
    ];

    /**
     * Legacy path prefix.
     *
     * RouterOS 6 exposed this as `/rest/user-manage/…` (no "r"); RouterOS 7
     * renamed it to `/rest/user-manager/…`. Both are still in the field, so a
     * 404 on the modern path is retried on the old one rather than reported as
     * "no groups exist".
     */
    private const LEGACY_PREFIX = '/rest/user-manage/';

    /** @return array<int,array{key:string,label:string,url:string,username:string,password:string}> */
    public function servers(): array
    {
        return array_values(config('mikrotik.servers', []));
    }

    public function configured(): bool
    {
        return $this->servers() !== [];
    }

    /**
     * A collection, from the first server that answers.
     *
     * @return array{reachable:bool,server:?string,rows:array<int,array>,errors:array<string,string>}
     */
    public function list(string $collection, array $query = []): array
    {
        $this->assertCollection($collection);

        $cacheKey = 'mikrotik:' . $collection . ':' . md5(json_encode($query));
        $ttl = (int) config('mikrotik.cache_ttl', 30);

        if ($ttl > 0 && ($cached = Cache::get($cacheKey)) !== null) {
            return $cached;
        }

        $errors = [];

        foreach ($this->servers() as $server) {
            try {
                $rows = $this->request($server, 'GET', self::PATHS[$collection], $query);

                $result = [
                    'reachable' => true,
                    'server' => $server['key'],
                    'rows' => is_array($rows) ? array_values($rows) : [],
                    'errors' => $errors,
                ];

                if ($ttl > 0) {
                    Cache::put($cacheKey, $result, $ttl);
                }

                return $result;
            } catch (Throwable $e) {
                // One dead server must not hide a live one. Collected and
                // reported alongside whatever the fleet did manage to answer.
                $errors[$server['key']] = $e->getMessage();
            }
        }

        return ['reachable' => false, 'server' => null, 'rows' => [], 'errors' => $errors];
    }

    /**
     * Finds a record by a field value, and says which server holds it.
     *
     * @return array{server:array,row:array}|null
     */
    public function locate(string $collection, string $field, string $value): ?array
    {
        $this->assertCollection($collection);

        foreach ($this->servers() as $server) {
            try {
                $rows = $this->request($server, 'GET', self::PATHS[$collection]);

                foreach ((array) $rows as $row) {
                    if (isset($row[$field]) && (string) $row[$field] === $value) {
                        return ['server' => $server, 'row' => $row];
                    }
                }
            } catch (Throwable $e) {
                Log::warning('Mikrotik lookup failed on a server', [
                    'server' => $server['key'],
                    'collection' => $collection,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    /**
     * Applies attributes to one record, on the server that holds it.
     *
     * @param  array<string,string|int> $attributes
     * @return array{server:string,before:array,after:array}
     */
    public function update(string $collection, string $field, string $value, array $attributes): array
    {
        $this->assertCollection($collection);

        if ($attributes === []) {
            throw new RuntimeException('Nothing to change.');
        }

        $located = $this->locate($collection, $field, $value);

        if ($located === null) {
            throw new RuntimeException("No '{$value}' found on any reachable RADIUS server.");
        }

        $id = $located['row']['.id'] ?? null;

        if ($id === null) {
            throw new RuntimeException("The record for '{$value}' carries no id and cannot be changed.");
        }

        $this->request(
            $located['server'],
            'PATCH',
            self::PATHS[$collection] . '/' . rawurlencode((string) $id),
            $attributes
        );

        $this->flush();

        // Read back rather than assuming the PATCH landed as sent. RouterOS
        // silently normalises some values — a rate limit especially — and
        // reporting what was *asked for* as what happened is how a screen comes
        // to disagree with the router it is describing.
        $after = $this->locate($collection, $field, $value);

        return [
            'server' => $located['server']['key'],
            'before' => $located['row'],
            'after' => $after['row'] ?? [],
        ];
    }

    /**
     * Active sessions, optionally filtered to one user or group.
     *
     * @return array{reachable:bool,rows:array<int,array>,errors:array<string,string>}
     */
    public function sessions(?string $user = null): array
    {
        $result = $this->list('sessions', $user !== null && $user !== '' ? ['user' => $user] : []);

        return [
            'reachable' => $result['reachable'],
            'rows' => $result['rows'],
            'errors' => $result['errors'],
        ];
    }

    /**
     * Terminates sessions, and reports how many on each server.
     *
     * Sessions are deleted on the server they were read from — a session id, like
     * every other User Manager id, means nothing on its neighbour.
     *
     * Failures are counted rather than thrown. A kick of forty sessions that
     * fails on the thirty-first has still disconnected thirty people, and
     * throwing would report the whole operation as not having happened.
     *
     * @param  string[] $usernames Empty means every session in the given group.
     * @return array{killed:int,failed:int,attempted:int,errors:array<int,string>}
     */
    public function kick(array $usernames = [], ?string $group = null): array
    {
        $killed = 0;
        $failed = 0;
        $attempted = 0;
        $errors = [];

        foreach ($this->servers() as $server) {
            try {
                $rows = $this->request($server, 'GET', self::PATHS['sessions']);
            } catch (Throwable $e) {
                $errors[] = "{$server['key']}: {$e->getMessage()}";

                continue;
            }

            foreach ((array) $rows as $row) {
                if (!$this->sessionMatches($row, $usernames, $group)) {
                    continue;
                }

                $id = $row['.id'] ?? null;

                if ($id === null) {
                    continue;
                }

                $attempted++;

                try {
                    $this->request(
                        $server,
                        'DELETE',
                        self::PATHS['sessions'] . '/' . rawurlencode((string) $id)
                    );

                    $killed++;
                } catch (Throwable $e) {
                    $failed++;
                    $errors[] = "{$server['key']} session {$id}: {$e->getMessage()}";
                }
            }
        }

        $this->flush();

        return ['killed' => $killed, 'failed' => $failed, 'attempted' => $attempted, 'errors' => $errors];
    }

    /**
     * Whether a session is one the caller asked to terminate.
     *
     * With neither a username list nor a group this returns false, not true. An
     * empty filter meaning "everything" would make a mistyped request into a
     * network-wide disconnection, and that is not a mistake worth leaving
     * available.
     */
    private function sessionMatches(array $row, array $usernames, ?string $group): bool
    {
        if ($usernames !== []) {
            return in_array((string) ($row['user'] ?? ''), $usernames, true);
        }

        if ($group !== null && $group !== '') {
            return (string) ($row['user-group'] ?? $row['group'] ?? '') === $group;
        }

        return false;
    }

    /** Drops the read cache, so a write is visible on the next read. */
    public function flush(): void
    {
        foreach (array_keys(self::PATHS) as $collection) {
            Cache::forget('mikrotik:' . $collection . ':' . md5(json_encode([])));
        }
    }

    /**
     * One HTTP call, with the RouterOS 6 path as a fallback.
     *
     * @return array<mixed>
     */
    private function request(array $server, string $method, string $path, array $payload = [])
    {
        $response = $this->send($server, $method, $path, $payload);

        // RouterOS 6 spells the collection without the trailing "r". Retried
        // rather than reported, because to an operator "404" and "this feature
        // does not work on our routers" are the same unhelpful message.
        if ($response->status() === 404 && str_starts_with($path, '/rest/user-manager/')) {
            $legacy = self::LEGACY_PREFIX . substr($path, strlen('/rest/user-manager/'));
            $response = $this->send($server, $method, $legacy, $payload);
        }

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                '%s returned HTTP %d%s',
                $server['label'] ?? $server['key'],
                $response->status(),
                ($message = data_get($response->json(), 'message')) ? ": {$message}" : ''
            ));
        }

        return $response->json() ?? [];
    }

    private function send(array $server, string $method, string $path, array $payload)
    {
        $request = Http::withBasicAuth((string) $server['username'], (string) $server['password'])
            ->withOptions(['verify' => (bool) config('mikrotik.verify_tls', false)])
            ->connectTimeout((int) config('mikrotik.connect_timeout', 4))
            ->timeout((int) config('mikrotik.timeout', 12))
            ->acceptJson();

        $url = rtrim((string) $server['url'], '/') . $path;

        return $method === 'GET'
            ? $request->get($url, $payload)
            : $request->send($method, $url, ['json' => $payload]);
    }

    private function assertCollection(string $collection): void
    {
        if (!isset(self::PATHS[$collection])) {
            // Guards against a controller passing a caller-supplied string
            // straight through and turning this into an arbitrary-path proxy
            // onto a router.
            throw new RuntimeException("Unknown User Manager collection '{$collection}'.");
        }
    }
}
