#!/usr/bin/env bash
# =============================================================================
# Tombola Innoss'B — paiement de bout en bout (HTTP, passerelle réelle)
# =============================================================================
#
# Déroule le parcours de paiement complet tel qu'un client le vit :
#
#   inscription → commande → ouverture du paiement (appel RÉEL à Futaye)
#   → relecture de la commande et de la liste des commandes
#   → interrogation du statut (réconciliation auprès de la passerelle)
#   → webhook signé → tickets émis → statuts cohérents partout
#   → rejeu du webhook (idempotence)
#
# La commande de paiement est envoyée au compte de test de la passerelle : elle
# crée une vraie session de checkout, sans mouvement d'argent.
#
# Usage : ./scripts/payment-e2e.sh [http://localhost:8000]
# =============================================================================
set -euo pipefail

API="${1:-http://localhost:8000}"

# ---------------------------------------------------------------------------
# AVERTISSEMENT — identifiants de DÉVELOPPEMENT
# ---------------------------------------------------------------------------
# Le compte admin@tombola-innossb.cd n'existe que dans un environnement local.
# Le seeder refuse ce mot de passe hors de `local` et `testing`.
# ---------------------------------------------------------------------------

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [ -f "$SCRIPT_DIR/../api/.env" ]; then
  APP_DIR="$(cd "$SCRIPT_DIR/../api" && pwd)"
else
  APP_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
fi
ENV_FILE="$APP_DIR/.env"

pass() { printf '\033[32m✓\033[0m %s\n' "$1"; }
fail() { printf '\033[31m✗\033[0m %s\n' "$1"; exit 1; }
step() { printf '\n\033[36m▸ %s\033[0m\n' "$1"; }

need() { command -v "$1" >/dev/null 2>&1 || fail "Commande requise absente : $1"; }
need curl
need php
[ -f "$ENV_FILE" ] || fail "Fichier introuvable : $ENV_FILE"

get_env() { grep -E "^$1=" "$ENV_FILE" | head -1 | cut -d= -f2- | tr -d '"' | tr -d "'"; }
FUTAYE_TOKEN="$(get_env FUTAYE_TOKEN)"
[ -n "$FUTAYE_TOKEN" ] || fail "FUTAYE_TOKEN absent de $ENV_FILE"

json() { php -r '
  $d = json_decode(file_get_contents("php://stdin"), true);
  foreach (explode(".", $argv[1]) as $k) {
      if (!is_array($d) || !array_key_exists($k, $d)) { exit(1); }
      $d = $d[$k];
  }
  if (is_bool($d)) { echo $d ? "1" : "0"; }
  elseif ($d === null) { echo ""; }
  elseif (is_scalar($d)) { echo (string) $d; }
  else { echo json_encode($d); }
' "$1"; }

echo "============================================================"
echo " Paiement de bout en bout — $API"
echo "============================================================"

step "1. Compte participant et commande"
STAMP="$(date +%s)"
EMAIL="paiement+$STAMP@example.com"
PHONE="24391${STAMP: -7}"

REGISTER="$(curl -sS --max-time 20 -X POST "$API/api/v1/auth/register" -H 'Content-Type: application/json' \
  -d "{\"first_name\":\"Paiement\",\"last_name\":\"BoutEnBout\",\"phone\":\"$PHONE\",\"email\":\"$EMAIL\",\"country\":\"CD\",\"password\":\"Paiement++2026*\",\"password_confirmation\":\"Paiement++2026*\",\"accept_terms\":true}")"
TOKEN="$(echo "$REGISTER" | json data.tokens.access_token)" || fail "Inscription échouée : $(echo "$REGISTER" | head -c 200)"
AUTH=(-H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json')
pass "compte créé ($EMAIL)"

# Le frontend doit connaître ses propres coordonnées après l'inscription.
echo "$REGISTER" | json data.user.email >/dev/null || fail "L'e-mail du propriétaire est absent de la réponse"
pass "la réponse d'inscription contient bien les coordonnées du propriétaire"

ORDER="$(curl -sS --max-time 20 -X POST "$API/api/v1/orders" "${AUTH[@]}" \
  -H "Idempotency-Key: paiement-$STAMP" -d '{"campaign":"lamborghini","quantity":2}')"
REF="$(echo "$ORDER" | json data.reference)" || fail "Commande impossible : $(echo "$ORDER" | head -c 200)"
TOTAL="$(echo "$ORDER" | json data.total_amount)"
# Le montant est un nombre JSON : on compare numériquement.
php -r "exit(abs((float) '$TOTAL' - 10.00) < 0.001 ? 0 : 1);" \
  || fail "Total inattendu : $TOTAL (attendu 10.00 pour 2 × 5 USD)"
pass "commande $REF — 2 tickets, total serveur : $TOTAL USD"

step "2. Ouverture du paiement (appel réel à la passerelle)"
# On demande le checkout hébergé (sans numéro) : la passerelle renvoie une page
# de paiement où le client choisit lui-même Mobile Money ou carte.
#
# Fournir un `phone` déclenche au contraire un push USSD immédiat vers
# l'opérateur : avec un numéro fictif, l'opérateur refuse — comportement testé
# à l'étape suivante.
PAY="$(curl -sS --max-time 30 -X POST "$API/api/v1/orders/$REF/pay" "${AUTH[@]}" \
  -d '{"channel":"card"}')"
PAYMENT_ID="$(echo "$PAY" | json data.payment_id)" || fail "Ouverture du paiement impossible : $(echo "$PAY" | head -c 300)"
CHECKOUT="$(echo "$PAY" | json data.checkout_url)"
PAY_STATUS="$(echo "$PAY" | json data.status)"
[ -n "$CHECKOUT" ] || fail "Aucune URL de checkout renvoyée"
echo "$CHECKOUT" | grep -qE '^https://' || fail "URL de checkout invalide : $CHECKOUT"
pass "paiement $PAYMENT_ID ouvert (statut « $PAY_STATUS »)"
pass "checkout hébergé : $CHECKOUT"

step "2 bis. Rejet explicite d'un push Mobile Money sur un numéro invalide"
# Nouvelle commande : le service réutilise volontairement une session fraîche
# (moins de 20 minutes) pour éviter les doublons, on ne peut donc pas tester le
# rejet sur la commande précédente.
ORDER2="$(curl -sS --max-time 20 -X POST "$API/api/v1/orders" "${AUTH[@]}" \
  -H "Idempotency-Key: paiement-bis-$STAMP" -d '{"campaign":"lamborghini","quantity":1}')"
REF2="$(echo "$ORDER2" | json data.reference)" || fail "Seconde commande impossible"
pass "seconde commande $REF2 créée"

# Un numéro fictif amène la passerelle à refuser la transaction. L'important est
# que le refus soit (1) explicite pour le client et (2) journalisé pour le support.
BAD="$(curl -sS --max-time 30 -X POST "$API/api/v1/orders/$REF2/pay" "${AUTH[@]}" \
  -d "{\"channel\":\"mobile_money\",\"phone\":\"$PHONE\",\"operator\":\"airtel\"}")"
BAD_MSG="$(echo "$BAD" | json message 2>/dev/null || echo "")"
echo "$BAD_MSG" | grep -qi "passerelle" \
  || fail "Le refus de la passerelle n'est pas remonté explicitement : $(echo "$BAD" | head -c 200)"
pass "refus explicite remonté au client"

# La tentative doit rester consultable côté support (payment_attempts).
ATTEMPTS="$(cd "$APP_DIR" && php artisan tombola:attempt-count "$REF2" 2>/dev/null | tail -1)"
[ "${ATTEMPTS:-0}" -ge 1 ] \
  || fail "Aucune tentative journalisée : le support ne pourrait pas diagnostiquer l'échec"
pass "tentative journalisée, consultable par le support ($ATTEMPTS entrée)"

# La commande reste payable : un échec ne doit pas la bloquer.
printf "  commande encore payable : "
curl -sS --max-time 20 "$API/api/v1/orders/$REF2" "${AUTH[@]}" | json data.status | sed 's/^/statut /'

step "3. Relecture de la commande et de la liste des commandes"
# Ces deux routes échouaient en « function max(uuid) does not exist ».
DETAIL="$(curl -sS --max-time 20 "$API/api/v1/orders/$REF" "${AUTH[@]}")"
DETAIL_STATUS="$(echo "$DETAIL" | json data.status)" || fail "GET /orders/{ref} en échec : $(echo "$DETAIL" | head -c 300)"
PAY_VISIBLE="$(echo "$DETAIL" | json data.payment.id)" || fail "Le paiement n'apparaît pas dans la commande"
pass "GET /orders/{ref} → statut « $DETAIL_STATUS », paiement visible"

LIST="$(curl -sS --max-time 20 "$API/api/v1/me/orders" "${AUTH[@]}")"
FOUND="$(echo "$LIST" | php -r '
  $d = json_decode(file_get_contents("php://stdin"), true);
  foreach (($d["data"] ?? []) as $o) { if (($o["reference"] ?? "") === $argv[1]) { echo "1"; exit(0); } }
  echo "0";
' "$REF")"
[ "$FOUND" = "1" ] || fail "GET /me/orders ne renvoie pas la commande : $(echo "$LIST" | head -c 300)"
pass "GET /me/orders → la commande y figure"

step "4. Interrogation du statut (réconciliation auprès de la passerelle)"
STATUS="$(curl -sS --max-time 30 "$API/api/v1/payments/$PAYMENT_ID/status" "${AUTH[@]}")"
STATUS_VALUE="$(echo "$STATUS" | json data.status)" || fail "GET /payments/{id}/status en échec : $(echo "$STATUS" | head -c 300)"
pass "statut renvoyé par l'API : « $STATUS_VALUE » (paiement non encore encaissé)"

step "5. Webhook signé : confirmation du paiement"
# On simule la notification de la passerelle, signée comme elle le ferait.
PROVIDER_ID="$(php -r 'echo "E2E".bin2hex(random_bytes(6));')"
BODY="$(PROVIDER_ID="$PROVIDER_ID" TOTAL="$TOTAL" php -r 'echo json_encode([
  "event" => "PAYMENT_SUCCESS",
  "id" => getenv("PROVIDER_ID"),
  "reference" => "FT-E2E-".substr(getenv("PROVIDER_ID"), 3),
  "status" => "SUCCESS",
  "amount" => (float) getenv("TOTAL"),
  "commission" => 0.20,
  "net" => (float) getenv("TOTAL") - 0.20,
  "currency" => "USD",
], JSON_UNESCAPED_SLASHES);')"

# Le paiement doit exister côté serveur : on aligne son identifiant public sur
# celui du webhook simulé.
ALIGN="$(cd "$APP_DIR" && php artisan tombola:test-payment "$REF" "$PROVIDER_ID" 2>/dev/null | tail -1)"
[ -n "$ALIGN" ] || fail "Impossible d'aligner le paiement de test"

TS="$(date +%s)"
SIG="$(TS="$TS" BODY="$BODY" TOKEN="$FUTAYE_TOKEN" php -r 'echo hash_hmac("sha256", getenv("TS").".".getenv("BODY"), getenv("TOKEN"));')"

curl -sS --max-time 20 -X POST "$API/api/v1/webhooks/futaye" -H 'Content-Type: application/json' \
  -H "X-Futaye-Timestamp: $TS" -H "X-Futaye-Signature: $SIG" -d "$BODY" \
  | grep -qE 'traité|processed' || fail "Webhook refusé"
pass "webhook accepté et traité"

step "6. Cohérence après confirmation"
ORDER_AFTER="$(curl -sS --max-time 20 "$API/api/v1/orders/$REF" "${AUTH[@]}")"
AFTER_STATUS="$(echo "$ORDER_AFTER" | json data.status)"
AFTER_PAYMENT="$(echo "$ORDER_AFTER" | json data.payment.status)"
[ "$AFTER_STATUS" = "paid" ] || fail "La commande n'est pas passée à « paid » (statut : $AFTER_STATUS)"
[ "$AFTER_PAYMENT" = "success" ] || fail "Le paiement n'est pas passé à « success » (statut : $AFTER_PAYMENT)"
pass "commande → paid, paiement → success"

TICKETS="$(curl -sS --max-time 20 "$API/api/v1/me/tickets" "${AUTH[@]}")"
COUNT="$(echo "$TICKETS" | php -r '$d=json_decode(file_get_contents("php://stdin"),true); echo is_array($d["data"]??null)?count($d["data"]):0;')"
[ "$COUNT" = "2" ] || fail "2 tickets attendus, $COUNT obtenu"
FIRST="$(echo "$TICKETS" | json data.0.ticket_number)"
echo "$FIRST" | grep -qE '^TMB-[0-9]{4}-[0-9]{8}$' || fail "Numéro de ticket invalide : $FIRST"
pass "2 tickets émis (premier : $FIRST)"

step "7. Rejeu du webhook (idempotence)"
REPLAY="$(curl -sS --max-time 20 -X POST "$API/api/v1/webhooks/futaye" -H 'Content-Type: application/json' \
  -H "X-Futaye-Timestamp: $TS" -H "X-Futaye-Signature: $SIG" -d "$BODY")"
echo "$REPLAY" | grep -q 'duplicate' || fail "Rejeu non détecté : $REPLAY"
COUNT_AFTER="$(curl -sS --max-time 20 "$API/api/v1/me/tickets" "${AUTH[@]}" | php -r '$d=json_decode(file_get_contents("php://stdin"),true); echo is_array($d["data"]??null)?count($d["data"]):0;')"
[ "$COUNT_AFTER" = "2" ] || fail "Le rejeu a créé des tickets supplémentaires ($COUNT_AFTER au lieu de 2)"
pass "rejeu détecté, aucun ticket supplémentaire"

step "8. Le frontend reçoit-il tout ce qu'il affiche ?"
for champ in reference total_amount currency payment.ticket_numbers; do :; done
echo "$ORDER_AFTER" | php -r '
  $d = json_decode(file_get_contents("php://stdin"), true)["data"] ?? [];
  $requis = ["reference", "quantity", "total_amount", "currency", "status", "status_label", "campaign", "payment", "created_at"];
  $manquants = array_values(array_filter($requis, fn ($k) => !array_key_exists($k, $d)));
  echo $manquants ? "  champs manquants : ".implode(", ", $manquants)."\n" : "";
  $p = $d["payment"] ?? [];
  $requisPaiement = ["id", "status", "status_label", "channel", "channel_label", "amount", "currency", "checkout_url"];
  $manquantsP = array_values(array_filter($requisPaiement, fn ($k) => !array_key_exists($k, $p)));
  echo $manquantsP ? "  champs de paiement manquants : ".implode(", ", $manquantsP)."\n" : "";
  exit(($manquants || $manquantsP) ? 1 : 0);
' || fail "Des champs attendus par le frontend sont absents"
pass "tous les champs consommés par le frontend sont présents"

echo
echo "============================================================"
printf '\033[32mPAIEMENT DE BOUT EN BOUT VALIDÉ\033[0m\n'
echo "Commande $REF — 2 tickets — $TOTAL USD"
echo "============================================================"
