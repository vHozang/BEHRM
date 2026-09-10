#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MIGRATION_SCRIPT="$SCRIPT_DIR/../migrate-runtime-layout.sh"
FIXTURE_ROOT="$(mktemp -d)"
trap 'rm -rf "$FIXTURE_ROOT"' EXIT

old="$FIXTURE_ROOT/Doan2_v2/Doan2"
new="$FIXTURE_ROOT/BE"
mkdir -p \
  "$old/docker/certbot/conf/archive/example" \
  "$old/docker/certbot/conf/live/example" \
  "$old/docker/certbot/www/.well-known" \
  "$old/storage/app/private" \
  "$new/docker/certbot/conf/live/example" \
  "$new/storage/app/private"

printf 'APP_ENV=production\nAPP_DEBUG=false\n' > "$old/.env"
chmod 0644 "$old/.env"
printf 'old-certificate\n' > "$old/docker/certbot/conf/archive/example/fullchain1.pem"
printf 'old-private-key\n' > "$old/docker/certbot/conf/archive/example/privkey1.pem"
ln -s '../../archive/example/fullchain1.pem' "$old/docker/certbot/conf/live/example/fullchain.pem"
ln -s '../../archive/example/privkey1.pem' "$old/docker/certbot/conf/live/example/privkey.pem"
ln -s '../../archive/example/fullchain1.pem' "$old/docker/certbot/conf/live/example/chain.pem"
printf 'old-challenge\n' > "$old/docker/certbot/www/.well-known/token"
printf 'old-upload\n' > "$old/storage/app/private/legacy.txt"
printf 'new-certificate\n' > "$new/docker/certbot/conf/live/example/fullchain.pem"
ln -s '../../archive/example/missing.pem' "$new/docker/certbot/conf/live/example/chain.pem"
printf 'new-upload\n' > "$new/storage/app/private/existing.txt"

"$MIGRATION_SCRIPT" "$FIXTURE_ROOT"

cmp "$old/.env" "$new/.env"
if env_mode="$(stat -c '%a' "$new/.env" 2>/dev/null)"; then
  :
else
  env_mode="$(stat -f '%Lp' "$new/.env")"
fi
test "$env_mode" = 600
grep -qx 'new-certificate' "$new/docker/certbot/conf/live/example/fullchain.pem"
test ! -L "$new/docker/certbot/conf/live/example/fullchain.pem"
test -f "$new/docker/certbot/conf/archive/example/fullchain1.pem"
test -f "$new/docker/certbot/conf/archive/example/privkey1.pem"
test -L "$new/docker/certbot/conf/live/example/privkey.pem"
test "$(readlink "$new/docker/certbot/conf/live/example/privkey.pem")" = '../../archive/example/privkey1.pem'
test -L "$new/docker/certbot/conf/live/example/chain.pem"
test "$(readlink "$new/docker/certbot/conf/live/example/chain.pem")" = '../../archive/example/missing.pem'
"$MIGRATION_SCRIPT" "$FIXTURE_ROOT"
test "$(readlink "$new/docker/certbot/conf/live/example/chain.pem")" = '../../archive/example/missing.pem'
grep -qx 'old-challenge' "$new/docker/certbot/www/.well-known/token"
grep -qx 'old-upload' "$new/storage/app/private/legacy.txt"
grep -qx 'new-upload' "$new/storage/app/private/existing.txt"

printf 'destination-secret\n' > "$new/.env"
"$MIGRATION_SCRIPT" "$FIXTURE_ROOT"
grep -qx 'destination-secret' "$new/.env"

# Migration is copy-only: every source file still exists after repeated runs.
test -f "$old/.env"
test -f "$old/docker/certbot/conf/live/example/fullchain.pem"
test -f "$old/docker/certbot/www/.well-known/token"
test -f "$old/storage/app/private/legacy.txt"

printf 'runtime layout migration test passed\n'
