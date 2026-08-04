# MinerU local service

MinerU is kept as a separate local service because its OCR models and Python dependencies are substantially heavier than the resume API.

The source used for local verification is cloned next to `BEHRM`:

```text
/mnt/d/hrm/MinerU
```

The Docker image is pinned to the matching MinerU release. Build and start the local pipeline backend with:

```bash
docker compose --profile mineru build mineru
docker compose --profile mineru up -d mineru
curl -fsS http://localhost:8001/health
```

Then recreate the resume backend so it can use `http://mineru:8000`:

```bash
docker compose up -d --build --force-recreate backend
```

MinerU runs fully offline after the image and models have been downloaded. The resume service uses its `content_list.json` output and falls back to PyMuPDF/python-docx if MinerU is unavailable.

The product documentation or interface must disclose that document parsing uses MinerU, as required by the MinerU Open Source License for online services.

