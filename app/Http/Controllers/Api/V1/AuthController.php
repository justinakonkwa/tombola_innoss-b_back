<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use App\Services\NotificationService;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Authentification (cahier des charges §5, §12).
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly OtpService $otp,
        private readonly NotificationService $notifications,
    ) {
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'min:2', 'max:80'],
            'last_name' => ['required', 'string', 'min:2', 'max:80'],
            'phone' => ['required', 'string', 'min:9', 'max:20'],
            // La vérification DNS/MX est optionnelle : elle rejette des domaines
            // parfaitement légitimes lors d'un incident DNS et ajoute de la latence.
            // La validation d'appartenance réelle passe par l'e-mail de vérification.
            'email' => ['required', config('tombola.security.verify_email_dns', false) ? 'email:rfc,dns' : 'email:rfc', 'max:180', 'unique:users,email'],
            'country' => ['nullable', 'string', 'size:2'],
            'city' => ['nullable', 'string', 'max:80'],
            'password' => ['required', 'string', 'min:10', 'max:72', 'confirmed'],
            'accept_terms' => ['accepted'],
        ], [
            'accept_terms.accepted' => 'Vous devez accepter les conditions de participation.',
            'password.min' => 'Le mot de passe doit contenir au moins 10 caractères.',
        ]);

        // Le téléphone est unique : on compare la forme normalisée.
        $normalized = $this->auth->normalizePhone($data['phone']);
        if (\App\Models\User::query()->where('phone', $normalized)->exists()) {
            return response()->json([
                'message' => 'Ce numéro de téléphone est déjà utilisé.',
                'errors' => ['phone' => ['Ce numéro de téléphone est déjà utilisé.']],
            ], 422);
        }

        $user = $this->auth->register($data, $request);

        // Vérification du numéro par OTP (le compte reste utilisable sans).
        try {
            $code = $this->otp->generate($user->phone, OtpPurpose::PhoneVerification, $user);
            $this->notifications->send($user, 'otp', ['code' => $code], null, 'Votre code de vérification', "Votre code : {$code}");
        } catch (\Throwable) {
            // L'échec d'envoi ne bloque pas l'inscription.
        }

        $tokens = $this->auth->issueTokens($user, $request, 'web');

        // Le client doit recevoir ses propres coordonnées. Sans cela, la requête
        // n'ayant pas encore d'utilisateur authentifié, UserResource le traite
        // comme un tiers et masque email et téléphone — y compris à l'inscription.
        $request->setUserResolver(fn () => $user);

        return response()->json([
            'data' => [
                'user' => new UserResource($user->load('roles')),
                'tokens' => $tokens,
            ],
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:180'],
            'password' => ['required', 'string', 'max:72'],
            'device_name' => ['nullable', 'string', 'max:60'],
        ]);

        $user = $this->auth->attemptLogin($data['identifier'], $data['password'], $request);

        // Deuxième facteur obligatoire pour les comptes internes.
        if ($user->requiresMfa() && $user->hasMfaEnabled()) {
            return response()->json([
                'mfa_required' => true,
                'challenge_token' => $this->auth->issueMfaChallenge($user),
                'message' => 'Saisissez le code de votre application d’authentification.',
            ], 200);
        }

        $tokens = $this->auth->issueTokens($user, $request, $data['device_name'] ?? 'web');

        // Le client doit recevoir ses propres coordonnées. Sans cela, la requête
        // n'ayant pas encore d'utilisateur authentifié, UserResource le traite
        // comme un tiers et masque email et téléphone — y compris à l'inscription.
        $request->setUserResolver(fn () => $user);

        return response()->json([
            'data' => [
                'user' => new UserResource($user->load('roles')),
                'tokens' => $tokens,
                'mfa_enrollment_required' => $user->requiresMfa() && ! $user->hasMfaEnabled(),
            ],
        ]);
    }

    /** Vérification du défi MFA (connexion back-office). */
    public function verifyMfaChallenge(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'size:6']]);

        /** @var \App\Models\User $user */
        $user = $request->user();

        if (! $this->auth->verifyMfa($user, $data['code'])) {
            return response()->json(['message' => 'Code invalide.'], 422);
        }

        // Le jeton de défi est consommé : il ne peut pas resservir.
        $user->currentAccessToken()?->delete();

        $tokens = $this->auth->issueTokens($user, $request, 'admin');

        // Le client doit recevoir ses propres coordonnées. Sans cela, la requête
        // n'ayant pas encore d'utilisateur authentifié, UserResource le traite
        // comme un tiers et masque email et téléphone — y compris à l'inscription.
        $request->setUserResolver(fn () => $user);

        return response()->json([
            'data' => [
                'user' => new UserResource($user->load('roles')),
                'tokens' => $tokens,
            ],
        ]);
    }

    public function requestOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'min:9', 'max:20'],
            'purpose' => ['nullable', 'string', 'in:login,phone_verification'],
        ]);

        $phone = $this->auth->normalizePhone($data['phone']);
        $purpose = OtpPurpose::from($data['purpose'] ?? 'login');
        $user = \App\Models\User::query()->where('phone', $phone)->first();

        if ($purpose === OtpPurpose::Login && ! $user) {
            // Réponse identique pour ne pas révéler l'existence d'un compte (anti-énumération).
            return response()->json(['message' => 'Si ce numéro est associé à un compte, un code a été envoyé.']);
        }

        $code = $this->otp->generate($phone, $purpose, $user);

        if ($user) {
            $this->notifications->send($user, 'otp', ['code' => $code], null, 'Votre code de connexion', "Votre code : {$code}");
        }

        $payload = ['message' => 'Si ce numéro est associé à un compte, un code a été envoyé.'];

        // En environnement local, le code est renvoyé pour faciliter les tests manuels.
        if (app()->environment('local') && config('app.debug')) {
            $payload['debug_code'] = $code;
        }

        return response()->json($payload);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'min:9', 'max:20'],
            'code' => ['required', 'string', 'size:6'],
        ]);

        $user = $this->auth->loginWithOtp($data['phone'], $data['code'], $request);
        $tokens = $this->auth->issueTokens($user, $request, 'web');

        // Le client doit recevoir ses propres coordonnées. Sans cela, la requête
        // n'ayant pas encore d'utilisateur authentifié, UserResource le traite
        // comme un tiers et masque email et téléphone — y compris à l'inscription.
        $request->setUserResolver(fn () => $user);

        return response()->json([
            'data' => [
                'user' => new UserResource($user->load('roles')),
                'tokens' => $tokens,
            ],
        ]);
    }

    public function refresh(Request $request): JsonResponse
    {
        $data = $request->validate(['refresh_token' => ['required', 'string', 'size:64']]);

        $tokens = $this->auth->refresh($data['refresh_token'], $request);

        return response()->json(['data' => ['tokens' => $tokens]]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout($request->user(), $request->input('refresh_token'));

        return response()->json(['message' => 'Déconnecté.']);
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $this->auth->logoutAll($request->user());

        return response()->json(['message' => 'Toutes vos sessions ont été déconnectées.']);
    }

    public function enrollMfa(Request $request): JsonResponse
    {
        $enrollment = $this->auth->beginMfaEnrollment($request->user());

        return response()->json([
            'data' => $enrollment,
            'message' => 'Scannez le QR code puis validez avec un code à 6 chiffres.',
        ]);
    }

    public function confirmMfa(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'size:6']]);

        if (! $this->auth->confirmMfaEnrollment($request->user(), $data['code'])) {
            return response()->json(['message' => 'Code invalide.'], 422);
        }

        return response()->json(['message' => 'Vérification en deux étapes activée.']);
    }

    public function disableMfa(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'size:6']]);

        if (! $this->auth->verifyMfa($request->user(), $data['code'])) {
            return response()->json(['message' => 'Code invalide.'], 422);
        }

        $this->auth->disableMfa($request->user());

        return response()->json(['message' => 'Vérification en deux étapes désactivée.']);
    }
}
