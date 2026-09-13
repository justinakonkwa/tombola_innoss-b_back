<?php

namespace App\Services;

use App\Exceptions\PaymentProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Client de la passerelle de paiement Futaye (https://futaye.buania.com).
 *
 * Authentification (documentation Futaye) : trois en-têtes sur chaque appel /v1/*
 *   X-Futaye-Client-Id : code application
 *   X-Futaye-Timestamp : epoch secondes (± 5 minutes)
 *   X-Futaye-Signature : HMAC-SHA256(token, timestamp + "." + METHOD + "." + path + "." + body) en hexadécimal
 *
 * Le token HMAC ne quitte jamais le serveur : il n'est jamais exposé au frontend.
 */
class FutayeClient
{
    private string $baseUrl;

    private string $clientId;

    private string $token;

    private int $timeout;

    public function __construct(?string $baseUrl = null, ?string $clientId = null, ?string $token = null, ?int $timeout = null)
    {
        $this->baseUrl = rtrim($baseUrl ?? (string) config('services.futaye.base_url'), '/');
        $this->clientId = (string) ($clientId ?? config('services.futaye.client_id'));
        $this->token = (string) ($token ?? config('services.futaye.token'));
        $this->timeout = $timeout ?? (int) config('services.futaye.timeout', 20);
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->clientId !== '' && $this->token !== '';
    }

    /**
     * Crée une session de paiement.
     *
     * @param  array{amount: string|float, currency: string, description?: string, returnUrl?: string, phone?: string, channel?: string}  $payload
     * @return array<string, mixed>
     */
    public function createPayment(array $payload): array
    {
        return $this->request('POST', '/v1/payments', $payload);
    }

    /** @return array<string, mixed> */
    public function getPayment(string $publicId): array
    {
        return $this->request('GET', '/v1/payments/'.rawurlencode($publicId));
    }

    /** @return array<string, mixed> */
    public function listPayments(int $page = 0, int $size = 20): array
    {
        return $this->request('GET', '/v1/payments', null, ['page' => $page, 'size' => $size]);
    }

    /** @return array<string, mixed> */
    public function balance(): array
    {
        return $this->request('GET', '/v1/balance');
    }

    /** @return array<string, mixed> */
    public function payouts(int $page = 0, int $size = 20): array
    {
        return $this->request('GET', '/v1/payouts', null, ['page' => $page, 'size' => $size]);
    }

    /**
     * Génère un lien de paiement partageable (WhatsApp, caisse, QR).
     *
     * @param  array{title: string, currency: string, amount: string|float, maxUses?: int}  $payload
     * @return array<string, mixed>
     */
    public function createPaymentLink(array $payload): array
    {
        return $this->request('POST', '/v1/payment-links', $payload);
    }

    /**
     * Signature d'un corps sortant.
     */
    public function signature(int $timestamp, string $method, string $path, string $body): string
    {
        return hash_hmac('sha256', $timestamp.'.'.strtoupper($method).'.'.$path.'.'.$body, $this->token);
    }

    /**
     * Vérifie la signature d'un webhook entrant.
     * Futaye signe : HMAC-SHA256(token, timestamp + "." + body).
     */
    public function verifyWebhookSignature(string $rawBody, string $timestamp, string $signature, int $toleranceSeconds = 300): bool
    {
        if ($this->token === '' || $timestamp === '' || $signature === '') {
            return false;
        }

        if (! ctype_digit($timestamp)) {
            return false;
        }

        // Protection contre le rejeu : horodatage dans la fenêtre de tolérance.
        if (abs(time() - (int) $timestamp) > $toleranceSeconds) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $this->token);

        return hash_equals($expected, strtolower($signature));
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $payload = null, array $query = []): array
    {
        if (! $this->isConfigured()) {
            throw new PaymentProviderException('Passerelle Futaye non configurée (client id ou token manquant).');
        }

        $body = $payload === null ? '' : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = time();
        $signature = $this->signature($timestamp, $method, $path, $body);

        $url = $this->baseUrl.$path.($query !== [] ? '?'.http_build_query($query) : '');

        try {
            $response = Http::withHeaders([
                'X-Futaye-Client-Id' => $this->clientId,
                'X-Futaye-Timestamp' => (string) $timestamp,
                'X-Futaye-Signature' => $signature,
                'Accept' => 'application/json',
            ])
                ->timeout($this->timeout)
                ->withBody($body, 'application/json')
                ->send($method, $url);
        } catch (ConnectionException $e) {
            Log::warning('Futaye: connexion impossible', ['path' => $path, 'error' => $e->getMessage()]);

            throw new PaymentProviderException('Passerelle de paiement injoignable.', 0, $e);
        }

        return $this->normalize($response, $path);
    }

    /** @return array<string, mixed> */
    private function normalize(Response $response, string $path): array
    {
        $json = null;

        try {
            $json = $response->json();
        } catch (\Throwable) {
            $json = null;
        }

        $result = [
            'ok' => $response->successful(),
            'status' => $response->status(),
            'json' => is_array($json) ? $json : null,
            'body' => $response->body(),
        ];

        if (! $response->successful()) {
            Log::warning('Futaye: réponse en erreur', [
                'path' => $path,
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 500),
            ]);
        }

        return $result;
    }
}
