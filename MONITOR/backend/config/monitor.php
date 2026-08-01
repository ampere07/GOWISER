<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Where the monitored databases are defined
    |--------------------------------------------------------------------------
    |
    | Not here. They live in the `site_connections` table, which the Databases
    | page writes — so adding one is a row with an encrypted password, not a
    | deploy. See App\Services\SourceRegistry.
    |
    | This file only says which *code* can query which *table structure*.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Schema drivers
    |--------------------------------------------------------------------------
    |
    | Maps a table structure to the class that knows how to query it. A site
    | connection names its structure (its profile), and SourceRegistry maps that
    | to one of these.
    |
    | The two monitored systems do not share a schema: GOWISER keeps money in
    | `transactions` and `billing_accounts`, NETMANAGER in `payments` and
    | `expenses`. One set of SQL cannot serve both, so each gets its own driver
    | and declares what it can answer.
    |
    | These are the executive rollups — the Overview, Revenue and Consolidated
    | pages.
    |
    */

    'drivers' => [
        'gowiser' => App\Services\Metrics\GowiserDriver::class,
        'netmanager' => App\Services\Metrics\NetmanagerDriver::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Reporting drivers
    |--------------------------------------------------------------------------
    |
    | The five operational sections, a much larger surface than the executive
    | rollups above: subscriber pipelines, profit and loss, the field-work queues,
    | technician workload, and staff attribution.
    |
    | No schema serves all five, and each driver declares what it can answer:
    |
    |               subscribers  financial  operations  tech  employee
    |   GOWISER         yes        yes         yes      yes     yes
    |   NETMANAGER      yes        yes         yes      no      yes
    |
    | NETMANAGER has no technician records. The frontend hides a section via the
    | capability list rather than offering a page that only fails once opened —
    | which is why this is a separate map from 'drivers' and not extra methods on
    | MetricsDriver.
    |
    */

    'reporting_drivers' => [
        'gowiser' => App\Services\Reports\GowiserReportsDriver::class,
        'netmanager' => App\Services\Reports\NetmanagerReportsDriver::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Metric cache
    |--------------------------------------------------------------------------
    |
    | Dashboards poll. Without a cache, ten managers with a browser open become
    | ten aggregate queries per poll interval, per database. Seconds; set to 0
    | while developing queries.
    |
    | Entries are keyed per database, so a fleet-wide view also warms every
    | single-database view. Keep `reporting:warm`'s schedule below this value or
    | it warms entries that have already expired.
    |
    */

    'cache_ttl' => env('MONITOR_CACHE_TTL', 60),

];
