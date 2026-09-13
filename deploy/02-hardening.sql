-- =============================================================================
-- Durcissement de l'instance PostgreSQL (exécuté au premier démarrage Docker)
-- =============================================================================

CREATE EXTENSION IF NOT EXISTS pgcrypto;
CREATE EXTENSION IF NOT EXISTS pg_trgm;

-- Le schéma public n'est pas ouvert à tous.
REVOKE ALL ON SCHEMA public FROM PUBLIC;

-- Journalisation des connexions et des erreurs pour l'audit.
ALTER SYSTEM SET log_connections = 'on';
ALTER SYSTEM SET log_disconnections = 'on';
ALTER SYSTEM SET log_min_duration_statement = '1000';
ALTER SYSTEM SET log_line_prefix = '%m [%p] %q%u@%d ';

-- Sauvegardes : les WAL permettent une restauration à un instant précis (PITR).
ALTER SYSTEM SET wal_level = 'replica';
ALTER SYSTEM SET archive_mode = 'on';
ALTER SYSTEM SET archive_command = 'test ! -f /var/lib/postgresql/wal-archive/%f && cp %p /var/lib/postgresql/wal-archive/%f';
