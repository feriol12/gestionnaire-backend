<?php

return [
    // Routing switch only: never substitutes for the database-backed MFA checks.
    'mfa_enabled' => env('ADMIN_MFA_ENABLED', false),
    // No origin is activated by default. This does not register any route.
    // Future deployment must provide one exact HTTPS origin (no path/wildcard).
    'origin' => env('ADMIN_ORIGIN'),
    'session' => [
        'cookie' => 'fluxmoneva_admin_session',
        // All future CSRF/login/MFA/logout endpoints must live below /api/admin.
        // Not __Host-: that prefix requires Path=/.
        'path' => '/api/admin',
        'connection' => env('ADMIN_SESSION_CONNECTION'),
        'table' => 'admin_sessions',
        'lifetime' => 15,
    ],
    // BE-03 (OpenCode F-06) : copie de la configuration de session CLIENT,
    // lue par la purge physique complémentaire d'une suspension. En contexte
    // admin, SelectAdminContext réécrit session.* : ces clés-ci restent celles
    // du client. Mêmes sources que config/session.php.
    'client_session' => [
        'driver' => env('SESSION_DRIVER', 'file'),
        'connection' => env('SESSION_CONNECTION'),
        'table' => 'sessions',
    ],
];
