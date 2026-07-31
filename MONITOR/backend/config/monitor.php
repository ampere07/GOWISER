<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Monitored sources
    |--------------------------------------------------------------------------
    |
    | Each entry is one operating system that management wants rolled up. The
    | key is what the frontend sends as ?source=.
    |
    |   connection  name of the connection in config/database.php
    |   driver      which schema this database uses (see app/Services/Metrics)
    |
    | 'driver' matters because the monitored systems do not share a schema.
    | GOWISER keeps money in `transactions` and `billing_accounts`; NETMANAGER
    | keeps it in `payments` and `expenses`. One set of SQL cannot serve both,
    | so each schema gets its own driver and declares what it can answer.
    |
    | Adding a database is a four-step change with no code:
    |   1. add the connection block in config/database.php
    |   2. add its DB_<NAME>_* credentials to .env
    |   3. add the entry here, pointing at an existing driver
    |   4. if the schema is new, add a driver class for it
    |
    */

    'sources' => [

        'gowiser' => [
            'label' => 'GOWISER',
            'connection' => 'gowiser',
            'driver' => 'gowiser',
            'enabled' => env('MONITOR_SOURCE_GOWISER_ENABLED', true),
        ],

        'netmanager' => [
            'label' => 'NetManager',
            'connection' => 'netmanager',
            'driver' => 'netmanager',
            'enabled' => env('MONITOR_SOURCE_NETMANAGER_ENABLED', true),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Schema drivers
    |--------------------------------------------------------------------------
    |
    | Maps a driver name to the class that knows how to query that schema.
    |
    */

    'drivers' => [
        'gowiser' => App\Services\Metrics\GowiserDriver::class,
        'netmanager' => App\Services\Metrics\NetmanagerDriver::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Default source
    |--------------------------------------------------------------------------
    |
    | Used when the frontend does not name one. Must be a key from 'sources'.
    |
    */

    'default_source' => env('MONITOR_DEFAULT_SOURCE', 'netmanager'),

    /*
    |--------------------------------------------------------------------------
    | Metric cache
    |--------------------------------------------------------------------------
    |
    | Executive dashboards poll. Without a cache, ten managers with a browser
    | open become ten aggregate queries per poll interval against a production
    | database. Seconds; set to 0 to disable while developing.
    |
    */

    'cache_ttl' => env('MONITOR_CACHE_TTL', 60),

    /*
    |--------------------------------------------------------------------------
    | Statement timeout
    |--------------------------------------------------------------------------
    |
    | Upper bound (seconds) on any single query fired at a source database, so
    | a slow rollup can never hold a connection on the production server.
    |
    */

    'query_timeout' => env('MONITOR_QUERY_TIMEOUT', 15),

];
