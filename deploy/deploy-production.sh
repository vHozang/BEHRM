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

if [ ! -s "$FRONTEND_DIR/index.html" ]; then
  echo "Missing frontend build at $FRONTEND_DIR/index.html" >&2
  exit 1
fi

cd "$BACKEND_DIR"
docker compose build
docker compose up -d postgres redis
docker compose run --rm --no-deps --user root php composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# Prevent workers from reading a new release before its migrations finish.
docker compose stop worker scheduler >/dev/null 2>&1 || true
docker compose up -d php nginx
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

docker compose exec -T php php artisan optimize:clear
docker compose exec -T php php artisan config:cache
docker compose up -d --remove-orphans

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
