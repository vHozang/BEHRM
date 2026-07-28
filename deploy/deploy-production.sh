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

cd "$BACKEND_DIR"
docker compose build
docker compose up -d postgres redis
docker compose run --rm --no-deps --user root php composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
docker compose up -d --remove-orphans
docker compose exec -T php php artisan migrate --force
docker compose exec -T php php artisan optimize:clear
docker compose exec -T php php artisan config:cache
docker compose ps
