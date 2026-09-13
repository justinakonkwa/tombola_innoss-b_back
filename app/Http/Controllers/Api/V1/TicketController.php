<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\TicketResource;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Consultation et vérification des tickets (cahier des charges §6, §7).
 *
 * Un ticket n'est visible que par son propriétaire. Le contrôle au guichet
 * (rôle support) vérifie la signature du QR code côté serveur.
 */
class TicketController extends Controller
{
    public function show(Request $request, string $ticketNumber): JsonResponse
    {
        $ticket = Ticket::query()
            ->where('ticket_number', $ticketNumber)
            ->where('user_id', $request->user()->id) // propriété vérifiée côté serveur
            ->with(['campaign', 'prize'])
            ->firstOrFail();

        return response()->json(['data' => new TicketResource($ticket)]);
    }

    /**
     * Contrôle d'un QR code présenté au guichet.
     * Route réservée au personnel disposant de `tickets.view`.
     */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ticket_number' => ['required', 'string', 'max:32'],
            'token' => ['required', 'string', 'max:64'],
            'signature' => ['required', 'string', 'size:64'],
            'consume' => ['sometimes', 'boolean'],
        ]);

        if (! Ticket::verifyQrSignature($data['ticket_number'], $data['token'], $data['signature'])) {
            return response()->json([
                'valid' => false,
                'reason' => 'signature_invalid',
                'message' => 'QR code falsifié ou illisible.',
            ], 422);
        }

        /** @var Ticket|null $ticket */
        $ticket = Ticket::query()
            ->where('ticket_number', $data['ticket_number'])
            ->with(['campaign', 'prize', 'user'])
            ->first();

        if (! $ticket || ! hash_equals($ticket->qr_token, $data['token'])) {
            return response()->json([
                'valid' => false,
                'reason' => 'not_found',
                'message' => 'Ticket inconnu.',
            ], 404);
        }

        $valid = $ticket->status === TicketStatus::Valid || $ticket->status === TicketStatus::Winner;

        if (! $valid) {
            return response()->json([
                'valid' => false,
                'reason' => 'status_'.$ticket->status->value,
                'message' => 'Ticket non valide : '.$ticket->status->label().'.',
                'data' => [
                    'ticket_number' => $ticket->ticket_number,
                    'status' => $ticket->status->value,
                ],
            ], 409);
        }

        // Consommation au guichet : le ticket passe en `used` une seule fois.
        if ($request->boolean('consume')) {
            if ($ticket->used_at !== null) {
                return response()->json([
                    'valid' => false,
                    'reason' => 'already_used',
                    'message' => 'Ce ticket a déjà été utilisé le '.$ticket->used_at->format('d/m/Y H:i').'.',
                ], 409);
            }

            // Verrouillage optimiste : garantit un usage unique même en concurrence.
            $updated = Ticket::query()
                ->whereKey($ticket->id)
                ->whereNull('used_at')
                ->whereIn('status', [TicketStatus::Valid->value, TicketStatus::Winner->value])
                ->update(['status' => TicketStatus::Used->value, 'used_at' => now()]);

            if ($updated === 0) {
                return response()->json([
                    'valid' => false,
                    'reason' => 'already_used',
                    'message' => 'Ce ticket vient d’être utilisé.',
                ], 409);
            }

            $ticket->refresh();
        }

        return response()->json([
            'valid' => true,
            'data' => [
                'ticket_number' => $ticket->ticket_number,
                'status' => $ticket->status->value,
                'holder' => $ticket->user?->maskedName(),
                'campaign' => $ticket->campaign?->name,
                'prize' => $ticket->prize?->name,
                'used_at' => $ticket->used_at?->toIso8601String(),
            ],
        ]);
    }
}
