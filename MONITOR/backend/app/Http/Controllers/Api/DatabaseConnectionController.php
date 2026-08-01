<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SchemaProfile;
use App\Models\SiteConnection;
use App\Services\Connector\ConnectionManager;
use App\Services\ReportingService;
use App\Services\SourceRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The Databases configuration page.
 *
 * The only writing endpoints in MONITOR, and they write to MONITOR's own
 * `site_connections` table — never to a monitored database. Those stay read-only
 * at the connection level, enforced in SourceRegistry::connection() regardless of
 * how a request arrived.
 *
 * Adding a row here makes the database appear in the source switcher on the next
 * request, with no deploy: SourceRegistry reads this table, and
 * ConnectionManager registers the connection with Laravel at runtime.
 *
 * Passwords are encrypted at rest by SiteConnection::setPasswordAttribute and
 * never returned by any endpoint here.
 */
class DatabaseConnectionController extends Controller
{
    /**
     * Reserved keys, because these collide with connection names Laravel already
     * owns and would make the app query itself.
     */
    private const RESERVED_KEYS = [
        // Collide with connection names Laravel already owns.
        'mysql', 'sqlite', 'pgsql', 'sqlsrv', 'default', 'monitor',
        // "all" is the aggregate filter, not a database.
        ReportingService::ALL_SOURCES,
    ];

    public function __construct(
        private SourceRegistry $sources,
        private ReportingService $reporting,
        private ConnectionManager $connections
    ) {
    }

    /**
     * Every configured database, with its last connectivity result and what the
     * portal can actually report from it.
     */
    public function index()
    {
        $rows = SiteConnection::orderBy('sort_order')->orderBy('label')->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'connections' => $rows->map(fn (SiteConnection $row) => $this->present($row))->all(),
                'profiles' => $this->profiles(),
                // With no enabled connection the portal has no data at all — this
                // table is the only place monitored databases are defined. The page
                // says so, because an empty list on its own is ambiguous.
                'no_sources' => $rows->where('enabled', true)->isEmpty(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        // A password is optional on edit (so a rename need not retype it) but
        // required on create, or the first connection test fails for a reason
        // nobody can see.
        if (($data['password'] ?? '') === '') {
            throw ValidationException::withMessages([
                'password' => 'A password is required when adding a database.',
            ]);
        }

        $connection = SiteConnection::create($data);

        $this->sources->flush();

        AuditLog::record(
            $request,
            'created',
            SiteConnection::class,
            $connection->id,
            "Added database connection [{$connection->key}] -> {$connection->host}/{$connection->database}",
            AuditLog::diff([], $this->auditable($data))
        );

        // Tested immediately: an operator who has just typed six fields wants to
        // know now whether they were right, not on the next page load.
        $result = $this->connections->test($connection->fresh());

        return response()->json([
            'status' => 'success',
            'message' => 'Database connection saved.',
            'data' => [
                'connection' => $this->present($connection->fresh()),
                'test' => $result,
            ],
        ], 201);
    }

    public function update(Request $request, SiteConnection $connection)
    {
        $before = $this->auditable($connection->getAttributes());
        $data = $this->validated($request, $connection);

        // An empty password means "leave it alone" — the model's mutator already
        // ignores it, and unsetting keeps it out of the audit diff too.
        if (($data['password'] ?? '') === '') {
            unset($data['password']);
        }

        $connection->fill($data)->save();

        $this->sources->flush();

        AuditLog::record(
            $request,
            'updated',
            SiteConnection::class,
            $connection->id,
            "Updated database connection [{$connection->key}]",
            AuditLog::diff($before, $this->auditable($data))
        );

        $result = $this->connections->test($connection->fresh());

        return response()->json([
            'status' => 'success',
            'message' => 'Database connection updated.',
            'data' => [
                'connection' => $this->present($connection->fresh()),
                'test' => $result,
            ],
        ]);
    }

    /**
     * Removes a connection.
     *
     * Deletes MONITOR's record of the database, not the database. Said plainly in
     * the response because "delete" on a page full of production databases is a
     * word worth being precise about.
     */
    public function destroy(Request $request, SiteConnection $connection)
    {
        $key = $connection->key;
        $before = $this->auditable($connection->getAttributes());

        $connection->delete();

        $this->sources->flush();

        AuditLog::record(
            $request,
            'deleted',
            SiteConnection::class,
            $connection->id,
            "Removed database connection [{$key}]",
            AuditLog::diff($before, [])
        );

        return response()->json([
            'status' => 'success',
            'message' => "Removed [{$key}] from MONITOR. The database itself was not touched.",
        ]);
    }

    /**
     * Tries the connection now and records the outcome on the row.
     *
     * Separate from save so an operator can re-check a database that was down
     * without editing anything.
     */
    public function test(Request $request, SiteConnection $connection)
    {
        $result = $this->connections->test($connection);

        return response()->json([
            'status' => 'success',
            'data' => [
                'test' => $result,
                'connection' => $this->present($connection->fresh()),
            ],
        ]);
    }

    /**
     * Tables the connection can see, and the columns of one table.
     *
     * Lets the page prove a connection reaches the right database — "1,204 rows in
     * `transactions`" is a far better confirmation than "connected".
     */
    public function introspect(Request $request, SiteConnection $connection)
    {
        if (!$connection->enabled) {
            return response()->json([
                'status' => 'error',
                'message' => 'Enable this connection before inspecting it.',
            ], 422);
        }

        $table = $request->query('table');
        $table = is_string($table) && $table !== '' ? $table : null;

        try {
            return response()->json([
                'status' => 'success',
                'data' => $this->connections->introspect($connection->key, $table),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Introspection failed', [
                'connection' => $connection->key,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => config('app.debug') ? $e->getMessage() : 'Could not read this database.',
            ], 502);
        }
    }

    /**
     * Reorders the list.
     *
     * Order matters beyond cosmetics: the first enabled source is the default the
     * portal opens on when no ?source= is given.
     */
    public function reorder(Request $request)
    {
        $validated = $request->validate([
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['integer', 'exists:site_connections,id'],
        ]);

        foreach ($validated['order'] as $position => $id) {
            SiteConnection::where('id', $id)->update(['sort_order' => $position + 1]);
        }

        $this->sources->flush();

        AuditLog::record(
            $request,
            'updated',
            SiteConnection::class,
            null,
            'Reordered database connections',
            ['order' => ['from' => null, 'to' => $validated['order']]]
        );

        return response()->json(['status' => 'success', 'message' => 'Order saved.']);
    }

    // ── Validation ───────────────────────────────────────────────────────

    private function validated(Request $request, ?SiteConnection $existing = null): array
    {
        $validated = $request->validate([
            'key' => [
                'required',
                'string',
                'max:64',
                // Used in a Laravel connection name and a query string, so it is
                // restricted to a slug rather than escaped at every use site.
                'regex:/^[a-z0-9][a-z0-9_-]*$/',
                Rule::notIn(self::RESERVED_KEYS),
                Rule::unique('site_connections', 'key')->ignore($existing?->id),
            ],
            'label' => ['required', 'string', 'max:120'],
            'profile_key' => ['required', 'string', Rule::in($this->profileKeys())],
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'database' => ['required', 'string', 'max:128'],
            'username' => ['required', 'string', 'max:128'],
            'password' => ['nullable', 'string', 'max:255'],
            'timezone' => ['nullable', 'string', 'max:32'],
            'enabled' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],

            // Row-level scope, for several operating companies sharing one
            // database rather than each having their own.
            'scope_column' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z_][A-Za-z0-9_]*$/'],
            'scope_value' => ['nullable', 'string', 'max:64'],
        ], [
            'key.regex' => 'The key may only contain lowercase letters, numbers, hyphens and underscores.',
            'key.not_in' => 'That key is reserved. Choose another.',
            'scope_column.regex' => 'The scope column must be a plain column name.',
        ]);

        // Both halves of a scope or neither: a column with no value would filter
        // every row out and read as an empty database.
        $column = trim((string) ($validated['scope_column'] ?? ''));
        $value = trim((string) ($validated['scope_value'] ?? ''));

        if (($column === '') !== ($value === '')) {
            throw ValidationException::withMessages([
                'scope_value' => 'Give both a scope column and a value, or leave both blank.',
            ]);
        }

        return [
            'key' => Str::lower($validated['key']),
            'label' => $validated['label'],
            'profile_key' => $validated['profile_key'],
            // MySQL only, per deployment. Fixed here rather than offered as a
            // field so an unsupported driver cannot be saved and then fail on
            // every query.
            'driver' => 'mysql',
            'host' => $validated['host'],
            'port' => (int) $validated['port'],
            'database' => $validated['database'],
            'username' => $validated['username'],
            'password' => $validated['password'] ?? '',
            'timezone' => $validated['timezone'] ?: '+08:00',
            'enabled' => (bool) ($validated['enabled'] ?? true),
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
            'scope' => $column !== '' ? ['column' => $column, 'value' => $value] : null,
        ];
    }

    // ── Presentation ─────────────────────────────────────────────────────

    /**
     * One connection as the page needs it.
     *
     * Never includes the password. `sections` is what the portal can actually
     * report from this database, so the page can show that a connection is
     * reachable *and* useful — a database that connects but maps nothing is a
     * misconfiguration worth surfacing here rather than as five empty pages.
     */
    private function present(SiteConnection $row): array
    {
        $driver = SourceRegistry::class;

        return [
            'id' => $row->id,
            'key' => $row->key,
            'label' => $row->label,
            'profile_key' => $row->profile_key,
            'profile_label' => $this->profileLabel($row->profile_key),
            'host' => $row->host,
            'port' => (int) $row->port,
            'database' => $row->database,
            'username' => $row->username,
            'timezone' => $row->timezone,
            'enabled' => (bool) $row->enabled,
            'sort_order' => (int) $row->sort_order,
            'has_password' => !empty($row->getAttributes()['password'] ?? null),
            'scope' => $row->scope,
            'last_status' => $row->last_status,
            'last_error' => $row->last_error,
            'last_checked_at' => $row->last_checked_at?->toDateTimeString(),
            'sections' => $row->enabled ? $this->sectionsFor($row->key) : [],
        ];
    }

    /**
     * Which reporting sections this source can serve.
     *
     * Wrapped: a source whose driver cannot be resolved must not break the whole
     * list, which is exactly the state someone visits this page to fix.
     */
    private function sectionsFor(string $key): array
    {
        try {
            return $this->reporting->capabilities($key);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** @return array<int,array{key:string,label:string,description:?string}> */
    private function profiles(): array
    {
        return SchemaProfile::orderBy('label')
            ->get()
            ->map(fn (SchemaProfile $profile) => [
                'key' => $profile->key,
                'label' => $profile->label,
                'description' => $profile->description,
                'is_system' => (bool) $profile->is_system,
            ])
            ->all();
    }

    private function profileKeys(): array
    {
        return SchemaProfile::pluck('key')->all();
    }

    private function profileLabel(string $key): string
    {
        return SchemaProfile::where('key', $key)->value('label') ?? $key;
    }

    /** The subset of a row worth recording in the audit trail. */
    private function auditable(array $attributes): array
    {
        return array_intersect_key($attributes, array_flip([
            'key', 'label', 'profile_key', 'host', 'port', 'database',
            'username', 'password', 'timezone', 'enabled', 'sort_order', 'scope',
        ]));
    }
}
