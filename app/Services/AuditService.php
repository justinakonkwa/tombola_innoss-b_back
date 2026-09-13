<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

/**
 * Journal d'audit chaîné (cahier des charges §17).
 *
 * Chaque entrée contient le SHA-256 de l'entrée précédente : toute altération
 * ou suppression rompt la chaîne et devient détectable. La table est de toute
 * façon protégée par un trigger PostgreSQL (append-only).
 */
class AuditService
{
    public function log(
        AuditAction|string $action,
        ?Model $resource = null,
        array $oldValues = [],
        array $newValues = [],
        ?User $actor = null,
        array $context = [],
    ): AuditLog {
        $actionValue = $action instanceof AuditAction ? $action->value : $action;
        $actor ??= auth()->user();

        $payload = [
            'actor_id' => $actor?->id,
            'actor_email' => $actor?->email ?? ($context['actor_email'] ?? null),
            'actor_role' => $actor?->relationLoaded('roles') ? $actor->roles->pluck('name')->implode(',') : null,
            'action' => $actionValue,
            'resource_type' => $resource ? class_basename($resource) : ($context['resource_type'] ?? null),
            'resource_id' => $resource?->getKey() ?? ($context['resource_id'] ?? null),
            'old_values' => $oldValues ?: null,
            'new_values' => $newValues ?: null,
            'ip' => $context['ip'] ?? Request::ip(),
            'user_agent' => mb_substr((string) ($context['user_agent'] ?? Request::userAgent()), 0, 255),
            'created_at' => now(),
        ];

        // Chaînage : on relit la dernière empreinte sous verrou pour éviter
        // deux entrées concurrentes partageant le même previous_hash.
        return DB::transaction(function () use ($payload) {
            $previous = AuditLog::query()->orderByDesc('id')->lockForUpdate()->first();
            $payload['previous_hash'] = $previous?->hash;
            $payload['hash'] = $this->fingerprint($payload);

            return AuditLog::query()->create($payload);
        });
    }

    /**
     * Empreinte d'une entrée d'audit.
     *
     * Le JSON est canonicalisé (clés triées récursivement) : PostgreSQL stocke
     * ces colonnes en `jsonb`, qui **ne préserve pas l'ordre des clés**. Hacher
     * un `json_encode` brut produirait donc une empreinte différente à la
     * relecture et casserait la vérification de la chaîne.
     *
     * @param  array<string, mixed>  $payload
     */
    private function fingerprint(array $payload): string
    {
        $createdAt = $payload['created_at'] ?? null;

        return hash('sha256', implode('|', [
            $payload['previous_hash'] ?? 'genesis',
            (string) ($payload['action'] ?? ''),
            (string) ($payload['actor_id'] ?? ''),
            (string) ($payload['resource_type'] ?? ''),
            (string) ($payload['resource_id'] ?? ''),
            $this->canonicalJson($payload['old_values'] ?? null),
            $this->canonicalJson($payload['new_values'] ?? null),
            $createdAt instanceof \DateTimeInterface
                ? $createdAt->format('Y-m-d\TH:i:sP')
                : (string) $createdAt,
        ]));
    }

    /** Sérialisation JSON stable, indépendante de l'ordre des clés. */
    private function canonicalJson(mixed $value): string
    {
        if ($value === null || $value === []) {
            return 'null';
        }

        if (! is_array($value)) {
            $value = [$value];
        }

        $encoded = json_encode(
            $this->sortKeysRecursively($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );

        return $encoded === false ? 'null' : $encoded;
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private function sortKeysRecursively(array $value): array
    {
        // Une liste (clés 0..n-1) conserve son ordre : il est significatif.
        if (array_is_list($value)) {
            return array_map(
                fn ($item) => is_array($item) ? $this->sortKeysRecursively($item) : $item,
                $value
            );
        }

        ksort($value);

        return array_map(
            fn ($item) => is_array($item) ? $this->sortKeysRecursively($item) : $item,
            $value
        );
    }

    /**
     * Vérifie l'intégrité de la chaîne d'audit.
     *
     * @return array{valid: bool, checked: int, broken_at: int|null}
     */
    public function verifyChain(): array
    {
        $previousHash = null;
        $checked = 0;
        $valid = true;
        $brokenAt = null;

        AuditLog::query()->orderBy('id')->chunk(500, function ($logs) use (&$previousHash, &$checked, &$valid, &$brokenAt) {
            foreach ($logs as $log) {
                $expected = $this->fingerprint([
                    'previous_hash' => $log->previous_hash,
                    'action' => $log->action,
                    'actor_id' => $log->actor_id,
                    'resource_type' => $log->resource_type,
                    'resource_id' => $log->resource_id,
                    'old_values' => $log->old_values,
                    'new_values' => $log->new_values,
                    'created_at' => $log->created_at,
                ]);

                // Rupture si le maillon ne pointe pas sur l'empreinte précédente
                // ou si le contenu ne correspond plus à son empreinte.
                if ($log->previous_hash !== $previousHash || ! hash_equals($expected, (string) $log->hash)) {
                    $valid = false;
                    $brokenAt = (int) $log->id;

                    return false;
                }

                $previousHash = $log->hash;
                $checked++;
            }

            return true;
        });

        return ['valid' => $valid, 'checked' => $checked, 'broken_at' => $brokenAt];
    }
}
