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
                'purpose' => 'Installations. Installed counts onsite_status Done on the install date; Rescheduled Install and Pending Install count onsite_status Reschedule and In Progress, all-time.',
                'required' => ['id', 'onsite_status', 'account_id'],
                'dates' => ['installed', 'modified', 'opened'],
            ],
            'service_orders' => [
                'section' => 'operations',
                'purpose' => 'Repairs. Repair counts visit_status Done on the modified date.',
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

    /**
     * How the expected tables reach each other.
     *
     * Declared rather than read out of `information_schema`, and that is the
     * point: several of these are not foreign keys at all. `service_orders`
     * reaches an account through `account_no`, a string the operator types, and
     * an application matches a plan by lower-cased name because there was no
     * plan row when it was filed. A reader looking at a "Repairs" figure that
     * seems short needs to know it is joined on a free-text account number —
     * which is exactly what a FOREIGN KEY listing would never have told them,
     * because there is no constraint to list.
     *
     * `kind` says which sort of linkage it is, because they fail differently:
     *
     *   fk      a real integer key. Missing rows mean deleted parents.
     *   lookup  a code table joined by id. Missing rows mean an unmapped code.
     *   match   joined on text. Missing rows mean a spelling difference, which
     *           is the one that produces plausible-looking wrong totals.
     *
     * @var array<string,array<int,array{from:string,to:string,on:string,kind:string,note:string}>>
     */
    public const RELATIONS = [
        'gowiser' => [
            [
                'from' => 'billing_accounts',
                'to' => 'customers',
                'on' => 'billing_accounts.customer_id → customers.id',
                'kind' => 'fk',
                'note' => 'The person behind the account. Left-joined everywhere, so an account whose customer row was deleted still counts.',
            ],
            [
                'from' => 'billing_accounts',
                'to' => 'plan_list',
                'on' => 'billing_accounts.plan_id → plan_list.id',
                'kind' => 'fk',
                'note' => 'What the account is billed. Accounts whose plan link was lost in the migration are matched back by name before counting.',
            ],
            [
                'from' => 'billing_accounts',
                'to' => 'billing_status',
                'on' => 'billing_accounts.billing_status_id → billing_status.id',
                'kind' => 'lookup',
                'note' => 'The Active / VIP / Inactive / Pullout vocabulary. An id with no row here lands in no status bucket at all.',
            ],
            [
                'from' => 'job_orders',
                'to' => 'billing_accounts',
                'on' => 'job_orders.account_id → billing_accounts.id',
                'kind' => 'fk',
                'note' => 'Installations key the account by id.',
            ],
            [
                'from' => 'service_orders',
                'to' => 'billing_accounts',
                'on' => 'service_orders.account_no = billing_accounts.account_no',
                'kind' => 'match',
                'note' => 'Repairs key the account by number, not by id — a string join. A mistyped account number silently produces a repair with no subscriber attached.',
            ],
            [
                'from' => 'job_orders',
                'to' => 'applications',
                'on' => 'job_orders.application_id → applications.id',
                'kind' => 'fk',
                'note' => 'Which application this installation came from. Carries the referring agent through to the install.',
            ],
            [
                'from' => 'applications',
                'to' => 'plan_list',
                'on' => 'LOWER(TRIM(applications.desired_plan)) = LOWER(TRIM(plan_list.plan_name))',
                'kind' => 'match',
                'note' => 'An application has no plan id — the applicant named a plan before an account existed. Joined on the name, so a renamed plan orphans historic applications.',
            ],
            [
                'from' => 'transactions',
                'to' => 'billing_accounts',
                'on' => 'transactions.account_no = billing_accounts.account_no',
                'kind' => 'match',
                'note' => 'Counter collections key the account by number. This is the join behind Revenue by Plan.',
            ],
            [
                'from' => 'online_status',
                'to' => 'billing_accounts',
                'on' => 'online_status.account_no = billing_accounts.account_no',
                'kind' => 'match',
                'note' => 'Live session state per account, keyed by number.',
            ],
        ],
        'netmanager' => [
            [
                'from' => 'subscribers',
                'to' => 'plans',
                'on' => 'subscribers.plan_id → plans.plan_id',
                'kind' => 'fk',
                'note' => 'What the subscriber is billed.',
            ],
            [
                'from' => 'subscribers',
                'to' => 'routers',
                'on' => 'subscribers.router_id → routers.router_id',
                'kind' => 'fk',
                'note' => 'The branch dimension. NETMANAGER genuinely has routers; GOWISER has none.',
            ],
            [
                'from' => 'payments',
                'to' => 'subscribers',
                'on' => 'payments.subscriber_id → subscribers.subscriber_id',
                'kind' => 'fk',
                'note' => 'Collections attach to a subscriber, not to an account number.',
            ],
        ],
    ];

    /**
     * Which tables each metric card on the portal is actually computed from.
     *
     * The question this answers is the one an operator asks in front of a figure
     * they do not believe: *where does this number come from*. Until now the
     * only answer was to read the driver. Declared per card rather than derived,
     * because a card is a business idea — "Rescheduled Install" — and no amount
     * of schema introspection recovers which tables were chosen to express it or
     * why.
     *
     * `basis` is the rule in one line, in the same words the card's own caption
     * uses, so the two can be compared without translating between them.
     *
     * @var array<string,array<int,array{key:string,label:string,page:string,tables:string[],basis:string}>>
     */
    public const METRIC_CARDS = [
        'gowiser' => [
            [
                'key' => 'active',
                'label' => 'Active / VIP / Inactive / Pullout',
                'page' => 'Group Overview · Subscriber Analytics',
                'tables' => ['billing_accounts', 'billing_status', 'customers'],
                'basis' => 'Headcount now, bucketed by billing status. Not windowed — a headcount filtered to a date range is not a smaller headcount, it is a wrong one.',
            ],
            [
                'key' => 'plans',
                'label' => 'Subscriber Plan',
                'page' => 'Group Overview',
                'tables' => ['billing_accounts', 'plan_list'],
                'basis' => 'Active subscribers per plan. Accounts whose plan link was lost are matched back by name, so these add up to Active rather than falling short of it.',
            ],
            [
                'key' => 'application',
                'label' => 'Application',
                'page' => 'Group Overview · Operations',
                'tables' => ['applications', 'plan_list'],
                'basis' => 'Filed in range, on the created date, with no status filter — a cancelled application was still received.',
            ],
            [
                'key' => 'installed',
                'label' => 'Installed',
                'page' => 'Group Overview · Operations',
                'tables' => ['job_orders', 'billing_accounts', 'customers', 'plan_list'],
                'basis' => 'onsite_status Done, dated on date_installed — not updated_at, which moves every time a photo is attached the next morning.',
            ],
            [
                'key' => 'repair',
                'label' => 'Repair',
                'page' => 'Group Overview · Operations',
                'tables' => ['service_orders', 'billing_accounts', 'customers'],
                'basis' => 'visit_status Done on the modified date. visit_status, not support_status: a ticket can sit In Progress for support while the visit that fixed it is Done.',
            ],
            [
                'key' => 'reschedule',
                'label' => 'Rescheduled Install',
                'page' => 'Group Overview',
                'tables' => ['job_orders', 'billing_accounts', 'customers'],
                'basis' => 'onsite_status Reschedule, all-time. A state rather than an event — an install rescheduled last month is still rescheduled this morning.',
            ],
            [
                'key' => 'pending',
                'label' => 'Pending Install',
                'page' => 'Group Overview',
                'tables' => ['job_orders', 'billing_accounts', 'customers'],
                'basis' => 'onsite_status In Progress, all-time. An installation part way through — the same queue as Rescheduled Install, and a state rather than an event for the same reason.',
            ],
            [
                'key' => 'income',
                'label' => 'Income · Office · PNB · Portal',
                'page' => 'Group Overview · Financial',
                'tables' => ['transactions'],
                'basis' => 'Payments marked paid, on date_processed, split into channels by the free-text payment method. Portal collections come from the payment-portal log instead, which is why they cannot be pattern-matched out of this table.',
            ],
            [
                'key' => 'expenses',
                'label' => 'Expenses · OpEx · CapEx',
                'page' => 'Group Overview · Financial',
                'tables' => ['transactions'],
                'basis' => 'Expenses booked against a longer reporting period are excluded from shorter views, so a day never carries a month of rent.',
            ],
            [
                'key' => 'sessions',
                'label' => 'Session Status',
                'page' => 'Subscriber Analytics',
                'tables' => ['online_status'],
                'basis' => 'Live connection state as last recorded. A statement about now, not about the range.',
            ],
        ],
        'netmanager' => [
            [
                'key' => 'active',
                'label' => 'Subscriber Status',
                'page' => 'Subscriber Analytics',
                'tables' => ['subscribers', 'plans'],
                'basis' => 'Headcount now, bucketed by status.',
            ],
            [
                'key' => 'income',
                'label' => 'Income',
                'page' => 'Financial',
                'tables' => ['payments', 'subscribers'],
                'basis' => 'Collections in range.',
            ],
            [
                'key' => 'expenses',
                'label' => 'Expenses',
                'page' => 'Financial',
                'tables' => ['expenses'],
                'basis' => 'Spend in range.',
            ],
            [
                'key' => 'branch',
                'label' => 'Collections by Branch',
                'page' => 'Financial',
                'tables' => ['payments', 'subscribers', 'routers'],
                'basis' => 'Collections attributed through the subscriber to their router.',
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
                'relations' => [],
                'metrics' => [],
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

        // Named distinctly from the per-table `$present` above, which is a
        // boolean and shares this function's scope.
        $presentTables = array_column(array_filter($tables, fn ($t) => $t['exists']), 'table');
        $key = strtolower($driver);

        return [
            'driver' => $driver,
            'declared' => true,
            'tables' => $tables,

            // Each linkage carries whether both of its ends are actually here.
            // A relation whose target table is missing is precisely why a figure
            // reads zero, and it is invisible from either table on its own.
            'relations' => array_map(
                fn (array $relation) => $relation + [
                    'available' => in_array($relation['from'], $presentTables, true)
                        && in_array($relation['to'], $presentTables, true),
                ],
                self::RELATIONS[$key] ?? []
            ),

            // The metric cards, each with the tables behind it and whether this
            // database can serve them. `missing` is what to fix, in the order a
            // reader asks for it: the card, then the table it cannot reach.
            'metrics' => array_map(
                function (array $card) use ($presentTables) {
                    $absent = array_values(array_diff($card['tables'], $presentTables));

                    return $card + [
                        'available' => $absent === [],
                        'missing' => $absent,
                    ];
                },
                self::METRIC_CARDS[$key] ?? []
            ),

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
