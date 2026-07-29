#!/usr/bin/env bash
set -Eeuo pipefail

APP_DIR="${DEPLOY_PATH:-/opt/hrm}"
BACKEND_DIR="$APP_DIR/Doan2_v2/Doan2"

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

cd "$BACKEND_DIR"
docker compose build
docker compose up -d postgres redis
docker compose run --rm --no-deps --user root php composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# Prevent workers from reading a new release before its migrations finish.
docker compose stop worker scheduler >/dev/null 2>&1 || true
docker compose up -d php nginx resume-backend
docker compose exec -T php php artisan migrate --force
docker compose exec -T php php artisan optimize:clear
docker compose exec -T php php artisan config:cache
docker compose up -d --remove-orphans

# Nginx resolves the PHP service at startup, so reload it after PHP recreation.
docker compose restart nginx >/dev/null
for attempt in {1..12}; do
  if docker compose exec -T nginx wget -qO- http://127.0.0.1/api/v1/health >/dev/null; then
    break
  fi
  if [ "$attempt" -eq 12 ]; then
    echo "Production API health check failed" >&2
    exit 1
  fi
  sleep 5
done
docker compose ps
