# Tombola Innoss’B

Plateforme web complète pour la tombola autour de la Lamborghini d’Innoss’B :
achat de tickets numériques, paiements Mobile Money / RDV / carte bancaire via
la passerelle **Futaye**, attribution automatique des tickets après confirmation
du paiement, tirage au sort **vérifiable** et back-office d’administration complet.

> **Implémentation du cahier des charges** — chaque règle critique est appliquée
> côté serveur **et** garantie par la base de données, pas seulement par l’interface.

---

## Sommaire

- [Architecture](#architecture)
- [Les quatre piliers](#les-quatre-piliers)
- [Démarrage rapide](#démarrage-rapide)
- [Structure du dépôt](#structure-du-dépôt)
- [Modèle de données](#modèle-de-données)
- [API](#api)
- [Paiements](#paiements)
- [Tirage vérifiable](#tirage-vérifiable)
- [Sécurité](#sécurité)
- [Tests](#tests)
- [Documentation](#documentation)

---

## Architecture

| Brique | Technologie | Dossier |
|---|---|---|
| Frontend (public + back-office, PWA) | Next.js 14, TypeScript, Tailwind CSS | `web/` |
| API REST versionnée | Laravel 10, PHP 8.1 | `api/` |
| Base de données | PostgreSQL 14+ | `api/database/migrations` |
| Cache, files, sessions | Redis | `docker-compose.yml` |
| Paiements | Passerelle Futaye (Mobile Money, RDV, cartes) | `api/app/Services/FutayeClient.php` |

```
tombola/
├── api/                    # Laravel 10 — API /api/v1
│   ├── app/Enums/          # 19 enums métier typés
│   ├── app/Models/         # 23 modèles Eloquent
│   ├── app/Services/       # toute la logique métier
│   ├── app/Http/           # contrôleurs, middlewares, resources
│   └── database/migrations # 6 migrations métier + triggers de protection
├── web/                    # Next.js 14 — site public + back-office
│   └── src/
│       ├── app/(site)/     # pages publiques et espace participant
│       ├── app/(admin)/    # back-office
│       ├── lib/            # client API, types, formatage, auth
│       └── components/     # design system et composants métier
├── deploy/                 # scripts SQL de provisionnement et durcissement
├── docs/                   # conventions, déploiement, sécurité
└── docker-compose.yml      # pile complète auto-hébergeable
```

---

## Les quatre piliers

### 🎨 Design premium

Direction artistique « luxe et événement » : fond nuit profond, accents or,
typographie éditoriale, hero dominé par le véhicule, animations discrètes.
Mobile-first, PWA installable, contrastes et navigation clavier vérifiés.

### 🔐 Sécurité maximale

- Mots de passe **Argon2id**, jamais stockés en clair.
- Access token courte durée + **refresh token tournant**, haché en base, révocable.
- **MFA TOTP obligatoire** pour tous les comptes du back-office.
- RBAC à 6 rôles et 25 permissions, vérifié par middleware **et** au niveau des requêtes.
- Anti-énumération : messages de connexion génériques, identifiants UUID.
- Verrouillage temporaire après 5 échecs, limitation de fréquence sur toutes les routes sensibles.
- Journal d’audit **chaîné par hachage** et append-only (trigger PostgreSQL).
- Aucun secret dans le frontend : le token HMAC de la passerelle reste serveur.

### 💳 Paiement fiable

Un paiement confirmé = une participation. Un paiement non confirmé = **aucun ticket**.

- Le montant est toujours recalculé côté serveur ; un prix envoyé par le client est ignoré.
- Webhooks authentifiés par **HMAC-SHA256**, protégés contre le rejeu (horodatage ±5 min
  + empreinte unique du corps) et **idempotents**.
- Montant ou devise divergents → webhook rejeté + événement de sécurité critique.
- `ticket_batches.payment_id` **UNIQUE** : un paiement ne peut produire qu’un seul lot,
  même en cas de double webhook, de retry ou de réconciliation concurrente.
- Filet de sécurité : réconciliation active auprès de la passerelle si un webhook est perdu.

### 🎲 Tirage transparent et vérifiable

Schéma **commit-reveal** :

1. à la création du tirage, le serveur tire `server_seed` et **publie immédiatement**
   son engagement `server_seed_hash = SHA-256(server_seed)` ;
2. les ventes sont fermées puis le pool des tickets éligibles est **figé** dans
   `draw_entries` (immuable par trigger) et empreinté (`ticket_pool_hash`) ;
3. le tirage combine un aléa **public** `client_seed` et le `server_seed` :
   `index = HMAC-SHA256(server_seed, client_seed:rang:compteur) mod N`,
   avec **échantillonnage par rejet** pour éliminer tout biais de modulo ;
4. le `server_seed` est **révélé** après exécution : n’importe qui peut recalculer
   le résultat et vérifier que le hash publié correspond à l’engagement initial.

`GET /api/v1/draws/{reference}/verify` rejoue le calcul et renvoie trois verdicts :
engagement valide, empreinte du pool valide, gagnants valides.

---

## Démarrage rapide

### Prérequis

- PHP 8.1+, Composer 2, PostgreSQL 14+
- Node.js 20+
- (optionnel) Docker et Docker Compose

### 1. Base de données

```bash
# Local : créer le rôle et la base
psql -d postgres -f deploy/01-create-database.sql
```

### 2. API

```bash
cd api
composer install
cp .env.example .env
php artisan key:generate
# renseigner DB_*, FUTAYE_CLIENT_ID, FUTAYE_TOKEN
php artisan migrate --force
php artisan db:seed --force
php artisan serve --port=8000
```

Comptes créés par le seeding :

| Rôle | Identifiant | Mot de passe |
|---|---|---|
| Super administrateur | `admin@tombola-innossb.cd` | `Tombola++2026*` (à changer) |

> **Production** : `REQUIRE_MFA_FOR_STAFF=true` et MFA activé sur chaque compte interne.
> En développement il est à `false` pour ne pas bloquer le back-office.

### 3. Frontend

```bash
cd web
npm install
NEXT_PUBLIC_API_URL=http://localhost:8000 npm run dev
```

Le site est disponible sur <http://localhost:3000>.

### 4. Pile Docker complète

```bash
cp .env.example .env      # renseigner les secrets
docker compose up -d --build
docker compose exec api php artisan migrate --force
docker compose exec api php artisan db:seed --force
```

Voir [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) pour la mise en production.

---

## Modèle de données

32 tables, réparties en six domaines.

| Domaine | Tables |
|---|---|
| Identité & accès | `users`, `roles`, `permissions`, `role_permissions`, `user_roles`, `devices`, `user_sessions`, `otp_codes` |
| Offre | `campaigns`, `prizes` |
| Commerce | `orders`, `payments`, `payment_attempts`, `payment_webhooks`, `refunds` |
| Tickets | `ticket_batches`, `tickets` |
| Tirage | `draws`, `draw_entries`, `winners` |
| Exploitation | `notifications`, `audit_logs`, `security_events`, `settings`, `risk_assessments` |

### Garanties portées par la base

| Objet | Effet |
|---|---|
| `ticket_batches.payment_id` UNIQUE | un paiement = un seul lot de tickets |
| `tickets_immutability` (trigger) | numéro, propriétaire, campagne, commande, paiement et QR d’un ticket verrouillé sont immuables |
| `tickets_no_delete` (trigger) | un ticket ne peut jamais être supprimé (annulation/remboursement uniquement) |
| `draws_freeze` (trigger) | graines et résultat d’un tirage exécuté sont figés |
| `draw_entries_no_change` (trigger) | le snapshot d’un tirage est immuable |
| `audit_logs_no_update` (trigger) | le journal d’audit est append-only |
| `tickets_serial_seq` | numérotation `TMB-AAAA-NNNNNNNN` non devinable |
| Champs `status` + `CHECK` | transitions d’état invalides impossibles |

---

## API

API versionnée sous `/api/v1`.

| Groupe | Routes principales |
|---|---|
| Public | `GET /campaigns`, `/campaigns/{slug}`, `/campaigns/{slug}/stats`, `/winners`, `/faq`, `/settings/public`, `/draws/{reference}/verify`, `/health` |
| Auth | `POST /auth/register`, `/auth/login`, `/auth/otp/request`, `/auth/otp/verify`, `/auth/refresh`, `/auth/logout`, `/auth/logout-all`, `/auth/mfa/*` |
| Participant | `GET /me`, `/me/dashboard`, `/me/tickets`, `/me/orders`, `/me/payments`, `/me/notifications`, `/me/sessions` |
| Achat | `POST /orders`, `GET /orders/{reference}`, `POST /orders/{reference}/pay`, `POST /orders/{reference}/cancel`, `GET /payments/{id}/status` |
| Webhook | `POST /webhooks/futaye` |
| Back-office | `/admin/*` — tableau de bord, campagnes, lots, tickets, participants, paiements, remboursements, tirages, gagnants, notifications, utilisateurs, rôles, audit, sécurité, paramètres |

Le back-office cumule `auth:sanctum` + `staff` + `mfa` + une permission fine par action.

---

## Paiements

Passerelle **Futaye** (`https://futaye.buania.com`).

```
Utilisateur → Frontend → API → Futaye → Opérateur bancaire
                                   ↓
                               Webhook signé
                                   ↓
                    Vérification (signature, montant, devise, unicité)
                                   ↓
                          Transaction CONFIRMED
                                   ↓
                            Création des tickets
```

| Moyen | Canal | Comportement |
|---|---|---|
| Mobile Money (Airtel, M-Pesa, Orange, Africell) | `mobile_money` | Push USSD immédiat si le numéro est fourni |
| Réseau local RDV | `rdv` | Traitement local |
| Carte bancaire (Visa / Mastercard) | `card` | Checkout hébergé — **aucun PAN stocké** |

---

## Tests

```bash
cd api
php artisan test
```

Couverture des règles critiques :

- aucun ticket sans paiement confirmé ;
- un paiement ne génère qu’un seul lot, même en double webhook ;
- le prix est recalculé côté serveur, une valeur client est ignorée ;
- un ticket validé est immuable (trigger) ;
- un tirage exécuté est figé et son résultat est reproductible ;
- un participant ne peut pas atteindre le back-office ; le personnel sans MFA est refusé.

```bash
cd web
npx tsc --noEmit     # vérification des types
npm run build        # build de production
```

---

## Vérifications effectuées

Toutes les preuves ci-dessous ont été produites en exécutant réellement le
système, pas seulement en relisant le code.

| Vérification | Commande | Résultat |
|---|---|---|
| Tests unitaires et d’intégration | `cd api && php artisan test` | **55 tests, 314 assertions, 0 échec** |
| Parcours d’achat de bout en bout | `./scripts/smoke-test.sh` | **tous les contrôles passés** |
| Paiement via la passerelle réelle | `./scripts/payment-e2e.sh` | **checkout Futaye créé, webhook, tickets, idempotence** |
| Déploiement Coolify | corrigé et documenté | `docs/DEPLOYMENT.md` §9 ter |
| Tirage au sort de bout en bout | `./scripts/draw-e2e.sh` | **42 tickets figés, 26 gagnants, tirage vérifié** |
| Intégration réelle de la passerelle | `php artisan tombola:futaye-check --payment` | **session de paiement créée sur futaye.buania.com** |
| Détection d’altération de l’audit | sabotage contrôlé d’une entrée | **chaîne signalée comme rompue** |
| Types du frontend | `cd web && npx tsc --noEmit` | **0 erreur** |
| Build de production | `cd web && npm run build` | **41 routes compilées, 0 erreur** |
| Parcours MFA (TOTP) | `./scripts/mfa-e2e.sh` | **9 étapes validées, 0 échec** |
| Remboursements et quatre yeux | tests `RefundControlTest` + API réelle | **garde-fous actifs** |
| Rendu des pages | `curl` sur les 13 pages publiques + 15 pages admin | **HTTP 200, 0 erreur applicative** |
| PWA et SEO technique | `curl` sur manifest, service worker, robots, sitemap | **HTTP 200, types corrects** |
| Images Docker | `docker build` + piles démarrées | **API healthy sur 8000, frontend 200 sur 3000** |
| Ports configurables | `API_PORT=8080 API_CONTAINER_PORT=9000` | **mapping et healthcheck suivent** |
| File d'attente Redis | inscription → job → worker | **job consommé, cache Redis opérationnel** |

### Ce que le test de fumée prouve

`scripts/smoke-test.sh` déroule le parcours critique sur une API démarrée :

```
inscription → catalogue → commande (3 tickets) → paiement
   → webhook signé HMAC → tickets
```

et vérifie explicitement :

- le montant total est calculé **par le serveur** (3 × 5,00 = 15,00) ;
- rejouer la même requête avec la même clé d’idempotence ne crée **pas** de seconde commande ;
- une signature de webhook invalide est **rejetée** (HTTP 401) ;
- rejouer le même webhook est détecté comme **doublon** et ne génère aucun ticket ;
- exactement **3 tickets** sont émis, au format `TMB-2026-NNNNNNNN` ;
- **un seul lot** de tickets existe pour le paiement.

### Ce que le test de tirage prouve

`scripts/draw-e2e.sh` va jusqu’à la publication :

```
achat (30 tickets) → paiement confirmé → création du tirage (engagement publié)
  → fermeture des ventes → gel du pool → exécution (graine publique)
  → publication (26 gagnants) → vérification publique
```

puis vérifie que `GET /api/v1/draws/{reference}/verify` recalcule et confirme
les trois preuves :

```
commitment_valid = true    (l'engagement SHA-256 correspond à la graine révélée)
pool_hash_valid  = true    (l'empreinte du pool figé est intacte)
winners_valid    = true    (les gagnants se recalculent depuis les deux graines)
```

et que la page publique des gagnants expose bien des **identités masquées**
(`T***** T***`) avec la date du tirage.

### Ce que la vérification MFA prouve

`scripts/mfa-e2e.sh` exécute le cycle complet de la double authentification :

```
enrôlement (secret + URI otpauth) → code erroné refusé → code TOTP validé
  → nouvelle connexion : défi exigé
  → le jeton de défi ne donne accès à RIEN
  → résolution du défi → accès au back-office
  → jeton de défi à usage unique
  → désactivation et restauration de l'état
```

Le script restaure systématiquement l'état initial (2FA désactivée) pour rester
compatible avec les autres scripts de vérification.

### Bugs réels trouvés et corrigés pendant la vérification

| Bug | Impact | Correctif |
|---|---|---|
| `personal_access_tokens.tokenable_id` en `bigint` alors que les utilisateurs sont en UUID | **inscription impossible** | `uuidMorphs('tokenable')` |
| `draws.algorithm` en `varchar(64)` pour une description de 75 caractères | **tirage impossible** | migration d’élargissement à 160 |
| `PermissionName` non importé dans `User.php` | **`GET /me` et `/admin/draws` en erreur 500** pour tout compte staff | import ajouté |
| Empreinte d’audit calculée sur un `json_encode` brut | `jsonb` réordonne les clés → **la chaîne d’audit ne se vérifiait plus** | canonicalisation JSON (tri récursif des clés) |
| `AuditService::verifyChain()` renvoyait `valid: true` en dur | **une altération passait inaperçue** | suivi réel de l’état, `broken_at` renseigné |
| Webhook à signature invalide sur un corps déjà traité | violation d’unicité → **HTTP 500 au lieu de 401** | empreintes de rejet dans un espace distinct |
| `config/hashing.php` en `bcrypt` alors que la politique exige Argon2id | affaiblissement du stockage des mots de passe | `env('HASH_DRIVER', 'argon2id')` |
| `GET /admin/winners/{id}` appelé par le back-office mais absent | volet de détail gagnant cassé | route + `show()` ajoutés |
| **Le jeton de défi MFA ouvrait le back-office et toutes les routes authentifiées** | une authentification partielle (mot de passe seul) valait authentification complète : la 2FA était décorative | middleware `RejectMfaChallenge` sur tous les groupes authentifiés + invariant absolu dans `EnsureMfaVerified` |
| Requête API sans en-tête `Accept` → **page HTML 500** au lieu d'un 401 (`Route [login] not defined`) | le middleware d'authentification de Laravel tentait de rediriger vers une route web inexistante | `shouldReturnJson()` sur `/api/*`, `App\Http\Middleware\Authenticate::redirectTo()` → `null`, `ForceJsonResponse` en tête du groupe |
| Le demandeur d'un remboursement pouvait l'approuver lui-même | absence de séparation des tâches sur une opération financière | contrôle des quatre yeux, appliqué dès qu'un second approbateur existe |
| **L'image Docker n'installait pas l'extension PHP `redis`** | `Class "Redis" not found` : toute route à session en erreur 500, et les files d'attente inopérantes | `pecl install redis` ajouté au Dockerfile |
| **Les fichiers copiés dans l'image étaient illisibles par l'utilisateur applicatif** | mode 600 + propriétaire `root` → `php artisan migrate` échouait au démarrage du conteneur | `COPY --chown=app:app` + `chmod -R u+rwX` |
| `worker` et `scheduler` héritaient du healthcheck HTTP de l'image | conteneurs marqués *unhealthy* en permanence alors qu'ils ne servent pas de HTTP | healthcheck désactivé pour ces deux services |
| Le service de debug optionnel bloquait `docker compose up` | l'interpolation `:?` s'applique même derrière un `profiles:` | service déplacé dans `docker-compose.debug.yml` |
| `GET /` renvoyait une erreur 500 | la page d'accueil Laravel appelle `route('login')`, inexistante dans une API | API pure : la racine renvoie un repère de service JSON |
| `package.json` / `vite.config.js` hérités du squelette | Railpack détectait Node et faisait échouer le déploiement Coolify | résidus retirés (l'application n'a pas de pipeline d'assets) |
| La réponse d'inscription omettait email et téléphone | le client devait appeler `/me` juste après pour connaître son profil | le propriétaire du compte reçoit ses propres coordonnées ; le test de minimisation couvre désormais le cas *tiers* |
| **`latestOfMany()` sur une clé UUID** | PostgreSQL n'a pas de `max(uuid)` → `GET /me/orders` et le checkout renvoyaient 500 : « Mes commandes » et le tunnel de paiement étaient inutilisables | relation `hasOne` ordonnée ; 6 tests de régression vérifiés comme détectant bien le bug |
| La transaction englobait l'appel réseau de paiement | verrou `FOR UPDATE` tenu jusqu'à 20 s pendant l'appel à la passerelle ; la tentative échouée était effacée par le rollback, donc invisible pour le support | appel réseau hors transaction, `payment_attempts` persisté avant toute décision |
| Message d'erreur générique en cas de refus de la passerelle | le motif réel (« La transaction ne peut être validée… ») était journalisé mais pas remonté au client | motif renvoyé dans la réponse |

### Outils d’exploitation livrés

| Commande | Rôle |
|---|---|
| `php artisan tombola:expire-orders` | ferme les commandes non payées et libère les tickets réservés |
| `php artisan tombola:reconcile-payments` | filet de sécurité si un webhook de paiement a été perdu |
| `php artisan tombola:verify-audit-chain` | vérifie l’intégrité de la chaîne d’audit |
| `php artisan tombola:reset-mfa {email}` | débloque un membre du personnel ayant perdu son téléphone (tracé et audité) |
| `php artisan tombola:futaye-check [--payment]` | diagnostique l’intégration de la passerelle, jusqu’à un vrai appel `/v1/payments` |
| `php artisan tombola:demo-reset` | réinitialise le jeu de démonstration (refusé en production) |

Les trois premières sont planifiées automatiquement (`app/Console/Kernel.php`).

### Pistes d’amélioration identifiées

- Le token HMAC de l’application Futaye doit être récupéré manuellement :
  l’interface de la passerelle n’affiche pas le modal de révélation (bug de
  template côté Futaye, détaillé dans `docs/DEPLOYMENT.md` §5.3).
- Aucun test end-to-end navigateur (Playwright) n’est encore en place : le
  rendu a été vérifié par requêtes HTTP, pas par interaction réelle.
- L’envoi SMS / WhatsApp nécessite de brancher un fournisseur dans
  `SendNotificationJob::sendViaBridge()`.

---

## Documentation

| Document | Contenu |
|---|---|
| [`docs/BACKEND-CONVENTIONS.md`](docs/BACKEND-CONVENTIONS.md) | conventions de code, enveloppe JSON, RBAC, règles non négociables |
| [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) | mise en production, sauvegardes, supervision, checklist |
| [`docs/SECURITY.md`](docs/SECURITY.md) | modèle de menaces et contre-mesures |
| [`cahier_des_charges.md`](docs/cahier_des_charges.md) | cahier des charges de référence |

---

## Conformité

Avant toute mise en production, le dispositif doit être validé par un conseil
juridique compétent : qualification juridique de la tombola, autorisations,
fiscalité, traitement des données personnelles, conditions de remise du véhicule,
âge minimum, restrictions géographiques et mécanismes de remboursement.

**Le contenu juridique livré (conditions, confidentialité) est un modèle à
faire valider, pas un document opposable.**
