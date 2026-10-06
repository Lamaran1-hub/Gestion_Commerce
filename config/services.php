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

    // Paiement en ligne des licences (Orange Money, MTN MoMo, carte) — https://developers.djomy.africa
    // Les fonds arrivent sur le compte marchand Djomy ; le reversement vers votre numéro dédié
    // se règle dans votre espace marchand Djomy, pas dans le code.
    'djomy' => [
        'actif' => (bool) env('DJOMY_ACTIF', false),
        // sandbox = test (aucun argent réel) ; production = paiements réels
        'mode' => env('DJOMY_MODE', 'sandbox') === 'production' ? 'production' : 'sandbox',
        'url' => rtrim((string) (env('DJOMY_BASE_URL') ?: (env('DJOMY_MODE', 'sandbox') === 'production'
            ? 'https://api.djomy.africa' : 'https://sandbox-api.djomy.africa')), '/'),
        // Numéro sur lequel l'éditeur reçoit les paiements (reversement réglé dans l'espace marchand Djomy)
        'numero_marchand' => env('DJOMY_NUMERO_MARCHAND', '224627406834'),
        'client_id' => env('DJOMY_CLIENT_ID'),
        'client_secret' => env('DJOMY_CLIENT_SECRET'),
        'pays' => env('DJOMY_PAYS', 'GN'),
        // Moyens acceptés par défaut (modifiables par le propriétaire dans « Ma société ») :
        // OM, MOMO, CARD, PAYCARD, KULU, SOUTRA_MONEY
        'moyens' => array_filter(explode(',', (string) env('DJOMY_MOYENS', 'OM,MOMO,CARD,PAYCARD,KULU,SOUTRA_MONEY'))),
        'delai' => (int) env('DJOMY_TIMEOUT', 20),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
