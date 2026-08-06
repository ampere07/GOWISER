<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\RadiusServer;
use App\Services\Mikrotik\UserManagerClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * RADIUS API Settings — the endpoints MikroTik RADIUS talks to.
 *
 * Ported from GOWISER's RadiusConfigController: the same fields, the same
 * two-server cap, the same #1/#2 failover order, so an operator configuring both
 * systems fills in one form twice rather than learning two schemas.
 *
 * Three things this adds over the version it was copied from, all of them
 * because MONITOR is reachable by more people than GOWISER's admin panel:
 *
 *   - the password is encrypted at rest, never returned, and an update that
 *     omits it keeps the stored one. A form that echoed the secret back would
 *     have it submitted again on every unrelated edit, through whatever proxy
 *     sits in front of this;
 *   - every write is audited with the actor and the payload minus the password,
 *     because these rows point at hardware that can disconnect a region;
 *   - the connectivity check authenticates rather than opening a socket. A
 *     router that accepts TCP but rejects the credentials is not a working
 *     configuration, and "the port is open" is the answer that sends someone
 *     looking in the wrong place for an afternoon.
 */
class RadiusConfigController extends Controller
{
    public function __construct(private UserManagerClient $client)
    {
    }

    /**
     * Every configured server, and where the live fleet is coming from.
     *
     * No probing. This is opened to read the configuration, and a page that
     * cannot render until two possibly-dead routers have timed out is a page
     * that looks broken whenever one of them is. Reachability is its own call
     * (see test), fired per row by the screen.
     */
    public function index()
    {
        $rows = RadiusServer::query()->orderBy('id')->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'servers' => $rows
                    ->values()
                    ->map(fn (RadiusServer $row, int $index) => $row->toPublicArray($index + 1))
                    ->all(),
                'max_servers' => RadiusServer::MAX_SERVERS,
                // 'settings' once a row exists, 'environment' while the legacy
                // MIKROTIK_* variables are still what the client uses. Stated so
                // an operator who edits nothing and sees the module keep working
                // knows why.
                'source' => $this->client->source(),
                'configured' => $this->client->configured(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        if (RadiusServer::query()->count() >= RadiusServer::MAX_SERVERS) {
            return response()->json([
                'status' => 'error',
                'message' => sprintf(
                    'At most %d RADIUS servers can be configured. Edit or remove one first.',
                    RadiusServer::MAX_SERVERS
                ),
            ], 422);
        }

        $validated = $request->validate($this->rules(true));

        $server = DB::transaction(function () use ($validated, $request) {
            $server = RadiusServer::create($validated + [
                'is_active' => $request->boolean('is_active', true),
                'updated_by' => $request->user()?->username,
            ]);

            RadiusServer::forget();

            return $server;
        });

        AuditLog::record(
            $request,
            'created',
            'radius-config',
            (string) $server->id,
            "Added RADIUS server {$server->baseUrl()}",
            $this->loggable($validated)
        );

        return response()->json([
            'status' => 'success',
            'message' => 'RADIUS server added.',
            'data' => $server->toPublicArray($this->positionOf($server)),
        ], 201);
    }

    public function update(Request $request, RadiusServer $radius)
    {
        $validated = $request->validate($this->rules(false));

        // An omitted or blank password means "leave it alone", not "clear it".
        // The form never receives the stored secret, so it has nothing to send
        // back, and treating an empty field as an instruction would wipe the
        // credentials every time somebody corrected a typo in the label.
        if (!array_key_exists('password', $validated) || trim((string) $validated['password']) === '') {
            unset($validated['password']);
        }

        DB::transaction(function () use ($radius, $validated, $request) {
            $radius->fill($validated);

            if ($request->has('is_active')) {
                $radius->is_active = $request->boolean('is_active');
            }

            $radius->updated_by = $request->user()?->username;
            $radius->save();

            RadiusServer::forget();
        });

        AuditLog::record(
            $request,
            'updated',
            'radius-config',
            (string) $radius->id,
            "Changed RADIUS server {$radius->baseUrl()}",
            $this->loggable($validated)
        );

        return response()->json([
            'status' => 'success',
            'message' => 'RADIUS server updated.',
            'data' => $radius->fresh()->toPublicArray($this->positionOf($radius)),
        ]);
    }

    public function destroy(Request $request, RadiusServer $radius)
    {
        $label = $radius->baseUrl();

        DB::transaction(function () use ($radius) {
            $radius->delete();

            RadiusServer::forget();
        });

        AuditLog::record(
            $request,
            'deleted',
            'radius-config',
            (string) $radius->id,
            "Removed RADIUS server {$label}"
        );

        return response()->json([
            'status' => 'success',
            // Said out loud because deleting the last row does not disable the
            // module — it falls back to the environment, and an operator who
            // deleted a server expecting the feature to stop needs to know that.
            'message' => RadiusServer::fleet() === []
                ? 'RADIUS server removed. No servers are configured here; the MIKROTIK_* environment variables are in use again if they are set.'
                : 'RADIUS server removed.',
        ]);
    }

    /**
     * Whether one configured server answers, and how quickly.
     *
     * Uses the stored credentials rather than anything in the request, so this
     * cannot be turned into a way to probe arbitrary hosts with arbitrary
     * credentials from inside the network.
     */
    public function test(Request $request, RadiusServer $radius)
    {
        $url = $radius->baseUrl();

        if ($url === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'This server has no host to reach.',
            ], 422);
        }

        try {
            $result = $this->client->probe([
                'key' => 'test-' . $radius->id,
                'label' => $radius->label ?: $url,
                'url' => $url,
                'username' => (string) $radius->username,
                'password' => (string) $radius->password,
            ]);

            return response()->json([
                'status' => 'success',
                'data' => $result + ['base_url' => $url],
                'message' => $result['online']
                    ? "Answered in {$result['latency_ms']} ms."
                    : 'No answer from this server.',
            ]);
        } catch (Throwable $e) {
            Log::error('RADIUS connectivity check failed: ' . $e->getMessage(), [
                'server' => $radius->id,
                'exception' => get_class($e),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => config('app.debug') ? $e->getMessage() : 'The check could not be run.',
            ], 500);
        }
    }

    /**
     * @param bool $creating password is required on create and optional on update
     * @return array<string,mixed>
     */
    private function rules(bool $creating): array
    {
        return [
            'ssl_type' => [$creating ? 'required' : 'sometimes', Rule::in(['http', 'https'])],
            // A hostname or an IP. Not a URL: the scheme and port are their own
            // fields, and accepting "https://10.0.0.2:443" here would produce
            // "https://https://10.0.0.2:443:443" the moment baseUrl() ran.
            'ip' => [
                $creating ? 'required' : 'sometimes',
                'string',
                'max:255',
                'regex:/^[A-Za-z0-9._:-]+$/',
            ],
            'port' => [$creating ? 'required' : 'sometimes', 'string', 'max:8', 'regex:/^\d{1,5}$/'],
            'username' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'password' => [$creating ? 'required' : 'nullable', 'string', 'max:255'],
            'label' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** The payload minus the secret, for the audit row. */
    private function loggable(array $validated): array
    {
        unset($validated['password']);

        return $validated;
    }

    /** Failover position — #1 is primary, #2 is secondary. */
    private function positionOf(RadiusServer $server): int
    {
        return (int) RadiusServer::query()->where('id', '<=', $server->id)->count();
    }
}
