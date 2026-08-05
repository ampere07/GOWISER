<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mikrotik User Manager servers
    |--------------------------------------------------------------------------
    |
    | The RADIUS servers the Mikrotik Radius Shortcut talks to, in failover
    | order: the first that answers a read wins, and a write is applied to the
    | server the record was found on.
    |
    | In config rather than in a database table, unlike the monitored database
    | connections. These credentials open a router that can cut every subscriber
    | in the region off, and that is not something to make editable from a web
    | form — the blast radius of a mistyped host here is the whole network, and a
    | change to it should require a deploy and appear in a diff.
    |
    | Configure through the environment:
    |
    |   MIKROTIK_1_URL=https://10.0.0.2:443
    |   MIKROTIK_1_USER=monitor-ro
    |   MIKROTIK_1_PASSWORD=…
    |   MIKROTIK_1_LABEL="Core RADIUS"
    |
    | A second server uses the _2_ prefix. A server with no URL is skipped, so an
    | unconfigured deployment simply reports the feature as unavailable rather
    | than erroring.
    |
    | Use an account with only the permissions this feature needs. It reads
    | users, groups and profiles, and it writes group rate limits, pools and
    | session terminations — nothing else.
    |
    */

    'servers' => array_values(array_filter([
        [
            'key' => 'primary',
            'label' => env('MIKROTIK_1_LABEL', 'Primary RADIUS'),
            'url' => env('MIKROTIK_1_URL'),
            'username' => env('MIKROTIK_1_USER'),
            'password' => env('MIKROTIK_1_PASSWORD'),
        ],
        [
            'key' => 'secondary',
            'label' => env('MIKROTIK_2_LABEL', 'Secondary RADIUS'),
            'url' => env('MIKROTIK_2_URL'),
            'username' => env('MIKROTIK_2_USER'),
            'password' => env('MIKROTIK_2_PASSWORD'),
        ],
    ], fn ($server) => !empty($server['url']))),

    /*
    |--------------------------------------------------------------------------
    | Request timeouts
    |--------------------------------------------------------------------------
    |
    | Short, and deliberately so. A router that is powered off but still
    | answering ARP holds a connection for the operating-system default, and this
    | screen is opened by an executive who will simply conclude the portal is
    | broken. Better to declare the server unreachable in a few seconds and say
    | which one.
    |
    */

    'connect_timeout' => env('MIKROTIK_CONNECT_TIMEOUT', 4),
    'timeout' => env('MIKROTIK_TIMEOUT', 12),

    /*
    |--------------------------------------------------------------------------
    | TLS verification
    |--------------------------------------------------------------------------
    |
    | Mikrotik routers usually present a self-signed certificate, so this
    | defaults to off for the private management network these hosts sit on.
    |
    | Turn it on wherever the routers carry a real certificate. Left off, the
    | connection is encrypted but not authenticated, which is acceptable only
    | because the alternative in most deployments is operators disabling TLS
    | altogether.
    |
    */

    'verify_tls' => env('MIKROTIK_VERIFY_TLS', false),

    /*
    |--------------------------------------------------------------------------
    | How long a read is cached
    |--------------------------------------------------------------------------
    |
    | Seconds. The user and group lists change rarely and are read on every page
    | open, so a short cache keeps a busy screen from hammering a router that is
    | also serving live authentication traffic.
    |
    | Writes flush it, so an operator never sees their own change fail to appear.
    |
    */

    'cache_ttl' => env('MIKROTIK_CACHE_TTL', 30),

    /*
    |--------------------------------------------------------------------------
    | The maintenance window
    |--------------------------------------------------------------------------
    |
    | "Kick Sessions Later" queues a disconnection instead of performing it, and
    | `mikrotik:drain-kicks` performs the queue during this window.
    |
    | Expressed as local times. A window that ends before it starts is read as
    | crossing midnight — 23:00 to 04:00 is the usual choice, being the hours
    | when cutting a session inconveniences fewest people.
    |
    */

    'maintenance_window' => [
        'start' => env('MIKROTIK_WINDOW_START', '01:00'),
        'end' => env('MIKROTIK_WINDOW_END', '05:00'),
    ],

];
