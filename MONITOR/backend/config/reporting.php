<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Company details for printed reports
    |--------------------------------------------------------------------------
    |
    | The letterhead on the Payment, Expense and Financial reports.
    |
    | NetManager keeps these in its own `settings` table and that is read
    | directly, so this block only applies to sources that have no equivalent —
    | GOWISER among them. Registration details rather than display preferences,
    | so they live in config where they can be reviewed in a diff, not in a
    | database row someone can quietly change on a document that gets signed.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Connect timeout for a monitored database
    |--------------------------------------------------------------------------
    |
    | Seconds to wait for the TCP connect before giving up on a branch.
    |
    | Without a bound, a server that is powered off but still answering ARP — or
    | behind a firewall that drops packets rather than rejecting them — holds the
    | request for the operating system default, well over a minute. Multiply that
    | by a fleet and one dead branch takes the whole page down. Better to declare
    | it unreachable in a few seconds and render the rest.
    |
    */

    'connect_timeout' => env('REPORTING_CONNECT_TIMEOUT', 5),

    /*
    |--------------------------------------------------------------------------
    | Parallel fan-out
    |--------------------------------------------------------------------------
    |
    | "All databases" runs a section against every configured database. Done in
    | sequence that is N round trips one after another, and almost all of that
    | time is spent *waiting* on remote servers rather than working — which is
    | exactly the shape that parallelism fixes.
    |
    | PHP cannot run the drivers concurrently in one process, so each database is
    | handled by a short-lived child process (`php artisan reporting:section`)
    | and the parent collects the JSON. That costs one framework boot per child,
    | so it only pays when several databases are genuinely cold: the runner is
    | skipped below 'min_sources', and cached databases never reach it at all.
    |
    | Disable it and everything still works, just serially — see
    | ParallelSectionRunner for the conditions that trigger a fallback.
    |
    */

    'parallel' => [
        'enabled' => env('REPORTING_PARALLEL', true),

        // How many children may run at once. Above the core count they compete
        // for CPU instead of overlapping network waits, which is the opposite of
        // the point.
        'max_processes' => env('REPORTING_PARALLEL_PROCESSES', 6),

        // Fewer cold databases than this and the serial path wins: one framework
        // boot costs more than one extra round trip.
        'min_sources' => env('REPORTING_PARALLEL_MIN', 2),

        // Per-child ceiling, seconds. Generous because a child pays a framework
        // boot on top of the queries. A child that exceeds it is reported as a
        // failed database, not a failed page.
        'timeout' => env('REPORTING_PARALLEL_TIMEOUT', 60),

        // Defaults to the binary running the parent, which is correct almost
        // always. Set it when the web server's PHP is not the CLI PHP.
        'php_binary' => env('REPORTING_PARALLEL_PHP'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache warming
    |--------------------------------------------------------------------------
    |
    | `reporting:warm` pre-computes the combinations someone opens a page on, so
    | the first viewer of the day hits a warm cache instead of paying for the
    | whole fan-out. Schedule it (see App\Console\Kernel) at an interval below
    | monitor.cache_ttl, or it will always be warming an entry that has already
    | expired.
    |
    | Only the *default* filters are warmed. Warming every date range anyone
    | might pick is not possible, and guessing at a few would mostly waste
    | queries on ranges nobody opens.
    |
    */

    'warm' => [
        'enabled' => env('REPORTING_WARM', true),

        // Sections worth pre-computing. Trim this if some are rarely opened —
        // each one costs a full fan-out across every database.
        'sections' => ['subscriber_analytics', 'financial', 'operations', 'tech', 'employee'],

        // Also warm each database on its own, not just the merged view. Costs
        // nothing extra: the aggregate pass caches each database separately, and
        // those same entries serve the single-database views.
        'include_aggregate' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Roles that are not staff
    |--------------------------------------------------------------------------
    |
    | The Employee section reports on people who work here. GOWISER keeps
    | subscribers in the same `users` table as employees, separated only by their
    | role, so without this every subscriber counts as staff — 3,595 customers
    | against 50 real employees, which makes the headcount, the role breakdown
    | and the donut all meaningless.
    |
    | Matched case-insensitively against the role name. Applies to both schemas:
    | GOWISER joins `roles.role_name`, NetManager stores `users.role` directly.
    |
    | Kept in config rather than hardcoded because which roles count as staff is
    | an organisational question, not a schema one — an installation that adds a
    | "Reseller" or "Applicant" role should be able to exclude it without a code
    | change. Left empty, nothing is excluded.
    |
    */

    'non_staff_roles' => ['Customer'],

    /*
    |--------------------------------------------------------------------------
    | Income channels
    |--------------------------------------------------------------------------
    |
    | Neither monitored system stores a collection channel. Both store a
    | free-text payment method that cashiers and gateways write a dozen ways, so
    | the three channels finance reconciles against — Cash, PNB and the Payment
    | Portal — are derived by matching fragments of that string.
    |
    | The portal channel is named for the route, not the provider: SYNC's online
    | collections have run through more than one gateway, and a channel named
    | after whichever is current would have to be renamed each time. 'xendit' is
    | therefore just one of its match patterns.
    |
    | Matched case-insensitively as substrings, first channel wins, in the order
    | written here. 'pnb' therefore has to come before 'cash', or a method
    | recorded as "PNB cash deposit" is counted over the counter.
    |
    | Anything unmatched lands in "Other" rather than being folded into Cash. The
    | residue is the signal that a new payment method appeared; absorbing it
    | would hide exactly that.
    |
    */

    'income_channels' => [
        'pnb' => ['pnb', 'philippine national bank', 'bank transfer', 'bank deposit', 'bank'],
        'portal' => ['portal', 'xendit', 'online', 'gcash', 'maya', 'paymaya', 'e-wallet', 'ewallet', 'card'],
        'cash' => ['cash', 'over the counter', 'otc', 'walk-in', 'walkin', 'office', 'counter'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Sync price per customer
    |--------------------------------------------------------------------------
    |
    | SYNC is licensed per subscriber, so the platform fee is a headcount times a
    | rate rather than a line in anyone's expenses ledger. It is therefore not in
    | `expenses_logs` and cannot be — MONITOR reads the operating databases and
    | never writes to them — which is why the rate lives here and the count is
    | derived.
    |
    | 'default' is the fallback rate. The live rate is an operator-editable
    | setting (App\Models\AppSetting, key `sync_price_per_customer`), because it
    | is renegotiated more often than this file is deployed; this value is only
    | what a fresh installation starts on.
    |
    | 'excluded_statuses' are billing statuses that are not billed for SYNC.
    | Matched case-insensitively against the *raw* status names the source system
    | holds, so both spellings of Pullout are listed. VIP and Pullout come from
    | the brief: a VIP account is not charged for and a pulled-out one is not
    | connected, and counting either inflates a figure that lands under Expenses.
    |
    | Left at 0, the whole block is reported as unconfigured rather than as a
    | ₱0.00 expense — those read very differently under a Net Income figure.
    |
    */

    'sync_price' => [
        'default' => env('REPORTING_SYNC_PRICE', 0),
        'excluded_statuses' => ['vip', 'pullout', 'pulled out'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Hosting fee
    |--------------------------------------------------------------------------
    |
    | A flat monthly infrastructure charge, and the second cost that exists in no
    | monitored ledger. Unlike the SYNC fee it carries no multiplier — it is one
    | negotiated amount, not a rate times a headcount.
    |
    | Same split as sync_price: this is only the fallback a fresh installation
    | starts on. The live figure is an operator-editable setting
    | (App\Models\AppSetting, key `hosting_fee_monthly`), because it is
    | renegotiated more often than this file is deployed.
    |
    | Left at 0 the Expenses panel reports it as unconfigured rather than as a
    | ₱0.00 line — under a Net Income figure those read very differently.
    |
    */

    'hosting_fee' => [
        'default' => env('REPORTING_HOSTING_FEE', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Application status buckets
    |--------------------------------------------------------------------------
    |
    | The Applications counter is
    |
    |     (rescheduled + in progress + new apply) − failed
    |
    | which is a reporting vocabulary, not SYNC's. SYNC's `applications.status` is
    | free text an operator picks from a dropdown — 'pending', 'schedule', 'no
    | facility', 'no slot', 'duplicate', 'in progress', 'completed' — and the four
    | reporting buckets are rolled up from it here rather than in SQL, so a status
    | added to that dropdown is a config line instead of a deploy.
    |
    | Matched case-insensitively against the whole trimmed status, not as a
    | substring: 'no slot' and 'slot' would otherwise both match the same rows.
    |
    | A status in none of these buckets still appears in the status breakdown but
    | contributes to neither side of the formula. That is deliberate — silently
    | folding an unrecognised status into 'new apply' is how a workflow state
    | nobody has modelled ends up inflating the headline.
    |
    */

    'application_buckets' => [
        'rescheduled' => ['reschedule', 'rescheduled', 'jo reschedule', 'jo rescheduled'],
        'in_progress' => ['in progress', 'in_progress', 'ongoing', 'schedule', 'scheduled'],
        'new_apply' => ['new apply', 'new', 'pending', 'for approval', 'submitted'],
        'failed' => ['failed', 'cancelled', 'canceled', 'rejected', 'no facility', 'no slot', 'duplicate'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Application buckets, as the Executive Dashboard words them
    |--------------------------------------------------------------------------
    |
    | A second partition of the same `applications.status` values, for the "New
    | Customer Applications" card. It exists separately from the set above rather
    | than replacing it because the two genuinely divide the statuses along
    | different lines, and both divisions are wanted:
    |
    |   - `application_buckets` is the operational view. "No facility" is a
    |     failure there, because the queue could not deliver.
    |
    |   - this set is the executive view. "No facility" is not a failure but a
    |     *pending action* — the customer still wants service and the answer is a
    |     build, not a cancellation. Filing it under failures is how demand in
    |     unbuilt areas becomes invisible to the people who authorise builds.
    |
    | Rescheduled applications are counted as Scheduled for Setup. A rescheduled
    | application is still scheduled, only re-dated, and the card has no tile of
    | its own for them — leaving them unbucketed would drop them into `other`
    | where nobody reads them.
    |
    | Anything matching nothing here still lands in `other` and is still counted
    | in the total, so a status added to the app shows up as an unexplained gap
    | rather than being silently absorbed. See StatusBuckets.
    |
    */

    'application_cadence_buckets' => [
        'newly_applied' => ['new apply', 'new', 'submitted', 'applied'],
        'scheduled_for_setup' => [
            'in progress', 'in_progress', 'ongoing', 'schedule', 'scheduled', 'processed',
            'approved', 'for installation', 'reschedule', 'rescheduled', 'jo reschedule',
            'jo rescheduled',
        ],
        'to_be_processed' => ['pending', 'for approval', 'for processing', 'to be processed', 'for verification'],
        'no_facility' => ['no facility', 'no facility available', 'no slot', 'out of coverage', 'no coverage'],
        'cancelled' => ['cancelled', 'canceled', 'rejected', 'failed', 'duplicate', 'void', 'declined'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Plans that are not charged for
    |--------------------------------------------------------------------------
    |
    | Substrings that mark a plan as a free connection, matched case-insensitively
    | against the reconciled plan name. The Group Overview reports these beside
    | the VIP billing status, because an account can be exempted either way and
    | management asks for the count of everyone who is not paying.
    |
    | Substring rather than exact match: the same plan is spelled "VIP",
    | "VIP FREE" and "VIP - FREE" across the migrated data, and listing every
    | spelling is a losing game.
    |
    */

    'free_connection_plans' => ['vip', 'free'],

    /*
    |--------------------------------------------------------------------------
    | Work-order status buckets
    |--------------------------------------------------------------------------
    |
    | The same idea for job orders (`onsite_status`) and service orders
    | (`support_status`), which the Executive Dashboard reports as two separate
    | streams rather than one blended JO/SO figure.
    |
    */

    'work_order_buckets' => [
        'done' => ['done', 'completed', 'resolved', 'approved', 'closed'],
        'reschedule' => ['reschedule', 'rescheduled', 'follow up', 'follow-up'],
        'failed' => ['failed', 'cancelled', 'canceled', 'unresolved', 'no facility', 'no slot'],
        'in_progress' => ['in progress', 'in_progress', 'ongoing', 'pending', 'new', 'open', 'assigned', 'scheduled', 'schedule'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Capital expenditure
    |--------------------------------------------------------------------------
    |
    | Expense-type names that mean an asset was bought rather than a period cost
    | incurred. Netting CapEx against one month's income understates that month
    | and overstates every later one, which is why the Financial module reports
    | the two apart.
    |
    | Unmatched types are treated as OpEx — true of the large majority of an
    | ISP's ledger, and the safer default: a CapEx item miscounted as OpEx
    | understates profit, which is the error that gets noticed.
    |
    */

    'capex_patterns' => [
        'equipment', 'hardware', 'router', 'switch', 'onu', 'olt', 'server',
        'vehicle', 'motorcycle', 'truck', 'tower', 'construction', 'building',
        'fiber', 'fibre', 'cable roll', 'capital', 'furniture', 'computer',
        'laptop', 'machinery', 'land', 'improvement', 'asset',
    ],

    /*
    |--------------------------------------------------------------------------
    | Recurring payables
    |--------------------------------------------------------------------------
    |
    | Expense-type names that come back every period and therefore need a monthly
    | paid/unpaid tick on the Accounts Payable panel.
    |
    | A positive list rather than a list of one-offs: fixed costs are a small,
    | stable, nameable set; the things bought once are unbounded.
    |
    | NETMANAGER's own period_type column already settles this for rows booked
    | 'monthly' or 'yearly' — see ExpenseClassifier::recurrence, which trusts that
    | first and only falls back to these names.
    |
    */

    'recurring_patterns' => [
        'rent', 'rental', 'lease', 'salary', 'salaries', 'payroll', 'wage',
        'electric', 'power', 'water', 'internet', 'bandwidth', 'ip transit',
        'subscription', 'insurance', 'permit', 'license', 'licence',
        'sss', 'philhealth', 'pag-ibig', 'pagibig', 'tax', 'loan', 'amortization',
        'maintenance', 'security', 'janitorial', 'allowance', 'utilities',
        'colocation', 'co-location', 'hosting', 'telephone', 'communication',
    ],

    /*
    |--------------------------------------------------------------------------
    | Churn assumption for projected loss
    |--------------------------------------------------------------------------
    |
    | Projected churn loss is the monthly revenue at risk from accounts that have
    | already lapsed. `at_risk_factor` is the share of currently-disconnected
    | subscribers assumed not to return.
    |
    | 1.0 would claim every lapsed account is lost, which the collections team
    | disproves every month; 0 would claim none are. The default is deliberately
    | conservative and deliberately visible — the figure is labelled with the
    | assumption on screen, because a projection whose assumption is buried is
    | read as a measurement.
    |
    */

    'churn' => [
        'at_risk_factor' => env('REPORTING_CHURN_FACTOR', 0.6),
    ],

    /*
    |--------------------------------------------------------------------------
    | The date operational records became reliable
    |--------------------------------------------------------------------------
    |
    | Work orders and installations carried over from before the migration have
    | timestamps that do not describe when anything actually happened — rows
    | back-dated in bulk, defaults left at epoch, tickets never closed because
    | the old system had no closing step.
    |
    | Left unbounded, the Executive Overview reads those as a live operational
    | emergency: "oldest open job has been waiting 2,400 days" is arithmetic
    | performed on a number that was never a date. An alarm nobody can act on
    | trains people to ignore the alarm panel, which is worse than having none.
    |
    | So the alarm derivation treats anything older than this as unmeasurable
    | rather than as ancient, and says so on screen. It bounds *alarms only* —
    | the backlog counts on the Operations module still report every open row,
    | because "how many are open" is a fair question about old data even when
    | "for how long" is not.
    |
    | Set empty to disable the floor entirely.
    |
    */

    'reliable_from' => env('REPORTING_RELIABLE_FROM', '2026-08-01'),

    'company' => [
        'name' => env('REPORT_COMPANY_NAME', 'GO WISER CORPORATION'),
        'description' => env('REPORT_COMPANY_DESC', 'Internet Service Provider'),
        'address' => env(
            'REPORT_COMPANY_ADDRESS',
            'Sta. Maria, Zamboanga City, Zamboanga del Sur, Zamboanga Peninsula (Region IX)'
        ),
        'contact' => env('REPORT_COMPANY_CONTACT', ''),
        'email' => env('REPORT_COMPANY_EMAIL', ''),
        'tin' => env('REPORT_COMPANY_TIN', ''),
        // A URL the browser can reach. Left empty prints the report without a
        // logo rather than leaving a broken-image icon on a signed document.
        'logo' => env('REPORT_COMPANY_LOGO', ''),
        'currency_symbol' => env('REPORT_CURRENCY_SYMBOL', '₱'),
        // Printed under the "Noted by" rule.
        'manager' => env('REPORT_COMPANY_MANAGER', ''),
    ],

];
