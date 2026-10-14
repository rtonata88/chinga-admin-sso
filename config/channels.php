<?php

return [
    /*
    | Simulator providers (KYC, mobile money, SMS) and the simulator endpoints. For
    | development, demos and integration sandboxes only. Outside local and testing they
    | run only for the tenants listed (comma-separated uuids) in CHANNEL_SIMULATOR_TENANTS:
    | sandbox tenants with no real players, never a real operator's.
    */
    'simulator' => (bool) env('CHANNEL_SIMULATOR', false),
    'simulator_tenants' => array_values(array_filter(array_map('trim', explode(',', (string) env('CHANNEL_SIMULATOR_TENANTS', ''))))),
    // The service code the integration sandbox's tenant answers on (ChannelSandboxSeeder).
    'sandbox_code' => env('CHANNEL_SANDBOX_CODE', '*123*45#'),

    // Load tests only: the simulator's SMS driver waits this long per message.
    'simulator_sms_delay_ms' => (int) env('CHANNEL_SIM_SMS_DELAY_MS', 0),

    // The terms players accept on first dial (USSD design §6). Bump to ask again.
    'terms_version' => env('CHANNEL_TERMS_VERSION', '2026-10'),

    // Where the verify link in a ladder SMS points (U-D18): {base}/v/<ladder>?c=<commitment prefix>.
    'verify_base_url' => env('CHANNEL_VERIFY_BASE_URL', 'http://localhost:5190'),

    // Player access tokens minted for a channel (U-D11).
    'player_token_minutes' => 5,
];
