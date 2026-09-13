<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

/**
 * Authentification d'une API sans interface web.
 *
 * Le squelette Laravel redirige une requête non authentifiée vers la route
 * nommée `login`. Sur une API pure cette route n'existe pas : `route('login')`
 * lève une `RouteNotFoundException` avant même que l'`AuthenticationException`
 * ne soit construite, et la réponse devient un HTTP 500 au lieu d'un 401.
 *
 * On ne redirige donc jamais. L'exception remonte au gestionnaire qui répond
 * systématiquement en JSON sur `/api/*` — y compris quand le client n'envoie
 * aucun en-tête `Accept`.
 */
class Authenticate extends Middleware
{
    protected function redirectTo(Request $request): ?string
    {
        return null;
    }
}
