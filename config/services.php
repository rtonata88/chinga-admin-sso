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

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // DEPRECATED: the fantasy backend URL now lives in games.backend_url
    // (see the 2026_09_06 platform-columns migration, which backfills it from
    // this value). Kept only as a fallback for rows that predate the column.
    'chinga_fantasy' => [
        'api_url' => env('CHINGA_FANTASY_API_URL', 'http://localhost:3001'),
    ],

    // Vrrr Pha engine, applied by VrrrPhaGameSeeder to games.backend_url /
    // games.launch_url. Both null by default: see the seeder for why.
    'vrrr_pha' => [
        'backend_url' => env('VRRR_PHA_BACKEND_URL'),
        'launch_url' => env('VRRR_PHA_LAUNCH_URL'),
    ],

    // SSO-internal OAuth client used to obtain a client_credentials token
    // for calling trusted partner services (e.g. chinga-fantasy admin APIs).
    'sso_internal' => [
        'client_id' => env('SSO_INTERNAL_CLIENT_ID'),
        'client_secret' => env('SSO_INTERNAL_CLIENT_SECRET'),
    ],

];
