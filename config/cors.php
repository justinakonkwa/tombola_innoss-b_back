<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS)
    |--------------------------------------------------------------------------
    |
    | Le frontend et l'API sont servis par des domaines distincts : le navigateur
    | n'autorise les appels que si l'origine du frontend est explicitement
    | déclarée ici.
    |
    | `FRONTEND_URL` est déjà la source de vérité pour les liens sortants : on
    | s'en sert aussi pour le CORS, afin qu'une seule variable pilote les deux.
    | `CORS_ALLOWED_ORIGINS` permet d'ajouter des origines supplémentaires
    | (préproduction, port local différent), séparées par des virgules.
    |
    | En développement, si FRONTEND_URL n'est pas définie, on retombe sur `*`
    | pour ne pas bloquer le travail local.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_merge(
        (array) env('FRONTEND_URL', ''),
        array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')))
    ))) ?: ['*'],

    'allowed_origins_patterns' => [],

    // Les en-têtes utilisés par le client : jeton d'accès, idempotence, JSON.
    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'Idempotency-Key',
        'X-Requested-With',
        'X-Futaye-Client-Id',
        'X-Futaye-Timestamp',
        'X-Futaye-Signature',
    ],

    'exposed_headers' => [],

    // Mise en cache des requêtes préalables (preflight) pendant 24 h. Sans cela,
    // chaque appel authentifié déclenche une requête OPTIONS supplémentaire,
    // ce qui double la latence perçue depuis le navigateur.
    'max_age' => 86400,

    // Authentification par jeton Bearer, pas par cookie : les identifiants ne
    // doivent pas être transmis automatiquement par le navigateur.
    'supports_credentials' => false,

];
