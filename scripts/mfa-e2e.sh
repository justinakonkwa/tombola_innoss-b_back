#!/usr/bin/env bash
# =============================================================================
# Tombola Innoss'B — vérification du parcours MFA (TOTP) et du RBAC sensible
# =============================================================================
#
# Exécute réellement le cycle complet de la double authentification :
#   inscription de la 2FA → validation d'un code → nouvelle connexion avec défi
#   → jeton portant l'abilité `mfa` → accès au back-office → désactivation
#
# Le script RESTAURE l'état initial (2FA désactivée) pour ne pas casser les
# autres scripts de vérification.
#
# Usage : ./scripts/mfa-e2e.sh [http://localhost:8000]
# =============================================================================
set -euo pipefail

API="${1:-http://localhost:8000}"
ADMIN_EMAIL="${TOMBOLA_ADMIN_EMAIL:-admin@tombola-innossb.cd}"
ADMIN_PASSWORD="${TOMBOLA_ADMIN_PASSWORD:-Tombola++2026*}"

pass() { printf '\033[32m✓\033[0m %s\n' "$1"; }
fail() { printf '\033[31m✗\033[0m %s\n' "$1"; exit 1; }
step() { printf '\n\033[36m▸ %s\033[0m\n' "$1"; }

# Extrait une valeur JSON. Les booléens sont normalisés en 1/0 : `echo false`
# en PHP n'imprime rien, ce qui rendrait toute comparaison silencieusement fausse.
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

# Génère un code TOTP RFC 6238 à partir d'un secret base32.
# Implémenté ici (et non exposé par l'application) : aucun outil de génération
# de codes à deux facteurs n'est livré avec le backend.
totp() {
  php -r '
    $secret = $argv[1];
    $alphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";
    $bits = "";
    foreach (str_split($secret) as $c) {
        $i = strpos($alphabet, $c);
        if ($i === false) continue;
        $bits .= str_pad(decbin($i), 5, "0", STR_PAD_LEFT);
    }
    $key = "";
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) $key .= chr(bindec($byte));
    }
    $counter = (int) floor(time() / 30);
    $hash = hash_hmac("sha1", pack("N*", 0) . pack("N*", $counter), $key, true);
    $offset = ord(substr($hash, -1)) & 0x0F;
    $value = ((ord($hash[$offset]) & 0x7F) << 24)
           | ((ord($hash[$offset + 1]) & 0xFF) << 16)
           | ((ord($hash[$offset + 2]) & 0xFF) << 8)
           | (ord($hash[$offset + 3]) & 0xFF);
    echo str_pad((string) ($value % 1000000), 6, "0", STR_PAD_LEFT);
  ' "$1"
}

echo "============================================================"
echo " Parcours MFA / RBAC — $API"
echo "============================================================"

step "1. Connexion initiale et état de la 2FA"
LOGIN="$(curl -sS -X POST "$API/api/v1/auth/login" -H 'Content-Type: application/json' \
  -d "{\"identifier\":\"$ADMIN_EMAIL\",\"password\":\"$ADMIN_PASSWORD\"}")"
TOKEN="$(echo "$LOGIN" | json data.tokens.access_token)" || fail "Connexion impossible : $LOGIN"
MFA_ENABLED="$(echo "$LOGIN" | json data.user.mfa_enabled)"
AUTH=(-H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json')
pass "connecté (mfa_enabled=$MFA_ENABLED)"

# Si la 2FA était déjà active, on la désactive d'abord pour partir d'un état connu.
if [ "$MFA_ENABLED" = "1" ]; then
  SECRET_STATE="$(curl -sS -X POST "$API/api/v1/auth/mfa/enroll" "${AUTH[@]}" | json data.secret)"
  CODE="$(totp "$SECRET_STATE")"
  curl -sS -o /dev/null -X POST "$API/api/v1/auth/mfa/disable" "${AUTH[@]}" -d "{\"code\":\"$CODE\"}"
  LOGIN="$(curl -sS -X POST "$API/api/v1/auth/login" -H 'Content-Type: application/json' \
    -d "{\"identifier\":\"$ADMIN_EMAIL\",\"password\":\"$ADMIN_PASSWORD\"}")"
  TOKEN="$(echo "$LOGIN" | json data.tokens.access_token)"
  AUTH=(-H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json')
  pass "état initial rétabli (2FA désactivée)"
fi

step "2. Enrôlement de la 2FA (secret + URI otpauth)"
ENROLL="$(curl -sS -X POST "$API/api/v1/auth/mfa/enroll" "${AUTH[@]}")"
SECRET="$(echo "$ENROLL" | json data.secret)" || fail "Enrôlement impossible : $ENROLL"
URI="$(echo "$ENROLL" | json data.uri)"
[ -n "$SECRET" ] || fail "Secret absent"
echo "$URI" | grep -q '^otpauth://totp/' || fail "URI otpauth invalide : $URI"
pass "secret généré (${#SECRET} caractères base32) et URI otpauth conforme"

step "3. Validation d'un code erroné (doit être refusé)"
BAD="$(curl -sS -o /dev/null -w '%{http_code}' -X POST "$API/api/v1/auth/mfa/confirm" "${AUTH[@]}" -d '{"code":"000000"}')"
[ "$BAD" = "422" ] || fail "Un code erroné a été accepté (HTTP $BAD) — FAILLE"
pass "code erroné refusé (422)"

step "4. Validation du code TOTP correct"
CODE="$(totp "$SECRET")"
CONFIRM="$(curl -sS -o /dev/null -w '%{http_code}' -X POST "$API/api/v1/auth/mfa/confirm" "${AUTH[@]}" -d "{\"code\":\"$CODE\"}")"
[ "$CONFIRM" = "200" ] || fail "Confirmation refusée (HTTP $CONFIRM)"
pass "2FA activée (code TOTP validé)"

step "5. Nouvelle connexion : le défi MFA doit être exigé"
CHALLENGE="$(curl -sS -X POST "$API/api/v1/auth/login" -H 'Content-Type: application/json' \
  -d "{\"identifier\":\"$ADMIN_EMAIL\",\"password\":\"$ADMIN_PASSWORD\"}")"
REQUIRED="$(echo "$CHALLENGE" | json mfa_required)"
[ "$REQUIRED" = "1" ] || fail "Le défi MFA n'est pas exigé : $CHALLENGE"
CHALLENGE_TOKEN="$(echo "$CHALLENGE" | json challenge_token)"
pass "défi MFA exigé, jeton de défi émis"

step "6. Le jeton de défi ne doit donner accès à RIEN d'autre"
LEAK="$(curl -sS -o /dev/null -w '%{http_code}' "$API/api/v1/admin/dashboard" \
  -H "Authorization: Bearer $CHALLENGE_TOKEN" -H 'Accept: application/json')"
[ "$LEAK" = "403" ] || fail "Le jeton de défi donne accès au back-office (HTTP $LEAK) — FAILLE"
pass "jeton de défi refusé sur /admin (403)"

step "7. Résolution du défi et accès au back-office"
RESOLVED="$(curl -sS -X POST "$API/api/v1/auth/mfa/verify" \
  -H "Authorization: Bearer $CHALLENGE_TOKEN" -H 'Content-Type: application/json' \
  -d "{\"code\":\"$(totp "$SECRET")\"}")"
MFA_TOKEN="$(echo "$RESOLVED" | json data.tokens.access_token)" || fail "Résolution du défi impossible : $RESOLVED"
DASH="$(curl -sS -o /dev/null -w '%{http_code}' "$API/api/v1/admin/dashboard" \
  -H "Authorization: Bearer $MFA_TOKEN" -H 'Accept: application/json')"
[ "$DASH" = "200" ] || fail "Accès au back-office refusé avec un jeton MFA (HTTP $DASH)"
pass "accès au back-office autorisé avec le jeton MFA"

step "8. Le jeton de défi est à usage unique"
REPLAY="$(curl -sS -o /dev/null -w '%{http_code}' -X POST "$API/api/v1/auth/mfa/verify" \
  -H "Authorization: Bearer $CHALLENGE_TOKEN" -H 'Content-Type: application/json' \
  -d "{\"code\":\"$(totp "$SECRET")\"}")"
[ "$REPLAY" != "200" ] || fail "Le jeton de défi est réutilisable — FAILLE"
pass "jeton de défi consommé (HTTP $REPLAY)"

step "9. Désactivation de la 2FA et restauration de l'état"
DISABLE="$(curl -sS -o /dev/null -w '%{http_code}' -X POST "$API/api/v1/auth/mfa/disable" \
  -H "Authorization: Bearer $MFA_TOKEN" -H 'Content-Type: application/json' \
  -d "{\"code\":\"$(totp "$SECRET")\"}")"
[ "$DISABLE" = "200" ] || fail "Désactivation refusée (HTTP $DISABLE)"

RESTORED="$(curl -sS -X POST "$API/api/v1/auth/login" -H 'Content-Type: application/json' \
  -d "{\"identifier\":\"$ADMIN_EMAIL\",\"password\":\"$ADMIN_PASSWORD\"}")"
STATE="$(echo "$RESTORED" | json data.user.mfa_enabled)"
[ "$STATE" = "0" ] || fail "La 2FA est restée active : les autres scripts seraient cassés"
pass "2FA désactivée, état de développement restauré"

echo
echo "============================================================"
printf '\033[32mPARCOURS MFA ET RBAC VALIDÉS\033[0m\n'
echo "============================================================"
