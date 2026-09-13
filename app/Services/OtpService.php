<?php

namespace App\Services;

use App\Enums\OtpPurpose;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Codes OTP à usage unique (cahier des charges §5, §24).
 * Codes hachés en base, durée de vie courte, nombre d'essais limité, rate limiting.
 */
class OtpService
{
    private const TTL_SECONDS = 300;

    private const MAX_ATTEMPTS = 5;

    /**
     * Génère un code et renvoie sa valeur en clair (à envoyer par SMS/WhatsApp).
     * Seul le hash est stocké.
     */
    public function generate(string $phone, OtpPurpose $purpose = OtpPurpose::Login, ?User $user = null): string
    {
        $key = "otp:{$purpose->value}:{$phone}";

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw new \RuntimeException('Trop de demandes de code. Réessayez dans une minute.');
        }

        RateLimiter::hit($key, 60);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // Invalide les codes précédents encore valides pour ce couple téléphone/usage.
        OtpCode::query()
            ->where('phone', $phone)
            ->where('purpose', $purpose->value)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        OtpCode::query()->create([
            'user_id' => $user?->id,
            'phone' => $phone,
            'code_hash' => Hash::make($code),
            'purpose' => $purpose,
            'max_attempts' => self::MAX_ATTEMPTS,
            'ip' => request()->ip(),
            'expires_at' => now()->addSeconds(self::TTL_SECONDS),
        ]);

        return $code;
    }

    public function verify(string $phone, string $code, OtpPurpose $purpose = OtpPurpose::Login): bool
    {
        /** @var OtpCode|null $otp */
        $otp = OtpCode::query()
            ->where('phone', $phone)
            ->where('purpose', $purpose->value)
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();

        if (! $otp || ! $otp->isUsable()) {
            return false;
        }

        $otp->increment('attempts');

        if (! Hash::check($code, $otp->code_hash)) {
            return false;
        }

        $otp->forceFill(['consumed_at' => now()])->save();

        return true;
    }
}
