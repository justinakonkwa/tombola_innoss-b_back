<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La description de l'algorithme de tirage dépasse 64 caractères
 * (« HMAC-SHA256(server_seed, client_seed:rank:counter) mod N (rejection sampling) »
 * en fait 75). La preuve publiée doit décrire précisément la méthode employée,
 * on élargit donc la colonne plutôt que de tronquer la documentation.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE draws ALTER COLUMN algorithm TYPE varchar(160)');
        DB::statement('ALTER TABLE draws ALTER COLUMN ticket_pool_hash TYPE varchar(64)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE draws ALTER COLUMN algorithm TYPE varchar(64)');
    }
};
