<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\OtpPurpose;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Exceptions\AuthenticationException;
use App\Models\Device;
use App\Models\Role;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Authentification et gestion des sessions (cahier des charges §5, §12).
 *
 * - mots de passe hachés en Argon2id ;
 * - verrouillage temporaire après N échecs (anti brute force) ;
 * - access token courte durée (Sanctum) + refresh token tournant, haché en base ;
 * - révocation possible de toutes les sessions ;
 * - MFA TOTP obligatoire pour les comptes du back-office.
 */
class AuthService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly SecurityEventService $security,
        private readonly OtpService $otp,
        private readonly TotpService $totp,
        private readonly NotificationService $notifications,
    ) {
    }

    /**
     * @param  array{first_name: string, last_name: string, phone: string, email: string, country?: string, city?: string, password: string}  $data
     */
    public function register(array $data, ?Request $request = null): User
    {
        return DB::transaction(function () use ($data, $request) {
            $user = User::query()->create([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'phone' => $this->normalizePhone($data['phone']),
                'email' => mb_strtolower($data['email']),
                'country' => strtoupper($data['country'] ?? 'CD'),
                'city' => $data['city'] ?? null,
                'password' => $data['password'], // haché automatiquement (cast hashed)
                'status' => UserStatus::Active,
                'locale' => $data['locale'] ?? 'fr',
            ]);

            $role = Role::query()->where('name', RoleName::Participant->value)->first();
            if ($role) {
                $user->roles()->attach($role->id);
            }

            $this->audit->log(AuditAction::UserRegistered, $user, [], [
                'email' => $user->email,
                'phone' => $user->phone,
            ], $user);

            $this->notifications->accountCreated($user);

            return $user;
        });
    }

    /**
     * Connexion par e-mail ou téléphone.
     */
    public function attemptLogin(string $identifier, string $password, ?Request $request = null): User
    {
        $user = $this->findByIdentifier($identifier);

        // Message volontairement identique dans tous les cas d'échec (anti-énumération).
        $genericError = 'Identifiants invalides.';

        if (! $user) {
            $this->security->log('login_failed', 'warning', null, [
                'identifier' => $this->maskIdentifier($identifier),
            ], 'Tentative de connexion sur un compte inexistant.');

            throw new AuthenticationException($genericError);
        }

        if ($user->isLocked()) {
            $this->security->log('login_locked', 'high', $user, [], 'Compte temporairement verrouillé.');

            throw new AuthenticationException(
                'Compte temporairement verrouillé. Réessayez dans '.$user->locked_until->diffInMinutes(now()).' minute(s).'
            );
        }

        if (! $user->status->canAuthenticate()) {
            throw new AuthenticationException('Ce compte est suspendu. Contactez le support.');
        }

        if (! Hash::check($password, $user->password)) {
            $this->registerFailedAttempt($user, $request);

            throw new AuthenticationException($genericError);
        }

        // Reconnaissance du couple utilisateur/appareil (détection de connexion inhabituelle).
        $this->touchDevice($user, $request);

        $user->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
            'last_login_ip' => $request?->ip(),
        ])->save();

        $this->audit->log(AuditAction::UserLoggedIn, $user, [], ['ip' => $request?->ip()], $user);

        return $user;
    }

    public function loginWithOtp(string $phone, string $code, ?Request $request = null): User
    {
        $phone = $this->normalizePhone($phone);

        if (! $this->otp->verify($phone, $code, OtpPurpose::Login)) {
            $this->security->log('otp_failed', 'warning', null, ['phone' => $this->maskIdentifier($phone)], 'Code OTP invalide.');

            throw new AuthenticationException('Code invalide ou expiré.');
        }

        $user = User::query()->where('phone', $phone)->first();

        if (! $user) {
            throw new AuthenticationException('Aucun compte associé à ce numéro.');
        }

        if (! $user->status->canAuthenticate()) {
            throw new AuthenticationException('Ce compte est suspendu. Contactez le support.');
        }

        $user->forceFill([
            'phone_verified_at' => $user->phone_verified_at ?? now(),
            'last_login_at' => now(),
            'last_login_ip' => $request?->ip(),
        ])->save();

        $this->touchDevice($user, $request);

        return $user;
    }

    /**
     * Émet un access token courte durée et un refresh token tournant.
     *
     * @return array{access_token: string, token_type: string, expires_at: string, refresh_token: string, refresh_expires_at: string}
     */
    public function issueTokens(User $user, ?Request $request = null, string $deviceName = 'web', array $abilities = ['*']): array
    {
        $accessTtlMinutes = (int) config('sanctum.expiration', 60);
        $refreshTtlDays = 30;

        // Les comptes du back-office reçoivent l'abilité `mfa` seulement après
        // vérification TOTP : elle conditionne l'accès aux routes /admin.
        if (in_array('*', $abilities, true) && $user->isStaff() && $user->hasMfaEnabled()) {
            $abilities = ['mfa'];
        }

        $access = $user->createToken(
            $deviceName,
            $abilities,
            now()->addMinutes($accessTtlMinutes ?: 60)
        );

        $refreshPlain = Str::random(64);

        $device = $this->device($user, $request);

        UserSession::query()->create([
            'user_id' => $user->id,
            'device_id' => $device?->id,
            'refresh_token_hash' => hash('sha256', $refreshPlain),
            'ip' => $request?->ip(),
            'user_agent' => mb_substr((string) $request?->userAgent(), 0, 255),
            'last_used_at' => now(),
            'expires_at' => now()->addDays($refreshTtlDays),
        ]);

        return [
            'access_token' => $access->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => now()->addMinutes($accessTtlMinutes ?: 60)->toIso8601String(),
            'refresh_token' => $refreshPlain,
            'refresh_expires_at' => now()->addDays($refreshTtlDays)->toIso8601String(),
        ];
    }

    /**
     * Rotation du refresh token : l'ancien est immédiatement révoqué.
     *
     * @return array{access_token: string, token_type: string, expires_at: string, refresh_token: string, refresh_expires_at: string}
     */
    public function refresh(string $refreshToken, ?Request $request = null): array
    {
        $hash = hash('sha256', $refreshToken);

        /** @var UserSession|null $session */
        $session = UserSession::query()->where('refresh_token_hash', $hash)->first();

        if (! $session || ! $session->isActive()) {
            $this->security->log('refresh_token_invalid', 'high', $session?->user, [], 'Refresh token invalide ou révoqué.');

            throw new AuthenticationException('Session expirée, veuillez vous reconnecter.');
        }

        $user = $session->user;

        if (! $user || ! $user->status->canAuthenticate()) {
            throw new AuthenticationException('Compte indisponible.');
        }

        $session->revoke('rotated');
        $user->tokens()->delete(); // rotation stricte : les access tokens précédents sont révoqués

        return $this->issueTokens($user, $request);
    }

    /** Déconnecte la session courante uniquement. */
    public function logout(User $user, ?string $refreshToken = null): void
    {
        $user->currentAccessToken()?->delete();

        if ($refreshToken) {
            UserSession::query()
                ->where('user_id', $user->id)
                ->where('refresh_token_hash', hash('sha256', $refreshToken))
                ->get()
                ->each(fn (UserSession $session) => $session->revoke('logout'));
        }

        $this->audit->log(AuditAction::UserLoggedOut, $user, [], [], $user);
    }

    /** Déconnexion de toutes les sessions (cahier des charges §5). */
    public function logoutAll(User $user): void
    {
        $user->tokens()->delete();

        UserSession::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->get()
            ->each(fn (UserSession $session) => $session->revoke('logout_all'));

        $this->security->log('sessions_revoked', 'info', $user, [], 'Toutes les sessions ont été révoquées.');

        $this->audit->log(AuditAction::UserLoggedOut, $user, [], ['all_sessions' => true], $user);
    }

    /**
     * Jeton temporaire de défi MFA : ne donne accès à rien d'autre qu'à la
     * vérification du code à deux facteurs.
     */
    public function issueMfaChallenge(User $user): string
    {
        return $user->createToken('mfa-challenge', ['mfa:challenge'], now()->addMinutes(5))->plainTextToken;
    }

    // -------------------------------------------------------------------- MFA

    /** @return array{secret: string, uri: string} */
    public function beginMfaEnrollment(User $user): array
    {
        $secret = $this->totp->generateSecret();

        $user->forceFill(['totp_secret' => $secret, 'totp_confirmed_at' => null])->save();

        return [
            'secret' => $secret,
            'uri' => $this->totp->provisioningUri($secret, $user->email, config('tombola.defaults.site_name')),
        ];
    }

    public function confirmMfaEnrollment(User $user, string $code): bool
    {
        if (! $user->totp_secret || ! $this->totp->verify($user->totp_secret, $code)) {
            return false;
        }

        $user->forceFill(['totp_confirmed_at' => now()])->save();

        $this->audit->log('MFA_ENABLED', $user, [], [], $user);

        return true;
    }

    public function verifyMfa(User $user, string $code): bool
    {
        if (! $user->totp_secret) {
            return false;
        }

        $valid = $this->totp->verify($user->totp_secret, $code);

        if (! $valid) {
            $this->security->log('mfa_failed', 'high', $user, [], 'Code MFA invalide.');
        }

        return $valid;
    }

    public function disableMfa(User $user): void
    {
        $user->forceFill(['totp_secret' => null, 'totp_confirmed_at' => null])->save();
        $this->security->log('mfa_disabled', 'high', $user, [], 'MFA désactivé sur le compte.');
    }

    // ----------------------------------------------------------------- helpers

    public function findByIdentifier(string $identifier): ?User
    {
        $identifier = trim($identifier);

        if (str_contains($identifier, '@')) {
            return User::query()->where('email', mb_strtolower($identifier))->first();
        }

        return User::query()->where('phone', $this->normalizePhone($identifier))->first();
    }

    /** Normalise un numéro congolais au format international 243XXXXXXXXX. */
    public function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '0')) {
            $digits = '243'.substr($digits, 1);
        } elseif (! str_starts_with($digits, '243') && strlen($digits) === 9) {
            $digits = '243'.$digits;
        }

        return $digits;
    }

    private function registerFailedAttempt(User $user, ?Request $request): void
    {
        $attempts = (int) $user->failed_login_attempts + 1;
        $max = (int) config('tombola.security.max_login_attempts', 5);

        $user->forceFill(['failed_login_attempts' => $attempts])->save();

        if ($attempts >= $max) {
            $user->forceFill([
                'locked_until' => now()->addMinutes((int) config('tombola.security.lockout_minutes', 15)),
                'failed_login_attempts' => 0,
            ])->save();

            $this->security->log('account_locked', 'high', $user, ['attempts' => $attempts], 'Compte verrouillé après trop d’échecs.');
        }

        $this->audit->log(AuditAction::UserLoginFailed, $user, [], ['attempts' => $attempts], $user, [
            'ip' => $request?->ip(),
        ]);
    }

    private function device(User $user, ?Request $request): ?Device
    {
        if (! $request) {
            return null;
        }

        $fingerprint = hash('sha256', implode('|', [
            (string) $request->userAgent(),
            (string) $request->header('Accept-Language'),
        ]));

        return Device::query()->firstOrCreate(
            ['user_id' => $user->id, 'fingerprint' => $fingerprint],
            [
                'label' => $this->guessDeviceLabel($request),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
                'last_ip' => $request->ip(),
                'last_seen_at' => now(),
            ]
        );
    }

    /** Détecte une connexion depuis un appareil inconnu et la signale. */
    private function touchDevice(User $user, ?Request $request): void
    {
        if (! $request) {
            return;
        }

        $fingerprint = hash('sha256', implode('|', [
            (string) $request->userAgent(),
            (string) $request->header('Accept-Language'),
        ]));

        $known = Device::query()
            ->where('user_id', $user->id)
            ->where('fingerprint', $fingerprint)
            ->exists();

        if (! $known && $user->devices()->exists()) {
            $this->security->log('new_device_login', 'warning', $user, [
                'user_agent' => $request->userAgent(),
                'ip' => $request->ip(),
            ], 'Connexion depuis un appareil inconnu.');
        }

        $this->device($user, $request)?->forceFill(['last_seen_at' => now(), 'last_ip' => $request->ip()])->save();
    }

    private function guessDeviceLabel(Request $request): string
    {
        $agent = (string) $request->userAgent();

        return match (true) {
            str_contains($agent, 'iPhone') => 'iPhone',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPad') => 'iPad',
            str_contains($agent, 'Macintosh') => 'Mac',
            str_contains($agent, 'Windows') => 'Windows',
            default => 'Appareil inconnu',
        };
    }

    private function maskIdentifier(string $identifier): string
    {
        $length = mb_strlen($identifier);

        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return mb_substr($identifier, 0, 2).str_repeat('*', max(1, $length - 4)).mb_substr($identifier, -2);
    }
}
