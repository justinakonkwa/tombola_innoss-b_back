#!/usr/bin/env bash
# =============================================================================
# Tombola Innoss'B — test de fumée de bout en bout (HTTP)
# =============================================================================
#
# Vérifie le parcours critique sur une API démarrée :
#   inscription → catalogue → commande → paiement → webhook signé → tickets
#
# Usage :
#   ./scripts/smoke-test.sh [http://localhost:8000]
#
# Le script LIT le token Futaye depuis api/.env pour signer le webhook,
# exactement comme le fait la passerelle. Il ne modifie aucun fichier.
# =============================================================================
set -euo pipefail

API="${1:-http://localhost:8000}"
# Résolution de l'emplacement de l'API : fonctionne aussi bien dans le
# monorepo (api/ + web/) que dans le dépôt backend autonome (racine = api/).
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [ -f "$SCRIPT_DIR/../api/.env" ]; then
  APP_DIR="$(cd "$SCRIPT_DIR/../api" && pwd)"
else
  APP_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
fi
ENV_FILE="$APP_DIR/.env"

pass() { printf '\033[32m✓\033[0m %s\n' "$1"; }
fail() { printf '\033[31m✗\033[0m %s\n' "$1"; exit 1; }
info() { printf '\033[36m•\033[0m %s\n' "$1"; }

need() { command -v "$1" >/dev/null 2>&1 || fail "Commande requise absente : $1"; }
need curl
need php

[ -f "$ENV_FILE" ] || fail "Fichier introuvable : $ENV_FILE"

get_env() { grep -E "^$1=" "$ENV_FILE" | head -1 | cut -d= -f2- | tr -d '"' | tr -d "'"; }
FUTAYE_TOKEN="$(get_env FUTAYE_TOKEN)"
[ -n "$FUTAYE_TOKEN" ] || fail "FUTAYE_TOKEN absent de $ENV_FILE"

jq_get() { php -r '$d=json_decode(file_get_contents("php://stdin"),true); $p=explode(".",$argv[1]); foreach($p as $k){ if(!is_array($d)||!array_key_exists($k,$d)){ exit(1);} $d=$d[$k]; } echo is_scalar($d)?$d:json_encode($d);' "$1"; }

echo "============================================================"
echo " Test de fumée — $API"
echo "============================================================"

# ---------------------------------------------------------------- 1. santé
info "1. Santé du service"
HEALTH="$(curl -sS --max-time 10 "$API/api/v1/health")" || fail "API injoignable sur $API"
echo "$HEALTH" | grep -q '"status":"ok"' || fail "Le service n'est pas sain : $HEALTH"
DB_STATE="$(echo "$HEALTH" | jq_get data.database 2>/dev/null || echo "$HEALTH" | jq_get database || echo '?')"
pass "service en ligne (base : $DB_STATE)"

# ------------------------------------------------------------- 2. catalogue
info "2. Catalogue public"
CAMPAIGNS="$(curl -sS --max-time 10 "$API/api/v1/campaigns")"
SLUG="$(echo "$CAMPAIGNS" | php -r '$d=json_decode(file_get_contents("php://stdin"),true); echo $d["data"][0]["slug"] ?? "";')"
[ -n "$SLUG" ] || fail "Aucune campagne publique. Lancez « php artisan db:seed »."
PRICE="$(echo "$CAMPAIGNS" | php -r '$d=json_decode(file_get_contents("php://stdin"),true); echo $d["data"][0]["ticket_price"] ?? "";')"
pass "campagne publique trouvée : $SLUG (ticket $PRICE)"

# ---------------------------------------------------------- 3. inscription
info "3. Inscription d'un participant"
STAMP="$(date +%s)"
EMAIL="smoke+$STAMP@example.com"
PHONE="24399${STAMP: -7}"
REGISTER="$(curl -sS --max-time 15 -X POST "$API/api/v1/auth/register" \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d "{\"first_name\":\"Test\",\"last_name\":\"Fumee\",\"phone\":\"$PHONE\",\"email\":\"$EMAIL\",\"country\":\"CD\",\"password\":\"Fumee++2026*\",\"password_confirmation\":\"Fumee++2026*\",\"accept_terms\":true}")"

TOKEN="$(echo "$REGISTER" | php -r '$d=json_decode(file_get_contents("php://stdin"),true); echo $d["data"]["tokens"]["access_token"] ?? "";')"
[ -n "$TOKEN" ] || fail "Inscription échouée : $REGISTER"
pass "compte créé ($EMAIL)"

AUTH=(-H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -H 'Accept: application/json')

# ------------------------------------------------------------- 4. commande
info "4. Création d'une commande (3 tickets)"
IDEM="smoke-$STAMP"
ORDER="$(curl -sS --max-time 15 -X POST "$API/api/v1/orders" "${AUTH[@]}" \
  -H "Idempotency-Key: $IDEM" \
  -d "{\"campaign\":\"$SLUG\",\"quantity\":3}")"

REF="$(echo "$ORDER" | php -r '$d=json_decode(file_get_contents("php://stdin"),true); echo $d["data"]["reference"] ?? "";')"
TOTAL="$(echo "$ORDER" | php -r '$d=json_decode(file_get_contents("php://stdin"),true); echo $d["data"]["total_amount"] ?? "";')"
[ -n "$REF" ] || fail "Création de commande échouée : $ORDER"

EXPECTED="$(php -r "echo number_format(3 * (float)'$PRICE', 2, '.', '');")"
[ "$(php -r "echo number_format((float)'$TOTAL',2,'.','');")" = "$EXPECTED" ] \
  || fail "Montant incohérent : serveur=$TOTAL attendu=$EXPECTED"
pass "commande $REF créée, total calculé par le serveur : $TOTAL $EXPECTED"

# Idempotence : rejouer la même requête ne doit PAS créer une seconde commande.
ORDER2="$(curl -sS --max-time 15 -X POST "$API/api/v1/orders" "${AUTH[@]}" \
  -H "Idempotency-Key: $IDEM" -d "{\"campaign\":\"$SLUG\",\"quantity\":3}")"
REF2="$(echo "$ORDER2" | php -r '$d=json_decode(file_get_contents("php://stdin"),true); echo $d["data"]["reference"] ?? "";')"
[ "$REF" = "$REF2" ] || fail "Idempotence rompue : $REF puis $REF2"
pass "idempotence respectée (même clé → même commande)"

# ------------------------------------------------------- 5. webhook signé
info "5. Webhook PAYMENT_SUCCESS signé (HMAC-SHA256)"
PAY_ID="$(php -r 'echo "SMOKE".bin2hex(random_bytes(6));')"
BODY="$(php -r "echo json_encode(['event'=>'PAYMENT_SUCCESS','id'=>getenv('PAY_ID'),'reference'=>'SMOKE-REF','status'=>'SUCCESS','amount'=>(float)getenv('TOTAL'),'commission'=>0.15,'net'=>(float)getenv('TOTAL')-0.15,'currency'=>'USD'], JSON_UNESCAPED_SLASHES);" 2>/dev/null || true)"
export PAY_ID TOTAL
BODY="$(PAY_ID="$PAY_ID" TOTAL="$TOTAL" php -r 'echo json_encode(["event"=>"PAYMENT_SUCCESS","id"=>getenv("PAY_ID"),"reference"=>"SMOKE-REF","status"=>"SUCCESS","amount"=>(float)getenv("TOTAL"),"commission"=>0.15,"net"=>(float)getenv("TOTAL")-0.15,"currency"=>"USD"], JSON_UNESCAPED_SLASHES);')"

# Le paiement doit exister côté serveur : on passe par la vraie API de paiement
# si la passerelle est configurée, sinon on injecte le paiement en base via artisan.
info "   création du paiement côté serveur…"
PAYMENT_ID="$(cd "$APP_DIR" && php artisan tombola:test-payment "$REF" "$PAY_ID" 2>/dev/null | tail -1)"
[ -n "$PAYMENT_ID" ] || fail "Impossible de créer le paiement de test"
pass "paiement de test créé ($PAY_ID)"

TS="$(date +%s)"
SIG="$(TS="$TS" BODY="$BODY" TOKEN="$FUTAYE_TOKEN" php -r 'echo hash_hmac("sha256", getenv("TS").".".getenv("BODY"), getenv("TOKEN"));')"

WEBHOOK="$(curl -sS --max-time 15 -X POST "$API/api/v1/webhooks/futaye" \
  -H 'Content-Type: application/json' \
  -H "X-Futaye-Timestamp: $TS" \
  -H "X-Futaye-Signature: $SIG" \
  -d "$BODY")"
echo "$WEBHOOK" | grep -qE 'traité|processed' || fail "Webhook refusé : $WEBHOOK"
pass "webhook accepté et traité"

# Signature invalide → doit être rejetée.
BAD="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 10 -X POST "$API/api/v1/webhooks/futaye" \
  -H 'Content-Type: application/json' \
  -H "X-Futaye-Timestamp: $TS" \
  -H 'X-Futaye-Signature: 0000000000000000000000000000000000000000000000000000000000000000' \
  -d "$BODY")"
[ "$BAD" = "401" ] || fail "Signature invalide acceptée (HTTP $BAD) — FAILLE"
pass "signature invalide rejetée (401)"

# Rejeu → doit être détecté comme doublon, sans nouveau ticket.
REPLAY="$(curl -sS --max-time 15 -X POST "$API/api/v1/webhooks/futaye" \
  -H 'Content-Type: application/json' \
  -H "X-Futaye-Timestamp: $TS" \
  -H "X-Futaye-Signature: $SIG" \
  -d "$BODY")"
echo "$REPLAY" | grep -q 'duplicate' || fail "Rejeu non détecté : $REPLAY"
pass "rejeu détecté (duplicate)"

# -------------------------------------------------------------- 6. tickets
info "6. Tickets générés"
TICKETS="$(curl -sS --max-time 15 "$API/api/v1/me/tickets" "${AUTH[@]}")"
COUNT="$(echo "$TICKETS" | php -r '$d=json_decode(file_get_contents("php://stdin"),true); echo is_array($d["data"] ?? null) ? count($d["data"]) : 0;')"
[ "$COUNT" = "3" ] || fail "Nombre de tickets attendu 3, obtenu $COUNT (doublon de webhook ?)"
FIRST="$(echo "$TICKETS" | php -r '$d=json_decode(file_get_contents("php://stdin"),true); echo $d["data"][0]["ticket_number"] ?? "";')"
echo "$FIRST" | grep -qE '^TMB-[0-9]{4}-[0-9]{8}$' || fail "Format de numéro invalide : $FIRST"
pass "3 tickets émis, premier numéro : $FIRST"

# Le webhook rejoué ne doit pas avoir créé de tickets supplémentaires.
BATCH_COUNT="$(cd "$APP_DIR" && php artisan tombola:order-summary "$REF" 2>/dev/null | tail -1)"
[ "$BATCH_COUNT" = "1" ] || fail "Plusieurs lots pour un même paiement ($BATCH_COUNT) — FAILLE"
pass "un seul lot de tickets pour le paiement (idempotence confirmée)"

echo "============================================================"
printf '\033[32mTOUS LES CONTRÔLES SONT PASSÉS\033[0m\n'
echo "============================================================"
