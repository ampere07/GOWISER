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
