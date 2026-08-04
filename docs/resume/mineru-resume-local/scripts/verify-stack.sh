#!/usr/bin/env bash
set -euo pipefail

skill_script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
hrm_repo_root="$(cd "$skill_script_dir/../../../.." && pwd)"
compose_dir="${1:-$hrm_repo_root/Doan2_v2/Doan2}"
mac_tailscale_ip="${HRM_MAC_TAILSCALE_IP:-100.105.84.89}"

command -v docker >/dev/null
command -v curl >/dev/null

cd "$compose_dir"
docker compose --profile resume --profile mineru config --quiet

mineru_health="$(curl --connect-timeout 5 --max-time 15 -fsS http://127.0.0.1:8001/health)"
resume_health="$(curl --connect-timeout 5 --max-time 15 -fsS "http://$mac_tailscale_ip:8000/health")"

if [[ "$mineru_health" != *'"status":"healthy"'* ]]; then
  echo "ERROR: MinerU health response is invalid." >&2
  exit 1
fi

if [[ "$resume_health" != *'"status":"ok"'* || "$resume_health" != *'"mineru"'* ]]; then
  echo "ERROR: Resume backend is not reporting a connected MinerU service." >&2
  exit 1
fi

docker compose --profile resume --profile mineru ps mineru resume-backend
echo "MinerU health passed at http://127.0.0.1:8001/health"
echo "Resume health passed at http://$mac_tailscale_ip:8000/health"

