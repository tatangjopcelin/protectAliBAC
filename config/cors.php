<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Autorise les requêtes depuis l'app mobile (Capacitor) : émulateur Android,
    | téléphone Android, iPhone, navigateur. Sans ça, le WebView reçoit "0 Unknown Error".
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:8100',
        'http://localhost:4200',
        'http://127.0.0.1:8100',
        'http://127.0.0.1:4200',
        'capacitor://localhost',
        'http://localhost',
        'http://172.20.10.3:8001',
    ],

    'allowed_origins_patterns' => [
        '#^capacitor://#',
        '#^https?://localhost(:\d+)?$#',
        '#^https?://172\.\d+\.\d+\.\d+(:\d+)?$#',
        '#^https://[a-z0-9-]+\.ngrok-free\.app$#',
        '#^https://[a-z0-9-]+\.ngrok-free\.dev$#',
        '#^https://[a-z0-9-]+\.ngrok\.io$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
