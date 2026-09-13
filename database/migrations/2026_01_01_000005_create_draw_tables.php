<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tirage au sort transparent et vérifiable (cahier des charges §20–§22).
 *
 * Preuve d'équité mise en œuvre :
 *  1. fermeture des ventes ;
 *  2. snapshot immuable des tickets éligibles + empreinte SHA-256 du pool (ticket_pool_hash) ;
 *  3. engagement du serveur publié AVANT le tirage : server_seed_hash = SHA-256(server_seed) ;
 *  4. le tirage consomme un aléa public (client_seed / graine d'audit) et le server_seed ;
 *     index gagnant = HMAC-SHA256(server_seed, client_seed) mod pool_size ;
 *  5. le server_seed est révélé après le tirage : tout tiers peut recalculer le résultat
 *     et vérifier que server_seed_hash correspond bien à l'engagement publié.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('draws', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference')->unique();                // DRAW-2026-001
            $table->foreignUuid('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->string('status', 16)->default('pending');     // pending|closed|snapshot|executed|published|cancelled
            $table->unsignedBigInteger('pool_size')->default(0);
            $table->string('ticket_pool_hash', 64)->nullable();   // SHA-256 du pool trié
            $table->string('algorithm', 64)->default('HMAC-SHA256(server_seed, client_seed) mod N');
            $table->string('server_seed_hash', 64)->nullable();   // engagement publié avant tirage
            $table->text('server_seed')->nullable();              // révélé après tirage
            $table->string('client_seed', 128)->nullable();       // aléa public (audit / blockchain / horodatage)
            $table->string('random_value', 64)->nullable();       // HMAC calculé
            $table->unsignedBigInteger('winning_position')->nullable();
            $table->foreignUuid('executed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->jsonb('evidence')->nullable();                // dossier de preuve complet
            $table->timestamps();

            $table->index(['campaign_id', 'status']);
        });

        // Snapshot immuable : une ligne par ticket éligible, figée au moment du tirage.
        Schema::create('draw_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('draw_id')->constrained('draws')->cascadeOnDelete();
            $table->foreignUuid('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->unsignedBigInteger('position');               // 0 .. pool_size-1
            $table->string('ticket_number', 32);
            $table->string('ticket_hash', 64);                    // SHA-256(ticket_number|user_id|serial)
            $table->timestamps();

            $table->unique(['draw_id', 'ticket_id']);
            $table->unique(['draw_id', 'position']);
        });

        Schema::create('winners', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('draw_id')->constrained('draws')->cascadeOnDelete();
            $table->foreignUuid('prize_id')->constrained('prizes')->cascadeOnDelete();
            $table->foreignUuid('ticket_id')->unique()->constrained('tickets')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('rank')->default(1);
            $table->string('status', 24)->default('pending');     // pending|contacted|verified|validated|delivered|rejected|forfeited
            $table->boolean('is_published')->default(false);      // publication sur la page publique
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('identity_verified_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->jsonb('proof')->nullable();                   // preuve de remise (photos, PV signé…)
            $table->text('notes')->nullable();
            $table->foreignUuid('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['draw_id', 'rank']);
            $table->index('status');
        });

        // ------------------------------------------------- contraintes d'intégrité
        DB::statement("ALTER TABLE draws ADD CONSTRAINT draws_status_check CHECK (status IN ('pending','closed','snapshot','executed','published','cancelled'))");
        DB::statement("ALTER TABLE winners ADD CONSTRAINT winners_status_check CHECK (status IN ('pending','contacted','verified','validated','delivered','rejected','forfeited'))");

        // Un tirage exécuté/publié est figé : seules les transitions vers published sont permises.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION draws_enforce_freeze() RETURNS trigger AS $$
            BEGIN
                IF OLD.status IN ('executed','published') THEN
                    IF NEW.server_seed      IS DISTINCT FROM OLD.server_seed
                       OR NEW.client_seed   IS DISTINCT FROM OLD.client_seed
                       OR NEW.random_value  IS DISTINCT FROM OLD.random_value
                       OR NEW.ticket_pool_hash IS DISTINCT FROM OLD.ticket_pool_hash
                       OR NEW.pool_size     IS DISTINCT FROM OLD.pool_size
                       OR NEW.winning_position IS DISTINCT FROM OLD.winning_position
                       OR NEW.executed_at   IS DISTINCT FROM OLD.executed_at THEN
                        RAISE EXCEPTION 'Tirage % déjà exécuté : les paramètres et le résultat sont figés', OLD.reference
                            USING ERRCODE = 'check_violation';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('CREATE TRIGGER draws_freeze BEFORE UPDATE ON draws FOR EACH ROW EXECUTE FUNCTION draws_enforce_freeze()');

        // Le snapshot d'un tirage est immuable.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION draw_entries_immutable() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Le snapshot d''un tirage est immuable'
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('CREATE TRIGGER draw_entries_no_change BEFORE UPDATE OR DELETE ON draw_entries FOR EACH ROW EXECUTE FUNCTION draw_entries_immutable()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS draw_entries_no_change ON draw_entries');
        DB::statement('DROP TRIGGER IF EXISTS draws_freeze ON draws');
        DB::statement('DROP FUNCTION IF EXISTS draw_entries_immutable()');
        DB::statement('DROP FUNCTION IF EXISTS draws_enforce_freeze()');
        Schema::dropIfExists('winners');
        Schema::dropIfExists('draw_entries');
        Schema::dropIfExists('draws');
    }
};
