<?php

namespace App\Jobs;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Models\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Livraison d'une notification.
 *
 * Les intégrations SMS / WhatsApp sont branchées via des drivers dédiés ;
 * en l'absence de driver configuré, l'envoi est journalisé et la notification
 * reste traçable dans l'historique utilisateur.
 */
class SendNotificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public string $notificationId)
    {
    }

    public function handle(): void
    {
        /** @var Notification|null $notification */
        $notification = Notification::query()->find($this->notificationId);

        if (! $notification || $notification->status === NotificationStatus::Sent) {
            return;
        }

        $notification->increment('attempts');

        try {
            match ($notification->channel) {
                NotificationChannel::Email => $this->sendEmail($notification),
                default => $this->sendViaBridge($notification),
            };

            $notification->forceFill([
                'status' => NotificationStatus::Sent,
                'sent_at' => now(),
                'error' => null,
            ])->save();
        } catch (\Throwable $e) {
            Log::warning('Échec d’envoi de notification', [
                'notification_id' => $notification->id,
                'channel' => $notification->channel->value,
                'error' => $e->getMessage(),
            ]);

            $notification->forceFill([
                'status' => NotificationStatus::Failed,
                'error' => mb_substr($e->getMessage(), 0, 500),
            ])->save();

            throw $e;
        }
    }

    private function sendEmail(Notification $notification): void
    {
        if (! $notification->destination) {
            return;
        }

        Mail::raw($notification->body ?? '', function ($message) use ($notification) {
            $message->to($notification->destination)
                ->subject($notification->subject ?? 'Tombola Innoss’B');
        });
    }

    /**
     * Point d'extension SMS / WhatsApp : brancher ici le fournisseur retenu
     * (Sendflow, Twilio, Meta Cloud API…). Sans configuration, on journalise.
     */
    private function sendViaBridge(Notification $notification): void
    {
        Log::info('Notification sortante (bridge non configuré)', [
            'channel' => $notification->channel->value,
            'destination' => $notification->destination,
            'template' => $notification->template,
        ]);
    }
}
