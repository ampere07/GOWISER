<?php

namespace App\Services\Mikrotik;

use App\Models\RadiusServer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
    /**
     * The menu each collection lives under, best candidate first.
     *
     * ── The HTTP 500 on User Groups ───────────────────────────────────
     *
     * `groups` used to be a single path, `/user-manager/user-group`, and that
     * menu does not exist on any RouterOS build. In RouterOS 7 the group list is
     * a *submenu of user* — `/user-manager/user/group` — which is why WebFig
     * shows it as "User Groups" nested under User Manager and why this repo's
     * own legacy client (frontend/views/lib/RouterosAPI.php) asks for
     * `/user/group/print`.
     *
     * RouterOS answers an unresolvable menu with HTTP 500 and "no such command
     * prefix" rather than a 404, so the symptom was a 500 on groups while
     * sessions returned thousands of rows — sessions were on a path that exists.
     *
     * Several spellings are kept per collection because the field runs both
     * generations at once and this fleet demonstrably does: GOWISER's own
     * services all talk to `/rest/user-manage/…` (no "r"), which is the
     * RouterOS 6 spelling, while newer boxes answer `/rest/user-manager/…`.
     * Rather than guess, each candidate is tried once and the one that answers
     * is remembered per server.
     *
     * @var array<string,string[]>
     */
    private const MENUS = [
        'users' => ['/user'],
        // Order matters: the real menu first, the historical spellings after.
        'groups' => ['/user/group', '/user-group', '/group'],
        'profiles' => ['/profile'],
        'sessions' => ['/session'],
        'limitations' => ['/limitation'],
        // The RADIUS dictionary — which attributes this User Manager knows how
        // to send, and in which packet types. Read-only here: editing the
        // dictionary is a router-configuration job, not a subscriber one, and a
        // wrong entry breaks authentication for everyone rather than for a group.
        'attributes' => ['/attribute'],
    ];

    /**
     * REST roots, newest first.
     *
     * RouterOS 6 exposed User Manager as `/rest/user-manage/…` (no "r");
     * RouterOS 7 renamed it to `/rest/user-manager/…`. Both are in this fleet.
     */
    private const PREFIXES = ['/rest/user-manager', '/rest/user-manage'];

    /**
     * Resolved path per server and collection, for the life of the request.
     *
     * Probing is a real cost — a wrong candidate is a round trip to a router
     * that is also serving live authentication — so it happens once per
     * collection rather than once per call. Also cached across requests; see
     * resolvePath().
     *
     * @var array<string,string>
     */
    private array $resolved = [];

    /**
     * The fleet, in failover order.
     *
     * Administration Settings wins over the environment. The table is the
     * manageable surface — an operator can move a RADIUS host without a deploy —
     * and the env vars remain as the fallback so an existing deployment keeps
     * working until somebody fills the form in. Precedence rather than merge:
     * two half-configured sources producing a three-server fleet is a worse
     * failure than either being wrong on its own.
     *
     * @return array<int,array{key:string,label:string,url:string,username:string,password:string}>
     */
    public function servers(): array
    {
        $managed = RadiusServer::fleet();

        if ($managed !== []) {
            return $managed;
        }

        return array_values(config('mikrotik.servers', []));
    }

    /** Where the current fleet came from, so the settings screen can say so. */
    public function source(): string
    {
        return RadiusServer::fleet() !== [] ? 'settings' : 'environment';
    }

    /**
     * Whether one server answers, and how quickly.
     *
     * A round trip to the smallest collection the API has rather than a socket
     * open: a router that accepts TCP but rejects the credentials is not a
     * working configuration, and "the port is open" is exactly the answer that
     * sends an operator looking in the wrong place.
     *
     * @return array{online:bool,latency_ms:?float,error:?string}
     */
    public function probe(array $server): array
    {
        $started = microtime(true);

        try {
            $this->request($server, 'GET', $this->path($server, 'groups'));

            return [
                'online' => true,
                'latency_ms' => round((microtime(true) - $started) * 1000, 1),
                'error' => null,
            ];
        } catch (Throwable $e) {
            Log::warning('RADIUS probe failed', [
                'server' => $server['key'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return ['online' => false, 'latency_ms' => null, 'error' => $e->getMessage()];
        }
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
                $rows = $this->request($server, 'GET', $this->path($server, $collection), $query);

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
                $rows = $this->request($server, 'GET', $this->path($server, $collection));

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
            $this->path($located['server'], $collection) . '/' . $this->encodeId($id),
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
                $rows = $this->request($server, 'GET', $this->path($server, 'sessions'));
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
                        $this->path($server, 'sessions') . '/' . $this->encodeId($id)
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
        foreach (array_keys(self::MENUS) as $collection) {
            Cache::forget('mikrotik:' . $collection . ':' . md5(json_encode([])));
        }
    }

    /**
     * Message fragments RouterOS uses to mean "that path does not exist here".
     *
     * ── The HTTP 500 bug ──────────────────────────────────────────────
     *
     * RouterOS's REST service does not answer an unknown path with 404. It hands
     * the request to the console, the console fails to resolve the command, and
     * the failure comes back as **HTTP 500** with the real reason in the JSON
     * body:
     *
     *     HTTP/1.1 500 Internal Server Error
     *     {"error":500,"message":"no such command prefix"}
     *
     * That is the whole of `culi-radius1 returned HTTP 500: Internal Server
     * Error`. Nothing is broken on the router and nothing is wrong with the
     * credentials — the box is a RouterOS 6 / early-7 build that spells the menu
     * `/rest/user-manage/…`, and the retry that exists for exactly this case only
     * fired on 404, which RouterOS never sends.
     *
     * So the legacy retry is triggered by what the body *says* rather than by the
     * status code, on any failing status. A router that genuinely has an internal
     * fault says something else and is still reported as a fault.
     */
    private const UNKNOWN_PATH_HINTS = [
        'no such command prefix',
        'no such command',
        'unknown command',
        'no such item',
        'not found',
        'bad command name',
    ];

    /**
     * The REST path this server answers for a collection.
     *
     * Probes the candidates once — every REST root crossed with every menu
     * spelling — and remembers the winner, in memory for this request and in the
     * cache for the next. A resolved path is stable for the life of a router's
     * firmware, so re-probing on every call would be several round trips per page
     * to learn something that changes at most once a year.
     *
     * Falls back to the first candidate when nothing answers. That keeps the
     * failure honest: the caller then gets a real HTTP error naming the path it
     * tried, rather than a silent empty list that reads as "this router has no
     * groups".
     */
    private function path(array $server, string $collection): string
    {
        $this->assertCollection($collection);

        $key = ($server['key'] ?? 'default') . ':' . $collection;

        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }

        $cacheKey = 'mikrotik:path:' . md5($key . '|' . ($server['url'] ?? ''));
        $cached = Cache::get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $this->resolved[$key] = $cached;
        }

        $candidates = $this->candidates($collection);

        foreach ($candidates as $candidate) {
            try {
                $response = $this->send($server, 'GET', $candidate, []);
            } catch (Throwable $e) {
                // The host is unreachable rather than the path being wrong.
                // Probing the remaining candidates would be several more
                // timeouts to learn the same thing.
                Log::warning('User Manager path probe could not reach the server', [
                    'server' => $server['key'] ?? null,
                    'collection' => $collection,
                    'path' => $candidate,
                    'error' => $e->getMessage(),
                ]);

                break;
            }

            if (!$response->failed()) {
                Log::info('User Manager path resolved', [
                    'server' => $server['key'] ?? null,
                    'collection' => $collection,
                    'path' => $candidate,
                ]);

                // A day: long enough that probing is invisible, short enough
                // that a firmware upgrade is picked up without a deploy.
                Cache::put($cacheKey, $candidate, 86400);

                return $this->resolved[$key] = $candidate;
            }

            if (!$this->looksLikeUnknownPath($response)) {
                // The menu exists and the router objected to something else —
                // credentials, a permission, a genuine fault. Trying the other
                // spellings would bury that behind a different error.
                Log::warning('User Manager path exists but the request failed', [
                    'server' => $server['key'] ?? null,
                    'collection' => $collection,
                    'path' => $candidate,
                    'status' => $response->status(),
                    'body' => Str::limit((string) $response->body(), 500),
                ]);

                return $this->resolved[$key] = $candidate;
            }

            Log::debug('User Manager menu not present; trying the next spelling', [
                'server' => $server['key'] ?? null,
                'collection' => $collection,
                'path' => $candidate,
                'status' => $response->status(),
                'body' => Str::limit((string) $response->body(), 200),
            ]);
        }

        return $this->resolved[$key] = $candidates[0];
    }

    /**
     * Every path worth trying for a collection, best first.
     *
     * The cross product of REST root and menu spelling, ordered so the modern
     * pair is tried first and the RouterOS 6 pair last.
     *
     * @return string[]
     */
    private function candidates(string $collection): array
    {
        $paths = [];

        foreach (self::MENUS[$collection] as $menu) {
            foreach (self::PREFIXES as $prefix) {
                $paths[] = $prefix . $menu;
            }
        }

        return $paths;
    }

    /**
     * One HTTP call.
     *
     * Path resolution happens in path() rather than here: retrying inside every
     * request meant a write could land on a different spelling from the read that
     * located its target, and a User Manager `.id` is only meaningful on the menu
     * it came from.
     *
     * @return array<mixed>
     */
    private function request(array $server, string $method, string $path, array $payload = [])
    {
        $response = $this->send($server, $method, $path, $payload);

        if ($response->failed()) {
            $detail = $this->errorDetail($response);

            // Logged with the body before it is thrown. The message that reaches
            // the operator is deliberately short; the reason the router gave is
            // the thing an engineer needs and it is otherwise lost — which is why
            // "returned HTTP 500" was all anyone had to go on.
            Log::error('User Manager request failed', [
                'server' => $server['key'] ?? null,
                'method' => $method,
                'path' => $path,
                'status' => $response->status(),
                'body' => Str::limit((string) $response->body(), 500),
            ]);

            throw new RuntimeException(sprintf(
                '%s returned HTTP %d%s',
                $server['label'] ?? $server['key'],
                $response->status(),
                $detail === null ? '' : ": {$detail}"
            ));
        }

        return $this->decode($response);
    }

    /**
     * Whether a failed response means "no such path" rather than "I am broken".
     *
     * Reads the body, because the status cannot tell them apart on RouterOS. A
     * body that is not JSON — an HTML error page from a reverse proxy in front of
     * the router — is not a RouterOS answer at all and is left as a real failure.
     */
    private function looksLikeUnknownPath($response): bool
    {
        if (!$response->failed()) {
            return false;
        }

        $message = strtolower((string) ($this->errorDetail($response) ?? ''));

        if ($message === '') {
            return false;
        }

        foreach (self::UNKNOWN_PATH_HINTS as $hint) {
            if (str_contains($message, $hint)) {
                return true;
            }
        }

        return false;
    }

    /** The router's own explanation, where it gave one. */
    private function errorDetail($response): ?string
    {
        $json = null;

        try {
            $json = $response->json();
        } catch (Throwable $e) {
            // Not JSON. Falls through to the raw body below, which is where an
            // HTML error page from a proxy would land.
            $json = null;
        }

        $message = is_array($json)
            ? ($json['message'] ?? $json['detail'] ?? $json['error'] ?? null)
            : null;

        if (is_string($message) && trim($message) !== '') {
            return Str::limit(trim($message), 200);
        }

        $body = trim((string) $response->body());

        // A body that is obviously markup tells the operator nothing useful and
        // would put a page of HTML in a toast.
        if ($body === '' || str_starts_with($body, '<')) {
            return null;
        }

        return Str::limit($body, 200);
    }

    /**
     * The response body as a list of rows.
     *
     * RouterOS is inconsistent about what it returns for a collection: usually a
     * JSON array, sometimes a single object when there is exactly one record, and
     * occasionally an object carrying an `error` key with HTTP 200. All three
     * used to reach the caller as-is and the last two would surface as a table
     * rendering nothing, or as `array_values()` on an error object producing rows
     * made of error text.
     *
     * @return array<mixed>
     */
    private function decode($response): array
    {
        $json = $response->json();

        if ($json === null) {
            return [];
        }

        if (!is_array($json)) {
            return [];
        }

        $isList = $this->isList($json);

        // An error object with a success status. Raised rather than rendered.
        if (!$isList && isset($json['error'])) {
            throw new RuntimeException(
                'The RADIUS server reported: ' . Str::limit((string) ($json['message'] ?? $json['error']), 200)
            );
        }

        // A single record returned bare rather than wrapped in a list.
        if (!$isList) {
            return $json === [] ? [] : [$json];
        }

        return $json;
    }

    /**
     * A User Manager record id, safe to put in a path.
     *
     * RouterOS `.id` values are of the form `*72` — an asterisk and a hex
     * counter. `rawurlencode()` turns that into `%2A72`, and RouterOS's REST
     * router does not decode it: the request arrives with a literal `%2A72`,
     * matches no record, and comes back
     *
     *     400 {"detail":"missing or invalid resource identifier"}
     *
     * which is what every group edit was failing with. GOWISER's own services
     * concatenate the id unencoded for the same reason.
     *
     * Everything except the asterisk is still encoded — the id reaches this from
     * a router response rather than from a user, but a path segment built by
     * concatenation is not somewhere to start trusting input.
     */
    private function encodeId($id): string
    {
        return str_replace('%2A', '*', rawurlencode((string) $id));
    }

    /**
     * Whether an array is a plain list.
     *
     * Hand-rolled rather than `array_is_list()`, which is PHP 8.1 — composer.json
     * declares ^8.0.2 and this box runs 8.0.30, so the built-in would be a fatal
     * error on the machine the feature is for.
     */
    private function isList(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
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
        if (!isset(self::MENUS[$collection])) {
            // Guards against a controller passing a caller-supplied string
            // straight through and turning this into an arbitrary-path proxy
            // onto a router.
            throw new RuntimeException("Unknown User Manager collection '{$collection}'.");
        }
    }
}
