import io
import json
import os
import re
import sqlite3
import unicodedata
from html import unescape
from typing import Any, Dict, List, Optional, Set, Tuple
from urllib.parse import urlparse

import fitz
import numpy as np
import requests
from docx import Document
from fastapi import FastAPI, File, Form, HTTPException, UploadFile
from fastapi.responses import FileResponse
from fastapi.staticfiles import StaticFiles

APP_DIR = os.path.dirname(os.path.abspath(__file__))
STATIC_DIR = os.path.join(APP_DIR, "static")
DATA_DIR = os.getenv("DATA_DIR", "/data")
DB_PATH = os.path.join(DATA_DIR, "screening.db")
OLLAMA_URL = os.getenv("OLLAMA_URL", "http://ollama:11434")
EMBED_MODEL = os.getenv("EMBED_MODEL", "mxbai-embed-large")
BATCH_EMBED_CHUNK_SIZE = int(os.getenv("BATCH_EMBED_CHUNK_SIZE", "16"))
BATCH_EMBED_BUDGET_DEFAULT = int(os.getenv("BATCH_EMBED_BUDGET_DEFAULT", "32"))

MUST_SECTION_MARKERS = [
    "must have",
    "required",
    "requirement",
    "mandatory",
    "bat buoc",
    "yeu cau",
    "can co",
]

NICE_SECTION_MARKERS = [
    "nice to have",
    "preferred",
    "plus point",
    "good to have",
    "uu tien",
    "loi the",
    "bonus",
]

NAME_HEADING_TOKENS = {
    "about me",
    "summary",
    "objective",
    "achievements",
    "expertise",
    "education",
    "project",
    "projects",
    "experience",
    "skills",
    "contact",
    "work experience",
    "certification",
    "activities",
    "ho so",
    "muc tieu",
    "kinh nghiem",
    "hoc van",
    "du an",
    "ky nang",
    "thong tin lien he",
}

JOB_TITLE_PATTERNS: List[Tuple[str, str]] = [
    (r"backend\s+(python\s+)?developer", "backend developer"),
    (r"frontend\s+developer", "frontend developer"),
    (r"full\s*stack\s+developer", "fullstack developer"),
    (r"python\s+developer", "python developer"),
    (r"data\s+analyst", "data analyst"),
    (r"lap trinh vien backend", "backend developer"),
    (r"lap trinh vien frontend", "frontend developer"),
    (r"lap trinh vien python", "python developer"),
]

URL_PATTERN = re.compile(
    r"(?i)\b((?:https?://|www\.)[^\s<>'\"\]\)]+|(?:[a-z0-9-]+\.)+(?:com|vn|io|app|dev|me|net|org)(?:/[^\s<>'\"\]\)]*)?)"
)

PRODUCT_HOST_HINTS = {
    "github.com",
    "gitlab.com",
    "bitbucket.org",
    "behance.net",
    "dribbble.com",
    "figma.com",
    "notion.site",
    "netlify.app",
    "vercel.app",
    "web.app",
    "firebaseapp.com",
    "render.com",
    "herokuapp.com",
}

PROJECT_SECTION_MARKERS = [
    "project",
    "projects",
    "du an",
    "portfolio",
    "case study",
    "san pham",
    "website",
]

app = FastAPI(title="Resume Screening API")
if os.path.isdir(STATIC_DIR):
    app.mount("/static", StaticFiles(directory=STATIC_DIR), name="static")

with open(os.path.join(APP_DIR, "skills.json"), "r", encoding="utf-8") as f:
    SKILL_DICT = json.load(f)


def strip_accents(text: str) -> str:
    decomposed = unicodedata.normalize("NFD", text)
    return "".join(c for c in decomposed if unicodedata.category(c) != "Mn")


def normalize_text(text: str) -> str:
    lowered = text.lower()
    lowered = re.sub(r"\s+", " ", lowered)
    return lowered.strip()


def normalize_for_match(text: str) -> str:
    normalized = strip_accents(text.lower())
    normalized = re.sub(r"[^a-z0-9+.#\s-]", " ", normalized)
    normalized = re.sub(r"\s+", " ", normalized)
    return normalized.strip()


def build_skill_match_index(skill_dict: Dict[str, List[str]]) -> Dict[str, List[str]]:
    indexed: Dict[str, List[str]] = {}
    for canonical, aliases in skill_dict.items():
        all_terms = [canonical] + aliases
        cleaned = [normalize_for_match(term) for term in all_terms if term and term.strip()]
        deduped = sorted(set(cleaned), key=len, reverse=True)
        indexed[canonical] = deduped
    return indexed


SKILL_MATCH_INDEX = build_skill_match_index(SKILL_DICT)


def get_conn() -> sqlite3.Connection:
    os.makedirs(DATA_DIR, exist_ok=True)
    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    return conn


def init_db() -> None:
    conn = get_conn()
    cur = conn.cursor()

    cur.execute(
        """
        CREATE TABLE IF NOT EXISTS jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT,
            jd_text TEXT NOT NULL,
            jd_json TEXT,
            jd_embedding TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
        """
    )

    cur.execute(
        """
        CREATE TABLE IF NOT EXISTS results (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            job_id INTEGER,
            candidate_name TEXT,
            email TEXT,
            filename TEXT,
            jd_text TEXT,
            final_score REAL,
            semantic_score REAL,
            must_have_score REAL,
            nice_score REAL,
            exp_score REAL,
            matched_skills TEXT,
            missing_skills TEXT,
            candidate_skills TEXT,
            years_experience INTEGER,
            raw_text TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
        """
    )

    conn.commit()
    conn.close()


@app.on_event("startup")
def startup_event() -> None:
    init_db()


def extract_pdf_text(file_bytes: bytes) -> str:
    text_parts: List[str] = []
    with fitz.open(stream=file_bytes, filetype="pdf") as doc:
        for page in doc:
            text_parts.append(page.get_text())
    return "\n".join(text_parts)


def extract_docx_text(file_bytes: bytes) -> str:
    doc = Document(io.BytesIO(file_bytes))
    return "\n".join([p.text for p in doc.paragraphs])


def extract_text(filename: str, file_bytes: bytes) -> str:
    lower = filename.lower()
    if lower.endswith(".pdf"):
        return extract_pdf_text(file_bytes)
    if lower.endswith(".docx"):
        return extract_docx_text(file_bytes)
    raise HTTPException(status_code=400, detail=f"Unsupported file type: {filename}")


def extract_email(text: str) -> str:
    m = re.search(r"[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}", text)
    return m.group(0) if m else ""


def looks_like_name(line: str) -> bool:
    if len(line) < 4 or len(line) > 60:
        return False
    if "@" in line or re.search(r"\d{6,}", line):
        return False

    if "/" in line or "&" in line:
        return False

    normalized = normalize_for_match(line)
    if not normalized:
        return False

    if "," in line:
        return False

    location_tokens = [
        "ho chi minh",
        "ha noi",
        "dong nai",
        "binh duong",
        "district",
        "ward",
        "quan",
        "phuong",
    ]
    if any(token in normalized for token in location_tokens):
        return False

    if normalized in NAME_HEADING_TOKENS:
        return False

    if any(token in normalized for token in NAME_HEADING_TOKENS):
        return False

    non_name_tokens = [
        "ui ux",
        "html",
        "css",
        "javascript",
        "react",
        "node",
        "developer",
        "design",
    ]
    if any(token in normalized for token in non_name_tokens):
        return False

    words = re.findall(r"[A-Za-zÀ-ỹ]+", line)
    if len(words) < 2 or len(words) > 5:
        return False

    cap_ratio = sum(1 for w in words if w[:1].isupper() or w.isupper()) / len(words)
    return cap_ratio >= 0.6


def infer_name(text: str, filename: str) -> str:
    lines = [x.strip() for x in text.splitlines() if x.strip()]
    for line in lines[:120]:
        line = re.sub(r"\s+", " ", line).strip("- |")
        if re.fullmatch(r"[A-ZÀ-Ỹ\s]{6,50}", line):
            words = re.findall(r"[A-ZÀ-Ỹ]+", line)
            if 2 <= len(words) <= 5:
                return line

    for line in lines[:120]:
        line = re.sub(r"\s+", " ", line).strip("- |")
        if looks_like_name(line):
            return line
    return os.path.splitext(filename)[0]


def has_alias(normalized_text: str, alias: str) -> bool:
    return re.search(rf"(?<!\w){re.escape(alias)}(?!\w)", normalized_text) is not None


def extract_skills(text: str) -> List[str]:
    found: Set[str] = set()
    normalized = normalize_for_match(text)
    for canonical, aliases in SKILL_MATCH_INDEX.items():
        for alias in aliases:
            if has_alias(normalized, alias):
                found.add(canonical)
                break
    return sorted(found)


def extract_year_values(text: str) -> List[int]:
    normalized = normalize_for_match(text)
    years: List[int] = []

    range_patterns = [
        r"(\d{1,2})\s*(?:-|to|den|~)\s*(\d{1,2})\s*(?:years?|yrs?|nam)",
        r"tu\s*(\d{1,2})\s*den\s*(\d{1,2})\s*(?:years?|yrs?|nam)",
    ]
    for pattern in range_patterns:
        for left, right in re.findall(pattern, normalized):
            years.append(max(int(left), int(right)))

    single_patterns = [
        r"(\d{1,2})\+?\s*(?:years?|yrs?)\s*(?:of\s+)?(?:experience|exp)?",
        r"(\d{1,2})\+?\s*nam\s*(?:kinh\s*nghiem)?",
        r"(?:experience|kinh\s*nghiem)[:\s]{0,8}(\d{1,2})\+?\s*(?:years?|yrs?|nam)?",
        r"(\d{1,2})\+?\s*(?:years?|yrs?|nam)\s*(?:experience|kinh\s*nghiem)",
    ]
    for pattern in single_patterns:
        years.extend(int(x) for x in re.findall(pattern, normalized))

    return [y for y in years if 0 < y <= 40]


def detect_years_experience(text: str) -> int:
    values = extract_year_values(text)
    return max(values) if values else 0


def detect_section_marker(line: str) -> Optional[str]:
    must_positions = [line.find(marker) for marker in MUST_SECTION_MARKERS if marker in line]
    nice_positions = [line.find(marker) for marker in NICE_SECTION_MARKERS if marker in line]

    if not must_positions and not nice_positions:
        return None

    first_must = min(must_positions) if must_positions else None
    first_nice = min(nice_positions) if nice_positions else None

    if first_must is not None and (first_nice is None or first_must <= first_nice):
        return "must"
    if first_nice is not None:
        return "nice"
    return None


def infer_job_title(jd_text: str) -> str:
    normalized = normalize_for_match(jd_text)
    for pattern, label in JOB_TITLE_PATTERNS:
        if re.search(pattern, normalized):
            return label

    lines = [x.strip() for x in jd_text.splitlines() if x.strip()]
    for line in lines[:4]:
        if 4 <= len(line) <= 80 and "@" not in line and not re.search(r"\d{6,}", line):
            return line
    return ""


def detect_language_hint(text: str) -> str:
    normalized = normalize_for_match(text)
    has_accent = strip_accents(text.lower()) != text.lower()

    vi_markers = [
        "kinh nghiem",
        "ky nang",
        "du an",
        "hoc van",
        "mo ta cong viec",
        "yeu cau",
        "ung vien",
        "lap trinh",
    ]
    en_markers = [
        "experience",
        "skills",
        "project",
        "education",
        "job description",
        "requirements",
        "candidate",
        "developer",
    ]

    vi_score = sum(1 for token in vi_markers if token in normalized) + (1 if has_accent else 0)
    en_score = sum(1 for token in en_markers if token in normalized)

    if vi_score > 0 and en_score > 0:
        return "bilingual"
    if vi_score > 0:
        return "vi"
    if en_score > 0:
        return "en"
    return "unknown"


def clean_candidate_url(raw_url: str) -> str:
    url = raw_url.strip().strip("()[]{}<>\"'")
    url = re.sub(r"[.,;:!?]+$", "", url)
    if url.lower().startswith("www."):
        url = f"https://{url}"
    if not re.match(r"^https?://", url, flags=re.IGNORECASE):
        url = f"https://{url}"
    return url


def extract_urls(text: str) -> List[str]:
    urls: List[str] = []
    seen: Set[str] = set()
    email_domains = {"gmail.com", "yahoo.com", "outlook.com", "hotmail.com"}

    for matched in URL_PATTERN.finditer(text):
        raw = matched.group(1)
        start_index = matched.start(1)

        if start_index > 0 and text[start_index - 1] == "@":
            continue

        candidate = clean_candidate_url(raw)
        if "@" in candidate:
            continue
        parsed = urlparse(candidate)
        if not parsed.netloc:
            continue

        host = parsed.netloc.lower().lstrip("www.")
        if host in email_domains and not parsed.path.strip("/"):
            continue

        normalized = candidate.lower()
        if normalized in seen:
            continue
        seen.add(normalized)
        urls.append(candidate)
    return urls


def extract_pdf_annotation_links(file_bytes: bytes) -> List[Dict[str, Any]]:
    links: List[Dict[str, Any]] = []
    seen: Set[Tuple[str, int, str]] = set()

    with fitz.open(stream=file_bytes, filetype="pdf") as doc:
        for page_index, page in enumerate(doc, start=1):
            for item in page.get_links():
                raw_url = item.get("uri", "")
                if not raw_url:
                    continue

                url = clean_candidate_url(raw_url)
                parsed = urlparse(url)
                if not parsed.netloc:
                    continue

                rect_obj = item.get("from")
                rect_list: Optional[List[float]] = None
                rect_key = ""
                if rect_obj is not None:
                    try:
                        rect = fitz.Rect(rect_obj)
                        rect_list = [round(rect.x0, 2), round(rect.y0, 2), round(rect.x1, 2), round(rect.y1, 2)]
                        rect_key = ",".join(str(x) for x in rect_list)
                    except Exception:
                        rect_list = None

                dedupe_key = (url.lower(), page_index, rect_key)
                if dedupe_key in seen:
                    continue
                seen.add(dedupe_key)

                links.append(
                    {
                        "url": url,
                        "source": "pdf_annotation",
                        "page": page_index,
                        "rect": rect_list,
                    }
                )

    return links


def collect_link_candidates(filename: str, file_bytes: bytes, raw_text: str) -> Dict[str, Any]:
    lower = filename.lower()
    if lower.endswith(".pdf"):
        annotation_links = extract_pdf_annotation_links(file_bytes)
        return {
            "detection_mode": "pdf_annotation_strict",
            "links": annotation_links,
        }

    text_links = [{"url": url, "source": "text"} for url in extract_urls(raw_text)]
    return {
        "detection_mode": "text_url",
        "links": text_links,
    }


def is_likely_product_link(url: str) -> bool:
    parsed = urlparse(url)
    host = parsed.netloc.lower().lstrip("www.")
    path = parsed.path.lower()

    if host in PRODUCT_HOST_HINTS:
        return True

    product_tokens = ["project", "portfolio", "case", "demo", "product", "san-pham", "du-an"]
    if any(token in path for token in product_tokens):
        return True

    # Keep broad support for personal domains that likely contain portfolio/demo.
    return host.endswith(".app") or host.endswith(".dev") or host.endswith(".io")


def extract_html_title_and_description(html_text: str) -> Tuple[str, str]:
    title = ""
    description = ""

    title_match = re.search(r"<title[^>]*>(.*?)</title>", html_text, flags=re.IGNORECASE | re.DOTALL)
    if title_match:
        title = unescape(re.sub(r"\s+", " ", title_match.group(1))).strip()

    desc_match = re.search(
        r'<meta[^>]+name=["\']description["\'][^>]+content=["\'](.*?)["\']',
        html_text,
        flags=re.IGNORECASE | re.DOTALL,
    )
    if desc_match:
        description = unescape(re.sub(r"\s+", " ", desc_match.group(1))).strip()

    return title[:180], description[:260]


def fetch_link_metadata(url: str, timeout_sec: int = 6) -> Dict[str, Any]:
    headers = {"User-Agent": "Mozilla/5.0 (ResumeScreeningBot/1.0)"}
    try:
        response = requests.get(url, timeout=timeout_sec, allow_redirects=True, headers=headers)
        content_type = response.headers.get("content-type", "").lower()
        text = response.text[:50000] if "text/html" in content_type else ""
        title, description = extract_html_title_and_description(text) if text else ("", "")
        return {
            "reachable": 200 <= response.status_code < 400,
            "status_code": response.status_code,
            "final_url": response.url,
            "title": title,
            "description": description,
            "error": "",
        }
    except Exception as exc:
        return {
            "reachable": False,
            "status_code": 0,
            "final_url": url,
            "title": "",
            "description": "",
            "error": str(exc),
        }


def build_jd_keyword_set(jd: Dict[str, Any]) -> Set[str]:
    keywords: Set[str] = set(jd.get("must_have", []) + jd.get("nice_to_have", []))
    title = normalize_for_match(jd.get("title", ""))
    keywords.update(token for token in title.split() if len(token) >= 3)
    return keywords


def keyword_overlap_score(text: str, keywords: Set[str]) -> float:
    if not keywords:
        return 0.0
    normalized = normalize_for_match(text)
    hits = sum(1 for token in keywords if has_alias(normalized, token))
    return hits / len(keywords)


def evaluate_text_relevance(text: str, jd_emb: List[float], jd_keywords: Set[str]) -> float:
    lexical = keyword_overlap_score(text, jd_keywords)
    semantic = 0.0
    compact_text = text.strip()[:1200]
    if compact_text:
        try:
            text_emb = get_embedding(compact_text)
            semantic = cosine_similarity(text_emb, jd_emb)
        except Exception:
            semantic = 0.0
    if semantic <= 0:
        return round(lexical, 4)
    return round(0.7 * semantic + 0.3 * lexical, 4)


def inspect_product_links(
    link_candidates: List[Dict[str, Any]], jd_emb: List[float], jd: Dict[str, Any], detection_mode: str
) -> Dict[str, Any]:
    checked_links: List[Dict[str, Any]] = []
    jd_keywords = build_jd_keyword_set(jd)

    for candidate in link_candidates[:5]:
        url = candidate.get("url", "")
        if not url:
            continue
        parsed = urlparse(url)
        host = parsed.netloc.lower().lstrip("www.")
        source = candidate.get("source", "text")
        likely_product = is_likely_product_link(url) or source == "pdf_annotation"
        meta = fetch_link_metadata(url, timeout_sec=5)

        relevance_text = " ".join(
            x for x in [meta.get("title", ""), meta.get("description", ""), meta.get("final_url", url)] if x
        ).strip()
        relevance_score = evaluate_text_relevance(relevance_text, jd_emb, jd_keywords) if relevance_text else 0.0

        checked_links.append(
            {
                "url": url,
                "host": host,
                "source": source,
                "page": candidate.get("page"),
                "rect": candidate.get("rect"),
                "likely_product_link": likely_product,
                "reachable": meta["reachable"],
                "status_code": meta["status_code"],
                "final_url": meta["final_url"],
                "title": meta["title"],
                "description": meta["description"],
                "relevance_score": relevance_score,
                "error": meta["error"],
            }
        )

    return {
        "detection_mode": detection_mode,
        "total_links_found": len(link_candidates),
        "total_links_checked": len(checked_links),
        "product_links_found": sum(1 for item in checked_links if item["likely_product_link"]),
        "links": checked_links,
    }


def extract_project_snippets(raw_text: str, max_snippets: int = 4) -> List[str]:
    lines = [line.strip() for line in raw_text.splitlines() if line.strip()]
    snippets: List[str] = []
    seen: Set[str] = set()

    normalized_lines = [normalize_for_match(line) for line in lines]
    for idx, normalized_line in enumerate(normalized_lines):
        if not any(marker in normalized_line for marker in PROJECT_SECTION_MARKERS):
            continue
        window = lines[idx + 1 : idx + 8]
        text = " ".join(window).strip()
        if len(text) < 30:
            continue
        normalized_text = normalize_for_match(text)
        if normalized_text in seen:
            continue
        seen.add(normalized_text)
        snippets.append(text[:700])
        if len(snippets) >= max_snippets:
            break

    if snippets:
        return snippets

    # Fallback: capture lines that look like project descriptions.
    fallback_markers = ["built", "developed", "implemented", "xay dung", "trien khai", "thiet ke"]
    for line in lines:
        normalized_line = normalize_for_match(line)
        if any(marker in normalized_line for marker in fallback_markers) and len(line) > 30:
            if normalized_line in seen:
                continue
            seen.add(normalized_line)
            snippets.append(line[:700])
            if len(snippets) >= max_snippets:
                break

    return snippets


def evaluate_projects_against_jd(
    project_snippets: List[str], link_report: Dict[str, Any], jd_emb: List[float], jd: Dict[str, Any]
) -> Dict[str, Any]:
    jd_keywords = build_jd_keyword_set(jd)
    snippet_results: List[Dict[str, Any]] = []

    for snippet in project_snippets[:3]:
        relevance = evaluate_text_relevance(snippet, jd_emb, jd_keywords)
        snippet_results.append({"snippet": snippet, "relevance_score": relevance})

    link_scores = [item["relevance_score"] for item in link_report["links"] if item["likely_product_link"]]
    snippet_scores = [item["relevance_score"] for item in snippet_results]
    all_scores = sorted(snippet_scores + link_scores, reverse=True)

    project_fit_score = round(sum(all_scores[:3]) / len(all_scores[:3]), 4) if all_scores else None
    has_supporting_evidence = bool(project_snippets or link_report["links"])

    return {
        "project_snippets_found": len(project_snippets),
        "project_snippet_evaluations": snippet_results,
        "project_fit_score": project_fit_score,
        "has_supporting_evidence": has_supporting_evidence,
    }


def parse_jd(jd_text: str) -> Dict[str, Any]:
    must_have: Set[str] = set()
    nice_to_have: Set[str] = set()

    section = "must"
    segments: List[str] = []
    for block in jd_text.splitlines():
        block = block.strip()
        if not block:
            continue
        segments.extend(re.split(r"[.;]+", block))

    lines = [normalize_for_match(line) for line in segments if line.strip()]

    for line in lines:
        marker = detect_section_marker(line)
        if marker:
            section = marker

        skills_in_line = extract_skills(line)
        if not skills_in_line:
            continue

        if section == "nice":
            nice_to_have.update(skills_in_line)
        else:
            must_have.update(skills_in_line)

    if not must_have and not nice_to_have:
        must_have.update(extract_skills(jd_text))

    nice_to_have = nice_to_have - must_have

    years = detect_years_experience(jd_text)
    title = infer_job_title(jd_text)

    return {
        "title": title,
        "must_have": sorted(must_have),
        "nice_to_have": sorted(nice_to_have),
        "min_years": years,
        "language_hint": detect_language_hint(jd_text),
    }


def build_resume_summary(name: str, skills: List[str], years: int, email: str, clean_text: str) -> str:
    short_text = clean_text[:2500]
    return (
        f"Candidate / Ung vien: {name}\n"
        f"Email: {email}\n"
        f"Skills / Ky nang: {', '.join(skills)}\n"
        f"Years of experience / So nam kinh nghiem: {years}\n"
        f"Profile: {short_text}"
    )


def sanitize_embedding_input(text: str, max_chars: int = 3000) -> str:
    sanitized = text.replace("\x00", " ")
    sanitized = re.sub(r"[\x01-\x08\x0b\x0c\x0e-\x1f]", " ", sanitized)
    sanitized = re.sub(r"\s+", " ", sanitized).strip()
    return sanitized[:max_chars]


def get_embedding(text: str) -> List[float]:
    clean_input = sanitize_embedding_input(text)
    if not clean_input:
        raise HTTPException(status_code=400, detail="Empty text after sanitization for embedding")

    resp = requests.post(
        f"{OLLAMA_URL}/api/embed",
        json={"model": EMBED_MODEL, "input": clean_input},
        timeout=120,
    )
    if not resp.ok:
        detail = resp.text[:300] if resp.text else f"HTTP {resp.status_code}"
        raise HTTPException(status_code=502, detail=f"Ollama embed failed: {detail}")
    data = resp.json()

    embeddings = data.get("embeddings")
    if not embeddings or not isinstance(embeddings, list):
        raise HTTPException(status_code=500, detail="Invalid embedding response from Ollama")

    return embeddings[0]


def get_embeddings(texts: List[str], chunk_size: int = BATCH_EMBED_CHUNK_SIZE) -> List[List[float]]:
    if not texts:
        return []

    chunk_size = max(1, min(chunk_size, 32))
    all_embeddings: List[List[float]] = []

    for start in range(0, len(texts), chunk_size):
        chunk = texts[start : start + chunk_size]
        try:
            cleaned_chunk = [sanitize_embedding_input(t) for t in chunk]
            resp = requests.post(
                f"{OLLAMA_URL}/api/embed",
                json={"model": EMBED_MODEL, "input": cleaned_chunk},
                timeout=240,
            )
            if not resp.ok:
                raise RuntimeError(resp.text[:300] if resp.text else f"HTTP {resp.status_code}")
            data = resp.json()
            embeddings = data.get("embeddings")
            if not isinstance(embeddings, list) or len(embeddings) != len(chunk):
                raise ValueError("Invalid batch embedding response")
            all_embeddings.extend(embeddings)
        except Exception:
            for text in chunk:
                try:
                    all_embeddings.append(get_embedding(text))
                except Exception:
                    all_embeddings.append([])

    return all_embeddings


def cosine_similarity(a: List[float], b: List[float]) -> float:
    va = np.array(a, dtype=np.float32)
    vb = np.array(b, dtype=np.float32)
    denom = np.linalg.norm(va) * np.linalg.norm(vb)
    if denom == 0:
        return 0.0
    return float(np.dot(va, vb) / denom)


def compute_rule_fit_score(cv_skills: List[str], cv_years: int, jd: Dict[str, Any]) -> float:
    has_must = bool(jd["must_have"])
    has_nice = bool(jd["nice_to_have"])
    has_exp = jd["min_years"] > 0

    must_hits = sum(1 for s in jd["must_have"] if s in cv_skills)
    must_score = must_hits / len(jd["must_have"]) if has_must else 1.0

    nice_hits = sum(1 for s in jd["nice_to_have"] if s in cv_skills)
    nice_score = nice_hits / len(jd["nice_to_have"]) if has_nice else 1.0

    exp_score = min(cv_years / jd["min_years"], 1.0) if has_exp and jd["min_years"] > 0 else 1.0

    active_scores: List[float] = []
    if has_must:
        active_scores.append(must_score)
    if has_nice:
        active_scores.append(nice_score)
    if has_exp:
        active_scores.append(exp_score)

    if not active_scores:
        return 1.0
    return float(sum(active_scores) / len(active_scores))


def score_candidate(
    cv_skills: List[str],
    cv_years: int,
    jd: Dict[str, Any],
    cv_emb: List[float],
    jd_emb: List[float],
    project_fit_score: Optional[float] = None,
    semantic_override: Optional[float] = None,
) -> Dict[str, Any]:
    if semantic_override is not None:
        semantic = max(0.0, min(1.0, float(semantic_override)))
    elif cv_emb and jd_emb:
        semantic = cosine_similarity(cv_emb, jd_emb)
    else:
        semantic = 0.0

    has_must = bool(jd["must_have"])
    has_nice = bool(jd["nice_to_have"])
    has_exp = jd["min_years"] > 0
    has_project = project_fit_score is not None

    must_hits = sum(1 for s in jd["must_have"] if s in cv_skills)
    must_score = must_hits / len(jd["must_have"]) if has_must else 1.0

    nice_hits = sum(1 for s in jd["nice_to_have"] if s in cv_skills)
    nice_score = nice_hits / len(jd["nice_to_have"]) if has_nice else 1.0

    exp_score = min(cv_years / jd["min_years"], 1.0) if has_exp else 1.0

    weights = {
        "semantic": 0.55,
        "must": 0.30,
        "nice": 0.10,
        "exp": 0.05,
        "project": 0.12,
    }

    weighted_sum = weights["semantic"] * semantic
    total_weight = weights["semantic"]

    if has_must:
        weighted_sum += weights["must"] * must_score
        total_weight += weights["must"]

    if has_nice:
        weighted_sum += weights["nice"] * nice_score
        total_weight += weights["nice"]

    if has_exp:
        weighted_sum += weights["exp"] * exp_score
        total_weight += weights["exp"]

    if has_project:
        weighted_sum += weights["project"] * float(project_fit_score)
        total_weight += weights["project"]

    final_score = weighted_sum / total_weight if total_weight > 0 else semantic

    missing_skills = [s for s in jd["must_have"] if s not in cv_skills]
    matched_skills = [s for s in cv_skills if s in jd["must_have"] or s in jd["nice_to_have"]]

    return {
        "semantic_score": round(semantic, 4),
        "must_have_score": round(must_score, 4),
        "nice_score": round(nice_score, 4),
        "exp_score": round(exp_score, 4),
        "project_score": round(float(project_fit_score), 4) if has_project else None,
        "final_score": round(final_score, 4),
        "matched_skills": matched_skills,
        "missing_skills": missing_skills,
        "active_constraints": {
            "must_have": has_must,
            "nice_to_have": has_nice,
            "min_years": has_exp,
            "project_evidence": has_project,
        },
    }


def recommendation_label(score: float) -> str:
    if score >= 0.8:
        return "strong_fit"
    if score >= 0.6:
        return "medium_fit"
    return "weak_fit"


def process_resume(
    filename: str, content: bytes, jd_text: str, jd: Dict[str, Any], jd_emb: List[float]
) -> Dict[str, Any]:
    raw_text = extract_text(filename, content)
    clean_text = normalize_text(raw_text)

    candidate_name = infer_name(raw_text, filename)
    email = extract_email(raw_text)
    skills = extract_skills(raw_text)
    years = detect_years_experience(raw_text)
    link_collection = collect_link_candidates(filename, content, raw_text)
    link_report = inspect_product_links(
        link_collection["links"],
        jd_emb,
        jd,
        detection_mode=link_collection["detection_mode"],
    )
    project_snippets = extract_project_snippets(raw_text)
    project_report = evaluate_projects_against_jd(project_snippets, link_report, jd_emb, jd)
    rule_fit_score = compute_rule_fit_score(skills, years, jd)
    jd_keywords = build_jd_keyword_set(jd)
    lexical_score = keyword_overlap_score(raw_text, jd_keywords) if jd_keywords else 0.0

    resume_summary = build_resume_summary(candidate_name, skills, years, email, clean_text)
    try:
        cv_emb = get_embedding(resume_summary)
    except Exception:
        cv_emb = []

    semantic_override = None
    if not cv_emb or not jd_emb:
        semantic_override = 0.6 * lexical_score + 0.4 * rule_fit_score

    scores = score_candidate(
        skills,
        years,
        jd,
        cv_emb,
        jd_emb,
        project_fit_score=project_report["project_fit_score"],
        semantic_override=semantic_override,
    )
    scores["recommendation"] = recommendation_label(scores["final_score"])

    return {
        "candidate_name": candidate_name,
        "email": email,
        "filename": filename,
        "skills": skills,
        "years_experience": years,
        "analysis": {
            "language_hint": detect_language_hint(raw_text),
            "resume_summary_preview": resume_summary[:350],
            "product_link_report": link_report,
            "project_fit_report": project_report,
        },
        "scores": scores,
        "raw_text": raw_text[:20000],
    }


def process_resume_light(
    filename: str, content: bytes, jd: Dict[str, Any], jd_keywords: Set[str]
) -> Dict[str, Any]:
    raw_text = extract_text(filename, content)
    clean_text = normalize_text(raw_text)

    candidate_name = infer_name(raw_text, filename)
    email = extract_email(raw_text)
    skills = extract_skills(raw_text)
    years = detect_years_experience(raw_text)
    link_collection = collect_link_candidates(filename, content, raw_text)

    lite_links: List[Dict[str, Any]] = []
    for item in link_collection["links"][:5]:
        url = item.get("url", "")
        if not url:
            continue
        parsed = urlparse(url)
        host = parsed.netloc.lower().lstrip("www.")
        source = item.get("source", "text")
        lite_links.append(
            {
                "url": url,
                "host": host,
                "source": source,
                "page": item.get("page"),
                "rect": item.get("rect"),
                "likely_product_link": is_likely_product_link(url) or source == "pdf_annotation",
                "reachable": None,
                "status_code": None,
                "final_url": url,
                "title": "",
                "description": "",
                "relevance_score": None,
                "error": "",
            }
        )

    resume_summary = build_resume_summary(candidate_name, skills, years, email, clean_text)
    lexical_score = keyword_overlap_score(raw_text, jd_keywords) if jd_keywords else 0.0
    rule_fit_score = compute_rule_fit_score(skills, years, jd)
    prefilter_score = 0.6 * lexical_score + 0.4 * rule_fit_score

    return {
        "candidate_name": candidate_name,
        "email": email,
        "filename": filename,
        "skills": skills,
        "years_experience": years,
        "resume_summary": resume_summary,
        "raw_text": raw_text[:20000],
        "analysis": {
            "language_hint": detect_language_hint(raw_text),
            "resume_summary_preview": resume_summary[:350],
            "batch_prefilter": {
                "lexical_score": round(lexical_score, 4),
                "rule_fit_score": round(rule_fit_score, 4),
                "prefilter_score": round(prefilter_score, 4),
            },
            "product_link_report": {
                "detection_mode": f"{link_collection['detection_mode']}_lite",
                "total_links_found": len(link_collection["links"]),
                "total_links_checked": len(lite_links),
                "product_links_found": sum(1 for item in lite_links if item["likely_product_link"]),
                "links": lite_links,
            },
            "project_fit_report": {
                "project_snippets_found": 0,
                "project_snippet_evaluations": [],
                "project_fit_score": None,
                "has_supporting_evidence": False,
            },
        },
    }


def save_job_and_results(
    jd_text: str, jd: Dict[str, Any], jd_emb: List[float], candidates: List[Dict[str, Any]]
) -> int:
    conn = get_conn()
    cur = conn.cursor()

    cur.execute(
        """
        INSERT INTO jobs (title, jd_text, jd_json, jd_embedding)
        VALUES (?, ?, ?, ?)
        """,
        (
            jd.get("title", ""),
            jd_text,
            json.dumps(jd, ensure_ascii=False),
            json.dumps(jd_emb),
        ),
    )
    job_id = cur.lastrowid

    for c in candidates:
        cur.execute(
            """
            INSERT INTO results (
                job_id, candidate_name, email, filename, jd_text, final_score, semantic_score,
                must_have_score, nice_score, exp_score, matched_skills, missing_skills,
                candidate_skills, years_experience, raw_text
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            """,
            (
                job_id,
                c["candidate_name"],
                c["email"],
                c["filename"],
                jd_text,
                c["scores"]["final_score"],
                c["scores"]["semantic_score"],
                c["scores"]["must_have_score"],
                c["scores"]["nice_score"],
                c["scores"]["exp_score"],
                json.dumps(c["scores"]["matched_skills"], ensure_ascii=False),
                json.dumps(c["scores"]["missing_skills"], ensure_ascii=False),
                json.dumps(c["skills"], ensure_ascii=False),
                c["years_experience"],
                c["raw_text"],
            ),
        )

    conn.commit()
    conn.close()
    return int(job_id)


@app.get("/health")
def health() -> Dict[str, str]:
    return {"status": "ok"}


@app.get("/", include_in_schema=False)
def serve_frontend() -> FileResponse:
    index_path = os.path.join(STATIC_DIR, "index.html")
    if not os.path.exists(index_path):
        raise HTTPException(status_code=404, detail="Frontend not found")
    return FileResponse(index_path)


@app.post("/screen")
async def screen_single_resume(file: UploadFile = File(...), jd_text: str = Form(...)) -> Dict[str, Any]:
    content = await file.read()
    jd = parse_jd(jd_text)
    try:
        jd_emb = get_embedding(jd_text[:2500])
    except Exception:
        jd_emb = []

    result = process_resume(file.filename, content, jd_text, jd, jd_emb)
    job_id = save_job_and_results(jd_text, jd, jd_emb, [result])

    return {"job_id": job_id, "jd": jd, "candidate": result}


@app.post("/screen/batch")
async def screen_batch_resumes(
    files: List[UploadFile] = File(...),
    jd_text: str = Form(...),
    top_k: int = Form(10),
    analysis_mode: str = Form("lite"),
    embedding_budget: int = Form(BATCH_EMBED_BUDGET_DEFAULT),
) -> Dict[str, Any]:
    if not files:
        raise HTTPException(status_code=400, detail="No files uploaded")

    jd = parse_jd(jd_text)
    try:
        jd_emb = get_embedding(jd_text[:2500])
    except Exception:
        jd_emb = []

    candidates: List[Dict[str, Any]] = []
    errors: List[Dict[str, str]] = []
    mode = (analysis_mode or "lite").strip().lower()

    if mode == "full":
        for file in files:
            try:
                content = await file.read()
                result = process_resume(file.filename, content, jd_text, jd, jd_emb)
                candidates.append(result)
            except Exception as e:
                errors.append({"filename": file.filename, "error": str(e)})
        strategy_info = {
            "mode": "full",
            "note": "Deep analysis for each CV. Higher CPU/RAM usage.",
        }
    else:
        mode = "lite"
        jd_keywords = build_jd_keyword_set(jd)
        pre_candidates: List[Dict[str, Any]] = []

        for file in files:
            try:
                content = await file.read()
                pre_candidates.append(process_resume_light(file.filename, content, jd, jd_keywords))
            except Exception as e:
                errors.append({"filename": file.filename, "error": str(e)})

        if pre_candidates:
            ranked_indices = sorted(
                range(len(pre_candidates)),
                key=lambda i: pre_candidates[i]["analysis"]["batch_prefilter"]["prefilter_score"],
                reverse=True,
            )

            max_budget = min(len(pre_candidates), 40)
            budget = max(8, min(int(embedding_budget), max_budget)) if max_budget > 0 else 0
            selected_indices = ranked_indices[:budget]

            selected_summaries = [pre_candidates[i]["resume_summary"] for i in selected_indices]
            selected_embeddings = get_embeddings(selected_summaries, chunk_size=BATCH_EMBED_CHUNK_SIZE)
            embedding_map = {idx: emb for idx, emb in zip(selected_indices, selected_embeddings)}

            for idx, candidate in enumerate(pre_candidates):
                prefilter_score = candidate["analysis"]["batch_prefilter"]["prefilter_score"]
                cv_emb = embedding_map.get(idx, [])
                semantic_override = None if (idx in embedding_map and jd_emb and cv_emb) else prefilter_score

                scores = score_candidate(
                    candidate["skills"],
                    candidate["years_experience"],
                    jd,
                    cv_emb,
                    jd_emb,
                    project_fit_score=None,
                    semantic_override=semantic_override,
                )
                scores["recommendation"] = recommendation_label(scores["final_score"])
                candidate["scores"] = scores
                candidate.pop("resume_summary", None)
                candidates.append(candidate)

            strategy_info = {
                "mode": "lite",
                "note": "Two-stage scoring: prefilter all CVs, embed top candidates only.",
                "embedding_budget": budget,
                "embedded_candidates": len(selected_indices),
                "prefiltered_candidates": len(pre_candidates),
            }
        else:
            strategy_info = {
                "mode": "lite",
                "note": "No valid candidates after parsing.",
                "embedding_budget": 0,
                "embedded_candidates": 0,
                "prefiltered_candidates": 0,
            }

    candidates.sort(key=lambda x: x["scores"]["final_score"], reverse=True)
    job_id = save_job_and_results(jd_text, jd, jd_emb, candidates)

    return {
        "job_id": job_id,
        "jd": jd,
        "batch_strategy": strategy_info,
        "total_uploaded": len(files),
        "total_success": len(candidates),
        "total_failed": len(errors),
        "top_candidates": candidates[:top_k],
        "errors": errors,
    }


@app.get("/results")
def list_results(limit: int = 100) -> List[Dict[str, Any]]:
    conn = get_conn()
    cur = conn.cursor()
    cur.execute(
        """
        SELECT id, job_id, candidate_name, email, filename, final_score,
               semantic_score, must_have_score, nice_score, exp_score,
               matched_skills, missing_skills, candidate_skills,
               years_experience, created_at
        FROM results
        ORDER BY final_score DESC, created_at DESC
        LIMIT ?
        """,
        (limit,),
    )
    rows = [dict(r) for r in cur.fetchall()]
    conn.close()

    for row in rows:
        for field in ["matched_skills", "missing_skills", "candidate_skills"]:
            try:
                row[field] = json.loads(row[field]) if row[field] else []
            except Exception:
                row[field] = []
        row["recommendation"] = recommendation_label(row["final_score"])

    return rows


@app.get("/jobs")
def list_jobs(limit: int = 50) -> List[Dict[str, Any]]:
    conn = get_conn()
    cur = conn.cursor()
    cur.execute(
        """
        SELECT id, title, jd_text, jd_json, created_at
        FROM jobs
        ORDER BY id DESC
        LIMIT ?
        """,
        (limit,),
    )
    rows = [dict(r) for r in cur.fetchall()]
    conn.close()

    for row in rows:
        try:
            row["jd_json"] = json.loads(row["jd_json"]) if row["jd_json"] else {}
        except Exception:
            row["jd_json"] = {}

    return rows


@app.get("/jobs/{job_id}/ranking")
def get_job_ranking(job_id: int, top_k: int = 10) -> Dict[str, Any]:
    conn = get_conn()
    cur = conn.cursor()

    cur.execute("SELECT * FROM jobs WHERE id = ?", (job_id,))
    job = cur.fetchone()
    if not job:
        conn.close()
        raise HTTPException(status_code=404, detail="Job not found")

    cur.execute(
        """
        SELECT candidate_name, email, filename, final_score, semantic_score,
               must_have_score, nice_score, exp_score, matched_skills,
               missing_skills, candidate_skills, years_experience, created_at
        FROM results
        WHERE job_id = ?
        ORDER BY final_score DESC
        LIMIT ?
        """,
        (job_id, top_k),
    )
    rows = [dict(r) for r in cur.fetchall()]
    conn.close()

    for row in rows:
        for field in ["matched_skills", "missing_skills", "candidate_skills"]:
            try:
                row[field] = json.loads(row[field]) if row[field] else []
            except Exception:
                row[field] = []
        row["recommendation"] = recommendation_label(row["final_score"])

    return {
        "job_id": job["id"],
        "title": job["title"],
        "jd_text": job["jd_text"],
        "jd": json.loads(job["jd_json"]) if job["jd_json"] else {},
        "top_candidates": rows,
    }
