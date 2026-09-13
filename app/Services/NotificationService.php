<?php

namespace App\Services;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\TicketBatch;
use App\Models\User;
use App\Models\Winner;
use Illuminate\Support\Facades\Log;

/**
 * Notifications transactionnelles (cahier des charges §24).
 *
 * Chaque envoi est journalisé en base avant expédition : l'historique reste
 * consultable dans l'espace utilisateur et le back-office, et un échec
 * d'expédition n'interrompt jamais le parcours d'achat.
 */
class NotificationService
{
    /** Canaux actifs par défaut, pilotés par le paramètre settings.notifications.channels. */
    private function defaultChannels(): array
    {
        $configured = config('tombola.notifications.channels', ['email']);

        return array_values(array_filter(array_map(
            fn (string $channel) => NotificationChannel::tryFrom($channel),
            $configured
        )));
    }

    /**
     * @param  list<NotificationChannel|string>|null  $channels
     */
    public function send(User $user, string $template, array $data = [], ?array $channels = null, ?string $subject = null, ?string $body = null): void
    {
        $channels = $channels ?? $this->defaultChannels();

        foreach ($channels as $channel) {
            $channel = $channel instanceof NotificationChannel ? $channel : NotificationChannel::from($channel);

            $notification = Notification::query()->create([
                'user_id' => $user->id,
                'channel' => $channel,
                'template' => $template,
                'subject' => $subject ?? $this->subjectFor($template),
                'body' => $body ?? $this->render($template, $data),
                'destination' => $channel === NotificationChannel::Email ? $user->email : $user->phone,
                'status' => NotificationStatus::Queued,
                'payload' => $data ?: null,
            ]);

            try {
                SendNotificationJob::dispatch($notification->id);
            } catch (\Throwable $e) {
                // Une file indisponible ne doit jamais casser un parcours métier.
                Log::warning('Notification non mise en file', [
                    'notification_id' => $notification->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function accountCreated(User $user): void
    {
        $this->send($user, 'account_created', ['name' => $user->first_name]);
    }

    public function paymentConfirmed(Payment $payment, TicketBatch $batch): void
    {
        $user = $payment->order?->user;
        if (! $user) {
            return;
        }

        $numbers = $batch->numbers ?? [];

        $this->send($user, 'payment_confirmed', [
            'name' => $user->first_name,
            'amount' => (string) $payment->amount,
            'currency' => $payment->currency,
            'quantity' => count($numbers),
            'order' => $payment->order?->reference,
        ]);

        $this->send($user, 'tickets_issued', [
            'name' => $user->first_name,
            'quantity' => count($numbers),
            'first' => $numbers[0] ?? null,
            'last' => $numbers[count($numbers) - 1] ?? null,
        ], null, 'Vos tickets Tombola Innoss’B');
    }

    public function winnerNotified(Winner $winner): void
    {
        $user = $winner->user;
        if (! $user) {
            return;
        }

        $this->send($user, 'winner', [
            'name' => $user->first_name,
            'prize' => $winner->prize?->name,
            'ticket' => $winner->ticket?->ticket_number,
        ], null, 'Vous avez gagné !');
    }

    public function drawPublished(User $user, array $data): void
    {
        $this->send($user, 'draw_published', $data);
    }

    private function subjectFor(string $template): string
    {
        return match ($template) {
            'account_created' => 'Bienvenue sur la Tombola Innoss’B',
            'payment_confirmed' => 'Paiement confirmé',
            'tickets_issued' => 'Vos tickets sont disponibles',
            'winner' => 'Vous avez gagné !',
            'draw_published' => 'Résultats du tirage',
            default => 'Tombola Innoss’B',
        };
    }

    /**
     * Rendu simple et sûr : les valeurs sont échappées, aucun template utilisateur.
     */
    private function render(string $template, array $data): string
    {
        $lines = match ($template) {
            'account_created' => [
                "Bonjour {$data['name']},",
                'Votre compte Tombola Innoss’B est créé. Bonne chance !',
            ],
            'payment_confirmed' => [
                "Bonjour {$data['name']},",
                "Nous avons bien reçu votre paiement de {$data['amount']} {$data['currency']} (commande {$data['order']}).",
            ],
            'tickets_issued' => [
                "Bonjour {$data['name']},",
                "{$data['quantity']} ticket(s) ont été générés pour votre compte.",
                'Premier numéro : '.($data['first'] ?? '—'),
                'Dernier numéro : '.($data['last'] ?? '—'),
            ],
            'winner' => [
                "Félicitations {$data['name']} !",
                "Votre ticket {$data['ticket']} a été tiré au sort pour le lot : {$data['prize']}.",
                'Notre équipe vous contactera pour la remise du lot.',
            ],
            default => ['Tombola Innoss’B'],
        };

        return implode("\n", array_map('strip_tags', $lines));
    }
}
