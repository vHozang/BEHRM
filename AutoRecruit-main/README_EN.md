# AutoRecruit - AI Resume Screening System

![Project](https://img.shields.io/badge/Project-AutoRecruit-0A66C2)
![Python](https://img.shields.io/badge/Python-3.11-3776AB?logo=python&logoColor=white)
![FastAPI](https://img.shields.io/badge/FastAPI-0.115-009688?logo=fastapi&logoColor=white)
![Docker](https://img.shields.io/badge/Docker-Compose-2496ED?logo=docker&logoColor=white)
![License](https://img.shields.io/badge/License-MIT-yellow.svg)

AutoRecruit screens resumes against a Job Description (JD), scores candidate fit, and ranks applicants.

## Project structure

- `app/`: FastAPI backend and static frontend.
- `data/`: runtime data (SQLite DB, CV files, JD file, ranking output).
- `training/`: training/ranking pipeline with Sentence-Transformers.
- `training/data/`: training dataset and source documents for data preparation.

## Requirements

- Docker Desktop (or Docker Engine)
- Internet access for first-time image/model downloads

## Start backend + Ollama

```powershell
docker compose up -d --build ollama backend
docker exec ollama ollama pull mxbai-embed-large
curl.exe http://localhost:8000/health
```

Expected: `{"status":"ok"}`

## Train embedding model (Sentence-Transformers)

One-line command to clean-build trainer and run training:

```powershell
docker compose --profile train build --no-cache --pull trainer; if ($LASTEXITCODE -eq 0) { docker compose --profile train run --rm trainer }
```

Fine-tuned model output:

- `./model_output/mxbai-cv-tuned`

## Rank CVs using the fine-tuned model

### Option 1: pass JD as text

```powershell
$env:JD_TEXT = @"
Backend Python Developer
Must have: Python, FastAPI, SQL, Docker
Nice to have: NLP, Sentence-Transformers
"@
docker compose --profile rank run --rm ranker
Remove-Item Env:JD_TEXT
```

### Option 2: use JD file

```powershell
docker compose --profile rank run --rm ranker
```

Default JD file: `./data/jd.txt`  
Ranking output: `./data/cv_ranking.json`

## Basic APIs

### Screen single CV

```powershell
curl.exe -X POST "http://localhost:8000/screen" ^
  -F "file=@C:\path\cv.pdf" ^
  -F "jd_text=Backend Developer. Must have: JavaScript, SQL."
```

### Screen batch CVs

```powershell
curl.exe -X POST "http://localhost:8000/screen/batch" ^
  -F "files=@C:\path\cv1.pdf" ^
  -F "files=@C:\path\cv2.docx" ^
  -F "jd_text=Frontend Developer. Must have: React, JavaScript, SQL." ^
  -F "analysis_mode=lite" ^
  -F "embedding_budget=24" ^
  -F "top_k=10"
```

## Notes

- `backend` uses Ollama embeddings (`mxbai-embed-large`).
- `trainer/ranker` uses Sentence-Transformers with a separate fine-tuned model.
- Use `analysis_mode=lite` for smaller VPS (2 CPU / 4 GB RAM).

## License

This project is licensed under the **MIT License**. See [LICENSE](./LICENSE).
