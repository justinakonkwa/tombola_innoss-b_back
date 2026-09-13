# Créer la base Tombola sur ton serveur

L'application **tourne déjà sur une base PostgreSQL** (32 tables, données réelles,
44 tests passés dessus). Ce document sert uniquement à créer la base **sur ton
serveur de production**.

---

## Pourquoi je n'ai pas pu le faire moi-même

| Vérification | Résultat |
|---|---|
| Hôte fourni | **aucun** — seulement `username: postgres` + mot de passe |
| `169.58.138.156:5432` (serveur Coolify) | **fermé / filtré** |
| `coolify.kazipro.tech/…/database/…` | **HTTP 302 → /login** (compte requis) |
| `ncwo4c8gw00g8s0s0kk0gcsw` (hôte interne vu dans `kamba-chat`) | **ne résout pas** hors du réseau Docker |
| `38.242.140.254:5432`, `161.97.105.22:5432` | **fermés** |

Par défaut, Coolify n'expose **pas** les bases de données sur Internet : elles
vivent dans un réseau Docker interne. Il faut donc soit un accès au serveur, soit
ouvrir un port, soit exécuter le script toi-même.

---

## Option A — Tu exécutes le script (le plus simple, 2 minutes)

### A.1 Depuis un terminal SSH sur le serveur

```bash
ssh root@169.58.138.156

# Trouver le conteneur PostgreSQL et ouvrir psql dedans
docker ps --format '{{.Names}}\t{{.Image}}' | grep -i postgres

# Remplacer <NOM_DU_CONTENEUR> par celui affiché ci-dessus
docker exec -i <NOM_DU_CONTENEUR> \
  psql -U postgres -v ON_ERROR_STOP=1 < /chemin/vers/01-create-database.sql
```

Si le fichier n'est pas encore sur le serveur, copie-le d'abord :

```bash
scp deploy/01-create-database.sql root@169.58.138.156:/root/
```

### A.2 Depuis le terminal intégré de Coolify

Coolify propose un terminal pour chaque ressource. Ouvre la base PostgreSQL dans
le panneau, onglet **Terminal**, puis colle le contenu de
[`01-create-database.sql`](01-create-database.sql).

> Le script utilise quelques méta-commandes `psql` (`\gexec`, `\connect`).
> Le terminal Coolify étant un vrai shell `psql`, elles fonctionnent.

### A.3 Ce que fait le script

1. crée le rôle `tombola_app` (`NOSUPERUSER`, `NOCREATEDB`, `NOCREATEROLE`, 40 connexions max) ;
2. crée la base `tombola` (`UTF8`, propriétaire `tombola_app`) ;
3. révoque tous les droits de `PUBLIC` sur la base et le schéma `public` ;
4. active `pgcrypto` et `pg_trgm` ;
5. affiche une vérification.

Le mot de passe généré se trouve dans `.server-db-credentials` (non versionné).

Ensuite, sur le serveur applicatif :

```bash
php artisan migrate --force
php artisan db:seed --force
```

---

## Option B — Tu m'exposes la base temporairement

Dans Coolify : **base PostgreSQL → Configuration → Ports mappings**, ajoute une
correspondance du type `5432:5432` (idéalement restreinte à ton IP, ou à
`127.0.0.1` + tunnel SSH), puis redéploie.

Donne-moi ensuite l'hôte public et je crée la base, lance les migrations et le
seeding, et vérifie la connexion.

⚠️ N'expose **jamais** PostgreSQL ouvert sur Internet sans restriction : c'est la
porte d'entrée la plus recherchée par les scanners automatiques. Préfère
l'option C.

---

## Option C — Tu me donnes un accès SSH (recommandé)

Le port 22 est ouvert sur `169.58.138.156`. Avec un accès SSH (clé ou mot de
passe), je peux :

1. localiser le conteneur PostgreSQL ;
2. créer la base et le rôle dédié ;
3. lancer migrations + seeding ;
4. vérifier les triggers de protection ;
5. configurer le `.env` de l'application déployée.

C'est la seule option qui me permet d'aller jusqu'au bout **et** de vérifier le
résultat, comme je l'ai fait en local.

---

## Faut-il partager l'instance PostgreSQL de kamba-chat ?

**L'application Tombola n'utilise en rien la base de kamba-chat.** Vérifié :

| Contrôle | Résultat |
|---|---|
| Base utilisée par Tombola | `tombola` (rôle `tombola_app`) |
| Base utilisée par kamba-chat | `kamba_meet` (rôle `postgres`) |
| Tables Tombola dans `kamba_meet` | **0** |
| Tables kamba-chat dans `tombola` | **0** |
| `tombola_app` peut lire les données de `kamba_meet` | **non** (permission denied) |

**Mais** un partage d'instance laisse un risque résiduel, que j'ai mesuré :

> PostgreSQL accorde `CONNECT` **et** `CREATE` à `PUBLIC` par défaut. Sur la même
> instance, `tombola_app` peut donc ouvrir une session sur `kamba_meet` et
> **créer des objets dans son schéma `public`** — sans jamais pouvoir lire les
> données existantes. Un `REVOKE ... FROM tombola_app` ne corrige rien : le
> privilège vient de `PUBLIC`, seul un `REVOKE ... FROM PUBLIC` agit (vérifié).

Deux solutions, par ordre de préférence :

1. **Recommandé — une instance PostgreSQL dédiée à Tombola.** Le problème
   disparaît, et aucune base voisine n'est touchée.
2. **Instance partagée + isolation cluster** : le bloc correspondant se trouve,
   commenté et documenté, à l'étape « 2 bis » de
   [`01-create-database.sql`](01-create-database.sql). À n'activer qu'en
   connaissance de cause : révoquer `CONNECT` à `PUBLIC` coupe l'accès de **tout**
   rôle non superutilisateur aux bases visées (`postgres`, lui, n'est pas
   affecté). Inspectez d'abord qui se connecte à quoi :

   ```sql
   SELECT datname, datacl FROM pg_database WHERE datistemplate = false;
   ```

Dans les deux cas, la séparation applicative est déjà totale : deux bases, deux
rôles, aucune table commune.

---

## Vérifier que tout est en place (à lancer après)

```bash
psql "postgresql://tombola_app:MOT_DE_PASSE@HOTE:5432/tombola" -c "\dt"    # 32 tables
psql "postgresql://tombola_app:MOT_DE_PASSE@HOTE:5432/tombola" -c "SELECT count(*) FROM roles;"       # 6
psql "postgresql://tombola_app:MOT_DE_PASSE@HOTE:5432/tombola" -c "SELECT count(*) FROM permissions;"  # 25
psql "postgresql://tombola_app:MOT_DE_PASSE@HOTE:5432/tombola" -c "SELECT count(*) FROM campaigns;"    # 2
```

Test négatif des garanties (doit **échouer**) :

```sql
UPDATE tickets SET ticket_number = 'TMB-2026-00000001' WHERE is_locked;  -- trigger d'immuabilité
DELETE FROM audit_logs WHERE id = 1;                                     -- journal append-only
```
