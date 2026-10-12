<?php

return [
    /*
    | Simulator providers (KYC, mobile money, SMS) and the simulator endpoints. For
    | development and demos only: never on in production.
    */
    'simulator' => (bool) env('CHANNEL_SIMULATOR', false),

    // The terms players accept on first dial (USSD design §6). Bump to ask again.
    'terms_version' => env('CHANNEL_TERMS_VERSION', '2026-10'),

    // Where the verify link in a ladder SMS points (U-D18): {base}/v/<ladder>?c=<commitment prefix>.
    'verify_base_url' => env('CHANNEL_VERIFY_BASE_URL', 'http://localhost:5190'),

    // Player access tokens minted for a channel (U-D11).
    'player_token_minutes' => 5,
];
