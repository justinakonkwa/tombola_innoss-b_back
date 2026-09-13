<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Statut des paiements côté participant.
 *
 * Point clé : le frontend interroge ce endpoint pour savoir si le paiement est
 * confirmé ; il ne décide jamais lui-même du succès. Si la passerelle confirme
 * un succès non encore reçu par webhook, la réconciliation attribue les tickets.
 */
class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments)
    {
    }

    public function status(Request $request, Payment $payment): JsonResponse
    {
        // Contrôle de propriété : un participant ne voit que ses paiements.
        $isOwner = $payment->order()->where('user_id', $request->user()->id)->exists();

        if (! $isOwner && ! $request->user()->isStaff()) {
            abort(404);
        }

        // Filet de sécurité : si le webhook s'est perdu, on interroge la passerelle.
        if (! $payment->isSettled() && $payment->provider_payment_id && $payment->created_at->gt(now()->subHours(24))) {
            $payment = $this->payments->reconcile($payment);
        }

        return response()->json([
            'data' => new PaymentResource($payment->load('order')),
        ]);
    }
}
