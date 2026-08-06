<?php

namespace App\Services\Reports;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * What each driver needs from a monitored database, and what is actually there.
 *
 * ── Why this exists ───────────────────────────────────────────────────
 *
 * MONITOR reads databases it does not own and cannot migrate. They drift: a
 * branch on an older SYNC release has `timestamp` where a newer one has
 * `updated_at`; a schema that never ran a particular migration has no
 * `date_installed` at all. Until now every driver method coped with that on its
 * own, by probing the schema inline and quietly falling back — which works, and
 * is invisible. The failure mode is a figure that reads zero because a column is
 * missing, with nothing anywhere saying so.
 *
 * This declares the expectation in one place and reports it. Two consumers:
 *
 *  - the drivers, which ask `dateColumn()` for the best available timestamp
 *    rather than each hard-coding a fallback chain;
 *  - the Databases screen, which renders the whole map so an operator can see
 *    which tables a connection actually has and which column each metric is
 *    being dated on — before a number is wrong rather than after.
 *
 * ── Why the results are cached ────────────────────────────────────────
 *
 * `information_schema` lookups are cheap individually and this asks for a few
 * dozen per connection. A schema changes on deploy, not on request, so the map
 * is held for an hour; the Databases screen passes `fresh: true` to bypass it,
 * because the one time somebody looks at that page is right after changing
 * something.
 */
class SchemaMap
{
    /**
     * Timestamp columns in order of preference, per meaning.
     *
     * The names on the right are what SYNC and NETMANAGER have used across
     * releases. First match wins, and the *reason* for the order differs per
     * key, which is why they are separate lists rather than one:
     *
     *   opened    when the record was raised. `timestamp` leads because that is
     *             what the app writes first and it is never back-dated.
     *   modified  when it last changed state. `updated_at` leads; `timestamp` is
     *             only a fallback for schemas that never added it, and on those
     *             "modified" degrades to "raised" — which the map reports rather
     *             than hides.
     *   created   when the row was inserted. Distinct from `opened`: an
     *             application filed weeks ago and imported yesterday has a
     *             `created_at` of yesterday and a `timestamp` of the filing.
     *   installed when the service actually went in. No fallback chain worth
     *             having — a schema without `date_installed` cannot answer
     *             "installed on the 4th" more precisely than "touched on the
     *             4th", and pretending otherwise is how installs land in the
     *             wrong day.
     */
    public const DATE_ROLES = [
        'opened' => ['timestamp', 'created_at'],
        'modified' => ['updated_at', 'timestamp', 'created_at'],
        'created' => ['created_at', 'timestamp'],
        'installed' => ['date_installed', 'updated_at', 'timestamp'],
    ];

    /**
     * The tables each reporting section reads, and what it needs from them.
     *
     * `required` columns are the ones without which the table cannot answer at
     * all; `dates` names the timestamp roles the section dates its figures on.
     * Kept declarative so the Databases screen can render it without executing
     * any of the queries it describes.
     *
     * @var array<string,array<string,array{section:string,purpose:string,required:string[],dates:string[]}>>
     */
    public const EXPECTED = [
        'gowiser' => [
            'billing_accounts' => [
                'section' => 'subscriber_analytics',
                'purpose' => 'The subscriber base: status, plan and install date.',
                'required' => ['id', 'account_no', 'customer_id', 'plan_id', 'billing_status_id'],
                'dates' => ['installed'],
            ],
            'customers' => [
                'section' => 'subscriber_analytics',
                'purpose' => 'Names, contact numbers and addresses behind each account.',
                'required' => ['id', 'first_name', 'last_name', 'contact_number_primary', 'barangay'],
                'dates' => [],
            ],
            'billing_status' => [
                'section' => 'subscriber_analytics',
                'purpose' => 'The status vocabulary Active / VIP / Inactive / Pullout is read from.',
                'required' => ['id', 'status_name'],
                'dates' => [],
            ],
            'plan_list' => [
                'section' => 'subscriber_analytics',
                'purpose' => 'Canonical plan names and prices, for the plan mix and expected MRC.',
                'required' => ['id', 'plan_name', 'price'],
                'dates' => [],
            ],
            'applications' => [
                'section' => 'operations',
                'purpose' => 'Applications filed. Counted on the created date, with no status filter.',
                'required' => ['id', 'status'],
                'dates' => ['created'],
            ],
            'job_orders' => [
                'section' => 'operations',
                'purpose' => 'Installations. Installed counts onsite_status Done on the install date; Rescheduled Install counts onsite_status Reschedule on the modified date.',
                'required' => ['id', 'onsite_status', 'account_id'],
                'dates' => ['installed', 'modified', 'opened'],
            ],
            'service_orders' => [
                'section' => 'operations',
                'purpose' => 'Repairs. Repair counts visit_status Done and Pending Install counts visit_status In Progress, both on the modified date.',
                'required' => ['id', 'visit_status', 'account_no'],
                'dates' => ['modified', 'opened'],
            ],
            'transactions' => [
                'section' => 'financial',
                'purpose' => 'Counter collections, split into the Cash / PNB / Xendit channels.',
                'required' => ['id', 'received_payment', 'date_processed'],
                'dates' => [],
            ],
            'online_status' => [
                'section' => 'subscriber_analytics',
                'purpose' => 'Live session state behind Online / Offline / Restricted / Disconnected.',
                'required' => ['session_status'],
                'dates' => [],
            ],
        ],
        'netmanager' => [
            'subscribers' => [
                'section' => 'subscriber_analytics',
                'purpose' => 'The subscriber base, with status and router.',
                'required' => ['subscriber_id', 'account_number', 'status', 'plan_id'],
                'dates' => [],
            ],
            'plans' => [
                'section' => 'subscriber_analytics',
                'purpose' => 'Plan names and amounts.',
                'required' => ['plan_id', 'title', 'amount'],
                'dates' => [],
            ],
            'payments' => [
                'section' => 'financial',
                'purpose' => 'Collections.',
                'required' => ['subscriber_id', 'amount', 'status'],
                'dates' => [],
            ],
            'expenses' => [
                'section' => 'financial',
                'purpose' => 'OpEx and CapEx.',
                'required' => ['amount'],
                'dates' => [],
            ],
            'routers' => [
                'section' => 'financial',
                'purpose' => 'The branch dimension.',
                'required' => ['router_id', 'name'],
                'dates' => [],
            ],
        ],
    ];

    private const CACHE_TTL = 3600;

    /**
     * The best available column for a timestamp role on one table.
     *
     * Null when the table has none of the candidates, which callers must treat
     * as "this schema cannot answer that question" rather than as a zero.
     */
    public function dateColumn(
        ConnectionInterface $db,
        string $table,
        string $role,
        string $connectionKey = ''
    ): ?string {
        $columns = $this->columns($db, $table, $connectionKey);

        foreach (self::DATE_ROLES[$role] ?? [] as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        return null;
    }

    public function hasTable(ConnectionInterface $db, string $table, string $connectionKey = ''): bool
    {
        return $this->columns($db, $table, $connectionKey) !== [];
    }

    public function hasColumn(
        ConnectionInterface $db,
        string $table,
        string $column,
        string $connectionKey = ''
    ): bool {
        return in_array($column, $this->columns($db, $table, $connectionKey), true);
    }

    /**
     * Every column on a table, or an empty list when the table is absent.
     *
     * One `information_schema` read per table, memoised. `getSchemaBuilder()
     * ->getColumnListing()` is the portable way to ask, and it is the same call
     * the drivers were each making inline.
     *
     * @return string[]
     */
    public function columns(ConnectionInterface $db, string $table, string $connectionKey = ''): array
    {
        $key = 'schema-map:' . ($connectionKey ?: 'default') . ':' . $table;

        return Cache::remember($key, self::CACHE_TTL, function () use ($db, $table): array {
            try {
                $schema = $db->getSchemaBuilder();

                if (!$schema->hasTable($table)) {
                    return [];
                }

                return $schema->getColumnListing($table);
            } catch (Throwable $e) {
                report($e);

                return [];
            }
        });
    }

    /**
     * The whole map for one connection, for the Databases screen.
     *
     * Reports, per expected table: whether it exists, which required columns are
     * missing, and which real column each timestamp role resolved to. A driver
     * name it has no expectations for returns an empty map rather than
     * pretending — better to say "no mapping is declared for this driver" than
     * to render an authoritative-looking empty table.
     *
     * @return array<string,mixed>
     */
    public function describe(
        ConnectionInterface $db,
        string $driver,
        string $connectionKey = '',
        bool $fresh = false
    ): array {
        $expected = self::EXPECTED[strtolower($driver)] ?? null;

        if ($expected === null) {
            return [
                'driver' => $driver,
                'declared' => false,
                'tables' => [],
            ];
        }

        if ($fresh) {
            $this->flush($connectionKey, array_keys($expected));
        }

        $tables = [];
        $missingTables = 0;
        $missingColumns = 0;

        foreach ($expected as $table => $spec) {
            $columns = $this->columns($db, $table, $connectionKey);
            $present = $columns !== [];

            $absent = $present
                ? array_values(array_diff($spec['required'], $columns))
                : $spec['required'];

            $dates = [];

            foreach ($spec['dates'] as $role) {
                $resolved = null;

                foreach (self::DATE_ROLES[$role] ?? [] as $candidate) {
                    if (in_array($candidate, $columns, true)) {
                        $resolved = $candidate;
                        break;
                    }
                }

                $dates[] = [
                    'role' => $role,
                    'preferred' => self::DATE_ROLES[$role][0] ?? null,
                    'resolved' => $resolved,
                    // True when the column in use is not the preferred one — the
                    // figure still computes, but on a weaker timestamp, and that
                    // is worth seeing before somebody queries the number.
                    'degraded' => $resolved !== null && $resolved !== (self::DATE_ROLES[$role][0] ?? null),
                ];
            }

            if (!$present) {
                $missingTables++;
            }

            $missingColumns += count($absent);

            $tables[] = [
                'table' => $table,
                'section' => $spec['section'],
                'purpose' => $spec['purpose'],
                'exists' => $present,
                'column_count' => count($columns),
                'required' => $spec['required'],
                'missing' => $absent,
                'dates' => $dates,
                'healthy' => $present && $absent === [],
            ];
        }

        return [
            'driver' => $driver,
            'declared' => true,
            'tables' => $tables,
            'summary' => [
                'expected' => count($expected),
                'present' => count($expected) - $missingTables,
                'missing_tables' => $missingTables,
                'missing_columns' => $missingColumns,
                'degraded_dates' => count(array_filter(
                    array_merge(...array_map(fn ($t) => $t['dates'], $tables ?: [[]])) ?: [],
                    fn ($d) => is_array($d) && ($d['degraded'] ?? false)
                )),
            ],
        ];
    }

    /** @param string[] $tables */
    private function flush(string $connectionKey, array $tables): void
    {
        foreach ($tables as $table) {
            Cache::forget('schema-map:' . ($connectionKey ?: 'default') . ':' . $table);
        }
    }
}
