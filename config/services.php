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

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],


    /*
    |--------------------------------------------------------------------------
    | Passerelle de paiement Futaye
    |--------------------------------------------------------------------------
    |
    | Le token HMAC est un secret serveur : il ne doit jamais être exposé au
    | frontend ni versionné. Renseignez FUTAYE_TOKEN dans l'environnement.
    |
    */
    'futaye' => [
        'base_url' => env('FUTAYE_BASE_URL', 'https://futaye.buania.com'),
        'client_id' => env('FUTAYE_CLIENT_ID'),
        'token' => env('FUTAYE_TOKEN'),
        'webhook_secret' => env('FUTAYE_WEBHOOK_SECRET'),
        'return_url' => env('FUTAYE_RETURN_URL', env('APP_URL').'/paiement/retour'),
        'timeout' => (int) env('FUTAYE_TIMEOUT', 20),
    ],

];
