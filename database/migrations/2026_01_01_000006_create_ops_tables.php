<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Exploitation : notifications, journal d'audit chaîné, événements de sécurité,
 * paramètres applicatifs et évaluation du risque (cahier des charges §17, §24, §27, §36).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('channel', 16);                       // email|sms|whatsapp|push
            $table->string('template', 64);                      // account_created|payment_confirmed|tickets_issued|winner…
            $table->string('subject')->nullable();
            $table->text('body')->nullable();
            $table->string('destination')->nullable();           // email ou téléphone utilisé
            $table->string('status', 16)->default('queued');     // queued|sent|delivered|failed
            $table->string('provider_ref')->nullable();
            $table->jsonb('payload')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'channel']);
        });

        // Journal d'audit append-only : chaîne de hachage + triggers anti UPDATE/DELETE.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_email')->nullable();
            $table->string('actor_role')->nullable();
            $table->string('action', 64);                        // UPDATE_DRAW, CREATE_CAMPAIGN, REVEAL_TOKEN…
            $table->string('resource_type', 64)->nullable();
            $table->string('resource_id')->nullable();
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->string('previous_hash', 64)->nullable();
            $table->string('hash', 64);                          // SHA-256(previous_hash|payload)
            $table->timestamp('created_at')->useCurrent();

            $table->index(['action', 'created_at']);
            $table->index(['resource_type', 'resource_id']);
            $table->index('created_at');
        });

        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->string('type', 48);                          // login_failed, brute_force, webhook_rejected…
            $table->string('severity', 16)->default('info');     // info|warning|high|critical
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->text('description')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->boolean('is_resolved')->default(false);
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'created_at']);
            $table->index(['severity', 'is_resolved']);
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->jsonb('value')->nullable();
            $table->string('group', 32)->default('general');
            $table->text('description')->nullable();
            $table->boolean('is_public')->default(false);        // exposable au frontend
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('group');
        });

        Schema::create('risk_assessments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('order_id')->nullable()->constrained('orders')->cascadeOnDelete();
            $table->unsignedSmallInteger('score')->default(0);   // 0..100
            $table->string('level', 16)->default('low');         // low|medium|high|critical
            $table->string('action', 16)->default('allow');      // allow|review|block
            $table->jsonb('signals')->nullable();                // [{code, weight, detail}]
            $table->boolean('is_reviewed')->default(false);
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->index(['level', 'is_reviewed']);
            $table->index('user_id');
        });

        // ------------------------------------------------- contraintes d'intégrité
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_channel_check CHECK (channel IN ('email','sms','whatsapp','push'))");
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_status_check CHECK (status IN ('queued','sent','delivered','failed'))");
        DB::statement("ALTER TABLE security_events ADD CONSTRAINT security_events_severity_check CHECK (severity IN ('info','warning','high','critical'))");
        DB::statement("ALTER TABLE risk_assessments ADD CONSTRAINT risk_assessments_score_check CHECK (score BETWEEN 0 AND 100)");
        DB::statement("ALTER TABLE risk_assessments ADD CONSTRAINT risk_assessments_level_check CHECK (level IN ('low','medium','high','critical'))");
        DB::statement("ALTER TABLE risk_assessments ADD CONSTRAINT risk_assessments_action_check CHECK (action IN ('allow','review','block'))");

        // ---------------------------------------- protection du journal d'audit
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_logs_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Le journal d''audit est append-only'
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('CREATE TRIGGER audit_logs_no_update BEFORE UPDATE OR DELETE ON audit_logs FOR EACH ROW EXECUTE FUNCTION audit_logs_append_only()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS audit_logs_no_update ON audit_logs');
        DB::statement('DROP FUNCTION IF EXISTS audit_logs_append_only()');
        Schema::dropIfExists('risk_assessments');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('security_events');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('notifications');
    }
};
