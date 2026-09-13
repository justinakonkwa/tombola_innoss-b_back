<?php

namespace Tests\Feature;

use App\Enums\DrawStatus;
use App\Enums\TicketStatus;
use App\Models\Campaign;
use App\Models\Draw;
use App\Models\DrawEntry;
use App\Models\Prize;
use App\Models\User;
use App\Models\Winner;
use App\Services\DrawService;
use Database\Factories\UserFactory;
use Database\Factories\CampaignFactory;
use Database\Factories\TicketFactory;
use Database\Factories\PrizeFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tirage au sort vérifiable et figé (cahier des charges §20–§22).
 *
 * Preuves vérifiées ici :
 *  - le snapshot fige exactement le pool éligible et son empreinte SHA-256 ;
 *  - l'exécution désigne les gagnants et `verify()` confirme les trois preuves ;
 *  - le trigger PostgreSQL interdit toute altération après exécution ;
 *  - le tirage est déterministe : mêmes graines ⇒ mêmes positions gagnantes.
 */
class DrawTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT_SEED = 'audit-public-2026-09-13-kin';

    private DrawService $draws;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->draws = app(DrawService::class);
        $this->admin = UserFactory::new()->admin()->create();
    }

    private function campaignWithTickets(int $ticketCount): Campaign
    {
        /** @var Campaign $campaign */
        $campaign = CampaignFactory::new()->create();

        TicketFactory::new()->count($ticketCount)->create([
            'campaign_id' => $campaign->id,
            'status' => TicketStatus::Valid,
            'is_locked' => true,
        ]);

        return $campaign;
    }

    private function publishPrize(Campaign $campaign, int $quantity, bool $isMain = true, int $position = 0): Prize
    {
        return PrizeFactory::new()
            ->forCampaign($campaign->id)
            ->create([
                'quantity' => $quantity,
                'is_main' => $isMain,
                'position' => $position,
            ]);
    }

    private function snapshotDraw(Campaign $campaign): Draw
    {
        $draw = $this->draws->create($campaign, $this->admin);

        return $this->draws->snapshot($draw, $this->admin);
    }

    private function executedDraw(Campaign $campaign, int $winnerCount = 1): Draw
    {
        $this->publishPrize($campaign, $winnerCount);

        $draw = $this->snapshotDraw($campaign);

        return $this->draws->execute($draw, self::CLIENT_SEED, $this->admin);
    }

    public function test_snapshot_freezes_the_eligible_ticket_pool(): void
    {
        $campaign = $this->campaignWithTickets(6);

        $draw = $this->snapshotDraw($campaign);

        $this->assertSame(DrawStatus::Snapshot, $draw->status);
        $this->assertSame(6, (int) $draw->pool_size);
        $this->assertSame(6, DrawEntry::query()->where('draw_id', $draw->id)->count());
        $this->assertSame($draw->pool_size, DrawEntry::query()->where('draw_id', $draw->id)->count());
        $this->assertNotNull($draw->ticket_pool_hash);
        $this->assertSame(64, strlen((string) $draw->ticket_pool_hash));

        // Les positions du snapshot sont contiguës de 0 à pool_size-1.
        $positions = DrawEntry::query()->where('draw_id', $draw->id)->orderBy('position')->pluck('position')
            ->map(fn ($position) => (int) $position)->all();
        $this->assertSame(range(0, 5), $positions);

        // L'engagement du serveur est publié avant le tirage.
        $this->assertNotNull($draw->server_seed_hash);
        $serverSeed = (string) DB::table('draws')->where('id', $draw->id)->value('server_seed');
        $this->assertSame(hash('sha256', $serverSeed), $draw->server_seed_hash);
    }

    public function test_executing_the_draw_produces_winners_and_verification_passes(): void
    {
        $campaign = $this->campaignWithTickets(8);
        $this->publishPrize($campaign, 1);

        $draw = $this->snapshotDraw($campaign);
        $draw = $this->draws->execute($draw, self::CLIENT_SEED, $this->admin);

        $this->assertSame(DrawStatus::Executed, $draw->status);
        $this->assertNotNull($draw->executed_at);
        $this->assertNotNull($draw->winning_position);
        $this->assertNotNull($draw->random_value);
        $this->assertSame(1, Winner::query()->where('draw_id', $draw->id)->count());

        $winner = Winner::query()->where('draw_id', $draw->id)->firstOrFail();
        $this->assertSame(1, (int) $winner->rank);
        $entry = DrawEntry::query()->where('draw_id', $draw->id)->where('ticket_id', $winner->ticket_id)->firstOrFail();
        $this->assertSame((int) $draw->winning_position, (int) $entry->position);

        // Le ticket gagnant passe au statut winner.
        $this->assertSame(TicketStatus::Winner, $winner->ticket->status);

        $verification = $this->draws->verify($draw);

        $this->assertTrue($verification['commitment_valid'], 'L’engagement SHA-256(server_seed) doit être valide.');
        $this->assertTrue($verification['pool_hash_valid'], 'L’empreinte du pool doit correspondre au snapshot.');
        $this->assertTrue($verification['winners_valid'], 'Les gagnants doivent être reproductibles depuis les graines.');
    }

    public function test_tampering_with_the_server_seed_after_execution_is_blocked_by_the_database_trigger(): void
    {
        $campaign = $this->campaignWithTickets(5);
        $draw = $this->executedDraw($campaign);

        $this->assertSame(DrawStatus::Executed, $draw->status);

        // Toute modification du résultat est impossible une fois le tirage exécuté.
        $this->expectException(QueryException::class);

        DB::table('draws')->where('id', $draw->id)->update(['server_seed' => 'graine-falsifiee']);
    }

    public function test_winning_positions_are_deterministic_for_the_same_seeds(): void
    {
        $campaign = $this->campaignWithTickets(20);
        $draw = $this->executedDraw($campaign, 5);

        $serverSeed = (string) DB::table('draws')->where('id', $draw->id)->value('server_seed');
        $poolSize = (int) $draw->pool_size;

        $first = $this->draws->selectPositions($serverSeed, self::CLIENT_SEED, $poolSize, 5);
        $second = $this->draws->selectPositions($serverSeed, self::CLIENT_SEED, $poolSize, 5);

        // Mêmes graines + même pool ⇒ positions strictement identiques.
        $this->assertSame($first, $second);
        $this->assertCount(5, $first);
        $this->assertCount(5, array_unique($first));
        foreach ($first as $position) {
            $this->assertGreaterThanOrEqual(0, $position);
            $this->assertLessThan($poolSize, $position);
        }

        // Et ces positions correspondent bien aux gagnants réellement enregistrés.
        $positionsByTicket = DrawEntry::query()
            ->where('draw_id', $draw->id)
            ->pluck('position', 'ticket_id')
            ->map(fn ($position) => (int) $position);

        $actualPositions = $draw->winners()
            ->orderBy('rank')
            ->pluck('ticket_id')
            ->map(fn (string $ticketId) => $positionsByTicket[$ticketId])
            ->all();

        $this->assertSame($first, $actualPositions);

        // Une graine publique différente change le résultat (pas de résultat figé en dur).
        $other = $this->draws->selectPositions($serverSeed, 'une-autre-graine-publique', $poolSize, 5);
        $this->assertNotSame($first, $other);
    }
}
