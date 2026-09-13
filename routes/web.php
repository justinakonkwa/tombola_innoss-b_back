<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Routes web
|--------------------------------------------------------------------------
|
| Cette application est une API pure : elle ne sert aucune vue Blade, tout le
| rendu est assuré par le frontend Next.js (dépôt `tombola_innoss-b_front`).
|
| La racine renvoie donc un repère de service en JSON plutôt que la page
| d'accueil Laravel par défaut — laquelle référence `route('login')`,
| inexistante ici, et provoquait une erreur 500.
|
| Toute la surface fonctionnelle est sous /api/v1.
|
*/

Route::get('/', function () {
    return response()->json([
        'service' => 'tombola-api',
        'status' => 'ok',
        'api' => url('/api/v1'),
        'health' => url('/api/v1/health'),
    ]);
});
