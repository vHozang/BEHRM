---
name: mineru-resume-local
description: Build, run, diagnose, and verify the HRM MinerU document parser and local resume-backend on Windows, Linux, or Apple Silicon macOS. Use when setting up MinerU 3.4.4, parsing PDF/DOCX CVs to JSON, testing `/file_parse` or `/parse`, configuring Docker Desktop resources on an M-series Mac, connecting the resume service over Tailscale, or diagnosing MinerU health, OCR, CPU architecture, fallback, and dependency failures.
---

# MinerU Resume Local

Use MinerU only as the document/OCR layer. Keep CV normalization, JD rubric generation, scoring, human review, and model calibration in `AutoRecruit-main`.

## Workflow

1. Locate the BEHRM repository and read:
   - `AutoRecruit-main/mineru-local/Dockerfile`
   - `AutoRecruit-main/app/modules/resume_parser.py`
   - `Doan2_v2/Doan2/docker-compose.yml`
2. On Apple Silicon, read [references/macos-m3.md](references/macos-m3.md) and run:

   ```bash
   bash docs/resume/mineru-resume-local/scripts/check-macos-docker.sh
   ```

3. Build the native CPU image from `Doan2_v2/Doan2`:

   ```bash
   docker compose --profile mineru build mineru
   docker compose --profile mineru up -d mineru
   ```

4. Start the resume API on the machine's Tailscale address. For the known Mac:

   ```bash
   RESUME_BIND_IP=100.105.84.89 \
     docker compose --profile resume --profile mineru \
     up -d --build --force-recreate resume-backend
   ```

5. Verify the stack:

   ```bash
   bash docs/resume/mineru-resume-local/scripts/verify-stack.sh
   ```

6. Force one real PDF through `POST /parse` with `force_mineru=true`. Confirm:
   - `profile.parser.parser_name` is `mineru`.
   - `profile.parser.parser_version` is `3.4.4`.
   - `warnings` is empty or explicitly explains a fallback.
   - Skill evidence contains source pages.
7. Run objective pipeline tests, Laravel tests, the recruitment regression script, the frontend build, Compose validation, and `git diff --check`.

## Apple Silicon Rules

- Allocate at least 16 GB RAM to Docker Desktop. With an M3 and 36 GB RAM, prefer 20-24 GB.
- Build native `linux/arm64`; do not force `linux/amd64` or Rosetta for this stack.
- Use the MinerU `pipeline` backend on CPU. Do not select VLM/hybrid for this machine unless separately benchmarked.
- Keep PyTorch pinned to CPU wheels. Confirm `torch.cuda.is_available()` is false.
- Keep `six==1.17.0`; MinerU/PaddleOCR imports it at runtime.
- Reserve at least 30 GB of free disk before the first build and model download.

## Endpoint Semantics

- `GET http://127.0.0.1:8001/health`: MinerU health.
- `GET http://127.0.0.1:8001/docs`: Swagger UI.
- `POST http://127.0.0.1:8001/file_parse`: MinerU parsing API.
- `GET http://127.0.0.1:8001/`: expected `404`; this does not mean MinerU is down.
- `GET http://100.105.84.89:8000/health`: Mac resume-backend health over Tailscale.
- `POST http://100.105.84.89:8000/parse`: normalized CV JSON endpoint.

## Fallback Rules

Read [references/api-and-fallback.md](references/api-and-fallback.md) before changing Laravel integration or production environment variables.

- Always order resume endpoints as Mac `100.105.84.89` first, then Windows `100.95.129.101`.
- Apply failover to screening, feedback, statistics, adjustments, and recruitment outcomes.
- Keep MinerU bound to `127.0.0.1:8001`; expose only resume-backend over Tailscale.
- Use a short connect timeout so an offline Mac fails over quickly.

## Safety

- Do not commit the cloned upstream MinerU source into BEHRM; pin the package/image version instead.
- Do not print candidate names, emails, phone numbers, or raw CV text in verification logs.
- Delete temporary CV response files and QA database rows after testing.
- Preserve fallback to PyMuPDF/python-docx when MinerU is unavailable.
- Keep the public MinerU attribution because the online service uses MinerU to parse documents.

