<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\WebhookStatus;
use App\Http\Controllers\Controller;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Réception des webhooks de la passerelle (cahier des charges §11).
 *
 * Le contrôleur ne contient aucune logique métier : toute la vérification
 * (signature, anti-rejeu, montant, idempotence) est dans PaymentService.
 * Les réponses sont volontairement pauvres pour ne rien divulguer.
 */
class WebhookController extends Controller
{
    public function __construct(private readonly PaymentService $payments)
    {
    }

    public function futaye(Request $request): JsonResponse
    {
        $webhook = $this->payments->handleWebhook($request);

        return match ($webhook->status) {
            // Signature invalide / horodatage hors tolérance : on rejette.
            WebhookStatus::Rejected => response()->json(['message' => 'Signature invalide.'], 401),

            // Rejeu : on répond 200 pour que la passerelle cesse de réessayer.
            WebhookStatus::Duplicate => response()->json(['message' => 'Déjà traité.', 'status' => 'duplicate'], 200),

            WebhookStatus::Ignored => response()->json(['message' => 'Événement ignoré.', 'status' => 'ignored'], 202),

            default => response()->json([
                'message' => 'Webhook traité.',
                'status' => $webhook->status->value,
            ]),
        };
    }
}
