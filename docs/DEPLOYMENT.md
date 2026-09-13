# Déploiement — Tombola Innoss’B

Ce guide couvre la mise en production complète : base de données, API, frontend,
passerelle de paiement, sauvegardes et supervision.

---

## 1. Vue d’ensemble

```
                    INTERNET
                       │
                       ▼
                  CLOUDFLARE  (WAF, DDoS, TLS)
                       │
                       ▼
                  REVERSE PROXY
             ┌─────────┴─────────┐
             ▼                   ▼
      FRONTEND (Next.js)    API (Laravel)
      tombola-…cd                 │
                            ┌─────┼──────────────┐
                            ▼     ▼              ▼
                       PostgreSQL  Redis    API Futaye
                                                 │
                                    ┌────────────┼─────────────┐
                                    ▼            ▼             ▼
                              Mobile Money      RDV      Cartes
```

| Composant | Rôle | Exposition |
|---|---|---|
| `web` | Frontend public + back-office | Internet (via proxy) |
| `api` | API REST `/api/v1` | Internet (via proxy) |
| `postgres` | Données | **Réseau interne uniquement** |
| `redis` | Cache, files, sessions | **Réseau interne uniquement** |
| `worker` | Files (notifications, réconciliation) | Interne |
| `scheduler` | Tâches planifiées | Interne |

---

## 2. Prérequis

- Docker 24+ et Docker Compose v2
- Un nom de domaine + certificat TLS (Cloudflare recommandé)
- Un compte partenaire **Futaye** avec un `Client ID` et un `Token HMAC`
- Un serveur SMTP pour les e-mails transactionnels

---

## 3. Base de données

### 3.1 Création du rôle et de la base

L’application **ne se connecte jamais** avec le compte `postgres`. Elle utilise un
rôle dédié, propriétaire de sa seule base.

```bash
psql "postgresql://postgres:MOT_DE_PASSE_SUPERUSER@HOTE:5432/postgres" \
     -v ON_ERROR_STOP=1 -f deploy/01-create-database.sql
```

Le script `deploy/01-create-database.sql` :

1. crée (ou met à jour) le rôle `tombola_app` — `NOSUPERUSER`, `NOCREATEDB`, `NOCREATEROLE`, 40 connexions max ;
2. crée la base `tombola` (`UTF8`, propriétaire `tombola_app`) ;
3. révoque tous les droits de `PUBLIC` sur la base et le schéma `public` ;
4. active `pgcrypto` (UUID) et `pg_trgm` (recherche) ;
5. affiche une vérification finale.

> Le mot de passe généré se trouve dans `.server-db-credentials` à la racine du
> projet (fichier local, non versionné). **Changez-le** et stockez-le dans votre
> gestionnaire de secrets.

### 3.2 Migrations

```bash
docker compose exec api php artisan migrate --force
docker compose exec api php artisan db:seed --force   # rôles, permissions, admin, campagne
```

Les migrations créent aussi les garanties portées par la base elle-même :

- `ticket_batches.payment_id` **UNIQUE** → un paiement = un seul lot de tickets ;
- trigger `tickets_immutability` → un ticket verrouillé est immuable ;
- trigger `tickets_no_delete` → un ticket ne peut pas être supprimé ;
- trigger `draws_freeze` → un tirage exécuté est figé ;
- trigger `draw_entries_no_change` → le snapshot d’un tirage est immuable ;
- trigger `audit_logs_no_update` → le journal d’audit est append-only.

### 3.3 Vérifier les garanties (à faire après le premier déploiement)

```sql
-- Doit lever une exception :
UPDATE tickets SET ticket_number = 'TMB-2026-00000001' WHERE is_locked;

-- Doit lever une exception :
DELETE FROM audit_logs WHERE id = 1;
```

---

## 4. Configuration de l’API

```bash
cp .env.example .env
php artisan key:generate --show    # à coller dans APP_KEY
```

Variables indispensables :

| Variable | Description |
|---|---|
| `APP_KEY` | Clé de chiffrement (générée, jamais partagée) |
| `APP_URL` | URL publique de l’API, **HTTPS** |
| `FRONTEND_URL` | URL publique du frontend |
| `DB_*` | Connexion au rôle `tombola_app` |
| `FUTAYE_CLIENT_ID` | Code application Futaye |
| `FUTAYE_TOKEN` | Token HMAC — **secret serveur**, jamais côté client |
| `REQUIRE_MFA_FOR_STAFF` | **`true` en production** (MFA obligatoire pour le back-office) |
| `NOTIFICATION_CHANNELS` | `email`, `sms`, `whatsapp` |

Génération d’une clé d’application :

```bash
docker compose exec api php artisan key:generate
```

---

## 5. Passerelle de paiement Futaye

### 5.1 Principe d’authentification

Chaque appel `POST /v1/payments` porte trois en-têtes :

```
X-Futaye-Client-Id : ft_cid_xxxxxxxx
X-Futaye-Timestamp : 1710000000            (epoch secondes, ±5 min)
X-Futaye-Signature : HMAC-SHA256(token, timestamp + "." + METHOD + "." + path + "." + body)
```

Le webhook entrant est signé différemment :

```
X-Futaye-Signature = HMAC-SHA256(token, timestamp + "." + body)
```

Le backend vérifie systématiquement :

1. la **signature** (`hash_equals`, comparaison à temps constant) ;
2. la **fenêtre temporelle** (±5 min) → anti-rejeu ;
3. l’**unicité du corps** (`payment_webhooks.payload_hash` UNIQUE) → idempotence ;
4. le **montant** et la **devise** contre la commande en base → toute divergence
   rejette le webhook et crée un événement de sécurité de niveau `critical` ;
5. l’**état du paiement** avant attribution → un paiement déjà confirmé ne
   regénère jamais de tickets.

### 5.2 Déclaration du webhook

Dans l’interface Futaye (`Partenaires → [partenaire] → Applications → [application] → Accès API`) :

```
URL du webhook : https://api.tombola-innossb.cd/api/v1/webhooks/futaye
```

### 5.3 Récupérer le token HMAC de l’application

L’application dédiée au projet a été créée sur la passerelle (partenaire
« Buania Platform ») sous le nom **Tombola Innoss’B**. Son code application et
son token HMAC se lisent dans **Partenaires → application → Accès API**.

> Ces deux valeurs sont des identifiants de production : ne les placez **jamais**
> dans ce dépôt, uniquement dans les variables d’environnement du serveur.

> ⚠️ **Anomalie constatée sur l’interface Futaye** : le clic sur « Afficher le
> token » affiche le message « Token affiché. Ne le partagez pas. » mais **le
> modal contenant le token n’est pas rendu** — la page ne contient aucun
> élément `<div id="modal…">`, seuls les boutons `data-open-modal` existent.
> Le même défaut affecte « Nouvelle application ». Le token doit donc être lu
> par un autre moyen (correctif du template Thymeleaf, ou lecture côté serveur
> Futaye). En attendant, l’environnement de développement utilise les
> identifiants de test publics de la documentation :
> `ft_cid_futaye_demo` et son token de démonstration.

---

## 6. Lancement de la pile

```bash
cp .env.example .env      # renseigner les secrets
docker compose up -d --build
docker compose exec api php artisan migrate --force
docker compose exec api php artisan db:seed --force
```

L’API répond sur `http://127.0.0.1:8000/api/v1/health`, le frontend sur
`http://127.0.0.1:3000`.

---

## 7. Reverse proxy (exemple nginx)

```nginx
# Frontend
server {
    listen 443 ssl http2;
    server_name tombola-innossb.cd;

    add_header Strict-Transport-Security "max-age=63072000; includeSubDomains; preload" always;

    location / {
        proxy_pass http://127.0.0.1:3000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}

# API
server {
    listen 443 ssl http2;
    server_name api.tombola-innossb.cd;

    # Le webhook Futaye ne doit jamais être mis en cache.
    location /api/v1/webhooks/ {
        proxy_pass http://127.0.0.1:8000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    location / {
        proxy_pass http://127.0.0.1:8000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

Côté Cloudflare : activer le WAF, le mode « Full (strict) », le rate limiting sur
`/api/v1/auth/*` et `/api/v1/webhooks/*`, et bloquer les pays hors zone autorisée
si la réglementation l’exige.

---

## 8. Sauvegardes et reprise d’activité

### 8.1 Sauvegarde quotidienne

```bash
#!/usr/bin/env bash
# /etc/cron.daily/tombola-backup
set -euo pipefail

set -a; source /opt/tombola/.env; set +a
STAMP=$(date +%F_%H%M)
TARGET=/var/backups/tombola

mkdir -p "$TARGET"
docker compose -f /opt/tombola/docker-compose.yml exec -T postgres \
  pg_dump -U "$DB_USERNAME" -d "$DB_DATABASE" --format=custom --no-owner \
  > "$TARGET/tombola_$STAMP.dump"

gpg --batch --yes --encrypt --recipient ops@tombola-innossb.cd "$TARGET/tombola_$STAMP.dump"
rm -f "$TARGET/tombola_$STAMP.dump"

# Rétention : 30 quotidiennes, 12 hebdomadaires, 12 mensuelles (copie hors site).
find "$TARGET" -name '*.gpg' -mtime +30 -delete
rclone copy "$TARGET" remote:tombola-backups --max-age 400d
```

### 8.2 Objectifs de reprise

| Indicateur | Valeur cible |
|---|---|
| **RPO** (perte de données max.) | 5 minutes (archivage WAL + sauvegarde quotidienne) |
| **RTO** (temps de reprise) | 2 heures |
| Responsable | Équipe infrastructure |
| Test de restauration | Trimestriel, sur un environnement isolé |

### 8.3 Restauration

```bash
docker compose exec -T postgres pg_restore -U tombola_app -d tombola --clean --if-exists < tombola_2026-09-13.dump
docker compose exec api php artisan migrate --force
```

---

## 9. Supervision et alertes

À surveiller : CPU, RAM, disque, latence API, taux d’erreurs 5xx, files d’attente,
connexions à la base, **paiements échoués** et **webhooks non traités**.

Alertes recommandées :

```
🚨 > 20 paiements échoués en 10 minutes
🚨 webhook rejeté (signature ou montant)                → critique
🚨 > 100 tentatives de connexion depuis une même IP
🚨 file d'attente > 500 jobs
🚨 disque > 85 %
🚨 sauvegarde quotidienne absente
```

Endpoints de supervision :

- `GET /api/v1/health` — état du service et de la base ;
- `GET /api/v1/admin/dashboard` — indicateurs métier ;
- `GET /api/v1/admin/audit-logs/verify` — intégrité de la chaîne d’audit.

---

## 9 bis. Outils d'exploitation

| Commande | Usage |
|---|---|
| `php artisan tombola:expire-orders` | ferme les commandes non payées, libère les tickets réservés |
| `php artisan tombola:reconcile-payments` | interroge la passerelle pour les paiements restés en attente (webhook perdu) |
| `php artisan tombola:verify-audit-chain` | recalcule la chaîne d'audit ; toute rupture doit déclencher une alerte |
| `php artisan tombola:reset-mfa {email} --force` | débloque un membre du personnel ayant perdu son téléphone |
| `php artisan tombola:futaye-check [--payment]` | diagnostique la configuration et la signature de la passerelle |
| `php artisan tombola:demo-reset` | remet le jeu de démonstration en vente — **refusé en production** |

`tombola:reset-mfa` exige un accès shell (niveau de privilège adéquat), écrit un
événement de sécurité `mfa_reset_by_operator` de sévérité **high** et une entrée
d'audit `MFA_RESET` : un détournement de compte reste ainsi détectable.

Le contrôle des quatre yeux sur les remboursements s'active automatiquement dès
qu'un second membre du personnel détient le rôle `finance` ou `super_admin`.
Tant qu'un seul approbateur existe, l'auto-approbation reste possible afin de ne
pas bloquer une équipe réduite.

## 9 ter. Déployer sur Coolify

Coolify choisit par défaut un **build pack automatique** (Railpack / Nixpacks)
qui déduit le langage depuis les fichiers présents. Sur un backend Laravel, cette
détection se trompe régulièrement.

### 9 ter.1 Symptôme observé

```
railpack prepare ... → INFO No package manager inferred, using npm default
Deployment failed: Command execution failed (exit code 1)
```

Railpack a vu un `package.json` (le squelette Laravel en embarque un pour Vite)
et a tenté un build **Node** au lieu d'un build **PHP**. Résultat : échec.

### 9 ter.2 Backend — réglages Coolify

| Réglage | Valeur |
|---|---|
| **Build pack** | **`Dockerfile`** (et non Railpack / Nixpacks) |
| Dockerfile location | `/Dockerfile` |
| Port exposé | `8000` |
| Health check path | `/api/v1/health` |
| Domaine | `https://api.tombola-innossb.cd` |

> Le `package.json` et le `vite.config.js` du squelette Laravel ont été **retirés
> du dépôt** : cette application est une API pure, sans pipeline d'assets. La
> détection automatique retombe donc sur PHP. Le build pack `Dockerfile` reste
> néanmoins **obligatoire**, car l'image installe des extensions PHP que la
> détection automatique ne prévoit pas — notamment `redis`, sans laquelle toute
> route à session échoue en « Class "Redis" not found ».

### 9 ter.3 Backend — variables d'environnement

```
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:...                      # php artisan key:generate --show
APP_URL=https://api.tombola-innossb.cd
FRONTEND_URL=https://tombola-innossb.cd
ADMIN_URL=https://tombola-innossb.cd/admin

DB_CONNECTION=pgsql
DB_HOST=<hôte interne fourni par Coolify>
DB_PORT=5432
DB_DATABASE=tombola
DB_USERNAME=tombola_app
DB_PASSWORD=<mot de passe fort>

CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
REDIS_HOST=<hôte interne fourni par Coolify>
REDIS_PORT=6379

REQUIRE_MFA_FOR_STAFF=true
FUTAYE_BASE_URL=https://futaye.buania.com
FUTAYE_CLIENT_ID=<code application>
FUTAYE_TOKEN=<token HMAC — secret>

NOTIFICATION_CHANNELS=email
MAIL_MAILER=smtp                       # ou `log` pour une démonstration
MAIL_HOST=...
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS=no-reply@tombola-innossb.cd
```

Deux points importants :

- **Les migrations s'exécutent automatiquement** au démarrage du conteneur
  (`php artisan migrate --force` dans la commande de démarrage).
- **Le seeding reste manuel.** Après le premier déploiement :
  ```
  php artisan db:seed --force
  ```
  Hors environnement local, le seeder exige `TOMBOLA_ADMIN_PASSWORD` ou génère
  un mot de passe aléatoire affiché **une seule fois** — notez-le.
- La commande de démarrage exécute `config:cache` : **modifier une variable
  d'environnement impose de redémarrer le conteneur**, pas seulement de la
  changer dans l'interface.

### 9 ter.4 Frontend — réglages Coolify

| Réglage | Valeur |
|---|---|
| **Build pack** | `Dockerfile` |
| Dockerfile location | `/Dockerfile` |
| Port exposé | `3000` |
| Domaine | `https://tombola-innossb.cd` |

⚠️ **Les variables `NEXT_PUBLIC_*` sont compilées dans le bundle.** Dans Coolify,
elles doivent être déclarées comme **arguments de build**, et non comme simples
variables d'exécution :

```
NEXT_PUBLIC_API_URL=https://api.tombola-innossb.cd
NEXT_PUBLIC_SITE_URL=https://tombola-innossb.cd
```

Toute modification de ces deux valeurs impose un **redéploiement avec
reconstruction** de l'image.

### 9 ter.5 Ordre de déploiement

1. créer la base PostgreSQL dans Coolify, puis relever son hôte interne ;
2. créer la base et le rôle applicatif (voir §3) ;
3. déployer le **backend** (build pack Dockerfile), renseigner les variables,
   puis lancer `db:seed` une fois ;
4. vérifier `https://api.…/api/v1/health` ;
5. déployer le **frontend** avec les bons arguments de build ;
6. vérifier `https://tombola-…` puis l'inscription et un achat de test.

---

## 10. Tâches planifiées

Le service `scheduler` exécute `php artisan schedule:run` chaque minute. Il doit :

- fermer / expirer les commandes non payées ;
- réconcilier les paiements restés en attente (webhook perdu) ;
- relancer les notifications en échec ;
- purger les codes OTP expirés.

---

## 11. Checklist de mise en production

- [ ] `APP_DEBUG=false` et `APP_ENV=production`
- [ ] `APP_KEY` générée et sauvegardée hors du serveur
- [ ] HTTPS forcé, HSTS actif, certificats valides
- [ ] `REQUIRE_MFA_FOR_STAFF=true` et MFA activé sur **tous** les comptes internes
- [ ] Mot de passe du super administrateur changé après le seeding
- [ ] Token Futaye en variable d’environnement, jamais dans le dépôt
- [ ] Webhook Futaye déclaré et testé de bout en bout (paiement réel de faible montant)
- [ ] Rôle `postgres` **non utilisé** par l’application ; accès distant restreint
- [ ] Sauvegardes chiffrées automatiques + test de restauration effectué
- [ ] Alertes configurées (paiements, webhooks, sécurité, disque)
- [ ] Journaux d’audit consultés : `GET /api/v1/admin/audit-logs/verify`
- [ ] Conditions de participation **validées par un conseil juridique**
- [ ] Tests de charge exécutés sur le pic d’ouverture des ventes
- [ ] Audit de sécurité externe (pentest) avant l’annonce publique
