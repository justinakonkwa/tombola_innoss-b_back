<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Commandes, paiements, tentatives, webhooks, remboursements (cahier des charges §8–§11, §26).
 *
 * Règles structurantes :
 *  - un paiement confirmé = une seule attribution de tickets (index unique ticket_batches.payment_id) ;
 *  - l'idempotence est garantie par orders.idempotency_key et payment_webhooks.payload_hash ;
 *  - aucun montant n'est jamais accepté depuis le client : il est recalculé puis figé ici.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------------------------------------------------------------- orders
        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference')->unique();              // ORD-2026-000123
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 12, 2);               // prix figé au moment de la commande
            $table->decimal('total_amount', 14, 2);
            $table->char('currency', 3)->default('USD');
            $table->string('status', 16)->default('pending');   // pending|processing|paid|failed|cancelled|refunded|expired
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->unsignedSmallInteger('risk_score')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index(['campaign_id', 'status']);
            $table->index('created_at');
        });

        // -------------------------------------------------------------- payments
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('provider', 32)->default('futaye');
            $table->string('provider_payment_id')->nullable();   // identifiant public renvoyé par Futaye
            $table->string('provider_reference')->nullable();    // référence FT…
            $table->string('channel', 24)->default('unknown');   // mobile_money|card|rdv|unknown
            $table->string('operator', 32)->nullable();          // airtel|mpesa|orange|africell
            $table->string('payer_phone', 32)->nullable();
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3)->default('USD');
            $table->decimal('commission', 14, 2)->default(0);
            $table->decimal('net', 14, 2)->default(0);
            $table->string('status', 16)->default('created');    // created|pending|success|failed|cancelled|refunded
            $table->text('checkout_url')->nullable();
            $table->jsonb('provider_payload')->nullable();       // dernière réponse brute du fournisseur
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_payment_id']);
            $table->index(['order_id', 'status']);
            $table->index('status');
        });

        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt_no')->default(1);
            $table->string('action', 32);                        // create|status_check|reconcile
            $table->string('status', 24);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->jsonb('request_payload')->nullable();
            $table->jsonb('response_payload')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['payment_id', 'attempt_no']);
        });

        // Réception des webhooks : journalisation + anti-rejeu + idempotence.
        Schema::create('payment_webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32)->default('futaye');
            $table->string('event', 48)->nullable();             // PAYMENT_SUCCESS|PAYMENT_FAILED|PAYOUT_SENT
            $table->string('provider_reference')->nullable();
            $table->string('signature')->nullable();
            $table->string('timestamp_header')->nullable();
            $table->string('payload_hash', 64)->unique();        // anti-rejeu / idempotence
            $table->jsonb('payload');
            $table->string('status', 16)->default('received');   // received|processed|ignored|rejected|duplicate
            $table->text('error')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['provider', 'event']);
            $table->index('created_at');
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->foreignUuid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3)->default('USD');
            $table->string('reason')->nullable();
            $table->string('status', 16)->default('requested');  // requested|approved|processing|completed|rejected
            $table->string('provider_refund_id')->nullable();
            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        // ------------------------------------------------- contraintes d'intégrité
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('pending','processing','paid','failed','cancelled','refunded','expired'))");
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_quantity_check CHECK (quantity > 0)');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_amount_check CHECK (total_amount >= 0 AND unit_price >= 0)');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_check CHECK (status IN ('created','pending','success','failed','cancelled','refunded'))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_channel_check CHECK (channel IN ('mobile_money','card','rdv','unknown'))");
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_check CHECK (amount >= 0)');
        DB::statement("ALTER TABLE payment_webhooks ADD CONSTRAINT payment_webhooks_status_check CHECK (status IN ('received','processed','ignored','rejected','duplicate'))");
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_status_check CHECK (status IN ('requested','approved','processing','completed','rejected'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payment_webhooks');
        Schema::dropIfExists('payment_attempts');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('orders');
    }
};
