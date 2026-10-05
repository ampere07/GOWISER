<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'api_key' => env('RESEND_API_KEY'),
    ],

    'xendit' => [
        'api_key' => env('XENDIT_API_KEY'),
        'callback_token' => env('XENDIT_CALLBACK_TOKEN'),
        'base_url' => env('XENDIT_BASE_URL', 'https://api.xendit.co'),
        'api_version' => env('XENDIT_API_VERSION', '2024-11-11'),
    ],

    'google' => [
        'drive' => [
            'folder_id' => env('GOOGLE_DRIVE_FOLDER_ID'),
            'client_id' => env('GOOGLE_DRIVE_CLIENT_ID'),
            'client_email' => env('GOOGLE_DRIVE_CLIENT_EMAIL'),
            'private_key_id' => env('GOOGLE_DRIVE_PRIVATE_KEY_ID'),
            'private_key' => env('GOOGLE_DRIVE_PRIVATE_KEY'),
            'project_id' => env('GOOGLE_DRIVE_PROJECT_ID'),
        ],
    ],

    // RouterOS API (binary protocol) endpoint the RADIUS status sync reads users and sessions
    // from. It reaches the router of ONE radius_config row through a different address; the
    // login is that row's username/password. Every other radius_config row — and this one,
    // if the API cannot be reached — keeps using the REST interface.
    'radius_status_api' => [
        'enabled' => env('RADIUS_STATUS_API_ENABLED', true),
        'host' => env('RADIUS_STATUS_API_HOST', '126.209.53.74'),
        'port' => (int) env('RADIUS_STATUS_API_PORT', 58728),
        'ssl' => env('RADIUS_STATUS_API_SSL', false),
        'timeout' => (int) env('RADIUS_STATUS_API_TIMEOUT', 15),
        // radius_config.id whose credentials (and router) this endpoint belongs to;
        // empty = the first radius_config by id ("Radius Config 1").
        'config_id' => env('RADIUS_STATUS_API_CONFIG_ID'),
    ],

];


