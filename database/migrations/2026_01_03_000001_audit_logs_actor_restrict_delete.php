<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Le journal d'audit est append-only (trigger `audit_logs_no_update`).
 *
 * Or `audit_logs.actor_id` portait `ON DELETE SET NULL` : supprimer un
 * utilisateur déclenchait un UPDATE sur le journal, que le trigger rejetait.
 * L'opérateur recevait alors « Le journal d'audit est append-only » — un
 * message qui ne dit rien de la cause réelle.
 *
 * On rend l'intention explicite : un utilisateur référencé par le journal
 * d'audit ne peut pas être supprimé définitivement. C'est le comportement
 * souhaitable pour la responsabilité (on doit toujours savoir qui a fait quoi)
 * et c'est cohérent avec la stratégie de conservation : les comptes sont
 * *désactivés* (soft delete) ou *anonymisés* (champs personnels effacés), la
 * ligne subsistant pour que les références d'audit restent valides.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE audit_logs DROP CONSTRAINT IF EXISTS audit_logs_actor_id_foreign');

        DB::statement(
            'ALTER TABLE audit_logs
             ADD CONSTRAINT audit_logs_actor_id_foreign
             FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE RESTRICT'
        );

        DB::statement(
            "COMMENT ON CONSTRAINT audit_logs_actor_id_foreign ON audit_logs IS
             'RESTRICT volontaire : un acteur référencé par le journal ne peut pas être supprimé (anonymisez-le à la place).'"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE audit_logs DROP CONSTRAINT IF EXISTS audit_logs_actor_id_foreign');

        DB::statement(
            'ALTER TABLE audit_logs
             ADD CONSTRAINT audit_logs_actor_id_foreign
             FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL'
        );
    }
};
