<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CampaignStatus;
use App\Enums\WinnerStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CampaignResource;
use App\Http\Resources\DrawResource;
use App\Http\Resources\PrizeResource;
use App\Http\Resources\WinnerResource;
use App\Models\Campaign;
use App\Models\Draw;
use App\Models\Prize;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Winner;
use App\Services\DrawService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Endpoints publics (cahier des charges §44, §23, §21).
 * Aucune donnée personnelle non minimisée n'est exposée ici.
 */
class PublicController extends Controller
{
    public function health(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'tombola-api',
            'time' => now()->toIso8601String(),
            'database' => $this->databaseHealthy() ? 'up' : 'down',
        ]);
    }

    public function publicSettings(): JsonResponse
    {
        $settings = Setting::query()
            ->where('is_public', true)
            ->get()
            ->mapWithKeys(fn (Setting $setting) => [$setting->key => $setting->value]);

        return response()->json([
            'data' => $settings->merge([
                'site_name' => config('tombola.defaults.site_name'),
                'support_email' => config('tombola.defaults.support_email'),
                'support_phone' => config('tombola.defaults.support_phone'),
                'currency' => 'USD',
            ]),
        ]);
    }

    public function campaigns(Request $request): JsonResponse
    {
        $campaigns = Campaign::query()
            ->publiclyVisible()
            ->with(['prizes' => fn ($query) => $query->where('status', 'published')])
            ->when($request->boolean('featured'), fn ($query) => $query->where('is_featured', true))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderByDesc('is_featured')
            ->orderByDesc('starts_at')
            ->paginate(min((int) $request->integer('per_page', 12), 50));

        return CampaignResource::collection($campaigns)->response();
    }

    public function campaign(string $slug): JsonResponse
    {
        $campaign = Campaign::query()
            ->where('slug', $slug)
            ->publiclyVisible()
            ->with([
                'prizes' => fn ($query) => $query->where('status', 'published')->orderBy('position'),
                'mainPrize',
            ])
            ->firstOrFail();

        return response()->json(['data' => new CampaignResource($campaign)]);
    }

    /** Statistiques publiques d'une campagne (transparence). */
    public function campaignStats(string $slug): JsonResponse
    {
        $campaign = Campaign::query()->where('slug', $slug)->publiclyVisible()->firstOrFail();

        $participants = Ticket::query()
            ->where('campaign_id', $campaign->id)
            ->distinct('user_id')
            ->count('user_id');

        $draw = Draw::query()
            ->where('campaign_id', $campaign->id)
            ->whereIn('status', ['executed', 'published'])
            ->latest('executed_at')
            ->first();

        return response()->json([
            'data' => [
                'tickets_sold' => (int) $campaign->tickets_sold,
                'tickets_remaining' => $campaign->ticketsRemaining(),
                'max_tickets' => (int) $campaign->max_tickets,
                'sales_progress' => $campaign->salesProgress(),
                'participants' => $participants,
                'ticket_price' => (float) $campaign->ticket_price,
                'currency' => $campaign->currency,
                'ends_at' => $campaign->ends_at?->toIso8601String(),
                'draw_at' => $campaign->draw_at?->toIso8601String(),
                'draw' => $draw ? [
                    'reference' => $draw->reference,
                    'executed_at' => $draw->executed_at?->toIso8601String(),
                    'published_at' => $draw->published_at?->toIso8601String(),
                    'pool_size' => (int) $draw->pool_size,
                    'ticket_pool_hash' => $draw->ticket_pool_hash,
                    'server_seed_hash' => $draw->server_seed_hash,
                    'client_seed' => $draw->client_seed,
                    'algorithm' => $draw->algorithm,
                ] : null,
            ],
        ]);
    }

    /** Page publique des gagnants : identités masquées (cahier des charges §23). */
    public function winners(Request $request): JsonResponse
    {
        $winners = Winner::query()
            ->where('is_published', true)
            ->whereIn('status', [
                WinnerStatus::Pending->value,
                WinnerStatus::Contacted->value,
                WinnerStatus::Verified->value,
                WinnerStatus::Validated->value,
                WinnerStatus::Delivered->value,
            ])
            ->with(['user', 'prize', 'ticket', 'draw.campaign'])
            ->when($request->filled('campaign'), function ($query) use ($request) {
                $query->whereHas('draw.campaign', fn ($q) => $q->where('slug', $request->string('campaign')));
            })
            ->orderByDesc('created_at')
            ->paginate(min((int) $request->integer('per_page', 20), 50));

        return WinnerResource::collection($winners)->response();
    }

    /** Vérification indépendante d'un tirage (cahier des charges §21). */
    public function verifyDraw(string $reference, DrawService $draws): JsonResponse
    {
        $draw = Draw::query()->where('reference', $reference)->firstOrFail();

        if (! $draw->isFrozen()) {
            return response()->json([
                'message' => 'Ce tirage n’a pas encore été exécuté : aucune preuve disponible.',
                'status' => $draw->status->value,
            ], 409);
        }

        $result = $draws->verify($draw);

        return response()->json([
            'data' => [
                'draw' => new DrawResource($draw->load('campaign', 'winners.prize', 'winners.ticket', 'winners.user')),
                'verification' => $result,
                'verified' => $result['commitment_valid'] && $result['pool_hash_valid'] && $result['winners_valid'],
            ],
        ]);
    }

    public function faq(): JsonResponse
    {
        // Contenu éditorial mis en cache : la FAQ ne change pas à chaque requête.
        $faq = Cache::remember('public.faq', now()->addHour(), fn () => [
            [
                'question' => 'Comment participer à la tombola ?',
                'answer' => 'Créez votre compte, choisissez le nombre de tickets souhaité, payez par Mobile Money, RDV ou carte bancaire. Vos tickets sont générés automatiquement dès que le paiement est confirmé.',
            ],
            [
                'question' => 'Quand vais-je recevoir mes tickets ?',
                'answer' => 'Immédiatement après confirmation du paiement par la passerelle. Ils sont visibles dans « Mes tickets » avec leur QR code.',
            ],
            [
                'question' => 'Le tirage est-il vérifiable ?',
                'answer' => 'Oui. Avant le tirage, nous publions l’empreinte du pool de tickets et l’engagement cryptographique du serveur. Après le tirage, la graine du serveur et la graine publique sont révélées : chacun peut recalculer le résultat et vérifier qu’il n’a pas été modifié.',
            ],
            [
                'question' => 'Que se passe-t-il si mon paiement échoue ?',
                'answer' => 'Aucun ticket n’est attribué tant que le paiement n’est pas confirmé. Vous pouvez relancer un paiement depuis « Mes commandes ».',
            ],
            [
                'question' => 'Comment récupérer mon lot si je gagne ?',
                'answer' => 'Notre équipe vous contacte avec le numéro associé à votre compte. Une vérification d’identité est effectuée avant la remise du lot, et la remise est documentée.',
            ],
            [
                'question' => 'Puis-je me faire rembourser ?',
                'answer' => 'Les demandes de remboursement sont étudiées au cas par cas, conformément aux conditions générales et à la réglementation applicable.',
            ],
        ]);

        return response()->json(['data' => $faq]);
    }

    private function databaseHealthy(): bool
    {
        try {
            DB::select('SELECT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
