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

    /*
    |--------------------------------------------------------------------------
    | Apple Push Notifications (APNs)
    |--------------------------------------------------------------------------
    | Pour envoyer des notifications push iOS. Clé .p8 depuis Apple Developer.
    | Bundle ID doit correspondre à l'app (ex. com.tdblg.app pour Brole).
    */
    'apn' => [
        'key_id' => env('APN_KEY_ID'),
        'team_id' => env('APN_TEAM_ID'),
        'bundle_id' => env('APN_BUNDLE_ID', 'com.tdblg.app'),
        'key_path' => env('APN_KEY_PATH'), // chemin vers le fichier .p8
        'sandbox' => env('APN_SANDBOX', true), // true = développement
    ],

    /*
    |--------------------------------------------------------------------------
    | Firebase Cloud Messaging (FCM) – notifications push Android
    |--------------------------------------------------------------------------
    | Fichier JSON du compte de service Firebase (Project settings > Service accounts).
    | Télécharger la clé JSON et placer le fichier dans storage/app (ne pas le versionner).
    */
    'fcm' => [
        'credentials_path' => env('FCM_CREDENTIALS_JSON', storage_path('app/firebase-credentials.json')),
    ],

];
