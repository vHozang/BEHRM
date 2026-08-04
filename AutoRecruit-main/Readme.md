# AutoRecruit - He thong loc CV bang AI

![Project](https://img.shields.io/badge/Project-AutoRecruit-0A66C2)
![Python](https://img.shields.io/badge/Python-3.11-3776AB?logo=python&logoColor=white)
![FastAPI](https://img.shields.io/badge/FastAPI-0.115-009688?logo=fastapi&logoColor=white)
![Docker](https://img.shields.io/badge/Docker-Compose-2496ED?logo=docker&logoColor=white)
![License](https://img.shields.io/badge/License-MIT-yellow.svg)

AutoRecruit giup sang loc CV theo Job Description (JD), cham diem phu hop va xep hang ung vien.

He thong dung MinerU nhu tang OCR/doc bo cuc tuy chon, sau do chuan hoa CV thanh JSON nghiep vu va cham theo rubric co bang chung. MinerU khong tu quyet dinh diem tuyen dung.

## Cau truc thu muc

- `app/`: FastAPI backend va giao dien static.
- `data/`: du lieu runtime (SQLite, CV, JD test, ket qua rank).
- `training/`: toan bo pipeline train/rank model embedding.
- `app/modules/`: resume parser, JD rubric, human feedback va calibration pipeline.
- `skills/`: quy tac on dinh cho AI; khong chua feedback runtime.
- `mineru-local/`: image MinerU pipeline chay rieng tren may local.
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

## MinerU local

Source MinerU duoc clone rieng ben canh repository BEHRM. Build va chay OCR service:

```powershell
docker compose --profile mineru build mineru
docker compose --profile mineru up -d mineru
curl.exe http://localhost:8001/health
docker compose up -d --build --force-recreate backend
```

Endpoint `POST /parse` tra ve CV JSON va metadata parser. Gui them `force_mineru=true` de ep test MinerU; neu MinerU loi, backend tu dong fallback sang PyMuPDF/python-docx.

Theo MinerU Open Source License, giao dien/tai lieu dich vu online can neu ro he thong co su dung MinerU de doc tai lieu.

Mac Apple Silicon M3 dung skill `docs/resume/mineru-resume-local/SKILL.md` de kiem tra Docker RAM toi thieu 16 GB, build native ARM64 va ket noi resume-backend qua Tailscale.

## Human-in-the-loop

- AI luu assessment theo tung rubric, bang chung, confidence va model version.
- HR/Truong phong gui diem tung tieu chi qua `POST /feedback` sau khi cham doc lap.
- Chi review duoc chap thuan va du ly do moi vao training dataset.
- Model chi duoc train theo dot va kich hoat neu ket qua danh gia khong kem baseline.

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
