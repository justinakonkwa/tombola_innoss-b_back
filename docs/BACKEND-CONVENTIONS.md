# Conventions backend — Tombola Innoss’B API

Ce document est la référence pour toute contribution à `tombola/api`.
Il est volontairement court : il fixe les conventions que le code doit respecter.

## 1. Stack et arborescence

- Laravel 10 (PHP 8.1), PostgreSQL 14, Sanctum, queues `database`.
- `app/Enums` : enums PHP natifs, un par fichier, `string` backed.
- `app/Models` : Eloquent, UUID (`HasUuids`) pour toute entité exposée publiquement.
- `app/Services` : **toute la logique métier**. Les contrôleurs ne font que
  valider, appeler un service et formater la réponse.
- `app/Http/Controllers/Api/V1` : contrôleurs HTTP. `Admin/` pour le back-office.
- `app/Http/Resources` : sérialisation JSON, une classe par modèle exposé.
- `app/Http/Requests` : validation (à créer si un contrôleur devient complexe).

## 2. Enveloppe JSON

- Succès simple : `{"data": ...}`
- Liste paginée : réponses de `Resource::collection()` (clés `data`, `links`, `meta`).
- Erreur : `{"message": "...", "errors": {...}}` avec un code HTTP cohérent.
- Les énumérations sont sérialisées deux fois : `status` (valeur machine) et
  `status_label` (libellé français).

## 3. Autorisation

Trois middlewares, cumulables dans cet ordre : `auth:sanctum`, `staff`, `mfa`.

- `staff` : réservé aux comptes internes (tout rôle ≠ `participant`, ou `is_admin`).
- `mfa` : exige un jeton portant l’abilité `mfa` (obtenue après vérification TOTP).
- `permission:xxx.yyy` : accepte plusieurs permissions séparées par des virgules
  (**OU** logique). Les valeurs viennent de `App\Enums\PermissionName`.

Les routes `/api/v1/admin/*` sont **déjà** derrière `staff` + `mfa` ;
il ne reste qu’à poser `permission:` sur chaque action (déjà fait dans `routes/api.php`).

## 4. Règles métier non négociables

1. Aucun ticket sans paiement `success` (`TicketService::issueForPayment`).
2. Un paiement = un seul lot (`ticket_batches.payment_id` UNIQUE).
3. Prix, quantité et montant total **toujours** recalculés côté serveur.
4. Un ticket verrouillé est immuable (trigger PostgreSQL).
5. Un tirage exécuté est figé (trigger PostgreSQL).
6. Toute action sensible écrit une entrée dans `audit_logs` via `AuditService`.
7. Les secrets (token Futaye, clés) ne quittent jamais le serveur.

## 5. Services disponibles

| Service | Rôle | Méthodes principales |
|---|---|---|
| `AuditService` | journal chaîné | `log(AuditAction, ?Model, array $old, array $new, ?User)` |
| `SecurityEventService` | événements sécurité | `log(string $type, string $severity, ?User, array $metadata, ?string $description)` |
| `AuthService` | comptes, sessions, MFA | `register`, `attemptLogin`, `issueTokens`, `refresh`, `logoutAll`, `beginMfaEnrollment`, `confirmMfaEnrollment`, `verifyMfa` |
| `OrderService` | commandes | `create(User, Campaign, int $quantity, array $context)`, `cancel(Order, string $reason)` |
| `PaymentService` | paiements Futaye | `initiate(Order, array $options)`, `handleWebhook(Request)`, `confirm`, `fail`, `reconcile` |
| `TicketService` | émission | `issueForPayment(Payment)`, `cancelBatch(TicketBatch, string $reason)`, `reserve`, `release` |
| `DrawService` | tirage commit-reveal | `create`, `closeSales`, `snapshot`, `execute`, `publish`, `verify` |
| `RiskService` | anti-fraude | `assess(User, ?Order, array $context)`, `review(RiskAssessment, User, bool, ?string)` |
| `NotificationService` | notifications | `send(User, string $template, array $data)`, `winnerNotified(Winner)` |
| `FutayeClient` | passerelle | `createPayment`, `getPayment`, `balance`, `payouts`, `createPaymentLink`, `verifyWebhookSignature` |

Les services lèvent des exceptions dédiées :
`AuthenticationException`, `OrderException`, `TicketIssuanceException`,
`DrawException`, `PaymentProviderException`.
`App\Exceptions\Handler` les traduit en réponses JSON.

## 6. Pagination et filtres

Convention de query string pour les listes admin :

```
?page=1&per_page=25&search=...&status=...&sort=-created_at&from=2026-01-01&to=2026-06-30
```

`per_page` est plafonné à 100.

## 7. Style

- Typage strict partout (`array`, `int`, types de retour).
- Pas de `DB::raw` interpolé : requêtes paramétrées ou Query Builder.
- Les commentaires expliquent le **pourquoi** (règle métier), pas le comment.
- Les migrations sont immuables une fois déployées : on en ajoute une nouvelle.
