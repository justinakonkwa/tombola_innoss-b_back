#!/usr/bin/env bash
# =============================================================================
# Tombola Innoss'B — validation du tirage au sort de bout en bout (HTTP)
# =============================================================================
#
# Déroule le cycle complet du tirage sur l'API :
#   achat de tickets → paiement confirmé par webhook signé →
#   création du tirage → fermeture des ventes → gel du pool (snapshot) →
#   exécution (commit-reveal) → publication → vérification publique
#
# Usage : ./scripts/draw-e2e.sh [http://localhost:8000] [quantité_de_tickets]
# =============================================================================
# ---------------------------------------------------------------------------
# AVERTISSEMENT — identifiants de DÉVELOPPEMENT
# ---------------------------------------------------------------------------
# Le couple admin@tombola-innossb.cd / Tombola++2026* n'existe QUE dans un
# environnement local. Le seeder refuse ce mot de passe hors de `local` et
# `testing` : en production il exige TOMBOLA_ADMIN_PASSWORD ou en génère un
# aléatoirement. Ces scripts ne doivent jamais être pointés vers une instance
# de production.
# ---------------------------------------------------------------------------

set -euo pipefail

API="${1:-http://localhost:8000}"
QUANTITY="${2:-30}"
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
step() { printf '\n\033[36m▸ %s\033[0m\n' "$1"; }

get_env() { grep -E "^$1=" "$ENV_FILE" | head -1 | cut -d= -f2- | tr -d '"' | tr -d "'"; }
FUTAYE_TOKEN="$(get_env FUTAYE_TOKEN)"
[ -n "$FUTAYE_TOKEN" ] || fail "FUTAYE_TOKEN absent de $ENV_FILE"

json() { php -r '$d=json_decode(file_get_contents("php://stdin"),true); $p=explode(".",$argv[1]); foreach($p as $k){ $k=is_numeric($k)?(int)$k:$k; if(!is_array($d)||!array_key_exists($k,$d)){ exit(1);} $d=$d[$k]; } echo is_scalar($d)||$d===null?$d:json_encode($d);' "$1"; }

echo "============================================================"
echo " Tirage de bout en bout — $API"
echo "============================================================"

step "1. Achat de $QUANTITY tickets et confirmation du paiement"
STAMP="$(date +%s)"
EMAIL="draw+$STAMP@example.com"
PHONE="24397${STAMP: -7}"

REGISTER="$(curl -sS -X POST "$API/api/v1/auth/register" -H 'Content-Type: application/json' \
  -d "{\"first_name\":\"Tirage\",\"last_name\":\"Test\",\"phone\":\"$PHONE\",\"email\":\"$EMAIL\",\"country\":\"CD\",\"password\":\"Tirage++2026*\",\"password_confirmation\":\"Tirage++2026*\",\"accept_terms\":true}")"
TOKEN="$(echo "$REGISTER" | json data.tokens.access_token)" || fail "Inscription échouée : $REGISTER"
AUTH=(-H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json')

ORDER="$(curl -sS -X POST "$API/api/v1/orders" "${AUTH[@]}" \
  -H "Idempotency-Key: draw-$STAMP" \
  -d "{\"campaign\":\"lamborghini\",\"quantity\":$QUANTITY}")"
REF="$(echo "$ORDER" | json data.reference)" || fail "Commande impossible : $ORDER"
TOTAL="$(echo "$ORDER" | json data.total_amount)"
pass "commande $REF — $QUANTITY tickets, total serveur : $TOTAL"

PAY_ID="DRAW${STAMP}"
PAYMENT_ID="$(cd "$APP_DIR" && php artisan tombola:test-payment "$REF" "$PAY_ID" 2>/dev/null | tail -1)"
[ -n "$PAYMENT_ID" ] || fail "Création du paiement impossible"

BODY="$(PAY_ID="$PAY_ID" TOTAL="$TOTAL" php -r 'echo json_encode(["event"=>"PAYMENT_SUCCESS","id"=>getenv("PAY_ID"),"reference"=>"DRAW-REF","status"=>"SUCCESS","amount"=>(float)getenv("TOTAL"),"commission"=>0.10,"net"=>(float)getenv("TOTAL")-0.10,"currency"=>"USD"], JSON_UNESCAPED_SLASHES);')"
TS="$(date +%s)"
SIG="$(TS="$TS" BODY="$BODY" TOKEN="$FUTAYE_TOKEN" php -r 'echo hash_hmac("sha256", getenv("TS").".".getenv("BODY"), getenv("TOKEN"));')"

curl -sS -X POST "$API/api/v1/webhooks/futaye" -H 'Content-Type: application/json' \
  -H "X-Futaye-Timestamp: $TS" -H "X-Futaye-Signature: $SIG" -d "$BODY" | grep -qE 'traité|processed' \
  || fail "Webhook refusé"
pass "paiement confirmé, tickets émis"

step "2. Connexion administrateur"
ADMIN_LOGIN="$(curl -sS -X POST "$API/api/v1/auth/login" -H 'Content-Type: application/json' \
  -d '{"identifier":"admin@tombola-innossb.cd","password":"Tombola++2026*"}')"
ADMIN_TOKEN="$(echo "$ADMIN_LOGIN" | json data.tokens.access_token)" || fail "Connexion admin impossible : $ADMIN_LOGIN"
ADMIN=(-H "Authorization: Bearer $ADMIN_TOKEN" -H 'Content-Type: application/json')
pass "administrateur authentifié"

CAMPAIGN_ID="$(curl -sS "$API/api/v1/campaigns/lamborghini" | json data.id)"
[ -n "$CAMPAIGN_ID" ] || fail "Campagne lamborghini introuvable"

step "3. Création du tirage (engagement cryptographique publié)"
DRAW="$(curl -sS -X POST "$API/api/v1/admin/draws" "${ADMIN[@]}" -d "{\"campaign_id\":\"$CAMPAIGN_ID\"}")"
DRAW_ID="$(echo "$DRAW" | json data.id)" || fail "Création du tirage impossible : $DRAW"
DRAW_REF="$(echo "$DRAW" | json data.reference)"
COMMITMENT="$(echo "$DRAW" | json data.server_seed_hash)"
pass "tirage $DRAW_REF créé — engagement : ${COMMITMENT:0:24}…"

step "4. Fermeture des ventes"
curl -sS -X POST "$API/api/v1/admin/draws/$DRAW_ID/close" "${ADMIN[@]}" | json data.status >/dev/null || fail "Fermeture impossible"
pass "ventes fermées"

step "5. Gel du pool (snapshot immuable)"
SNAPSHOT="$(curl -sS -X POST "$API/api/v1/admin/draws/$DRAW_ID/snapshot" "${ADMIN[@]}")"
POOL_SIZE="$(echo "$SNAPSHOT" | json data.pool_size)" || fail "Snapshot impossible : $SNAPSHOT"
POOL_HASH="$(echo "$SNAPSHOT" | json data.ticket_pool_hash)"
pass "pool figé : $POOL_SIZE tickets — empreinte ${POOL_HASH:0:24}…"

step "6. Exécution du tirage (graine publique)"
CLIENT_SEED="Tirage public Tombola Innoss'B — $STAMP"
EXECUTE="$(curl -sS -X POST "$API/api/v1/admin/draws/$DRAW_ID/execute" "${ADMIN[@]}" \
  -d "$(CLIENT_SEED="$CLIENT_SEED" php -r 'echo json_encode(["client_seed"=>getenv("CLIENT_SEED")]);')")"
RANDOM_VALUE="$(echo "$EXECUTE" | json data.random_value)" || fail "Exécution impossible : $EXECUTE"
pass "tirage exécuté — HMAC : ${RANDOM_VALUE:0:24}…"

step "7. Publication et notification des gagnants"
PUBLISH="$(curl -sS -X POST "$API/api/v1/admin/draws/$DRAW_ID/publish" "${ADMIN[@]}")"
WINNERS_COUNT="$(echo "$PUBLISH" | php -r '$d=json_decode(file_get_contents("php://stdin"),true); echo is_array($d["data"]["winners"]??null)?count($d["data"]["winners"]):0;')"
[ "$WINNERS_COUNT" -gt 0 ] || fail "Aucun gagnant publié"
pass "$WINNERS_COUNT gagnant(s) publié(s)"

step "8. Vérification publique du tirage"
VERIFY="$(curl -sS "$API/api/v1/draws/$DRAW_REF/verify")"
VERIFIED="$(echo "$VERIFY" | json data.verified)"
[ "$VERIFIED" = "1" ] || fail "Vérification échouée : $VERIFY"
pass "tirage VÉRIFIÉ (engagement, empreinte du pool et gagnants recalculés)"

step "9. Page publique des gagnants"
WINNERS="$(curl -sS "$API/api/v1/winners?per_page=5")"
FIRST_NAME="$(echo "$WINNERS" | json data.0.winner_name)"
FIRST_DATE="$(echo "$WINNERS" | json data.0.draw_date)"
FIRST_TICKET="$(echo "$WINNERS" | json data.0.ticket_number)"
echo "$FIRST_NAME" | grep -q '\*' || fail "Le nom du gagnant n'est pas masqué : $FIRST_NAME"
[ -n "$FIRST_DATE" ] || fail "La date du tirage n'est pas exposée"
pass "gagnant public : $FIRST_NAME — ticket $FIRST_TICKET — tirage du $FIRST_DATE"

echo
echo "============================================================"
printf '\033[32mTIRAGE DE BOUT EN BOUT VALIDÉ\033[0m\n'
echo "Référence : $DRAW_REF   —   vérifiable sur /verifier?reference=$DRAW_REF"
echo "============================================================"
