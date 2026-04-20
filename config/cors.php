<?php

$appEnv = strtolower((string) env('APP_ENV', 'production'));
$isLocal = in_array($appEnv, ['local', 'development', 'testing'], true);

$allowedOrigins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
)));

$allowedOriginPatterns = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGIN_PATTERNS', ''))
)));

// In local/dev environments, auto-allow common dev origins (Vite, Valet/Herd
// .test, localhost) so cookie-based auth works without manual CORS setup.
if ($isLocal && empty($allowedOrigins) && empty($allowedOriginPatterns)) {
    $allowedOriginPatterns = [
        '#^http://localhost(:\d+)?$#',
        '#^http://127\.0\.0\.1(:\d+)?$#',
        '#^https?://[a-z0-9-]+\.test$#',
    ];
}

$supportsCredentials = (bool) env('CORS_SUPPORTS_CREDENTIALS', $isLocal);

// If nothing is configured and we're not in local mode, fall back to a
// wildcard origin with credentials disabled (browsers reject wildcard +
// credentials, which is the whole point of this safety rail).
if (empty($allowedOrigins) && empty($allowedOriginPatterns)) {
    $allowedOrigins = ['*'];
    $supportsCredentials = false;
}

return [

    'paths' => ['api/*', 'oauth/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $allowedOrigins,

    'allowed_origins_patterns' => $allowedOriginPatterns,

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => $supportsCredentials,

];
