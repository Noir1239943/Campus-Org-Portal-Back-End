<?php

/*
| The React frontend is hosted on a different domain (e.g. Vercel), so the
| API must allow it. Put one or more comma-separated origins in FRONTEND_URL.
*/

$origins = array_values(array_filter(array_map(
    fn ($origin) => rtrim(trim($origin), '/'),
    explode(',', env('CORS_ALLOWED_ORIGINS', env('FRONTEND_URL', 'http://localhost:5173')))
)));

return [
    'paths' => ['api/*', 'up'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $origins,

    // Allows Vercel preview deployments such as https://cpc-orgs-git-main-yourname.vercel.app
    'allowed_origins_patterns' => array_values(array_filter([
        env('CORS_ALLOWED_PATTERN'),
    ])),

    'allowed_headers' => ['*'],

    'exposed_headers' => ['Content-Disposition'],

    'max_age' => 86400,

    // Auth uses Bearer tokens, not cookies.
    'supports_credentials' => false,
];
