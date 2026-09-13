<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Identité : utilisateurs, RBAC, appareils, sessions, OTP.
 *
 * Toutes les clés primaires exposées publiquement sont des UUID afin de
 * limiter les attaques par énumération (cf. cahier des charges §13).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------------------------------------------------------------- roles
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();          // super_admin, admin, finance, support, auditor, participant
            $table->string('label');
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        // ---------------------------------------------------------- permissions
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();          // campaigns.create, draws.execute, refunds.approve…
            $table->string('label');
            $table->string('group')->index();
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });

        // ---------------------------------------------------------------- users
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('phone', 32)->unique();
            $table->string('email')->unique();
            $table->char('country', 2)->default('CD');
            $table->string('city')->nullable();
            $table->string('password');
            $table->string('status', 16)->default('active'); // active|suspended|blocked
            $table->boolean('is_admin')->default(false);     // accès back-office (MFA obligatoire)
            $table->string('totp_secret')->nullable();
            $table->timestamp('totp_confirmed_at')->nullable();
            $table->timestamp('phone_verified_at')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->unsignedSmallInteger('risk_score')->default(0); // 0..100
            $table->string('locale', 5)->default('fr');
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->unsignedInteger('failed_login_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'created_at']);
            $table->index('risk_score');
        });

        // ------------------------------------------------------------ user_roles
        Schema::create('user_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignUuid('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'role_id']);
        });

        // -------------------------------------------------------------- devices
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('fingerprint', 64);
            $table->string('label')->nullable();
            $table->string('user_agent')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->boolean('is_trusted')->default(false);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'fingerprint']);
        });

        // -------------------------------------------------------- user_sessions
        // Table « sessions » du cahier des charges §28. Nommée user_sessions pour
        // ne pas entrer en conflit avec le driver de session Laravel.
        Schema::create('user_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->string('refresh_token_hash', 64)->unique();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
        });

        // ------------------------------------------------------------ otp_codes
        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('phone', 32)->index();
            $table->string('code_hash');
            $table->string('purpose', 32)->default('login'); // login|phone_verification|transaction
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(5);
            $table->string('ip', 45)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['phone', 'purpose', 'consumed_at']);
        });

        // ----------------------------------------------- contraintes applicatives
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('active','suspended','blocked'))");
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_risk_score_check CHECK (risk_score BETWEEN 0 AND 100)');
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_codes');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('devices');
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('users');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
