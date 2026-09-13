<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\CampaignStatus;
use App\Enums\DrawStatus;
use App\Enums\TicketStatus;
use App\Enums\WinnerStatus;
use App\Exceptions\DrawException;
use App\Models\Campaign;
use App\Models\Draw;
use App\Models\DrawEntry;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Winner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tirage au sort transparent et vérifiable (cahier des charges §20–§22).
 *
 * Schéma commit-reveal mis en œuvre :
 *  1. à la création du tirage, le serveur tire `server_seed` et publie immédiatement
 *     son engagement `server_seed_hash = SHA-256(server_seed)` ;
 *  2. les ventes sont fermées, puis un snapshot immuable des tickets éligibles est
 *     figé (table draw_entries, protégée en écriture par trigger) et empreinté
 *     (`ticket_pool_hash`) ;
 *  3. le tirage combine un aléa public `client_seed` (horodatage vérifiable, hash
 *     de bloc, média…) et le `server_seed` : index = HMAC-SHA256(server_seed, client_seed:k:c) mod N
 *     avec échantillonnage par rejet pour éviter tout biais de modulo ;
 *  4. le `server_seed` est révélé après exécution : n'importe qui peut recalculer
 *     le résultat et vérifier que le hash publié correspond à l'engagement initial.
 */
class DrawService
{
    /** 2^60 : borne des tirages aléatoires (15 caractères hexadécimaux). */
    private const RANDOM_CEILING = 1152921504606846976;

    public function __construct(
        private readonly AuditService $audit,
        private readonly NotificationService $notifications,
        private readonly SecurityEventService $security,
    ) {
    }

    /** Ouvre un tirage et publie l'engagement cryptographique. */
    public function create(Campaign $campaign, ?User $actor = null): Draw
    {
        return DB::transaction(function () use ($campaign, $actor) {
            $existing = Draw::query()
                ->where('campaign_id', $campaign->id)
                ->whereNotIn('status', [DrawStatus::Cancelled->value])
                ->exists();

            if ($existing) {
                throw new DrawException('Un tirage est déjà en cours pour cette campagne.');
            }

            $sequence = (int) DB::selectOne("SELECT nextval('draws_reference_seq') AS n")->n;
            $serverSeed = bin2hex(random_bytes(32));

            $draw = Draw::query()->create([
                'reference' => sprintf('DRAW-%d-%03d', (int) now()->format('Y'), $sequence),
                'campaign_id' => $campaign->id,
                'status' => DrawStatus::Pending,
                'algorithm' => 'HMAC-SHA256(server_seed, client_seed:rank:counter) mod N (rejection sampling)',
                'server_seed' => $serverSeed,
                'server_seed_hash' => hash('sha256', $serverSeed),
                'executed_by' => $actor?->id,
            ]);

            $this->audit->log(AuditAction::DrawCreated, $draw, [], [
                'reference' => $draw->reference,
                'commitment' => $draw->server_seed_hash,
            ], $actor);

            return $draw;
        });
    }

    /** Ferme les ventes de la campagne associée. */
    public function closeSales(Draw $draw, ?User $actor = null): Draw
    {
        return DB::transaction(function () use ($draw, $actor) {
            /** @var Draw $locked */
            $locked = Draw::query()->whereKey($draw->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isFrozen()) {
                throw new DrawException('Ce tirage a déjà été exécuté.');
            }

            $campaign = Campaign::query()->whereKey($locked->campaign_id)->lockForUpdate()->firstOrFail();
            $campaign->forceFill(['status' => CampaignStatus::Closed])->save();

            $locked->forceFill(['status' => DrawStatus::Closed, 'closed_at' => now()])->save();

            $this->audit->log(AuditAction::DrawClosed, $locked, [], ['closed_at' => now()->toIso8601String()], $actor);

            return $locked->refresh();
        });
    }

    /**
     * Fige le pool des tickets éligibles. Opération définitive : le snapshot
     * devient immuable (trigger PostgreSQL) et son empreinte est enregistrée.
     */
    public function snapshot(Draw $draw, ?User $actor = null): Draw
    {
        return DB::transaction(function () use ($draw, $actor) {
            /** @var Draw $locked */
            $locked = Draw::query()->whereKey($draw->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isFrozen()) {
                throw new DrawException('Ce tirage a déjà été exécuté : le pool ne peut plus être modifié.');
            }

            if ((int) $locked->pool_size > 0 || DrawEntry::query()->where('draw_id', $locked->id)->exists()) {
                throw new DrawException('Le pool de ce tirage est déjà figé.');
            }

            $tickets = Ticket::query()
                ->eligibleForDraw()
                ->where('campaign_id', $locked->campaign_id)
                ->orderBy('serial')
                ->get(['id', 'ticket_number', 'user_id', 'serial']);

            if ($tickets->isEmpty()) {
                throw new DrawException('Aucun ticket éligible : le tirage ne peut pas être figé.');
            }

            $now = now();
            $rows = [];
            $hashes = [];

            foreach ($tickets as $position => $ticket) {
                $hash = hash('sha256', $ticket->hashPayload());
                $hashes[] = $hash;
                $rows[] = [
                    'draw_id' => $locked->id,
                    'ticket_id' => $ticket->id,
                    'position' => $position,
                    'ticket_number' => $ticket->ticket_number,
                    'ticket_hash' => $hash,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            // Insertion par lots pour absorber de gros volumes (100 000+ tickets).
            foreach (array_chunk($rows, 1000) as $chunk) {
                DrawEntry::query()->insert($chunk);
            }

            $locked->forceFill([
                'status' => DrawStatus::Snapshot,
                'pool_size' => count($rows),
                'ticket_pool_hash' => hash('sha256', implode('', $hashes)),
            ])->save();

            $this->audit->log(AuditAction::DrawSnapshotCreated, $locked, [], [
                'pool_size' => $locked->pool_size,
                'ticket_pool_hash' => $locked->ticket_pool_hash,
            ], $actor);

            return $locked->refresh();
        });
    }

    /**
     * Exécute le tirage et désigne les gagnants.
     *
     * @param  string  $clientSeed  aléa public, publié avec le résultat
     */
    public function execute(Draw $draw, string $clientSeed, ?User $actor = null): Draw
    {
        $clientSeed = trim($clientSeed);

        if (mb_strlen($clientSeed) < 8) {
            throw new DrawException('La graine publique (client_seed) doit contenir au moins 8 caractères.');
        }

        return DB::transaction(function () use ($draw, $clientSeed, $actor) {
            /** @var Draw $locked */
            $locked = Draw::query()->whereKey($draw->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isFrozen()) {
                throw new DrawException('Ce tirage a déjà été exécuté.');
            }

            if ((int) $locked->pool_size < 1) {
                throw new DrawException('Le pool doit être figé avant d’exécuter le tirage.');
            }

            $campaign = Campaign::query()->whereKey($locked->campaign_id)->lockForUpdate()->firstOrFail();

            $prizes = $campaign->prizes()
                ->where('status', '!=', 'withdrawn')
                ->orderByDesc('is_main')
                ->orderBy('position')
                ->get();

            if ($prizes->isEmpty()) {
                throw new DrawException('Aucun lot publié pour cette campagne.');
            }

            $needed = (int) $prizes->sum('quantity');
            $poolSize = (int) $locked->pool_size;

            if ($needed > $poolSize) {
                throw new DrawException('Le nombre de lots dépasse le nombre de tickets éligibles.');
            }

            $serverSeed = (string) $locked->server_seed;
            $positions = $this->selectPositions($serverSeed, $clientSeed, $poolSize, $needed);

            $entries = DrawEntry::query()
                ->where('draw_id', $locked->id)
                ->whereIn('position', $positions)
                ->get()
                ->keyBy('position');

            $randomValue = hash_hmac('sha256', $serverSeed, $clientSeed.':0:0');
            $rank = 0;
            $createdWinners = [];

            foreach ($prizes as $prize) {
                for ($i = 0; $i < (int) $prize->quantity; $i++) {
                    $position = $positions[$rank];
                    /** @var DrawEntry|null $entry */
                    $entry = $entries->get($position);

                    if (! $entry) {
                        throw new DrawException('Incohérence du snapshot : entrée manquante.');
                    }

                    $rank++;

                    $winner = Winner::query()->create([
                        'draw_id' => $locked->id,
                        'prize_id' => $prize->id,
                        'ticket_id' => $entry->ticket_id,
                        'user_id' => Ticket::query()->whereKey($entry->ticket_id)->value('user_id'),
                        'rank' => $rank,
                        'status' => WinnerStatus::Pending,
                    ]);

                    Ticket::query()->whereKey($entry->ticket_id)->update(['status' => TicketStatus::Winner->value]);
                    $prize->increment('quantity_awarded');

                    $createdWinners[] = $winner;
                }
            }

            $locked->forceFill([
                'status' => DrawStatus::Executed,
                'client_seed' => $clientSeed,
                'random_value' => $randomValue,
                'winning_position' => $positions[0],
                'executed_at' => now(),
                'executed_by' => $actor?->id ?? $locked->executed_by,
                'evidence' => [
                    'algorithm' => $locked->algorithm,
                    'pool_size' => $poolSize,
                    'ticket_pool_hash' => $locked->ticket_pool_hash,
                    'server_seed_hash' => $locked->server_seed_hash,
                    'server_seed' => $serverSeed,
                    'client_seed' => $clientSeed,
                    'random_value' => $randomValue,
                    'winning_positions' => $positions,
                    'executed_at' => now()->toIso8601String(),
                ],
            ])->save();

            $this->audit->log(AuditAction::DrawExecuted, $locked, [], [
                'reference' => $locked->reference,
                'pool_size' => $poolSize,
                'random_value' => $randomValue,
                'winners' => $createdWinners === [] ? [] : array_map(
                    fn (Winner $w) => ['rank' => $w->rank, 'ticket_id' => $w->ticket_id],
                    $createdWinners
                ),
            ], $actor);

            return $locked->refresh();
        });
    }

    /** Publie le résultat et notifie les gagnants. */
    public function publish(Draw $draw, ?User $actor = null): Draw
    {
        $published = DB::transaction(function () use ($draw, $actor) {
            /** @var Draw $locked */
            $locked = Draw::query()->whereKey($draw->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== DrawStatus::Executed && $locked->status !== DrawStatus::Published) {
                throw new DrawException('Seul un tirage exécuté peut être publié.');
            }

            Winner::query()->where('draw_id', $locked->id)->update(['is_published' => true]);

            Campaign::query()->whereKey($locked->campaign_id)->update(['status' => CampaignStatus::Drawn->value]);

            $locked->forceFill([
                'status' => DrawStatus::Published,
                'published_at' => $locked->published_at ?? now(),
            ])->save();

            $this->audit->log(AuditAction::DrawPublished, $locked, [], [
                'reference' => $locked->reference,
                'published_at' => now()->toIso8601String(),
            ], $actor);

            return $locked->refresh();
        });

        foreach ($published->winners()->with(['user', 'prize', 'ticket'])->get() as $winner) {
            if ($winner->status === WinnerStatus::Pending) {
                $winner->forceFill(['status' => WinnerStatus::Contacted, 'notified_at' => now()])->save();
                $this->notifications->winnerNotified($winner);
            }
        }

        return $published;
    }

    /**
     * Vérification indépendante du tirage : recalcule l'engagement et l'ordre des
     * gagnants à partir des preuves publiées. Un tiers peut rejouer ce calcul.
     *
     * @return array{commitment_valid: bool, pool_hash_valid: bool, winners_valid: bool, details: array<string, mixed>}
     */
    public function verify(Draw $draw): array
    {
        $entries = DrawEntry::query()
            ->where('draw_id', $draw->id)
            ->orderBy('position')
            ->get(['position', 'ticket_id', 'ticket_hash']);

        $poolHash = hash('sha256', $entries->pluck('ticket_hash')->implode(''));
        $commitmentValid = $draw->server_seed !== null
            && hash_equals((string) $draw->server_seed_hash, hash('sha256', (string) $draw->server_seed));

        $winnersValid = true;
        $expectedOrder = null;

        if ($commitmentValid && $draw->client_seed !== null && $entries->isNotEmpty()) {
            $expectedOrder = $this->selectPositions(
                (string) $draw->server_seed,
                (string) $draw->client_seed,
                (int) $draw->pool_size,
                $draw->winners()->count()
            );

            $actualWinners = $draw->winners()->orderBy('rank')->get(['ticket_id']);
            $expectedTickets = $entries->whereIn('position', $expectedOrder)->keyBy('position');

            foreach ($actualWinners as $index => $winner) {
                $expectedTicket = $expectedTickets->get($expectedOrder[$index])?->ticket_id;
                if ($expectedTicket !== $winner->ticket_id) {
                    $winnersValid = false;
                    break;
                }
            }
        }

        return [
            'commitment_valid' => $commitmentValid,
            'pool_hash_valid' => $poolHash === $draw->ticket_pool_hash,
            'winners_valid' => $winnersValid,
            'details' => [
                'algorithm' => $draw->algorithm,
                'pool_size' => $draw->pool_size,
                'computed_pool_hash' => $poolHash,
                'published_pool_hash' => $draw->ticket_pool_hash,
                'server_seed_hash' => $draw->server_seed_hash,
                'client_seed' => $draw->client_seed,
                'random_value' => $draw->random_value,
                'winning_positions' => $expectedOrder,
            ],
        ];
    }

    // ------------------------------------------------------------------ tirage

    /**
     * Sélectionne `count` positions distinctes dans [0, poolSize) de façon
     * déterministe et reproductible à partir des deux graines.
     *
     * @return list<int>
     */
    public function selectPositions(string $serverSeed, string $clientSeed, int $poolSize, int $count): array
    {
        $remaining = range(0, $poolSize - 1);
        $selected = [];

        for ($rank = 0; $rank < $count; $rank++) {
            $index = $this->randomIndex($serverSeed, $clientSeed, $rank, count($remaining));
            $selected[] = $remaining[$index];
            array_splice($remaining, $index, 1);
        }

        return $selected;
    }

    /**
     * Index uniforme dans [0, n) : échantillonnage par rejet pour éliminer le biais
     * de modulo, avec compteur de reprise publié dans la preuve.
     */
    private function randomIndex(string $serverSeed, string $clientSeed, int $rank, int $n): int
    {
        if ($n <= 1) {
            return 0;
        }

        $limit = intdiv(self::RANDOM_CEILING, $n) * $n;

        for ($counter = 0; $counter < 1000; $counter++) {
            $hex = hash_hmac('sha256', $serverSeed, $clientSeed.':'.$rank.':'.$counter);
            $value = hexdec(substr($hex, 0, 15));

            if ($value < $limit) {
                return (int) ($value % $n);
            }
        }

        return (int) (hexdec(substr(hash_hmac('sha256', $serverSeed, $clientSeed.':'.$rank.':0'), 0, 15)) % $n);
    }
}
