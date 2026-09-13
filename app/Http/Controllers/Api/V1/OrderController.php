<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Campaign;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Achat de tickets (cahier des charges §8).
 *
 * Parcours serveur : création de commande → calcul du montant côté serveur →
 * ouverture d'une session de paiement → confirmation par webhook → tickets.
 */
class OrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly PaymentService $payments,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'campaign' => ['required', 'string', 'max:120'],   // slug public
            'quantity' => ['required', 'integer', 'min:1', 'max:1000'],
        ], [
            'quantity.max' => 'Le nombre de tickets demandé est trop élevé.',
        ]);

        $campaign = Campaign::query()->where('slug', $data['campaign'])->firstOrFail();

        // Clé d'idempotence : rejouer la requête ne crée pas de seconde commande.
        $idempotencyKey = $request->header('Idempotency-Key')
            ?? $request->input('idempotency_key')
            ?? (string) Str::uuid();

        $order = $this->orders->create($request->user(), $campaign, (int) $data['quantity'], [
            'idempotency_key' => mb_substr((string) $idempotencyKey, 0, 64),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'data' => new OrderResource($order->load('campaign')),
        ], 201);
    }

    public function show(Request $request, string $reference): JsonResponse
    {
        $order = $this->findOwnOrder($request, $reference);

        return response()->json([
            'data' => new OrderResource($order->load(['campaign', 'latestPayment'])),
        ]);
    }

    public function cancel(Request $request, string $reference): JsonResponse
    {
        $order = $this->findOwnOrder($request, $reference);

        $order = $this->orders->cancel($order, 'user_cancelled');

        return response()->json([
            'data' => new OrderResource($order->load('campaign')),
            'message' => 'Commande annulée.',
        ]);
    }

    /**
     * Ouvre une session de paiement auprès de la passerelle.
     * Le montant transmis provient de la commande, jamais de la requête cliente.
     */
    public function pay(Request $request, string $reference): JsonResponse
    {
        $data = $request->validate([
            'channel' => ['required', 'string', 'in:mobile_money,card,rdv'],
            'phone' => ['required_if:channel,mobile_money', 'nullable', 'string', 'min:9', 'max:20'],
            'operator' => ['nullable', 'string', 'in:airtel,mpesa,orange,africell'],
            'return_url' => ['nullable', 'url', 'max:255'],
        ]);

        $order = $this->findOwnOrder($request, $reference);

        $payment = $this->payments->initiate($order, [
            'channel' => $data['channel'],
            'phone' => $data['phone'] ?? null,
            'operator' => $data['operator'] ?? null,
            'return_url' => $data['return_url'] ?? null,
        ]);

        return response()->json([
            'data' => [
                'payment_id' => $payment->id,
                'status' => $payment->status->value,
                'checkout_url' => $payment->checkout_url,
                'amount' => (float) $payment->amount,
                'currency' => $payment->currency,
                'channel' => $payment->channel->value,
            ],
            'message' => 'Session de paiement ouverte.',
        ], 201);
    }

    /** La commande est recherchée par référence ET par propriétaire. */
    private function findOwnOrder(Request $request, string $reference): Order
    {
        return Order::query()
            ->where('reference', $reference)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();
    }
}
