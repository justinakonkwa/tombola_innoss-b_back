<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * MFA obligatoire pour le personnel (cahier des charges §16).
 *
 * Deux niveaux :
 *  1. **Invariant absolu** — un jeton de défi MFA (`mfa:challenge`) ne donne
 *     jamais accès au back-office, quel que soit l'environnement ;
 *  2. **Politique** — hors dérogation de développement, le jeton doit porter
 *     l'abilité `mfa`, obtenue uniquement après validation du code TOTP.
 */
class EnsureMfaVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Authentification requise.'], 401);
        }

        $abilities = $user->currentAccessToken()?->abilities ?? [];

        // Niveau 1 : jamais de jeton de défi sur le back-office, même en développement.
        if (in_array('mfa:challenge', $abilities, true)) {
            return response()->json([
                'message' => 'Vérification en deux étapes requise.',
                'code' => 'mfa_required',
            ], 403);
        }

        // Niveau 2 : politique de double authentification.
        if (! config('tombola.security.require_mfa_for_staff', true) || ! $user->isStaff()) {
            return $next($request);
        }

        if (! in_array('mfa', $abilities, true)) {
            return response()->json([
                'message' => 'Vérification en deux étapes requise.',
                'code' => 'mfa_required',
            ], 403);
        }

        return $next($request);
    }
}
