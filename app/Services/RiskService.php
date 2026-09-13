<?php

namespace App\Services;

use App\Enums\RiskAction;
use App\Enums\RiskLevel;
use App\Models\Order;
use App\Models\RiskAssessment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Moteur anti-fraude (cahier des charges §27).
 *
 * Le score (0–100) agrège des signaux pondérés et déclenche une action
 * proportionnée : allow / review / block. Une revue manuelle est toujours
 * possible pour ne pas bloquer arbitrairement un participant légitime.
 */
class RiskService
{
    public function __construct(
        private readonly SecurityEventService $security,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * @param  array<string, mixed>  $context  ip, device_fingerprint, quantity…
     */
    public function assess(User $user, ?Order $order = null, array $context = []): RiskAssessment
    {
        $score = 0;
        $signals = [];

        $add = function (int $weight, string $code, string $detail) use (&$score, &$signals) {
            $score += $weight;
            $signals[] = ['code' => $code, 'weight' => $weight, 'detail' => $detail];
        };

        // 1. Compte très récent.
        $ageHours = $user->created_at?->diffInHours(now()) ?? 0;
        if ($ageHours < 1) {
            $add(15, 'account_brand_new', 'Compte créé il y a moins d’une heure');
        } elseif ($ageHours < 24) {
            $add(8, 'account_very_recent', 'Compte créé il y a moins de 24 heures');
        }

        // 2. Vélocité de commandes sur la dernière heure.
        $recentOrders = Order::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($recentOrders >= 10) {
            $add(30, 'order_velocity_extreme', "{$recentOrders} commandes dans l’heure");
        } elseif ($recentOrders >= 5) {
            $add(18, 'order_velocity_high', "{$recentOrders} commandes dans l’heure");
        }

        // 3. Paiements échoués répétés (carte / mobile money testés en série).
        $failedPayments = DB::table('payments')
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->where('orders.user_id', $user->id)
            ->whereIn('payments.status', ['failed', 'cancelled'])
            ->where('payments.created_at', '>=', now()->subDay())
            ->count();

        if ($failedPayments >= 5) {
            $add(25, 'many_failed_payments', "{$failedPayments} paiements échoués en 24 h");
        } elseif ($failedPayments >= 3) {
            $add(12, 'failed_payments', "{$failedPayments} paiements échoués en 24 h");
        }

        // 4. Quantité inhabituelle pour une commande.
        $quantity = (int) ($context['quantity'] ?? $order?->quantity ?? 0);
        if ($quantity >= 100) {
            $add(20, 'large_quantity', "{$quantity} tickets demandés");
        } elseif ($quantity >= 50) {
            $add(10, 'elevated_quantity', "{$quantity} tickets demandés");
        }

        // 5. Multiplicité de comptes depuis la même adresse IP.
        $ip = $context['ip'] ?? $order?->ip;
        if ($ip) {
            $accountsFromIp = Order::query()
                ->where('ip', $ip)
                ->where('created_at', '>=', now()->subDay())
                ->distinct('user_id')
                ->count('user_id');

            if ($accountsFromIp >= 5) {
                $add(25, 'ip_multi_accounts', "{$accountsFromIp} comptes distincts depuis la même IP");
            } elseif ($accountsFromIp >= 3) {
                $add(12, 'ip_several_accounts', "{$accountsFromIp} comptes depuis la même IP");
            }
        }

        // 6. Score de risque déjà accumulé sur le compte.
        if ((int) $user->risk_score >= 60) {
            $add(20, 'user_flagged', "Score de risque du compte : {$user->risk_score}");
        } elseif ((int) $user->risk_score >= 35) {
            $add(10, 'user_watchlisted', "Score de risque du compte : {$user->risk_score}");
        }

        // 7. Téléphone non vérifié.
        if ($user->phone_verified_at === null) {
            $add(6, 'phone_unverified', 'Numéro de téléphone non vérifié');
        }

        $score = max(0, min(100, $score));
        $level = RiskLevel::fromScore($score);

        $action = match ($level) {
            RiskLevel::Critical => RiskAction::Block,
            RiskLevel::High => RiskAction::Review,
            default => RiskAction::Allow,
        };

        $assessment = RiskAssessment::query()->create([
            'user_id' => $user->id,
            'order_id' => $order?->id,
            'score' => $score,
            'level' => $level,
            'action' => $action,
            'signals' => $signals ?: null,
        ]);

        // Le score du compte suit le niveau de risque observé.
        $user->forceFill(['risk_score' => max((int) $user->risk_score, $score)])->save();

        if ($action !== RiskAction::Allow) {
            $this->security->log(
                $action === RiskAction::Block ? 'risk_blocked' : 'risk_review',
                $action === RiskAction::Block ? 'critical' : 'high',
                $user,
                ['score' => $score, 'signals' => $signals, 'order_id' => $order?->id],
                "Commande évaluée à risque {$level->value} (score {$score})."
            );
        }

        return $assessment;
    }

    /** Validation manuelle d'une alerte par un administrateur. */
    public function review(RiskAssessment $assessment, User $reviewer, bool $allow, ?string $note = null): RiskAssessment
    {
        $assessment->forceFill([
            'is_reviewed' => true,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_note' => $note,
            'action' => $allow ? RiskAction::Allow : RiskAction::Block,
        ])->save();

        $this->audit->log('RISK_REVIEWED', $assessment, [], [
            'allowed' => $allow,
            'note' => $note,
        ], $reviewer);

        return $assessment;
    }
}
