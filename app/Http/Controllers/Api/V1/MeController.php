<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Http\Resources\OrderResource;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\TicketResource;
use App\Http\Resources\UserResource;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Ticket;
use App\Models\UserSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Espace personnel du participant (cahier des charges §6, §45).
 *
 * Chaque requête est strictement limitée aux ressources de l'utilisateur
 * authentifié : aucun identifiant fourni par le client n'est utilisé pour
 * décider de la propriété d'une ressource (§15).
 */
class MeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'data' => new UserResource($request->user()->load('roles')),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'first_name' => ['sometimes', 'string', 'min:2', 'max:80'],
            'last_name' => ['sometimes', 'string', 'min:2', 'max:80'],
            'city' => ['sometimes', 'nullable', 'string', 'max:80'],
            'country' => ['sometimes', 'string', 'size:2'],
            'locale' => ['sometimes', 'string', 'in:fr,en'],
        ]);

        $user->fill($data)->save();

        return response()->json([
            'data' => new UserResource($user->fresh('roles')),
            'message' => 'Profil mis à jour.',
        ]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:10', 'max:72', 'confirmed', 'different:current_password'],
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            return response()->json([
                'message' => 'Mot de passe actuel incorrect.',
                'errors' => ['current_password' => ['Mot de passe actuel incorrect.']],
            ], 422);
        }

        $user->forceFill(['password' => $data['password']])->save();

        // Sécurité : tout changement de mot de passe révoque les autres sessions.
        app(\App\Services\AuthService::class)->logoutAll($user);
        $tokens = app(\App\Services\AuthService::class)->issueTokens($user, $request, 'web');

        return response()->json([
            'message' => 'Mot de passe modifié. Vos autres sessions ont été déconnectées.',
            'data' => ['tokens' => $tokens],
        ]);
    }

    /** Tableau de bord : tickets, participations, dépenses, prochains tirages. */
    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();

        $tickets = Ticket::query()->where('user_id', $user->id);

        $stats = [
            'tickets_total' => (clone $tickets)->count(),
            'tickets_valid' => (clone $tickets)->whereIn('status', ['valid', 'winner'])->count(),
            'tickets_pending' => (clone $tickets)->where('status', 'pending')->count(),
            'campaigns_participated' => (clone $tickets)->distinct('campaign_id')->count('campaign_id'),
            'orders_total' => Order::query()->where('user_id', $user->id)->count(),
            'orders_paid' => Order::query()->where('user_id', $user->id)->where('status', 'paid')->count(),
            'amount_spent' => (float) Payment::query()
                ->whereHas('order', fn ($query) => $query->where('user_id', $user->id))
                ->where('status', 'success')
                ->sum('amount'),
            'wins' => \App\Models\Winner::query()->where('user_id', $user->id)->count(),
        ];

        $upcomingDraws = \App\Models\Campaign::query()
            ->whereIn('id', (clone $tickets)->distinct('campaign_id')->pluck('campaign_id'))
            ->whereNotNull('draw_at')
            ->where('draw_at', '>', now())
            ->orderBy('draw_at')
            ->limit(5)
            ->get(['id', 'name', 'slug', 'draw_at', 'status']);

        return response()->json([
            'data' => [
                'stats' => $stats,
                'recent_tickets' => TicketResource::collection(
                    Ticket::query()
                        ->where('user_id', $user->id)
                        ->with(['campaign', 'prize'])
                        ->latest('issued_at')
                        ->limit(5)
                        ->get()
                ),
                'upcoming_draws' => $upcomingDraws->map(fn ($campaign) => [
                    'id' => $campaign->id,
                    'name' => $campaign->name,
                    'slug' => $campaign->slug,
                    'draw_at' => $campaign->draw_at?->toIso8601String(),
                    'status' => $campaign->status->value,
                ]),
            ],
        ]);
    }

    public function tickets(Request $request): JsonResponse
    {
        $tickets = Ticket::query()
            ->where('user_id', $request->user()->id)
            ->with(['campaign', 'prize'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('campaign'), function ($query) use ($request) {
                $query->whereHas('campaign', fn ($q) => $q->where('slug', $request->string('campaign')));
            })
            ->when($request->filled('search'), fn ($query) => $query->where('ticket_number', 'ilike', '%'.$request->string('search').'%'))
            ->orderByDesc('issued_at')
            ->paginate(min((int) $request->integer('per_page', 25), 100));

        return TicketResource::collection($tickets)->response();
    }

    public function orders(Request $request): JsonResponse
    {
        $orders = Order::query()
            ->where('user_id', $request->user()->id)
            ->with(['campaign', 'latestPayment'])
            ->withCount('tickets')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderByDesc('created_at')
            ->paginate(min((int) $request->integer('per_page', 25), 100));

        return OrderResource::collection($orders)->response();
    }

    public function payments(Request $request): JsonResponse
    {
        $payments = Payment::query()
            ->whereHas('order', fn ($query) => $query->where('user_id', $request->user()->id))
            ->with('order')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderByDesc('created_at')
            ->paginate(min((int) $request->integer('per_page', 25), 100));

        return PaymentResource::collection($payments)->response();
    }

    public function notifications(Request $request): JsonResponse
    {
        $notifications = \App\Models\Notification::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->paginate(min((int) $request->integer('per_page', 25), 100));

        return NotificationResource::collection($notifications)->response();
    }

    /** Sessions actives : permet à l'utilisateur de révoquer un appareil (cahier des charges §5). */
    public function sessions(Request $request): JsonResponse
    {
        $sessions = UserSession::query()
            ->where('user_id', $request->user()->id)
            ->with('device')
            ->orderByDesc('last_used_at')
            ->get()
            ->map(fn (UserSession $session) => [
                'id' => $session->id,
                'device' => $session->device?->label,
                'ip' => $session->ip,
                'user_agent' => $session->user_agent,
                'last_used_at' => $session->last_used_at?->toIso8601String(),
                'expires_at' => $session->expires_at->toIso8601String(),
                'is_active' => $session->isActive(),
                'revoked_at' => $session->revoked_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $sessions]);
    }

    public function revokeSession(Request $request, string $session): JsonResponse
    {
        /** @var UserSession|null $model */
        $model = UserSession::query()
            ->where('id', $session)
            ->where('user_id', $request->user()->id) // propriété vérifiée côté serveur
            ->first();

        if (! $model) {
            return response()->json(['message' => 'Session introuvable.'], 404);
        }

        $model->revoke('user_revoked');

        return response()->json(['message' => 'Session révoquée.']);
    }
}
