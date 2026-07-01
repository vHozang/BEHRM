import argparse
import json
from pathlib import Path
from typing import Dict, List, Tuple

import fitz
import numpy as np
from docx import Document
from sentence_transformers import SentenceTransformer


SUPPORTED_EXTENSIONS = {".pdf", ".docx", ".txt"}


def extract_pdf_text(path: Path) -> str:
    parts: List[str] = []
    with fitz.open(path) as doc:
        for page in doc:
            parts.append(page.get_text())
    return "\n".join(parts).strip()


def extract_docx_text(path: Path) -> str:
    doc = Document(str(path))
    return "\n".join(p.text for p in doc.paragraphs).strip()


def extract_text(path: Path) -> str:
    ext = path.suffix.lower()
    if ext == ".pdf":
        return extract_pdf_text(path)
    if ext == ".docx":
        return extract_docx_text(path)
    if ext == ".txt":
        return path.read_text(encoding="utf-8-sig", errors="ignore").strip()
    return ""


def load_jd_text(jd_text: str, jd_file: str) -> str:
    if jd_text and jd_text.strip():
        return jd_text.strip()
    if jd_file:
        content = Path(jd_file).read_text(encoding="utf-8-sig", errors="ignore").strip()
        if content:
            return content
    raise ValueError("Can cung cap --jd-text hoac --jd-file.")


def collect_cv_files(cv_dir: Path, excluded_paths: List[Path]) -> List[Path]:
    if not cv_dir.exists():
        raise ValueError(f"Thu muc CV khong ton tai: {cv_dir}")
    excluded = {p.resolve() for p in excluded_paths if p}
    files = [
        p
        for p in cv_dir.rglob("*")
        if p.is_file() and p.suffix.lower() in SUPPORTED_EXTENSIONS and p.resolve() not in excluded
    ]
    if not files:
        raise ValueError(f"Khong tim thay CV hop le trong: {cv_dir}")
    return sorted(files)


def build_cv_texts(files: List[Path], max_chars: int) -> Tuple[List[Path], List[str]]:
    kept_files: List[Path] = []
    texts: List[str] = []
    for path in files:
        text = extract_text(path)
        if not text:
            continue
        cleaned = " ".join(text.split())
        if not cleaned:
            continue
        kept_files.append(path)
        texts.append(cleaned[:max_chars])
    if not texts:
        raise ValueError("Tat ca CV deu rong sau khi trich xuat noi dung.")
    return kept_files, texts


def rank_candidates(
    model: SentenceTransformer,
    jd_text: str,
    cv_files: List[Path],
    cv_texts: List[str],
    batch_size: int,
) -> List[Dict[str, float]]:
    jd_vector = model.encode([jd_text], normalize_embeddings=True, convert_to_numpy=True)[0]
    cv_vectors = model.encode(cv_texts, normalize_embeddings=True, convert_to_numpy=True, batch_size=batch_size)

    scores = np.dot(cv_vectors, jd_vector)
    ranked: List[Dict[str, float]] = []
    for path, score in zip(cv_files, scores):
        ranked.append(
            {
                "filename": path.name,
                "path": str(path),
                "cosine_similarity": float(score),
            }
        )
    ranked.sort(key=lambda item: item["cosine_similarity"], reverse=True)
    return ranked


def main() -> None:
    parser = argparse.ArgumentParser(description="Rank CV theo cosine similarity voi JD.")
    parser.add_argument("--model-path", default="./mxbai-cv-tuned")
    parser.add_argument("--jd-text", default="")
    parser.add_argument("--jd-file", default="")
    parser.add_argument("--cv-dir", default="./data")
    parser.add_argument("--output-file", default="./data/cv_ranking.json")
    parser.add_argument("--top-k", type=int, default=10)
    parser.add_argument("--batch-size", type=int, default=16)
    parser.add_argument("--max-chars", type=int, default=5000)
    args = parser.parse_args()

    jd_file_path = Path(args.jd_file).resolve() if args.jd_file else None
    jd_text = load_jd_text(args.jd_text, args.jd_file)
    cv_dir = Path(args.cv_dir)
    cv_files = collect_cv_files(cv_dir, excluded_paths=[jd_file_path] if jd_file_path else [])
    cv_files, cv_texts = build_cv_texts(cv_files, max_chars=max(500, args.max_chars))

    print(f"[1/5] Load model: {args.model_path}")
    model = SentenceTransformer(args.model_path)

    print(f"[2/5] So CV hop le: {len(cv_files)}")
    print("[3/5] Tao vector cho JD...")
    print("[4/5] Tao vector cho tat ca CV va tinh cosine similarity...")
    ranked = rank_candidates(model, jd_text, cv_files, cv_texts, batch_size=max(1, args.batch_size))

    output_path = Path(args.output_file)
    output_path.parent.mkdir(parents=True, exist_ok=True)
    payload = {
        "model_path": args.model_path,
        "jd_preview": jd_text[:250],
        "total_candidates": len(ranked),
        "ranking": ranked,
    }
    output_path.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")

    print(f"[5/5] Hoan tat. Da ghi ket qua vao: {output_path}")
    print("")
    print("Top candidates:")
    for idx, item in enumerate(ranked[: max(1, args.top_k)], start=1):
        print(f"{idx:>2}. {item['filename']} | cosine={item['cosine_similarity']:.4f}")


if __name__ == "__main__":
    main()
