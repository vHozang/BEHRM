#!/usr/bin/env bash
set -Eeuo pipefail

APP_DIR="${1:-${DEPLOY_PATH:-/opt/hrm}}"
LEGACY_BACKEND_DIR="$APP_DIR/Doan2_v2/Doan2"
BACKEND_DIR="$APP_DIR/BE"

if [ ! -d "$LEGACY_BACKEND_DIR" ]; then
  exit 0
fi

mkdir -p "$BACKEND_DIR"

if [ -f "$LEGACY_BACKEND_DIR/.env" ] && [ ! -e "$BACKEND_DIR/.env" ]; then
  cp -p "$LEGACY_BACKEND_DIR/.env" "$BACKEND_DIR/.env"
  chmod 0600 "$BACKEND_DIR/.env"
  if [ -n "${MIGRATION_TARGET_UID:-}" ] && [ -n "${MIGRATION_TARGET_GID:-}" ]; then
    chown "$MIGRATION_TARGET_UID:$MIGRATION_TARGET_GID" "$BACKEND_DIR/.env"
  fi
fi

copy_missing_tree() {
  local source_dir="$1"
  local destination_dir="$2"

  if [ ! -d "$source_dir" ]; then
    return
  fi

  mkdir -p "$destination_dir"
  while IFS= read -r -d '' source_path; do
    local relative_path="${source_path#"$source_dir"/}"
    local destination_path="$destination_dir/$relative_path"

    if [ -L "$source_path" ]; then
      if [ ! -e "$destination_path" ] && [ ! -L "$destination_path" ]; then
        mkdir -p "$(dirname "$destination_path")"
        ln -s "$(readlink "$source_path")" "$destination_path"
      fi
    elif [ -d "$source_path" ]; then
      mkdir -p "$destination_path"
    elif [ ! -e "$destination_path" ] && [ ! -L "$destination_path" ]; then
      mkdir -p "$(dirname "$destination_path")"
      cp -p "$source_path" "$destination_path"
    fi
  done < <(find "$source_dir" -mindepth 1 -print0)
}

copy_missing_tree "$LEGACY_BACKEND_DIR/docker/certbot/conf" "$BACKEND_DIR/docker/certbot/conf"
copy_missing_tree "$LEGACY_BACKEND_DIR/docker/certbot/www" "$BACKEND_DIR/docker/certbot/www"
copy_missing_tree "$LEGACY_BACKEND_DIR/storage/app" "$BACKEND_DIR/storage/app"
