#!/usr/bin/env bash
set -euo pipefail

allow_non_macos=0
if [[ "${1:-}" == "--allow-non-macos" ]]; then
  allow_non_macos=1
fi

if [[ "$(uname -s)" != "Darwin" && "$allow_non_macos" -ne 1 ]]; then
  echo "ERROR: Run this check on the target macOS machine." >&2
  exit 1
fi

command -v docker >/dev/null || {
  echo "ERROR: Docker Desktop is not installed or not on PATH." >&2
  exit 1
}

docker info >/dev/null
docker compose version >/dev/null

docker_arch="$(docker info --format '{{.Architecture}}')"
docker_memory_bytes="$(docker info --format '{{.MemTotal}}')"
minimum_memory_bytes=$((16 * 1024 * 1024 * 1024))

case "$docker_arch" in
  arm64|aarch64) ;;
  *)
    if [[ "$allow_non_macos" -ne 1 ]]; then
      echo "ERROR: Docker server architecture is $docker_arch; expected native arm64." >&2
      exit 1
    fi
    ;;
esac

if (( docker_memory_bytes < minimum_memory_bytes )); then
  docker_memory_gib=$((docker_memory_bytes / 1024 / 1024 / 1024))
  echo "ERROR: Docker has ${docker_memory_gib} GiB RAM; allocate at least 16 GiB." >&2
  exit 1
fi

docker_memory_gib=$((docker_memory_bytes / 1024 / 1024 / 1024))
echo "Docker architecture: $docker_arch"
echo "Docker memory: ${docker_memory_gib} GiB"
echo "Resource check passed. For a 36 GB M3 Mac, 20-24 GiB is recommended."

