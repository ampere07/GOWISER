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
