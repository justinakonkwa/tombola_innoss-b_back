<?php

namespace App\Console\Commands;

use App\Services\FutayeClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Diagnostic de l'intégration Futaye : vérifie la configuration, la signature
 * HMAC, la validité de la signature d'un webhook et, sur demande, crée une
 * session de paiement réelle (compte de test).
 */
class FutayeCheck extends Command
{
    protected $signature = 'tombola:futaye-check {--payment : Crée réellement une session de paiement de test}';

    protected $description = 'Diagnostique l’intégration de la passerelle Futaye (config, HMAC, appel API)';

    public function handle(FutayeClient $futaye): int
    {
        $this->info('=== Configuration Futaye ===');
        $this->line('Base URL  : '.config('services.futaye.base_url'));
        $this->line('Client ID : '.(config('services.futaye.client_id') ?: '(non configuré)'));
        $this->line('Token     : '.(config('services.futaye.token') ? 'configuré ('.strlen((string) config('services.futaye.token')).' caractères)' : '(non configuré)'));
        $this->line('Return    : '.config('services.futaye.return_url'));

        if (! $futaye->isConfigured()) {
            $this->error('Configuration incomplète : renseignez FUTAYE_CLIENT_ID et FUTAYE_TOKEN.');

            return self::FAILURE;
        }

        // 1. Signature sortante — reproductible, sert de référence.
        $this->newLine();
        $this->info('=== Signature HMAC sortante ===');
        $timestamp = time();
        $signature = $futaye->signature($timestamp, 'POST', '/v1/payments', '{"amount":"1.00"}');
        $this->line("timestamp={$timestamp}");
        $this->line("signature={$signature}");

        $expected = hash_hmac('sha256', $timestamp.'.POST./v1/payments.{"amount":"1.00"}', (string) config('services.futaye.token'));
        $this->line($signature === $expected ? '✓ signature conforme au calcul de référence' : '✗ signature incohérente');

        // 2. Vérification d'une signature de webhook entrant.
        $this->newLine();
        $this->info('=== Vérification de signature de webhook ===');
        $body = '{"event":"PAYMENT_SUCCESS","amount":100.00,"currency":"USD"}';
        $webhookSignature = hash_hmac('sha256', $timestamp.'.'.$body, (string) config('services.futaye.token'));
        $valid = $futaye->verifyWebhookSignature($body, (string) $timestamp, $webhookSignature);
        $this->line($valid ? '✓ signature valide acceptée' : '✗ signature valide refusée');

        $tampered = $futaye->verifyWebhookSignature($body.' ', (string) $timestamp, $webhookSignature);
        $this->line(! $tampered ? '✓ corps altéré rejeté' : '✗ corps altéré accepté (FAILLE)');

        $oldTimestamp = (string) ($timestamp - 3600);
        $staleSignature = hash_hmac('sha256', $oldTimestamp.'.'.$body, (string) config('services.futaye.token'));
        $stale = $futaye->verifyWebhookSignature($body, $oldTimestamp, $staleSignature);
        $this->line(! $stale ? '✓ horodatage hors fenêtre rejeté (anti-rejeu)' : '✗ horodatage ancien accepté (FAILLE)');

        // 3. Joignabilité de la passerelle.
        $this->newLine();
        $this->info('=== Joignabilité ===');
        try {
            $response = Http::timeout(10)->get(config('services.futaye.base_url').'/login');
            $this->line('HTTP '.$response->status().' sur /login');
        } catch (\Throwable $e) {
            $this->error('Injoignable : '.$e->getMessage());

            return self::FAILURE;
        }

        // 4. Appel authentifié réel.
        if ($this->option('payment')) {
            $this->newLine();
            $this->info('=== Création d’une session de paiement de test ===');

            try {
                $result = $futaye->createPayment([
                    'amount' => '1.00',
                    'currency' => 'USD',
                    'description' => 'Test d’intégration Tombola Innoss’B',
                    'returnUrl' => config('services.futaye.return_url'),
                ]);

                $this->line('HTTP '.$result['status']);
                $this->line(json_encode($result['json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: $result['body']);

                return $result['ok'] ? self::SUCCESS : self::FAILURE;
            } catch (\Throwable $e) {
                $this->error('Échec : '.$e->getMessage());

                return self::FAILURE;
            }
        }

        $this->newLine();
        $this->info('Diagnostic terminé. Utilisez --payment pour tester un vrai appel /v1/payments.');

        return self::SUCCESS;
    }
}
