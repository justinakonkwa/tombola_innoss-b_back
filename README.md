# Tombola Innoss'B — API

Backend de la plateforme **Tombola Innoss'B** : authentification, RBAC, campagnes,
lots, tickets, commandes, paiements Mobile Money / RDV / carte via la passerelle
**Futaye**, tirage au sort vérifiable et journal d'audit chaîné.

> Frontend associé : [`tombola_innoss-b_front`](https://github.com/justinakonkwa/tombola_innoss-b_front)

**Laravel 10 · PHP 8.1 · PostgreSQL 14+ · Redis · API REST versionnée `/api/v1`**

---

## Les garanties structurantes

Ces règles ne sont pas seulement dans le code : elles sont **portées par la base
de données**, donc impossibles à contourner même par un accès SQL direct.

| Garantie | Mécanisme |
|---|---|
| Un paiement = **un seul** lot de tickets | `ticket_batches.payment_id` **UNIQUE** + verrou `SELECT … FOR UPDATE` |
| Aucun ticket sans paiement confirmé | `TicketService::issueForPayment()` refuse tout statut ≠ `success` |
| Un ticket émis est **immuable** | trigger `tickets_immutability` (numéro, propriétaire, campagne, QR) |
| Un tirage exécuté est **figé** | trigger `draws_freeze` + snapshot `draw_entries` immuable |
| Le journal d'audit est **inaltérable** | trigger `audit_logs_no_update` + chaîne de hachage |

---

## Démarrage rapide

### 1. Base de données

L'application n'utilise **jamais** le compte `postgres`. Elle dispose d'un rôle
dédié, propriétaire de sa seule base.

```bash
psql "postgresql://postgres:MOT_DE_PASSE@HOTE:5432/postgres" \
     -v ON_ERROR_STOP=1 -f deploy/01-create-database.sql
```

Le script crée le rôle `tombola_app` (`NOSUPERUSER`, `NOCREATEDB`, 40 connexions
max), la base `tombola`, révoque les droits de `PUBLIC`, et active `pgcrypto` et
`pg_trgm`. Voir [`deploy/README.md`](deploy/README.md) pour le détail, notamment
le cloisonnement si l'instance est partagée avec une autre application.

### 2. API

```bash
composer install
cp .env.example .env
php artisan key:generate
# renseigner DB_*, FUTAYE_CLIENT_ID, FUTAYE_TOKEN
php artisan migrate --force
php artisan db:seed --force
php artisan serve --port=8000
```

Vérification : `http://localhost:8000/api/v1/health` → `{"status":"ok","database":"up"}`

### 3. Docker

```bash
cp .env.example .env      # renseigner les secrets
docker compose up -d --build
docker compose exec api php artisan migrate --force
docker compose exec api php artisan db:seed --force
```

La pile démarre PostgreSQL, Redis, l'API, un worker de files et un planificateur.

---

## Configuration

| Variable | Rôle |
|---|---|
| `APP_KEY` | Clé de chiffrement — `php artisan key:generate --show` |
| `APP_URL` | URL publique de l'API, **HTTPS** |
| `FRONTEND_URL` | URL publique du frontend (liens des e-mails, CORS) |
| `DB_*` | Connexion au rôle `tombola_app` |
| `FUTAYE_CLIENT_ID` | Code application de la passerelle |
| `FUTAYE_TOKEN` | **Token HMAC — secret serveur, jamais exposé au client** |
| `TOMBOLA_ADMIN_EMAIL` / `TOMBOLA_ADMIN_PASSWORD` | Compte administrateur initial |
| `REQUIRE_MFA_FOR_STAFF` | **`true` en production** (obligatoire) |
| `NOTIFICATION_CHANNELS` | `email`, `sms`, `whatsapp`, `push` |

Le fichier [`​.env.production.example`](.env.production.example) liste **toutes**
les variables, classées par criticité (obligatoire / recommandé / optionnel),
avec les pièges à éviter. C'est la référence à recopier dans Coolify.

### Compte administrateur

Hors environnement local, **aucun mot de passe par défaut n'est utilisé**. Le
seeder exige `TOMBOLA_ADMIN_PASSWORD` (12 caractères minimum) ou génère un mot de
passe aléatoire de 24 caractères, affiché **une seule fois**.

---

## API

90 routes sous `/api/v1`. Les réponses sont enveloppées (`{"data": …}`) et les
listes paginées (`data`, `meta`, `links`). Toutes les erreurs sont du JSON, y
compris sans en-tête `Accept`.

| Groupe | Routes |
|---|---|
| Public | `/campaigns`, `/campaigns/{slug}`, `/campaigns/{slug}/stats`, `/winners`, `/faq`, `/settings/public`, `/draws/{reference}/verify`, `/health` |
| Auth | `/auth/register`, `/auth/login`, `/auth/otp/*`, `/auth/refresh`, `/auth/logout*`, `/auth/mfa/*` |
| Participant | `/me`, `/me/dashboard`, `/me/tickets`, `/me/orders`, `/me/payments`, `/me/notifications`, `/me/sessions` |
| Achat | `POST /orders`, `GET /orders/{reference}`, `POST /orders/{reference}/pay`, `POST /orders/{reference}/cancel`, `GET /payments/{id}/status` |
| Webhook | `POST /webhooks/futaye` |
| Back-office | `/admin/*` — tableau de bord, campagnes, lots, tickets, participants, paiements, remboursements, tirages, gagnants, notifications, utilisateurs, rôles, audit, sécurité, paramètres |

Le back-office cumule `auth:sanctum` → `challenge.guard` → `staff` → `mfa` →
une permission fine par action.

---

## Paiements Futaye

Authentification par HMAC-SHA256, vérifiée dans les deux sens.

```
Sortant :  HMAC-SHA256(token, timestamp + "." + METHOD + "." + path + "." + body)
Webhook :  HMAC-SHA256(token, timestamp + "." + body)
```

Contrôles appliqués à chaque webhook, dans cet ordre :

1. **signature** (`hash_equals`, temps constant) ;
2. **fenêtre temporelle** ±5 min → anti-rejeu ;
3. **unicité du corps** (`payment_webhooks.payload_hash`) → idempotence ;
4. **montant et devise** comparés à la commande → divergence = rejet + événement
   de sécurité `critical` ;
5. **état du paiement** → un paiement déjà confirmé ne régénère jamais de tickets.

Un webhook perdu est rattrapé par `tombola:reconcile-payments`, qui interroge la
passerelle.

Diagnostic de l'intégration :

```bash
php artisan tombola:futaye-check            # config, signature, joignabilité
php artisan tombola:futaye-check --payment  # crée une vraie session de test
```

---

## Tirage vérifiable (commit-reveal)

1. à la création du tirage, le serveur tire `server_seed` et **publie
   immédiatement** son engagement `server_seed_hash = SHA-256(server_seed)` ;
2. les ventes sont fermées, puis le pool des tickets éligibles est **figé** dans
   `draw_entries` (immuable) et empreinté (`ticket_pool_hash`) ;
3. le tirage combine un aléa **public** `client_seed` et le `server_seed` :
   `index = HMAC-SHA256(server_seed, client_seed:rang:compteur) mod N`, avec
   **échantillonnage par rejet** pour éliminer le biais de modulo ;
4. le `server_seed` est **révélé** après exécution : chacun peut recalculer le
   résultat et vérifier l'engagement initial.

```
GET /api/v1/draws/DRAW-2026-001/verify
→ commitment_valid, pool_hash_valid, winners_valid
```

---

## Sécurité

- Mots de passe **Argon2id**, jamais en clair.
- Access token courte durée + **refresh token tournant**, haché en base, révocable.
- **MFA TOTP obligatoire** pour tout le back-office. Un jeton de défi MFA ne donne
  accès à **rien** d'autre qu'à la résolution du défi (invariant absolu, testé).
- RBAC : 6 rôles, 25 permissions, contrôle serveur sur chaque ressource.
- Anti-énumération : identifiants UUID, messages de connexion génériques.
- Verrouillage après 5 échecs, limitation de fréquence sur toutes les routes sensibles.
- Journal d'audit **chaîné par hachage** et append-only.
- Aucun secret côté client : le token HMAC reste serveur.

Détail complet du modèle de menaces : [`docs/SECURITY.md`](docs/SECURITY.md)

---

## Ports

Tout est configurable par variable d'environnement, sans modifier les fichiers.

| Variable | Défaut | Rôle |
|---|---|---|
| `API_BIND` | `127.0.0.1` | Adresse d'écoute côté hôte. `127.0.0.1` = uniquement via un reverse proxy (recommandé) ; `0.0.0.0` = exposition directe |
| `API_PORT` | `8000` | Port publié sur la machine hôte |
| `API_CONTAINER_PORT` | `8000` | Port d'écoute interne au conteneur (le healthcheck s'y adapte) |

Exemple : servir l'API sur le port 8080 et la faire écouter sur toutes les
interfaces.

```bash
API_BIND=0.0.0.0 API_PORT=8080 docker compose up -d
```

PostgreSQL et Redis ne sont **jamais** publiés : ils restent sur le réseau
interne. Pour inspecter la base, utilisez le profil de diagnostic :

```bash
export PGADMIN_PASSWORD='un-mot-de-passe-solide'
docker compose -f docker-compose.yml -f docker-compose.debug.yml --profile debug up -d
# → http://127.0.0.1:5050
```

---

## Tests et vérifications

```bash
php artisan test        # 55 tests, 314 assertions
```

Les tests couvrent les règles critiques : aucun ticket sans paiement confirmé,
idempotence du double webhook, montant recalculé côté serveur, immuabilité d'un
ticket verrouillé, tirage figé et reproductible, cloisonnement RBAC/MFA, et
séparation des tâches sur les remboursements.

Scripts de vérification de bout en bout (API démarrée) :

```bash
./scripts/smoke-test.sh http://localhost:8000   # achat → webhook signé → tickets
./scripts/draw-e2e.sh   http://localhost:8000   # tirage complet → vérification publique
./scripts/mfa-e2e.sh    http://localhost:8000   # parcours TOTP et RBAC
./scripts/payment-e2e.sh http://localhost:8000   # paiement complet via la passerelle réelle
```

---

## Outils d'exploitation

| Commande | Rôle |
|---|---|
| `tombola:expire-orders` | ferme les commandes non payées, libère les tickets réservés |
| `tombola:reconcile-payments` | rattrape les webhooks perdus |
| `tombola:verify-audit-chain` | vérifie l'intégrité de la chaîne d'audit |
| `tombola:reset-mfa {email}` | débloque un membre du personnel ayant perdu son téléphone |
| `tombola:futaye-check` | diagnostique la passerelle |
| `tombola:demo-reset` | remet le jeu de démonstration en vente (refusé en production) |

Les trois premières sont planifiées automatiquement.

---

## Structure

```
app/
├── Enums/         19 enums métier typés
├── Models/        23 modèles Eloquent (UUID)
├── Services/      toute la logique métier
├── Http/          contrôleurs, middlewares, resources
└── Console/       commandes et planification
database/
├── migrations/    schéma + triggers de protection + séquences
├── seeders/       rôles, permissions, paramètres, campagne de démonstration
└── factories/
docs/              conventions, déploiement, sécurité, cahier des charges
deploy/            scripts SQL de provisionnement
scripts/           vérifications de bout en bout
```

---

## Conformité

Le dispositif doit être validé par un conseil juridique compétent avant toute
mise en production : qualification juridique de la tombola, autorisations,
fiscalité, traitement des données personnelles, conditions de remise du véhicule,
âge minimum, restrictions géographiques et remboursements.
