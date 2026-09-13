<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentChannel;
use App\Enums\PaymentStatus;
use App\Enums\TicketStatus;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Tableau de bord du back-office (cahier des charges §25).
 *
 * Les agrégations sont coûteuses sur de gros volumes : elles sont mises en
 * cache 60 secondes sous une clé unique (données globales, pas par utilisateur).
 */
class DashboardController extends Controller
{
    /** Fenêtre glissante des séries temporelles. */
    private const DAYS = 30;

    private const CACHE_TTL_SECONDS = 60;

    public function index(): JsonResponse
    {
        $stats = Cache::remember(
            'admin.dashboard.v1',
            self::CACHE_TTL_SECONDS,
            fn () => $this->buildStats()
        );

        return response()->json(['data' => $stats]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildStats(): array
    {
        $since = now()->subDays(self::DAYS - 1)->startOfDay();

        $ticketsSold = (int) Campaign::query()->sum('tickets_sold');
        $capacity = (int) Campaign::query()->sum('max_tickets');
        $reserved = (int) Campaign::query()->sum('tickets_reserved');

        $totals = [
            'participants' => User::query()->count(),
            'tickets_sold' => $ticketsSold,
            'tickets_remaining' => max(0, $capacity - $ticketsSold - $reserved),
            'revenue' => round((float) Payment::query()
                ->where('status', PaymentStatus::Success->value)
                ->sum('amount'), 2),
            'transactions' => Payment::query()->count(),
            'active_users' => User::query()->where('status', UserStatus::Active->value)->count(),
            'active_campaigns' => Campaign::query()->where('status', 'active')->count(),
        ];

        $createdOrders = Order::query()->count();
        $paidOrders = Order::query()->where('status', OrderStatus::Paid->value)->count();

        return [
            'totals' => $totals,
            'conversion_rate' => $createdOrders > 0
                ? round(($paidOrders / $createdOrders) * 100, 2)
                : 0.0,
            'sales_by_day' => $this->salesByDay($since),
            'new_participants_by_day' => $this->newParticipantsByDay($since),
            'payment_methods' => $this->paymentMethods(),
            'per_campaign' => $this->perCampaign(),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Tickets vendus et revenus encaissés par jour (jours sans activité inclus
     * pour que le frontend trace une courbe continue).
     *
     * @return list<array{date: string, tickets: int, revenue: float, transactions: int}>
     */
    private function salesByDay(\Illuminate\Support\Carbon $since): array
    {
        $tickets = DB::table('tickets')
            ->where('created_at', '>=', $since)
            ->whereIn('status', [
                TicketStatus::Valid->value,
                TicketStatus::Winner->value,
                TicketStatus::Used->value,
            ])
            ->selectRaw('DATE(created_at) AS day, COUNT(*) AS total')
            ->groupByRaw('DATE(created_at)')
            ->pluck('total', 'day');

        $payments = DB::table('payments')
            ->where('status', PaymentStatus::Success->value)
            ->whereRaw('COALESCE(confirmed_at, created_at) >= ?', [$since])
            ->selectRaw('DATE(COALESCE(confirmed_at, created_at)) AS day, COUNT(*) AS total, COALESCE(SUM(amount), 0) AS revenue')
            ->groupByRaw('DATE(COALESCE(confirmed_at, created_at))')
            ->get()
            ->keyBy('day');

        $series = [];

        for ($offset = 0; $offset < self::DAYS; $offset++) {
            $date = $since->copy()->addDays($offset)->toDateString();
            $payment = $payments->get($date);

            $series[] = [
                'date' => $date,
                'tickets' => (int) ($tickets[$date] ?? 0),
                'revenue' => round((float) ($payment->revenue ?? 0), 2),
                'transactions' => (int) ($payment->total ?? 0),
            ];
        }

        return $series;
    }

    /**
     * @return list<array{date: string, participants: int}>
     */
    private function newParticipantsByDay(\Illuminate\Support\Carbon $since): array
    {
        $users = DB::table('users')
            ->whereNull('deleted_at')
            ->where('created_at', '>=', $since)
            ->selectRaw('DATE(created_at) AS day, COUNT(*) AS total')
            ->groupByRaw('DATE(created_at)')
            ->pluck('total', 'day');

        $series = [];

        for ($offset = 0; $offset < self::DAYS; $offset++) {
            $date = $since->copy()->addDays($offset)->toDateString();

            $series[] = [
                'date' => $date,
                'participants' => (int) ($users[$date] ?? 0),
            ];
        }

        return $series;
    }

    /**
     * Répartition des encaissements par canal de paiement.
     *
     * @return list<array{channel: string, channel_label: string, count: int, amount: float, commission: float, net: float}>
     */
    private function paymentMethods(): array
    {
        return DB::table('payments')
            ->where('status', PaymentStatus::Success->value)
            ->selectRaw('channel, COUNT(*) AS total, COALESCE(SUM(amount), 0) AS amount, COALESCE(SUM(commission), 0) AS commission, COALESCE(SUM(net), 0) AS net')
            ->groupBy('channel')
            ->orderByDesc('amount')
            ->get()
            ->map(function ($row) {
                $channel = PaymentChannel::tryFrom((string) $row->channel);

                return [
                    'channel' => (string) $row->channel,
                    'channel_label' => $channel?->label() ?? (string) $row->channel,
                    'count' => (int) $row->total,
                    'amount' => round((float) $row->amount, 2),
                    'commission' => round((float) $row->commission, 2),
                    'net' => round((float) $row->net, 2),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Statistiques par campagne (avancement des ventes, participants, revenus).
     *
     * @return list<array<string, mixed>>
     */
    private function perCampaign(): array
    {
        $revenue = DB::table('payments')
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->where('payments.status', PaymentStatus::Success->value)
            ->whereNull('orders.deleted_at')
            ->selectRaw('orders.campaign_id AS campaign_id, COALESCE(SUM(payments.amount), 0) AS revenue, COUNT(*) AS transactions')
            ->groupBy('orders.campaign_id')
            ->get()
            ->keyBy('campaign_id');

        $participants = DB::table('tickets')
            ->selectRaw('campaign_id, COUNT(DISTINCT user_id) AS participants')
            ->groupBy('campaign_id')
            ->pluck('participants', 'campaign_id');

        return Campaign::query()
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(function (Campaign $campaign) use ($revenue, $participants) {
                $money = $revenue->get($campaign->id);

                return [
                    'id' => $campaign->id,
                    'name' => $campaign->name,
                    'slug' => $campaign->slug,
                    'status' => $campaign->status->value,
                    'status_label' => $campaign->status->label(),
                    'tickets_sold' => (int) $campaign->tickets_sold,
                    'tickets_remaining' => $campaign->ticketsRemaining(),
                    'sales_progress' => $campaign->salesProgress(),
                    'participants' => (int) ($participants[$campaign->id] ?? 0),
                    'revenue' => round((float) ($money->revenue ?? 0), 2),
                    'transactions' => (int) ($money->transactions ?? 0),
                    'currency' => $campaign->currency,
                    'starts_at' => $campaign->starts_at?->toIso8601String(),
                    'ends_at' => $campaign->ends_at?->toIso8601String(),
                    'draw_at' => $campaign->draw_at?->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }
}
