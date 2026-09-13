# Sécurité — modèle de menaces et contre-mesures

> Principe directeur : **Never trust the client.** Toute donnée critique est
> recalculée et validée côté serveur, et les règles structurantes sont garanties
> par la base de données elle-même.

Ce document associe chaque exigence du cahier des charges à sa mise en œuvre
concrète dans le code.

---

## 1. Authentification

| Exigence | Mise en œuvre | Fichier |
|---|---|---|
| Mots de passe jamais en clair | Argon2id via le cast `hashed` | `app/Models/User.php` |
| Access token courte durée | Sanctum, expiration 60 min | `app/Services/AuthService.php` |
| Refresh token sécurisé, rotation, révocation | Jeton aléatoire 64 caractères, **haché SHA-256** en base, rotation stricte à chaque usage (l’ancien est révoqué et les access tokens précédents supprimés) | `AuthService::refresh()` |
| Déconnexion de toutes les sessions | `POST /auth/logout-all` révoque jetons et sessions | `AuthService::logoutAll()` |
| MFA pour les administrateurs | TOTP RFC 6238 maison, obligatoire pour tout compte interne | `app/Services/TotpService.php`, middleware `mfa` |
| Limitation des tentatives | 5 échecs → verrouillage 15 min | `AuthService::registerFailedAttempt()` |
| Détection des connexions inhabituelles | Empreinte d’appareil + événement `new_device_login` | `AuthService::touchDevice()` |
| Anti-credential stuffing | Throttling `10/min` sur `/auth/login`, verrouillage par compte | `routes/api.php` |

Le changement de mot de passe **révoque toutes les autres sessions**.

L’application mobile/web ne stocke le token nulle part côté serveur en clair ;
les jetons de défi MFA (`mfa:challenge`) n’ont qu’une seule abilité et expirent en 5 minutes.

---

## 2. Autorisation

RBAC à **6 rôles** (`super_admin`, `admin`, `finance`, `support`, `auditor`, `participant`)
et **25 permissions** granulaires.

Trois contrôles **cumulés** sur le back-office :

```
auth:sanctum  →  staff  →  mfa  →  permission:xxx.yyy
```

- `staff` — un participant ne peut jamais atteindre `/api/v1/admin/*` ;
- `mfa` — le jeton doit porter l’abilité `mfa`, obtenue uniquement après
  vérification TOTP ; un mot de passe volé ne suffit donc pas ;
- `permission:` — contrôle fin par action (un compte Support ne peut pas
  lancer un tirage : `draws.execute` lui est refusé).

**Protection contre l’IDOR** : les ressources sont toujours recherchées par
propriétaire côté serveur, jamais par un identifiant fourni par le client :

```php
Order::where('reference', $reference)
     ->where('user_id', $request->user()->id)   // propriété vérifiée
     ->firstOrFail();
```

Toutes les clés exposées sont des **UUID** : les attaques par énumération
séquentielle sont impossibles.

---

## 3. Paiements

| Menace | Contre-mesure |
|---|---|
| Falsification du montant côté client | Le total est recalculé depuis `campaigns.ticket_price` × `quantity` et figé dans `orders.unit_price` / `total_amount`. Un montant transmis par le client est ignoré. |
| Faux webhook | Signature `HMAC-SHA256(token, timestamp + "." + body)` vérifiée avec `hash_equals` (temps constant). |
| Rejeu de webhook | Fenêtre d’horodatage ±5 min **et** empreinte unique `payment_webhooks.payload_hash` (contrainte UNIQUE). |
| Webhook avec montant trafiqué | Comparaison stricte du montant **et** de la devise avec la commande ; divergence → rejet + événement de sécurité `critical`. |
| Double attribution de tickets | `ticket_batches.payment_id` **UNIQUE** + verrou `SELECT … FOR UPDATE` sur le paiement. Un second appel renvoie le lot existant. |
| Ticket créé depuis le frontend | Aucune route publique ne crée de ticket. Seul `TicketService::issueForPayment()` en crée, et uniquement si `payments.status = success`. |
| Survente | Capacité vérifiée sous verrou (`FOR UPDATE`) ; réservation à la commande, libération à l’échec. |
| Webhook perdu | Réconciliation active : `GET /payments/{id}/status` interroge la passerelle et applique le statut réel. |
| Rejeu de la page de retour | La page de retour ne fait que **lire** le statut serveur ; elle ne confirme jamais un paiement. |

Toute tentative rejetée laisse une trace : `payment_webhooks.status = rejected`
avec le motif, plus un `security_events` de sévérité appropriée.

---

## 4. Intégrité des données

Ces garanties sont dans la base, pas seulement dans le code : même un accès SQL
direct ne peut pas les contourner.

| Trigger | Protection |
|---|---|
| `tickets_immutability` | numéro, série, propriétaire, campagne, commande, paiement, QR et lot d’un ticket verrouillé sont immuables |
| `tickets_no_delete` | un ticket ne peut jamais être supprimé (statut `cancelled`/`refunded` uniquement) |
| `draws_freeze` | graines, empreinte du pool, taille du pool, position gagnante et date d’exécution d’un tirage exécuté sont figés |
| `draw_entries_no_change` | le snapshot d’un tirage est immuable (ni UPDATE ni DELETE) |
| `audit_logs_no_update` | le journal d’audit est append-only |

Contraintes `CHECK` : tout statut hors énumération est rejeté par PostgreSQL
(utilisateurs, campagnes, lots, commandes, paiements, webhooks, remboursements,
tickets, tirages, gagnants, notifications, évaluations de risque).

---

## 5. Traçabilité

Le journal d’audit est **chaîné par hachage** :

```
hash = SHA-256(previous_hash | action | actor_id | resource | old_values | new_values | created_at)
```

- toute altération ou suppression **rompt la chaîne** et devient détectable ;
- `GET /api/v1/admin/audit-logs/verify` recalcule la chaîne complète ;
- la table est protégée par un trigger PostgreSQL interdisant UPDATE et DELETE ;
- les valeurs avant/après sont conservées pour chaque opération sensible.

Actions auditées : inscription, connexion (succès et échec), commande, initiation
et confirmation de paiement, webhook rejeté, émission et annulation de tickets,
cycle de vie complet du tirage, remboursements, changements de rôle, paramètres.

---

## 5 bis. Cloisonnement des bases de données

L'application utilise un rôle dédié `tombola_app`, propriétaire de sa seule base
`tombola`. Elle n'utilise jamais le compte `postgres`.

| Contrôle | Mise en œuvre |
|---|---|
| Rôle applicatif non privilégié | `NOSUPERUSER`, `NOCREATEDB`, `NOCREATEROLE`, 40 connexions max |
| Droits de `PUBLIC` révoqués sur la base | `REVOKE ALL ON DATABASE tombola FROM PUBLIC` |
| Schéma `public` non ouvert | `REVOKE ALL ON SCHEMA public FROM PUBLIC` |
| Aucune lecture croisée | `tombola_app` n'a aucun `SELECT` sur les tables des autres bases |

**Risque résiduel en instance partagée** — PostgreSQL accorde `CONNECT` et
`CREATE` à `PUBLIC` par défaut : sur une instance hébergeant d'autres
applications (kamba-chat, par exemple), `tombola_app` peut ouvrir une session
sur ces bases et créer des objets dans leur schéma `public`. Il ne peut pas lire
leurs données. Un `REVOKE ... FROM tombola_app` est sans effet — le privilège
découle de `PUBLIC` ; seul `REVOKE ... FROM PUBLIC` agit. La parade recommandée
est une **instance dédiée** ; l'alternative (isolation cluster) est fournie,
commentée, dans `deploy/01-create-database.sql` (§2 bis).

---

## 6. Protection applicative

| Menace | Contre-mesure |
|---|---|
| SQL Injection | Eloquent et Query Builder uniquement ; requêtes brutes **paramétrées** (`DB::select('… ?', [$v])`). Aucune concaténation SQL. |
| XSS | API JSON pure ; le frontend échappe par défaut (React). Aucun `dangerouslySetInnerHTML`. Contenu de notification assaini par `strip_tags`. |
| CSRF | API sans session à cookie : authentification par jeton `Bearer`. Les cookies ne portent pas l’autorisation. |
| Brute force | Throttling par route (5–30 req/min) + verrouillage de compte. |
| Énumération de comptes | Réponses génériques identiques pour un compte existant ou non ; identifiants UUID. |
| Manipulation de paramètres | `quantity`, `channel`, `status`, `amount` validés ; les valeurs d’énumération sont vérifiées par `Rule::enum`. |
| Abus d’API | `ThrottleRequests` sur toutes les routes sensibles. |
| Rejeu | Fenêtre temporelle sur les webhooks + hash de corps unique. |
| Fuite d’erreurs techniques | `Handler` renvoie un message générique en production ; aucune trace d’exécution exposée. |
| Fuite de secrets | Le token HMAC Futaye n’existe que côté serveur (variable d’environnement) ; aucun `NEXT_PUBLIC_*` sensible. |

### En-têtes de durcissement

`SecurityHeaders` (API) et la configuration Next.js (frontend) appliquent :

```
Strict-Transport-Security: max-age=63072000; includeSubDomains; preload
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: camera=(), microphone=(), geolocation=()
```

---

## 7. Protection des données personnelles

- **Minimisation** : la page publique des gagnants n’affiche que des noms masqués
  (`J*** D***`), le numéro de ticket et le lot.
- **Masquage** : les numéros de payeur sont tronqués dans les réponses API
  (`2438****12`).
- **Aucune donnée bancaire** : les paiements par carte passent par le checkout
  hébergé de la passerelle ; aucun PAN n’est collecté ni stocké.
- **Chiffrement** : TLS en transit ; disque chiffré recommandé au repos ;
  sauvegardes chiffrées (GPG).
- **Rétention** : à définir avec le conseil juridique ; l’anonymisation est
  possible sans casser la traçabilité des tickets (clé étrangère conservée).
- **Accès** : chaque consultation de données sensibles par le personnel est
  journalisée dans `audit_logs`.

---

## 8. Tests de sécurité à exécuter avant production

Les tests automatisés couvrent déjà :

- aucun ticket sans paiement confirmé ;
- double webhook → un seul lot de tickets ;
- montant divergent → rejet ;
- prix manipulé côté client → ignoré ;
- ticket verrouillé → modification impossible ;
- tirage exécuté → figé et reproductible ;
- participant → back-office inaccessible ;
- personnel sans MFA → refusé.

À compléter manuellement :

- [ ] Scan OWASP ZAP / Burp sur l’API et le frontend
- [ ] Vérification de la configuration TLS (SSL Labs, note A minimum)
- [ ] Test d’intrusion externe par un prestataire
- [ ] Test de charge au pic d’ouverture (plusieurs milliers d’utilisateurs simultanés)
- [ ] Test de restauration de sauvegarde sur environnement isolé
- [ ] Revue des permissions de chaque rôle avec le responsable métier
- [ ] Vérification qu’aucun secret n’est présent dans l’historique Git

---

## 9. Signalement

Toute vulnérabilité doit être signalée en privé à l’équipe sécurité avant toute
divulgation publique. Aucun test d’intrusion ne doit être mené sur l’environnement
de production sans autorisation écrite.
