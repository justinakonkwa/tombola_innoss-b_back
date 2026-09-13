<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Campagnes et lots (cahier des charges §18, §19).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();                 // URL dédiée : /tombola/{slug}
            $table->text('description')->nullable();
            $table->text('short_description')->nullable();
            $table->string('hero_media_url')->nullable();      // image ou vidéo du hero
            $table->string('hero_poster_url')->nullable();
            $table->string('og_image_url')->nullable();        // Open Graph / partage WhatsApp
            $table->decimal('ticket_price', 12, 2);
            $table->char('currency', 3)->default('USD');
            $table->unsignedBigInteger('max_tickets');         // nombre maximum de tickets
            $table->unsignedBigInteger('tickets_sold')->default(0);
            $table->unsignedBigInteger('tickets_reserved')->default(0);
            $table->unsignedInteger('min_tickets_per_order')->default(1);
            $table->unsignedInteger('max_tickets_per_order')->default(100);
            $table->unsignedInteger('max_tickets_per_user')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();          // fermeture des ventes
            $table->timestamp('draw_at')->nullable();          // date du tirage annoncée
            $table->string('status', 16)->default('draft');    // draft|scheduled|active|closed|drawn|archived
            $table->boolean('is_featured')->default(false);
            $table->string('terms_url')->nullable();
            $table->jsonb('settings')->nullable();             // options libres (anti-fraude, limites pays…)
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'starts_at']);
            $table->index('is_featured');
        });

        Schema::create('prizes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->jsonb('media')->nullable();                 // [{type:image|video,url,alt}]
            $table->decimal('indicative_value', 14, 2)->nullable();
            $table->char('currency', 3)->default('USD');
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedInteger('quantity_awarded')->default(0);
            $table->boolean('is_main')->default(false);         // la Lamborghini = lot principal
            $table->unsignedInteger('position')->default(0);
            $table->timestamp('draw_at')->nullable();
            $table->string('status', 16)->default('draft');     // draft|published|withdrawn
            $table->timestamps();

            $table->unique(['campaign_id', 'slug']);
            $table->index(['campaign_id', 'position']);
        });

        // ------------------------------------------------- contraintes d'intégrité
        DB::statement("ALTER TABLE campaigns ADD CONSTRAINT campaigns_status_check CHECK (status IN ('draft','scheduled','active','closed','drawn','archived'))");
        DB::statement('ALTER TABLE campaigns ADD CONSTRAINT campaigns_ticket_price_check CHECK (ticket_price > 0)');
        DB::statement('ALTER TABLE campaigns ADD CONSTRAINT campaigns_max_tickets_check CHECK (max_tickets > 0)');
        DB::statement('ALTER TABLE campaigns ADD CONSTRAINT campaigns_sold_check CHECK (tickets_sold >= 0 AND tickets_sold <= max_tickets)');
        DB::statement('ALTER TABLE campaigns ADD CONSTRAINT campaigns_currency_check CHECK (currency IN (\'USD\',\'CDF\',\'EUR\'))');
        DB::statement("ALTER TABLE prizes ADD CONSTRAINT prizes_status_check CHECK (status IN ('draft','published','withdrawn'))");
        DB::statement('ALTER TABLE prizes ADD CONSTRAINT prizes_quantity_check CHECK (quantity_awarded <= quantity)');
    }

    public function down(): void
    {
        Schema::dropIfExists('prizes');
        Schema::dropIfExists('campaigns');
    }
};
