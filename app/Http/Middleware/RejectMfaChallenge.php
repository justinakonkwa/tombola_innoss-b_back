<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Interdit l'usage d'un jeton de défi MFA hors de la résolution du défi.
 *
 * Un jeton de défi est émis après vérification du mot de passe mais AVANT la
 * validation du second facteur : il ne représente donc qu'une authentification
 * partielle. Il ne doit permettre **aucune** action métier (consulter son
 * profil, passer une commande, atteindre le back-office) — uniquement fournir
 * le code TOTP. Cet invariant est indépendant de l'environnement : la
 * dérogation de développement `REQUIRE_MFA_FOR_STAFF=false` ne l'assouplit pas.
 */
class RejectMfaChallenge
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $abilities = $user?->currentAccessToken()?->abilities ?? [];

        if (in_array('mfa:challenge', $abilities, true)) {
            return response()->json([
                'message' => 'Vérification en deux étapes requise avant toute autre action.',
                'code' => 'mfa_required',
            ], 403);
        }

        return $next($request);
    }
}
