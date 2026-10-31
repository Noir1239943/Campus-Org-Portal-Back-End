<?php

return [
    // API tokens are used (Authorization: Bearer ...), so no stateful domains are needed.
    'stateful' => [],

    'guard' => ['web'],

    // Each token gets its own expiry when it is created (see AuthController::login).
    'expiration' => null,

    // Default token lifetime in minutes (7 days). "Keep me signed in" extends it to 30 days.
    'token_minutes' => (int) env('SANCTUM_EXPIRATION', 60 * 24 * 7),

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', 'cpc_'),
];
