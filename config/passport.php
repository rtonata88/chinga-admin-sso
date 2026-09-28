<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Passport Guard
    |--------------------------------------------------------------------------
    |
    | Here you may specify which authentication guard Passport will use when
    | authenticating users. This value should correspond with one of your
    | guards that is already present in your "auth" configuration file.
    |
    */

    'guard' => 'web',

    /*
    |--------------------------------------------------------------------------
    | Encryption Keys
    |--------------------------------------------------------------------------
    |
    | Passport uses encryption keys while generating secure access tokens for
    | your application. By default, the keys are stored as local files but
    | can be set via environment variables when that is more convenient.
    |
    */

    'private_key' => env('PASSPORT_PRIVATE_KEY'),

    'public_key' => env('PASSPORT_PUBLIC_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Passport Database Connection
    |--------------------------------------------------------------------------
    |
    | By default, Passport's models will utilize your application's default
    | database connection. If you wish to use a different connection you
    | may specify the configured name of the database connection here.
    |
    */

    'connection' => env('PASSPORT_CONNECTION'),


    /*
    |--------------------------------------------------------------------------
    | Player token lifetimes
    |--------------------------------------------------------------------------
    |
    | Access tokens are short; the client refreshes them silently while the
    | player is active. The refresh token's lifetime is the idle window: a
    | player away for longer must log in again. The refresh cookie is issued
    | with the same lifetime so the two never disagree.
    |
    */

    'access_tokens_expire_in_minutes' => (int) env('PASSPORT_ACCESS_TOKEN_MINUTES', 10),
    'refresh_tokens_expire_in_minutes' => (int) env('PASSPORT_REFRESH_TOKEN_MINUTES', 20),

];
