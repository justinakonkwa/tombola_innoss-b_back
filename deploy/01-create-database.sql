-- ============================================================================
-- Tombola Innoss'B — création de la base et du rôle applicatif
-- ============================================================================
--
-- À exécuter UNE FOIS sur le serveur PostgreSQL, connecté en superutilisateur :
--
--   psql "postgresql://postgres:MOT_DE_PASSE@HOTE:5432/postgres" \
--        -v ON_ERROR_STOP=1 -f deploy/01-create-database.sql
--
-- L'application ne se connecte JAMAIS avec le compte « postgres » : elle
-- utilise le rôle dédié tombola_app, propriétaire de sa seule base
-- (principe du moindre privilège, cahier des charges §33 et §34).
-- ============================================================================

\set ON_ERROR_STOP on

-- ---------------------------------------------------------------------------
-- 1. Rôle applicatif
-- ---------------------------------------------------------------------------
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tombola_app') THEN
        ALTER ROLE tombola_app WITH LOGIN PASSWORD 'KK1SncsN3VAB3EqnoetqE31yaJ2RkamKf8FAjG5txqdb0AWw';
        RAISE NOTICE 'Rôle tombola_app déjà présent : mot de passe mis à jour.';
    ELSE
        CREATE ROLE tombola_app WITH
            LOGIN
            PASSWORD 'KK1SncsN3VAB3EqnoetqE31yaJ2RkamKf8FAjG5txqdb0AWw'
            NOSUPERUSER
            NOCREATEDB
            NOCREATEROLE
            NOINHERIT
            CONNECTION LIMIT 40;
        RAISE NOTICE 'Rôle tombola_app créé.';
    END IF;
END
$$;

-- ---------------------------------------------------------------------------
-- 2. Base de données dédiée
-- ---------------------------------------------------------------------------
SELECT 'CREATE DATABASE tombola OWNER tombola_app ENCODING ''UTF8'' TEMPLATE template0'
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = 'tombola')\gexec

-- ---------------------------------------------------------------------------
-- 2 bis. Cloisonnement entre bases (OPTIONNEL — à lire avant d'activer)
-- ---------------------------------------------------------------------------
--
-- Trois constats vérifiés sur PostgreSQL 14 :
--
--   1. `tombola_app` NE PEUT PAS lire les données des autres bases : les
--      tables y appartiennent à d'autres rôles et aucun SELECT ne lui est
--      accordé. C'est déjà le cas sans rien faire.
--
--   2. EN REVANCHE, `tombola_app` PEUT OUVRIR UNE SESSION sur les autres bases
--      de l'instance, et y CRÉER des objets dans le schéma `public` — parce que
--      PostgreSQL accorde CONNECT et CREATE à PUBLIC par défaut.
--
--   3. Un `REVOKE ... FROM tombola_app` ne change rien : le privilège vient de
--      PUBLIC, pas d'un grant explicite. Seul `REVOKE ... FROM PUBLIC` agit.
--
-- Par conséquent, si Tombola partage l'instance PostgreSQL d'une autre
-- application (kamba-chat, par exemple), il reste un risque résiduel :
-- tombola_app peut polluer le schéma public des bases voisines.
--
-- DEUX SOLUTIONS, par ordre de préférence :
--
--   A. RECOMMANDÉ — déployer Tombola sur une instance PostgreSQL dédiée.
--      Le problème disparaît entièrement, sans toucher aux bases voisines.
--
--   B. Isoler au niveau du cluster (ci-dessous), SI ET SEULEMENT SI vous
--      acceptez l'implication : révoquer CONNECT à PUBLIC coupe l'accès de
--      TOUT rôle non superutilisateur à ces bases. Les superutilisateurs
--      (comme `postgres`) ne sont pas affectés. Tout autre rôle applicatif
--      devra recevoir un GRANT CONNECT explicite.
--
-- Avant d'activer le bloc ci-dessous, inspectez qui se connecte à quoi :
--
--   SELECT datname, datacl FROM pg_database WHERE datistemplate = false;
--
-- Puis décommentez :
--
-- DO $$
-- DECLARE cible record;
-- BEGIN
--     FOR cible IN
--         SELECT datname FROM pg_database
--         WHERE datistemplate = false AND datname NOT IN ('tombola', 'postgres')
--     LOOP
--         EXECUTE format('REVOKE CONNECT ON DATABASE %I FROM PUBLIC', cible.datname);
--         EXECUTE format('REVOKE CREATE ON SCHEMA public FROM PUBLIC');
--         RAISE NOTICE 'Base % isolée de PUBLIC', cible.datname;
--     END LOOP;
-- END
-- $$;
--
-- Vérification après activation (doit échouer) :
--   psql "postgresql://tombola_app:MOT_DE_PASSE@HOTE:5432/kamba_meet" -c '\l'
--
-- ---------------------------------------------------------------------------
-- 3. Droits (moindre privilège)
-- ---------------------------------------------------------------------------
REVOKE ALL ON DATABASE tombola FROM PUBLIC;
GRANT CONNECT, TEMPORARY ON DATABASE tombola TO tombola_app;

\connect tombola

REVOKE ALL ON SCHEMA public FROM PUBLIC;
ALTER SCHEMA public OWNER TO tombola_app;
GRANT USAGE, CREATE ON SCHEMA public TO tombola_app;

-- Les extensions utiles (pgcrypto pour les UUID, pg_trgm pour la recherche).
CREATE EXTENSION IF NOT EXISTS pgcrypto;
CREATE EXTENSION IF NOT EXISTS pg_trgm;

-- ---------------------------------------------------------------------------
-- 4. Vérification
-- ---------------------------------------------------------------------------
SELECT current_database() AS base, current_user AS utilisateur;

\echo ''
\echo 'Base « tombola » et rôle « tombola_app » prêts.'
\echo 'Étape suivante : renseigner DB_PASSWORD dans le .env de l''API puis lancer'
\echo '  php artisan migrate --force && php artisan db:seed --force'
