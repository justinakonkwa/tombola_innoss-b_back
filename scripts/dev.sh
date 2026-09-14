#!/usr/bin/env bash
# =============================================================================
# Tombola Innoss'B — démarrage de la pile de développement
# =============================================================================
#
# Démarre en une commande :
#   - l'API Laravel            http://localhost:8000
#   - le worker de files       (notifications, tâches différées)
#   - le planificateur         (expiration des commandes, réconciliation)
#   - le frontend Next.js      http://localhost:3000
#
# Usage :
#   ./scripts/dev.sh              # frontend en mode développement (rechargement à chaud)
#   ./scripts/dev.sh --prod       # frontend servi depuis le build de production
#   ./scripts/dev.sh --api-only   # API, worker et planificateur uniquement
#
# Ctrl+C arrête proprement tous les processus lancés par ce script.
# =============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [ -f "$SCRIPT_DIR/../api/.env" ]; then
  ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
else
  ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
fi
API_DIR="$ROOT/api"
WEB_DIR="$ROOT/web"

API_PORT="${API_PORT:-8000}"
WEB_PORT="${WEB_PORT:-3000}"

MODE="dev"
API_ONLY=false
for arg in "$@"; do
  case "$arg" in
    --prod) MODE="prod" ;;
    --api-only) API_ONLY=true ;;
    -h|--help) sed -n '2,20p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) printf 'Option inconnue : %s\n' "$arg" >&2; exit 2 ;;
  esac
done

bold() { printf '\033[1m%s\033[0m\n' "$1"; }
ok()   { printf '\033[32m✓\033[0m %s\n' "$1"; }
warn() { printf '\033[33m!\033[0m %s\n' "$1"; }
die()  { printf '\033[31m✗\033[0m %s\n' "$1" >&2; exit 1; }

[ -f "$API_DIR/.env" ] || die "Fichier introuvable : $API_DIR/.env (copiez .env.example)"
[ -d "$API_DIR/vendor" ] || die "Dépendances PHP absentes : lancez « composer install » dans api/"

# Le dépôt backend est autonome : le frontend vit dans un dépôt séparé. Dans ce
# cas on démarre la pile API sans échouer, en le signalant.
if [ "$API_ONLY" = false ] && [ ! -d "$WEB_DIR" ]; then
  warn "Aucun frontend à côté de l'API : démarrage de la pile API uniquement."
  warn "Le frontend se lance depuis son propre dépôt (tombola_innoss-b_front)."
  API_ONLY=true
fi

if [ "$API_ONLY" = false ]; then
  [ -d "$WEB_DIR/node_modules" ] || die "Dépendances JS absentes : lancez « npm install » dans web/"
fi

# -----------------------------------------------------------------------------
# Vérifications préalables
# -----------------------------------------------------------------------------
bold "Vérifications"

command -v php >/dev/null || die "php est introuvable"
ok "php $(php -r 'echo PHP_VERSION;')"

if (cd "$API_DIR" && php artisan migrate:status >/dev/null 2>&1); then
  ok "base de données joignable, schéma à jour"
else
  die "Base de données injoignable. Vérifiez DB_* dans api/.env et que PostgreSQL tourne."
fi

for port in "$API_PORT" "$WEB_PORT"; do
  if lsof -nP -iTCP:"$port" -sTCP:LISTEN >/dev/null 2>&1; then
    case "$port" in
      "$API_PORT") die "Le port $port est déjà utilisé (API déjà démarrée ?)" ;;
      "$WEB_PORT") [ "$API_ONLY" = true ] || die "Le port $port est déjà utilisé (frontend déjà démarré ?)" ;;
    esac
  fi
done

# -----------------------------------------------------------------------------
# Nettoyage à l'arrêt
# -----------------------------------------------------------------------------
PIDS=()
cleanup() {
  printf '\n'
  bold "Arrêt"
  for pid in "${PIDS[@]:-}"; do
    [ -n "$pid" ] && kill "$pid" 2>/dev/null || true
  done
  wait 2>/dev/null || true
  ok "tous les processus sont arrêtés"
}
trap cleanup EXIT INT TERM

# -----------------------------------------------------------------------------
# Démarrage
# -----------------------------------------------------------------------------
printf '\n'
bold "Démarrage"

( cd "$API_DIR" && exec php artisan serve --host=127.0.0.1 --port="$API_PORT" ) &
PIDS+=($!)
ok "API            http://localhost:$API_PORT"

( cd "$API_DIR" && exec php artisan queue:work --sleep=3 --tries=3 ) &
PIDS+=($!)
ok "worker de files"

( cd "$API_DIR" && exec php artisan schedule:work ) &
PIDS+=($!)
ok "planificateur (commandes expirées, réconciliation des paiements)"

if [ "$API_ONLY" = false ]; then
  if [ "$MODE" = "prod" ]; then
    [ -d "$WEB_DIR/.next" ] || die "Aucun build de production : lancez « npm run build » dans web/"
    ( cd "$WEB_DIR" && exec npm run start -- --port "$WEB_PORT" ) &
  else
    ( cd "$WEB_DIR" && exec npm run dev -- --port "$WEB_PORT" ) &
  fi
  PIDS+=($!)
  ok "frontend       http://localhost:$WEB_PORT  ($([ "$MODE" = prod ] && echo 'build de production' || echo 'mode développement'))"
fi

# -----------------------------------------------------------------------------
# Attente de disponibilité
# -----------------------------------------------------------------------------
printf '\n'
bold "Attente de disponibilité"

for _ in $(seq 1 30); do
  if curl -sS -o /dev/null --max-time 3 "http://127.0.0.1:$API_PORT/api/v1/health" 2>/dev/null; then
    ok "API prête"
    break
  fi
  sleep 1
done

if [ "$API_ONLY" = false ]; then
  for _ in $(seq 1 45); do
    if curl -sS -o /dev/null --max-time 3 "http://localhost:$WEB_PORT/" 2>/dev/null; then
      ok "frontend prêt"
      break
    fi
    sleep 1
  done
fi

cat <<EOF

$(bold "Le projet tourne")

  Site public     http://localhost:$WEB_PORT
  Back-office     http://localhost:$WEB_PORT/admin
  API             http://localhost:$API_PORT/api/v1/health

  Administrateur  admin@tombola-innossb.cd / Tombola++2026*
                  (identifiants de développement uniquement)

  Vérifications   ./scripts/smoke-test.sh    parcours d'achat
                  ./scripts/payment-e2e.sh   paiement via la passerelle
                  ./scripts/draw-e2e.sh      tirage au sort complet
                  ./scripts/mfa-e2e.sh       double authentification

  Ctrl+C pour tout arrêter.

EOF

wait
