# Cahier des charges — Plateforme Web Tombola Innoss’B

## 1. Présentation du projet

### 1.1 Nom du projet
**Tombola Innoss’B**

### 1.2 Type de solution
Application web **responsive, mobile-first et PWA**, accessible depuis smartphone, tablette et ordinateur.

### 1.3 Concept

La plateforme permet aux utilisateurs de participer à une tombola organisée autour de plusieurs lots.

Le **lot principal et élément central de la communication est une Lamborghini appartenant à l’artiste Innoss’B**.

D'autres lots peuvent être proposés :

- équipements électroniques ;
- smartphones ;
- motos ;
- appareils électroménagers ;
- récompenses financières ;
- autres équipements ou cadeaux.

Les participants achètent des tickets numériques à travers plusieurs moyens de paiement :

- Mobile Money ;
- réseau local RDV ;
- carte bancaire.

Après confirmation du paiement, les tickets sont générés automatiquement et associés au compte du participant.

---

## 2. Objectifs

La plateforme doit permettre de :

1. Présenter la tombola de manière premium.
2. Maximiser la participation.
3. Permettre l'achat de tickets en quelques étapes.
4. Accepter plusieurs moyens de paiement.
5. Garantir la sécurité des transactions.
6. Empêcher la fraude et la duplication des tickets.
7. Garantir l'intégrité du tirage.
8. Permettre aux participants de vérifier leurs tickets.
9. Permettre la publication des gagnants.
10. Fournir à l'administration un système complet de gestion.
11. Assurer une traçabilité complète de toutes les opérations.
12. Respecter les obligations légales et réglementaires applicables.

---

## 3. Principes fondamentaux

Le développement devra respecter quatre principes :

### 🎨 Expérience premium
Interface moderne, élégante et rapide.

### 🔐 Security by Design
La sécurité ne doit pas être ajoutée après le développement. Elle doit être intégrée dans l'architecture dès le départ.

### 💳 Payment Integrity
Un paiement confirmé = une participation valide.

Un paiement non confirmé = **aucun ticket attribué**.

### 🎲 Fair & Verifiable Draw
Le système de tirage doit être conçu pour empêcher toute manipulation.

---

## 4. Identité visuelle et UI/UX

### 4.1 Direction artistique

L'interface doit communiquer :

- luxe ;
- exclusivité ;
- confiance ;
- événement ;
- modernité ;
- prestige.

La Lamborghini doit être le **hero element** de la page d'accueil.

### 4.2 Page d'accueil

**Header**
- Logo Tombola Innoss’B
- Accueil
- Lots
- Comment participer
- Gagnants
- FAQ
- Connexion

**Hero**
- grande image/vidéo du véhicule ;
- description courte ;
- prix du ticket ;
- nombre de tickets disponibles ;
- compte à rebours ;
- bouton **Participer maintenant**.

**Sections**
1. Lamborghini
2. Autres lots
3. Comment ça marche ?
4. Statistiques
5. Sécurité
6. Derniers gagnants
7. FAQ
8. Conditions
9. Footer

---

## 5. Gestion des utilisateurs

### Inscription

Champs :

- nom ;
- prénom ;
- numéro de téléphone ;
- adresse e-mail ;
- pays ;
- mot de passe.

La vérification du numéro de téléphone peut être effectuée par OTP.

### Connexion

Possibilités :

- téléphone + mot de passe ;
- email + mot de passe ;
- OTP selon la politique retenue.

### Sécurité

- limitation des tentatives ;
- protection contre le credential stuffing ;
- sessions sécurisées ;
- déconnexion de toutes les sessions ;
- détection des connexions inhabituelles.

---

## 6. Profil utilisateur

Chaque participant dispose d'un espace personnel.

### Dashboard

Afficher :

- nombre de tickets ;
- participations ;
- transactions ;
- lots concernés ;
- prochains tirages.

### Mes tickets

Chaque ticket affiche :

- numéro ;
- lot ;
- date d'achat ;
- transaction ;
- statut ;
- QR code ;
- statut du ticket.

Exemple :

**Ticket #TMB-2026-00045821**

Statut :

🟢 Valide

---

## 7. Système de tickets

Le système de tickets constitue une fonctionnalité critique.

Chaque ticket possède un identifiant unique.

Structure possible :

`TMB-2026-XXXXXX`

### Un ticket doit être lié à :

- utilisateur ;
- campagne ;
- lot ;
- transaction ;
- paiement ;
- date de création ;
- statut.

### Statuts

- Pending
- Valid
- Used
- Winner
- Cancelled
- Refunded
- Suspended

Un ticket ne doit jamais pouvoir être créé directement depuis le frontend.

**Seul le backend peut créer un ticket après validation du paiement.**

---

## 8. Achat des tickets

### Parcours

1. L'utilisateur sélectionne le nombre de tickets.
2. Le système calcule le montant total côté serveur.
3. L'utilisateur choisit le moyen de paiement.
4. La commande est créée.
5. Le paiement est lancé.
6. Le fournisseur traite le paiement.
7. Le webhook confirme la transaction.
8. Le backend vérifie la confirmation.
9. Les tickets sont générés.
10. Une confirmation est envoyée à l'utilisateur.

### Moyens de paiement

**Mobile Money**
- Airtel Money
- M-Pesa
- Orange Money
- Africell Money

**Réseau local**
- RDV

**Carte bancaire**
- Visa
- Mastercard
- autres cartes compatibles avec la passerelle retenue.

---

## 9. Architecture du paiement

Le frontend ne doit **jamais décider qu'un paiement est réussi**.

```text
Utilisateur
     ↓
Frontend
     ↓
Backend
     ↓
Payment Gateway
     ↓
Opérateur bancaire
     ↓
Webhook
     ↓
Backend
     ↓
Vérification
     ↓
Transaction CONFIRMED
     ↓
Création tickets
```

### Règle critique

Le backend doit vérifier le paiement auprès du fournisseur avant toute attribution de tickets.

---

## 10. Idempotence des paiements

Chaque transaction doit avoir une clé d'idempotence.

Exemple :

`IDEMPOTENCY_KEY = UUID`

Si le même webhook est reçu plusieurs fois :

**un seul paiement doit être enregistré et un seul ensemble de tickets doit être généré.**

Cela évite les doublons liés aux retries ou aux problèmes réseau.

---

## 11. Webhooks

Les webhooks des fournisseurs de paiement doivent être :

- authentifiés ;
- vérifiés ;
- journalisés ;
- protégés contre les rejouements.

Le système doit vérifier :

- signature ;
- transaction ID ;
- montant ;
- devise ;
- utilisateur ;
- statut ;
- référence ;
- timestamp.

Une confirmation avec un montant différent de celui attendu doit être rejetée et signalée.

---

## 12. Sécurité applicative

### Authentification

Utiliser :

- access token courte durée ;
- refresh token sécurisé ;
- rotation des refresh tokens ;
- révocation des sessions ;
- MFA pour les administrateurs.

### Mots de passe

Ne jamais stocker les mots de passe en clair.

Utiliser un algorithme moderne de hashage adapté aux mots de passe, par exemple **Argon2id**.

---

## 13. Protection contre les attaques

La plateforme devra être protégée contre :

- SQL Injection ;
- XSS ;
- CSRF ;
- brute force ;
- credential stuffing ;
- bots ;
- replay attacks ;
- API abuse ;
- enumeration attacks ;
- manipulation des paramètres côté client.

Mesures :

- requêtes paramétrées/ORM ;
- validation stricte des entrées ;
- encodage des sorties ;
- rate limiting ;
- CAPTCHA/anti-bot lorsque nécessaire ;
- contrôle d'accès serveur ;
- validation des signatures ;
- journalisation des événements sensibles.

---

## 14. Sécurité API

Toutes les API doivent être :

- accessibles uniquement en HTTPS ;
- authentifiées lorsque nécessaire ;
- autorisées ;
- validées ;
- limitées en fréquence ;
- journalisées.

Exemple :

`POST /api/v1/tickets/purchase`

Le client ne doit pas pouvoir imposer :

- son `user_id` ;
- son prix ;
- son statut de paiement ;
- son statut de ticket ;
- son rôle.

Le serveur doit déterminer et vérifier toutes les données critiques.

---

## 15. Autorisations

Utiliser un système RBAC.

### Super Admin
Accès complet.

### Administrateur
Gestion opérationnelle.

### Finance
Accès aux paiements, remboursements et rapports financiers.

### Support
Accès aux utilisateurs, tickets et assistance.

### Auditeur
Lecture seule sur les transactions, tickets, logs et tirages.

Un utilisateur ne doit jamais pouvoir accéder à une ressource appartenant à un autre utilisateur simplement en modifiant un identifiant dans une URL ou une requête API.

---

## 16. Protection de l'administration

Le back-office doit être séparé du frontend public.

Exemple :

```text
tombola.com
admin.tombola.com
api.tombola.com
```

L'administration doit utiliser :

- MFA obligatoire ;
- RBAC ;
- protection anti-brute-force ;
- audit logs ;
- sessions sécurisées ;
- restrictions adaptées aux opérations critiques.

---

## 17. Audit Logs

Chaque action importante doit être enregistrée.

Exemple :

```text
Admin: admin@example.com
Action: UPDATE_DRAW
Date: 13/09/2026 10:31
Resource: DRAW-2026-001
Ancienne valeur: CLOSED
Nouvelle valeur: OPEN
```

Les logs critiques doivent être protégés contre la modification et la suppression non autorisée.

---

## 18. Gestion des lots

L'administrateur peut créer :

- nom du lot ;
- description ;
- images ;
- vidéos ;
- valeur indicative ;
- quantité ;
- date du tirage ;
- nombre de tickets ;
- statut.

Exemple :

```text
Lot #001
Lamborghini Innoss’B
Tickets : 100 000
Prix : 5 USD
```

---

## 19. Gestion des campagnes

Une campagne doit contenir :

```text
ID
Nom
Description
Date début
Date fin
Date du tirage
Prix du ticket
Nombre maximum de tickets
Statut
```

Statuts :

- Draft
- Scheduled
- Active
- Closed
- Drawn
- Archived

---

## 20. Tirage au sort

Le tirage est une fonctionnalité critique.

Il ne doit pas être basé sur une simple fonction aléatoire non contrôlée sans traçabilité.

### Processus

1. Fermeture des ventes.
2. Création d'un snapshot immuable des tickets éligibles.
3. Génération d'un engagement cryptographique du pool.
4. Sélection aléatoire à l'aide d'un générateur aléatoire cryptographiquement sécurisé.
5. Enregistrement du résultat.
6. Publication du résultat.
7. Conservation des preuves et logs d'audit.

---

## 21. Transparence du tirage

La plateforme peut afficher :

- nombre total de tickets éligibles ;
- date et heure du tirage ;
- identifiant du tirage ;
- hash de preuve ;
- méthode utilisée ;
- numéro gagnant ;
- statut de vérification.

Objectif :

> permettre à un tiers de vérifier que le résultat n'a pas été modifié après le tirage.

---

## 22. Gestion des gagnants

Processus :

```text
Ticket gagnant
↓
Utilisateur
↓
Notification
↓
Vérification
↓
Validation
↓
Remise du lot
↓
Preuve de remise
```

L'administration peut gérer :

- gagnant contacté ;
- identité vérifiée ;
- lot remis ;
- preuve de remise ;
- date de remise ;
- statut de réclamation.

---

## 23. Page publique des gagnants

Afficher :

- nom/prénom partiellement masqué ;
- numéro de ticket ;
- lot gagné ;
- date du tirage.

Les informations personnelles doivent être minimisées et publiées uniquement lorsque nécessaire et autorisé.

---

## 24. Notifications

### Email

- création de compte ;
- paiement confirmé ;
- tickets générés ;
- résultat ;
- notification de gain.

### SMS

- OTP ;
- confirmation ;
- notifications importantes.

### WhatsApp

Optionnel :

- confirmation d'achat ;
- envoi des tickets ;
- notification de gain.

---

## 25. Dashboard administrateur

### Vue générale

```text
Participants       XX XXX
Tickets vendus     XXX XXX
Revenus            XXX XXX
Transactions       XX XXX
Tickets restants   XX XXX
```

### Statistiques

- ventes quotidiennes ;
- revenus ;
- moyens de paiement ;
- nouveaux participants ;
- taux de conversion ;
- tickets par campagne.

---

## 26. Dashboard financier

Filtres :

- période ;
- méthode de paiement ;
- statut ;
- campagne ;
- devise.

Statuts :

- Pending
- Processing
- Paid
- Failed
- Cancelled
- Refunded

---

## 27. Système anti-fraude

Mettre en place un moteur de détection des comportements suspects.

Signaux possibles :

- volume anormal de transactions ;
- création massive de comptes ;
- paiements échoués répétés ;
- activité automatisée ;
- comportement inhabituel ;
- tentatives répétées sur différents comptes ;
- anomalies de paiement.

Un **Risk Score** peut être attribué :

```text
0 → 100
```

Exemple :

```text
Risk Score: 87
Status: HIGH RISK
Action: REVIEW
```

Les règles anti-fraude doivent éviter de bloquer arbitrairement les utilisateurs légitimes et prévoir une procédure de revue.

---

## 28. Base de données

Tables principales :

```text
users
roles
permissions
user_roles

campaigns
prizes
tickets
ticket_batches

orders
payments
payment_attempts
payment_webhooks
refunds

draws
draw_entries
winners

notifications
otp_codes

audit_logs
security_events

sessions
devices

settings
```

---

## 29. Relations principales

```text
User
 │
 ├── Orders
 │     └── Payments
 │
 └── Tickets
       └── Campaign
             └── Prize
```

```text
Campaign
   ↓
Draw
   ↓
Eligible Tickets
   ↓
Winner
   ↓
Prize
```

---

## 30. API

Organisation recommandée :

```text
/api/v1/auth
/api/v1/users
/api/v1/campaigns
/api/v1/prizes
/api/v1/tickets
/api/v1/orders
/api/v1/payments
/api/v1/webhooks
/api/v1/draws
/api/v1/winners
/api/v1/notifications
/api/v1/admin
```

Les API doivent être versionnées afin de permettre les évolutions futures.

---

## 31. Infrastructure

### Frontend
- Next.js
- TypeScript
- Tailwind CSS
- PWA

### Backend
- Laravel + PHP

### Base de données
- PostgreSQL

### Cache et files
- Redis

### Sécurité réseau
- Cloudflare
- WAF
- DDoS protection

### Déploiement
- Docker
- GitLab CI/CD

---

## 32. Architecture

```text
                    INTERNET
                       │
                       ▼
                  CLOUDFLARE
                 WAF / DDoS
                       │
                       ▼
                 LOAD BALANCER
                       │
             ┌─────────┴─────────┐
             ▼                   ▼
        FRONTEND              API BACKEND
        Next.js               Laravel
                                  │
                 ┌────────────────┼──────────────┐
                 ▼                ▼              ▼
             PostgreSQL        Redis       Payment APIs
                                                │
                           ┌────────────────────┼───────────────┐
                           ▼                    ▼               ▼
                       Mobile Money            RDV           Cards
```

---

## 33. Sécurité infrastructure

Prévoir :

- HTTPS obligatoire ;
- TLS moderne ;
- HSTS ;
- WAF ;
- protection DDoS ;
- firewall ;
- ports minimaux ouverts ;
- accès SSH sécurisé ;
- clés SSH ;
- secrets hors Git ;
- variables d'environnement ;
- rotation des secrets ;
- sauvegardes chiffrées ;
- monitoring ;
- alertes.

**Aucun secret API ne doit être présent dans le frontend.**

---

## 34. Protection des données

Le système doit appliquer :

- minimisation des données ;
- chiffrement des données sensibles ;
- contrôle d'accès ;
- durée de conservation définie ;
- suppression ou anonymisation lorsque légalement possible ;
- journalisation des accès sensibles.

Les données de carte bancaire ne doivent pas être stockées directement lorsque la passerelle permet une tokenisation ou un paiement hébergé.

---

## 35. Sauvegarde et Disaster Recovery

Prévoir :

### Backup quotidien
Sauvegarde de la base de données.

### Backup périodique
Stockage séparé.

### Rétention
- quotidiennes ;
- hebdomadaires ;
- mensuelles.

### Disaster Recovery

Documenter :

```text
RTO
RPO
procédure de restauration
responsables
```

---

## 36. Monitoring

Surveiller :

- CPU ;
- RAM ;
- disque ;
- API ;
- erreurs ;
- paiements ;
- webhooks ;
- base de données ;
- temps de réponse ;
- erreurs 500 ;
- tentatives de connexion ;
- événements de sécurité.

Exemples d'alertes :

```text
🚨 50 paiements échoués en 2 minutes
🚨 500 tentatives de connexion depuis une même source
🚨 Anomalie sur les webhooks de paiement
```

---

## 37. Tests

### Tests unitaires
Services métier et règles critiques.

### Tests d'intégration
Paiements, tickets, utilisateurs, webhooks.

### Tests E2E

```text
Inscription
→ Achat
→ Paiement
→ Confirmation
→ Ticket
```

### Tests de sécurité

- OWASP Top 10 ;
- API Security ;
- authentification ;
- autorisation ;
- injection ;
- XSS ;
- CSRF ;
- rate limiting ;
- gestion des secrets.

### Tests de charge

Simuler des milliers d'utilisateurs simultanés et des pics de trafic.

---

## 38. Tests critiques

### Double paiement
Un même paiement ne doit jamais générer deux ensembles de tickets.

### Double webhook
Même webhook reçu plusieurs fois → une seule transaction.

### Modification du prix
Le prix doit être calculé côté serveur.

### Manipulation du ticket
Impossible de modifier son numéro depuis le frontend.

### Tirage
Impossible de modifier le résultat après validation.

### Administration
Un compte Support ne peut pas lancer ou modifier un tirage.

---

## 39. SEO et communication

Prévoir :

- SEO ;
- Open Graph ;
- partage WhatsApp ;
- Facebook ;
- X ;
- Instagram ;
- QR code de campagne.

Chaque campagne peut avoir une URL dédiée :

```text
/tombola/lamborghini
```

---

## 40. Performance

Objectifs :

- chargement rapide ;
- images optimisées ;
- WebP/AVIF ;
- lazy loading ;
- CDN ;
- cache ;
- pagination ;
- API optimisées.

La page Lamborghini doit rester visuellement spectaculaire tout en conservant de bonnes performances sur les réseaux mobiles.

---

## 41. Accessibilité

Prévoir :

- contraste suffisant ;
- navigation clavier ;
- textes alternatifs ;
- tailles de texte adaptées ;
- boutons suffisamment grands ;
- messages d'erreur compréhensibles.

---

## 42. Multilingue

Prévoir l'architecture pour :

- Français ;
- Anglais.

Le français peut être la langue principale.

---

## 43. Responsive

La plateforme doit fonctionner correctement sur :

- iPhone ;
- Android ;
- tablette ;
- ordinateur.

Priorité :

**Mobile → Tablet → Desktop**

---

## 44. Pages publiques

```text
/
 /lots
 /lots/lamborghini
 /lots/[id]
 /comment-participer
 /gagnants
 /faq
 /securite
 /conditions
 /confidentialite
 /contact
 /connexion
 /inscription
```

---

## 45. Pages utilisateur

```text
/dashboard
/mes-tickets
/mes-commandes
/mes-paiements
/mon-profil
/securite
/notifications
```

---

## 46. Pages administrateur

```text
/admin
/admin/campagnes
/admin/lots
/admin/tickets
/admin/participants
/admin/paiements
/admin/remboursements
/admin/tirages
/admin/gagnants
/admin/notifications
/admin/utilisateurs
/admin/roles
/admin/audit-logs
/admin/security
/admin/settings
```

---

## 47. Règles métier critiques

1. Aucun paiement confirmé → aucun ticket.
2. Aucun ticket ne peut être créé depuis le frontend.
3. Un paiement ne peut générer qu'une seule attribution de tickets.
4. Un ticket appartient à une seule campagne.
5. Un ticket validé ne peut pas être modifié.
6. Une campagne clôturée n'accepte plus de tickets.
7. Un tirage terminé ne peut pas être modifié par un administrateur standard.
8. Toutes les opérations sensibles sont auditées.
9. Les secrets restent exclusivement côté serveur.
10. Le résultat du tirage doit être traçable et vérifiable.

---

## 48. Conformité

Avant la mise en production, faire valider le dispositif par un conseil juridique compétent concernant notamment :

- qualification juridique de la tombola ;
- autorisations éventuelles ;
- règles applicables aux jeux promotionnels ;
- conditions de participation ;
- fiscalité ;
- traitement des données personnelles ;
- règles concernant les paiements ;
- conditions de remise du véhicule ;
- âge minimum ;
- restrictions géographiques ;
- mécanismes de remboursement.

Cette validation est particulièrement importante pour une tombola avec un véhicule de grande valeur.

---

## 49. Phases de développement

### Phase 1 — Architecture
- spécifications ;
- maquettes ;
- architecture ;
- base de données ;
- sécurité ;
- API.

### Phase 2 — Frontend
- landing page premium ;
- lots ;
- Lamborghini ;
- inscription ;
- compte utilisateur ;
- checkout.

### Phase 3 — Backend
- utilisateurs ;
- campagnes ;
- lots ;
- tickets ;
- commandes ;
- paiements.

### Phase 4 — Paiements
- Mobile Money ;
- RDV ;
- carte bancaire ;
- webhooks ;
- idempotence.

### Phase 5 — Administration
- dashboard ;
- utilisateurs ;
- paiements ;
- tickets ;
- campagnes ;
- lots.

### Phase 6 — Tirage
- fermeture ;
- snapshot ;
- pool ;
- tirage sécurisé ;
- gagnant ;
- preuve.

### Phase 7 — Sécurité
- audit ;
- tests ;
- pentest ;
- monitoring ;
- backups.

### Phase 8 — Production
- infrastructure ;
- DNS ;
- SSL ;
- CI/CD ;
- monitoring ;
- sauvegardes ;
- lancement.

---

## 50. MVP recommandé

### Priorité 1 — Critique

- Landing page premium ;
- inscription/connexion ;
- campagnes ;
- Lamborghini ;
- autres lots ;
- achat de tickets ;
- Mobile Money ;
- RDV ;
- carte ;
- tickets ;
- dashboard utilisateur ;
- dashboard admin ;
- paiements ;
- webhooks ;
- idempotence ;
- audit logs ;
- sécurité ;
- tirage.

### Priorité 2

- SMS ;
- WhatsApp ;
- statistiques avancées ;
- système anti-fraude ;
- notifications avancées ;
- multilingue.

### Priorité 3

- application mobile native ;
- programme ambassadeurs ;
- parrainage ;
- gamification ;
- fidélité.

---

## 51. Critère de réussite

Le projet sera considéré comme prêt pour la production lorsqu'un utilisateur peut :

1. s'inscrire ;
2. acheter un ticket avec Mobile Money, RDV ou carte ;
3. effectuer un paiement sécurisé ;
4. recevoir automatiquement son ticket ;
5. consulter sa participation ;
6. recevoir les notifications pertinentes.

En parallèle, l'administration doit pouvoir :

1. gérer les campagnes ;
2. gérer les lots ;
3. suivre les paiements ;
4. gérer les tickets ;
5. contrôler les opérations ;
6. réaliser un tirage sécurisé ;
7. publier les gagnants ;
8. consulter les journaux d'audit.

Aucune étape critique ne doit pouvoir être contournée côté client.

---

## 52. Stack technique recommandée

### Frontend
**Next.js + TypeScript + Tailwind CSS**

### Backend
**Laravel + PHP**

### Base de données
**PostgreSQL**

### Cache / Jobs
**Redis**

### Infrastructure
**Docker + Cloudflare + GitLab CI/CD**

### Architecture fonctionnelle
Les domaines suivants doivent être séparés logiquement :

- Payment Service ;
- Ticket Service ;
- Draw Service ;
- User/Auth Service ;
- Notification Service ;
- Admin Service ;
- Audit/Security Service.

Cette séparation facilite les tests, la maintenance, la supervision et surtout l'audit des fonctions critiques.

---

# 53. Exigence générale de sécurité

La sécurité doit être considérée comme une exigence fonctionnelle et non fonctionnelle de premier niveau.

Le système doit appliquer le principe :

> **Never trust the client.**

Toutes les données critiques doivent être recalculées et validées côté serveur.

Les opérations sensibles doivent être :

- authentifiées ;
- autorisées ;
- validées ;
- journalisées ;
- idempotentes lorsque nécessaire ;
- protégées contre les attaques par rejeu ;
- surveillées.

Les fonctionnalités de paiement, d'attribution des tickets et de tirage doivent faire l'objet d'une revue de sécurité spécifique avant la mise en production.

---

# 54. Résultat attendu

La plateforme finale doit donner l'impression d'un **grand événement digital premium**, tout en reposant sur une architecture robuste.

Les quatre piliers du produit sont :

**🎨 Design premium**  
**🔐 Sécurité maximale**  
**💳 Paiement fiable**  
**🎲 Tirage transparent et vérifiable**

Le produit doit être conçu pour inspirer confiance au participant dès la première seconde et rester techniquement robuste lorsque le trafic et le volume des transactions augmentent fortement.
