<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Frontend
    |--------------------------------------------------------------------------
    */
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),
    'admin_url' => env('ADMIN_URL', env('FRONTEND_URL', 'http://localhost:3000').'/admin'),

    /*
    |--------------------------------------------------------------------------
    | Commandes
    |--------------------------------------------------------------------------
    */
    'orders' => [
        // Durée de vie d'une commande en attente de paiement (minutes).
        'ttl_minutes' => (int) env('ORDER_TTL_MINUTES', 30),
        'max_per_hour_per_user' => (int) env('ORDER_MAX_PER_HOUR', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tickets
    |--------------------------------------------------------------------------
    */
    'tickets' => [
        'number_prefix' => env('TICKET_PREFIX', 'TMB'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | Canaux actifs : email, sms, whatsapp, push.
    | SMS/WhatsApp nécessitent un driver configuré (voir SendNotificationJob).
    |
    */
    'notifications' => [
        'channels' => array_values(array_filter(explode(',', (string) env('NOTIFICATION_CHANNELS', 'email')))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tirage
    |--------------------------------------------------------------------------
    */
    'draws' => [
        // Longueur minimale de la graine publique saisie par l'administrateur.
        'min_client_seed_length' => (int) env('DRAW_MIN_SEED_LENGTH', 8),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sécurité
    |--------------------------------------------------------------------------
    */
    'security' => [
        'max_login_attempts' => (int) env('MAX_LOGIN_ATTEMPTS', 5),
        'lockout_minutes' => (int) env('LOCKOUT_MINUTES', 15),
        'otp_ttl_seconds' => (int) env('OTP_TTL_SECONDS', 300),
        'require_mfa_for_staff' => (bool) env('REQUIRE_MFA_FOR_STAFF', true),
        // Vérification DNS/MX de l'e-mail à l'inscription (désactivée par défaut).
        'verify_email_dns' => (bool) env('VERIFY_EMAIL_DNS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Paramètres publics par défaut
    |--------------------------------------------------------------------------
    */
    'defaults' => [
        'site_name' => env('SITE_NAME', "Tombola Innoss’B"),
        'support_email' => env('SUPPORT_EMAIL', 'support@tombola-innossb.cd'),
        'support_phone' => env('SUPPORT_PHONE', '+243000000000'),
    ],
];
