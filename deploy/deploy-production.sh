#!/usr/bin/env bash
set -Eeuo pipefail

APP_DIR="${DEPLOY_PATH:-/opt/hrm}"
BACKEND_DIR="$APP_DIR/Doan2_v2/Doan2"
FRONTEND_DIR="$APP_DIR/dist/public"

if ! command -v docker >/dev/null 2>&1; then
  echo "Docker is required on the VPS" >&2
  exit 1
fi

if ! docker compose version >/dev/null 2>&1; then
  echo "Docker Compose v2 is required on the VPS" >&2
  exit 1
fi

if [ ! -f "$BACKEND_DIR/.env" ]; then
  echo "Missing $BACKEND_DIR/.env; create production secrets on the VPS first" >&2
  exit 1
fi

if ! grep -Eq '^APP_ENV=production$' "$BACKEND_DIR/.env"; then
  echo "APP_ENV must be production in $BACKEND_DIR/.env" >&2
  exit 1
fi

if ! grep -Eq '^APP_DEBUG=(false|0)$' "$BACKEND_DIR/.env"; then
  echo "APP_DEBUG must be false in $BACKEND_DIR/.env" >&2
  exit 1
fi

set_env_value() {
  local key="$1"
  local value="$2"

  if grep -q "^${key}=" "$BACKEND_DIR/.env"; then
    sed -i "s|^${key}=.*|${key}=${value}|" "$BACKEND_DIR/.env"
  else
    printf '\n%s=%s\n' "$key" "$value" >> "$BACKEND_DIR/.env"
  fi
}

# Prefer the Mac resume backend and fail over to Windows when Mac is offline.
set_env_value AUTORECRUIT_MAC_URL "${AUTORECRUIT_MAC_URL:-http://100.105.84.89:8000}"
set_env_value AUTORECRUIT_URL "${AUTORECRUIT_URL:-http://100.105.84.89:8000}"
set_env_value AUTORECRUIT_FALLBACK_URLS "${AUTORECRUIT_FALLBACK_URLS:-http://100.95.129.101:8000}"
set_env_value AUTORECRUIT_CONNECT_TIMEOUT "${AUTORECRUIT_CONNECT_TIMEOUT:-5}"
set_env_value AUTORECRUIT_TIMEOUT "${AUTORECRUIT_TIMEOUT:-120}"

# Reverb credentials are generated once on the VPS and never stored in Git.
for key in REVERB_APP_ID REVERB_APP_KEY REVERB_APP_SECRET; do
  if ! grep -Eq "^${key}=.+$" "$BACKEND_DIR/.env"; then
    case "$key" in
      REVERB_APP_ID) value="hrm-production" ;;
      *) value="$(openssl rand -hex 24)" ;;
    esac
    set_env_value "$key" "$value"
  fi
done
set_env_value BROADCAST_CONNECTION reverb
# Browser connects through the existing HTTPS Nginx proxy.
set_env_value REVERB_HOST devtapcode.io.vn
set_env_value REVERB_PORT 443
set_env_value REVERB_SCHEME https
set_env_value REVERB_PUBLIC_HOST devtapcode.io.vn
set_env_value REVERB_PUBLIC_PORT 443
set_env_value REVERB_PUBLIC_SCHEME https
# Laravel queue workers publish directly over the private Docker network.
set_env_value REVERB_INTERNAL_HOST reverb
set_env_value REVERB_INTERNAL_PORT 8080
set_env_value REVERB_INTERNAL_SCHEME http
set_env_value REVERB_SERVER_HOST 0.0.0.0
set_env_value REVERB_SERVER_PORT 8080
set_env_value REVERB_ALLOWED_ORIGINS devtapcode.io.vn,www.devtapcode.io.vn
set_env_value HRM_ATT_OVERVIEW_CACHE_STORE redis

background_services_stopped=0
restore_background_services() {
  if [ "$background_services_stopped" -eq 1 ]; then
    docker compose up -d worker scheduler reverb >/dev/null 2>&1 || true
  fi
}
trap restore_background_services EXIT

if [ ! -s "$FRONTEND_DIR/index.html" ]; then
  echo "Missing frontend build at $FRONTEND_DIR/index.html" >&2
  exit 1
fi

cd "$BACKEND_DIR"
docker compose build
docker compose up -d postgres redis
docker compose run --rm --no-deps --user root php composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# Prevent workers from reading a new release before its migrations finish.
docker compose stop worker scheduler reverb >/dev/null 2>&1 || true
background_services_stopped=1
docker compose up -d php reverb nginx
docker compose exec -T php php artisan migrate --force

# A fresh production database needs the bundled demo organization and accounts.
if ! docker compose exec -T php php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
exit(Illuminate\Support\Facades\DB::table("employees")->exists() ? 0 : 1);
' </dev/null; then
  docker compose exec -T php php artisan db:seed --force
fi

# Keep public careers content present on existing databases too. This seeder is
# idempotent and does not touch employee, attendance, or payroll data.
docker compose exec -T php php artisan db:seed --class=RecruitmentPostDemoSeeder --force

# Keep the three employee shift demo accounts usable on existing databases.
docker compose exec -T php php artisan db:seed --class=ShiftQuickLoginSeeder --force

# Backfill organization-unit types and branch heads on existing databases.
docker compose exec -T php php artisan db:seed --class=OrganizationStructureSeeder --force

docker compose exec -T php php artisan optimize:clear
docker compose exec -T php php artisan config:cache
docker compose up -d --remove-orphans
background_services_stopped=0

# Nginx resolves the PHP service at startup, so reload it after PHP recreation.
docker compose restart nginx >/dev/null
for attempt in {1..12}; do
  if docker compose exec -T php curl -fkS -H 'Host: devtapcode.io.vn' https://nginx/ \
      | grep -q '<div id="root"></div>' \
    && docker compose exec -T php curl -fkS -H 'Host: devtapcode.io.vn' https://nginx/api/v1/health >/dev/null; then
    break
  fi
  if [ "$attempt" -eq 12 ]; then
    echo "Production frontend or API health check failed" >&2
    exit 1
  fi
  sleep 5
done
docker compose ps
