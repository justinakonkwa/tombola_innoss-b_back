<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Système de tickets (cahier des charges §7).
 *
 * Garanties fortes portées par la base :
 *  - `ticket_batches.payment_id` est UNIQUE : un paiement ne peut produire qu'un seul lot
 *    de tickets, même en cas de double webhook ou de retry concurrent (§10, §38).
 *  - la numérotation provient d'une séquence PostgreSQL : impossible de la deviner/rejouer.
 *  - un ticket validé est immuable : un trigger PostgreSQL rejette toute modification
 *    de son numéro, de son propriétaire, de sa campagne ou de sa commande (§47.5).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Séquence globale de numérotation des tickets (jamais exposée au client).
        DB::statement('CREATE SEQUENCE IF NOT EXISTS tickets_serial_seq START WITH 1 INCREMENT BY 1');

        DB::statement('CREATE SEQUENCE IF NOT EXISTS orders_reference_seq START WITH 1 INCREMENT BY 1');
        DB::statement('CREATE SEQUENCE IF NOT EXISTS draws_reference_seq START WITH 1 INCREMENT BY 1');

        Schema::create('ticket_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders')->cascadeOnDelete();
            // UNIQUE : verrou d'idempotence de l'attribution des tickets.
            $table->foreignUuid('payment_id')->unique()->constrained('payments')->cascadeOnDelete();
            $table->foreignUuid('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('first_serial');
            $table->unsignedBigInteger('last_serial');
            $table->string('checksum', 64);                     // preuve d'intégrité du lot
            $table->jsonb('numbers')->nullable();               // liste des numéros générés
            $table->timestamp('generated_at');
            $table->timestamps();
        });

        Schema::create('tickets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('ticket_number', 32)->unique();       // TMB-2026-00045821
            $table->unsignedBigInteger('serial')->unique();      // numéro de séquence interne
            $table->foreignUuid('batch_id')->nullable()->constrained('ticket_batches')->nullOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->foreignUuid('prize_id')->nullable()->constrained('prizes')->nullOnDelete();
            $table->foreignUuid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignUuid('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->string('status', 16)->default('pending');    // pending|valid|used|winner|cancelled|refunded|suspended
            $table->string('qr_token', 64)->unique();            // jeton opaque du QR code
            $table->boolean('is_locked')->default(false);        // verrouillé après validation du paiement
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index(['campaign_id', 'status']);
            $table->index(['user_id', 'status']);
            $table->index(['campaign_id', 'serial']);
            $table->index('created_at');
        });

        // ------------------------------------------------- contraintes d'intégrité
        DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_status_check CHECK (status IN ('pending','valid','used','winner','cancelled','refunded','suspended'))");
        DB::statement('ALTER TABLE ticket_batches ADD CONSTRAINT ticket_batches_quantity_check CHECK (quantity > 0 AND last_serial >= first_serial)');

        // ------------------------------------------- immuabilité des tickets validés
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION tickets_enforce_immutability() RETURNS trigger AS $$
            BEGIN
                IF OLD.is_locked THEN
                    IF NEW.ticket_number IS DISTINCT FROM OLD.ticket_number
                       OR NEW.serial      IS DISTINCT FROM OLD.serial
                       OR NEW.user_id     IS DISTINCT FROM OLD.user_id
                       OR NEW.campaign_id IS DISTINCT FROM OLD.campaign_id
                       OR NEW.order_id    IS DISTINCT FROM OLD.order_id
                       OR NEW.payment_id  IS DISTINCT FROM OLD.payment_id
                       OR NEW.qr_token    IS DISTINCT FROM OLD.qr_token
                       OR NEW.batch_id    IS DISTINCT FROM OLD.batch_id THEN
                        RAISE EXCEPTION 'Ticket % est verrouillé : ses données d''émission sont immuables', OLD.ticket_number
                            USING ERRCODE = 'check_violation';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement('CREATE TRIGGER tickets_immutability BEFORE UPDATE ON tickets FOR EACH ROW EXECUTE FUNCTION tickets_enforce_immutability()');

        // Un ticket ne peut jamais être supprimé : il doit être annulé/remboursé (traçabilité).
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION tickets_prevent_delete() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Un ticket ne peut pas être supprimé (utilisez le statut cancelled/refunded)'
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('CREATE TRIGGER tickets_no_delete BEFORE DELETE ON tickets FOR EACH ROW EXECUTE FUNCTION tickets_prevent_delete()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS tickets_no_delete ON tickets');
        DB::statement('DROP TRIGGER IF EXISTS tickets_immutability ON tickets');
        DB::statement('DROP FUNCTION IF EXISTS tickets_prevent_delete()');
        DB::statement('DROP FUNCTION IF EXISTS tickets_enforce_immutability()');
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('ticket_batches');
        DB::statement('DROP SEQUENCE IF EXISTS tickets_serial_seq');
        DB::statement('DROP SEQUENCE IF EXISTS orders_reference_seq');
        DB::statement('DROP SEQUENCE IF EXISTS draws_reference_seq');
    }
};
