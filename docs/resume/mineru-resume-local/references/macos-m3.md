# macOS M3 Setup

## Docker Desktop resources

Open Docker Desktop > Settings > Resources and configure:

- Memory: minimum 16 GB; recommended 20-24 GB for a 36 GB Mac.
- CPUs: 6 or more when available.
- Swap: 4-8 GB.
- Disk image limit: leave at least 30 GB free for Python packages, OCR models, layers, and output.

Apply and restart Docker Desktop after changing resources.

Use native Apple Silicon images. The Docker server architecture must report `arm64` or `aarch64`. Do not add `platform: linux/amd64`; emulation increases build and OCR time.

The pinned CPU wheels have Linux ARM64 builds:

- `torch==2.9.1+cpu`
- `torchvision==0.24.1+cpu`

## Clone and build

```bash
git clone --branch production --single-branch https://github.com/vHozang/BEHRM.git
cd BEHRM/Doan2_v2/Doan2

bash ../../docs/resume/mineru-resume-local/scripts/check-macos-docker.sh
docker compose --profile mineru build mineru
docker compose --profile mineru up -d mineru
```

The first build downloads the MinerU pipeline models. Do not interrupt model download or image export.

## Tailscale and resume-backend

Install and authenticate Tailscale, then verify the Mac address:

```bash
tailscale ip -4
tailscale status
```

Start the stack with the known Mac address:

```bash
RESUME_BIND_IP=100.105.84.89 \
  docker compose --profile resume --profile mineru \
  up -d --build --force-recreate resume-backend
```

If Docker Desktop cannot bind directly to the Tailscale address, temporarily use `RESUME_BIND_IP=0.0.0.0`, restrict TCP/8000 with the macOS firewall and Tailscale ACLs, and verify access from the VPS before continuing.

## Verification

```bash
curl -fsS http://127.0.0.1:8001/health
open http://127.0.0.1:8001/docs
curl -fsS http://100.105.84.89:8000/health
```

The root URL `http://127.0.0.1:8001/` returns `404` by design. Use `/docs` or `/health`.

