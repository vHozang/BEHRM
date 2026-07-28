#!/usr/bin/env bash
set -Eeuo pipefail

if [ "$(id -u)" -ne 0 ]; then
  echo "Run this script as root on the VPS" >&2
  exit 1
fi

: "${DEPLOY_PUBLIC_KEY:?Set DEPLOY_PUBLIC_KEY to the public SSH key for user deloy}"
DEPLOY_USER="${DEPLOY_USER:-deloy}"
DEPLOY_PATH="${DEPLOY_PATH:-/opt/hrm}"
REPO_URL="${REPO_URL:-https://github.com/vHozang/BEHRM.git}"

if ! command -v git >/dev/null 2>&1; then
  apt-get update
  DEBIAN_FRONTEND=noninteractive apt-get install -y git
fi

if ! command -v docker >/dev/null 2>&1; then
  echo "Docker is not installed. Install Docker Engine and the Compose v2 plugin, then run this script again." >&2
  exit 1
fi

if ! docker compose version >/dev/null 2>&1; then
  echo "Docker Compose v2 plugin is not installed. Install it, then run this script again." >&2
  exit 1
fi

if ! id "$DEPLOY_USER" >/dev/null 2>&1; then
  useradd --create-home --shell /bin/bash "$DEPLOY_USER"
fi

if getent group docker >/dev/null 2>&1; then
  usermod -aG docker "$DEPLOY_USER"
else
  echo "Docker group is missing; verify the Docker installation" >&2
  exit 1
fi

DEPLOY_HOME="$(getent passwd "$DEPLOY_USER" | cut -d: -f6)"
install -d -m 700 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "$DEPLOY_HOME/.ssh"
printf '%s\n' "$DEPLOY_PUBLIC_KEY" > "$DEPLOY_HOME/.ssh/authorized_keys"
chown "$DEPLOY_USER:$DEPLOY_USER" "$DEPLOY_HOME/.ssh/authorized_keys"
chmod 600 "$DEPLOY_HOME/.ssh/authorized_keys"

install -d -m 755 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "$DEPLOY_PATH"
if [ ! -d "$DEPLOY_PATH/.git" ]; then
  runuser -u "$DEPLOY_USER" -- git clone --branch production --single-branch "$REPO_URL" "$DEPLOY_PATH"
fi
echo "User $DEPLOY_USER is ready. Put the production .env at $DEPLOY_PATH/Doan2_v2/Doan2/.env."
