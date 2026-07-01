# AutoRecruit - He thong loc CV bang AI

![Project](https://img.shields.io/badge/Project-AutoRecruit-0A66C2)
![Python](https://img.shields.io/badge/Python-3.11-3776AB?logo=python&logoColor=white)
![FastAPI](https://img.shields.io/badge/FastAPI-0.115-009688?logo=fastapi&logoColor=white)
![Docker](https://img.shields.io/badge/Docker-Compose-2496ED?logo=docker&logoColor=white)
![License](https://img.shields.io/badge/License-MIT-yellow.svg)

AutoRecruit giup sang loc CV theo Job Description (JD), cham diem phu hop va xep hang ung vien.

## Cau truc thu muc

- `app/`: FastAPI backend va giao dien static.
- `data/`: du lieu runtime (SQLite, CV, JD test, ket qua rank).
- `training/`: toan bo pipeline train/rank model embedding.
- `training/data/`: train_data va bo tai lieu nguon de tao du lieu train.

## Yeu cau

- Docker Desktop (hoac Docker Engine)
- Ket noi internet de pull image/model lan dau

## Khoi dong he thong backend + Ollama

```powershell
docker compose up -d --build ollama backend
docker exec ollama ollama pull mxbai-embed-large
curl.exe http://localhost:8000/health
```

Ky vong: `{"status":"ok"}`

## Train model embedding (Sentence-Transformers)

Lenh 1 dong de build trainer sach va train:

```powershell
docker compose --profile train build --no-cache --pull trainer; if ($LASTEXITCODE -eq 0) { docker compose --profile train run --rm trainer }
```

Model fine-tuned duoc luu tai:

- `./model_output/mxbai-cv-tuned`

## Rank CV bang model da train

### Cach 1: Nhap JD bang text

```powershell
$env:JD_TEXT = @"
Backend Python Developer
Must have: Python, FastAPI, SQL, Docker
Nice to have: NLP, Sentence-Transformers
"@
docker compose --profile rank run --rm ranker
Remove-Item Env:JD_TEXT
```

### Cach 2: Dung file JD

```powershell
docker compose --profile rank run --rm ranker
```

Mac dinh file JD: `./data/jd.txt`  
Ket qua ranking: `./data/cv_ranking.json`

## API co ban

### Cham 1 CV

```powershell
curl.exe -X POST "http://localhost:8000/screen" ^
  -F "file=@C:\path\cv.pdf" ^
  -F "jd_text=Backend Developer. Must have: JavaScript, SQL."
```

### Cham nhieu CV

```powershell
curl.exe -X POST "http://localhost:8000/screen/batch" ^
  -F "files=@C:\path\cv1.pdf" ^
  -F "files=@C:\path\cv2.docx" ^
  -F "jd_text=Frontend Developer. Must have: React, JavaScript, SQL." ^
  -F "analysis_mode=lite" ^
  -F "embedding_budget=24" ^
  -F "top_k=10"
```

## Luu y

- `backend` dang dung embedding qua Ollama (`mxbai-embed-large`).
- `trainer/ranker` dung Sentence-Transformers voi model fine-tuned rieng.
- Dung `analysis_mode=lite` neu VPS nho (2 CPU / 4 GB RAM).

## License

Du an su dung **MIT License**. Xem file [LICENSE](./LICENSE).
