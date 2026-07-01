import argparse
import json
import random
import re
import unicodedata
import zipfile
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Dict, List, Optional, Set, Tuple
from xml.etree import ElementTree as ET


W_NS = {"w": "http://schemas.openxmlformats.org/wordprocessingml/2006/main"}

DEPARTMENT_SKILLS: Dict[str, List[str]] = {
    "Ban Giám Đốc": [
        "Hoạch định chiến lược (Strategic Planning)",
        "Quản trị rủi ro",
        "ROI Management",
        "Hệ thống ERP/BI",
    ],
    "Kinh Doanh": [
        "CRM (HubSpot/Salesforce/Base)",
        "B2B/B2C Sales",
        "Đàm phán",
        "Quản lý Pipeline",
    ],
    "Marketing": [
        "SEO/SEM",
        "GA4",
        "Content Marketing",
        "Martech",
        "Phân tích dữ liệu",
    ],
    "CSKH": [
        "Xử lý khiếu nại (Complaint Handling)",
        "Thấu cảm (Empathy)",
        "CSAT/NPS",
        "SLA Management",
    ],
    "HR & Admin": [
        "Luật lao động VN",
        "C&B",
        "HRIS (MISA AMIS/1Office)",
        "Tuyển dụng & Đào tạo",
    ],
    "R&D": [
        "Big Data",
        "AutoCAD/SolidWorks",
        "Thống kê (SPSS/Python)",
        "Nghiên cứu bao bì/sản phẩm",
    ],
    "IT": [
        "Lập trình (Java/Python/C++)",
        "SQL/Oracle",
        "Network Security",
        "Troubleshooting",
    ],
    "Sản Xuất": [
        "Lean Manufacturing",
        "5S/Kaizen",
        "ERP/MES",
        "Lập kế hoạch sản xuất",
    ],
    "QA/QC": [
        "ISO 9001/14001",
        "HACCP/FSSC 22000",
        "SOP",
        "Root Cause Analysis",
    ],
    "Tài chính - Kế toán": [
        "Chuẩn mực VAS",
        "Phần mềm MISA",
        "Quyết toán thuế",
        "Kiểm toán nội bộ",
    ],
}

VIETNAMESE_AUGMENT: Dict[str, List[str]] = {
    "Ban Giám Đốc": ["quản trị khủng hoảng", "phân bổ vốn", "kiểm soát KPI chiến lược"],
    "Kinh Doanh": ["chăm sóc đại lý", "objection handling", "proposal/tender"],
    "Marketing": ["Google Analytics 4", "Meta Ads", "tối ưu CTR/CAC"],
    "CSKH": ["call center", "chat đa kênh", "duy trì SLA phản hồi"],
    "HR & Admin": ["BHXH", "thuế TNCN", "onboarding"],
    "R&D": ["thử nghiệm mẫu", "đánh giá xu hướng thị trường", "tối ưu vật liệu"],
    "IT": ["helpdesk ticket", "hardening hệ thống", "giám sát hạ tầng"],
    "Sản Xuất": ["OTIF", "định mức vật tư", "an toàn PCCC"],
    "QA/QC": ["điều tra nguyên nhân gốc", "phòng ngừa lỗi lặp", "kiểm soát công đoạn"],
    "Tài chính - Kế toán": ["VAS", "MISA AMIS", "đối soát công nợ"],
}


def normalize_text(text: str) -> str:
    lowered = text.lower()
    decomposed = unicodedata.normalize("NFD", lowered)
    without_accents = "".join(c for c in decomposed if unicodedata.category(c) != "Mn")
    cleaned = re.sub(r"[^a-z0-9+/#&\s-]", " ", without_accents)
    cleaned = re.sub(r"\s+", " ", cleaned).strip()
    return cleaned


def compact_line(text: str, max_chars: int = 280) -> str:
    text = re.sub(r"\s+", " ", text).strip()
    if len(text) <= max_chars:
        return text
    clipped = text[:max_chars].rsplit(" ", 1)[0].strip()
    if not clipped:
        clipped = text[:max_chars].strip()
    return f"{clipped}..."


def extract_docx_lines(path: Path) -> List[str]:
    with zipfile.ZipFile(path) as zf:
        data = zf.read("word/document.xml")
    root = ET.fromstring(data)
    lines: List[str] = []
    for paragraph in root.findall(".//w:p", W_NS):
        texts = [n.text for n in paragraph.findall(".//w:t", W_NS) if n.text]
        line = "".join(texts).strip()
        if line:
            lines.append(line)
    return lines


def extract_html_lines(path: Path) -> List[str]:
    html = path.read_text(encoding="utf-8", errors="ignore")
    html = re.sub(r"<script[\s\S]*?</script>", " ", html, flags=re.IGNORECASE)
    html = re.sub(r"<style[\s\S]*?</style>", " ", html, flags=re.IGNORECASE)
    html = re.sub(r"<[^>]+>", "\n", html)
    lines = [re.sub(r"\s+", " ", line).strip() for line in html.splitlines()]
    return [line for line in lines if line]


def load_source_lines(source_files: List[Path]) -> List[str]:
    lines: List[str] = []
    for path in source_files:
        if not path.exists():
            continue
        if path.suffix.lower() == ".docx":
            lines.extend(extract_docx_lines(path))
        elif path.suffix.lower() in (".html", ".htm"):
            lines.extend(extract_html_lines(path))
        elif path.suffix.lower() == ".txt":
            lines.extend(path.read_text(encoding="utf-8-sig", errors="ignore").splitlines())
    dedup: List[str] = []
    seen: Set[str] = set()
    for line in lines:
        if len(line) < 20:
            continue
        key = normalize_text(line)
        if not key or key in seen:
            continue
        seen.add(key)
        dedup.append(line.strip())
    return dedup


def find_evidence_by_department(source_lines: List[str]) -> Dict[str, List[str]]:
    evidence: Dict[str, List[str]] = {dept: [] for dept in DEPARTMENT_SKILLS}
    department_norm_tokens = {
        dept: normalize_text(dept).split() + [normalize_text(skill) for skill in skills]
        for dept, skills in DEPARTMENT_SKILLS.items()
    }
    for line in source_lines:
        line_norm = normalize_text(line)
        for dept, tokens in department_norm_tokens.items():
            if any(tok and tok in line_norm for tok in tokens):
                evidence[dept].append(line)
    for dept, lines in evidence.items():
        uniq: List[str] = []
        seen: Set[str] = set()
        for line in lines:
            key = normalize_text(line)
            if key in seen:
                continue
            seen.add(key)
            uniq.append(line)
        evidence[dept] = uniq
    return evidence


def load_existing_triplets(path: Path) -> List[Dict[str, str]]:
    raw = json.loads(path.read_text(encoding="utf-8-sig"))
    if isinstance(raw, dict):
        records = raw.get("hr_recruitment_triplets", [])
    elif isinstance(raw, list):
        records = raw
    else:
        records = []

    triplets: List[Dict[str, str]] = []
    for rec in records:
        source_tag = str(rec.get("source", "existing")).strip().lower()
        if source_tag.startswith("generated-"):
            continue
        anchor = str(rec.get("anchor", "")).strip()
        positive = str(rec.get("positive", "")).strip()
        negative = str(rec.get("negative", "")).strip()
        if anchor and positive and negative:
            triplets.append({"anchor": anchor, "positive": positive, "negative": negative, "source": "existing"})
    return triplets


def choose_negative_skill(current_dept: str, all_departments: List[str], rng: random.Random) -> Tuple[str, str]:
    other_depts = [d for d in all_departments if d != current_dept]
    neg_dept = rng.choice(other_depts)
    neg_skill = rng.choice(DEPARTMENT_SKILLS[neg_dept])
    return neg_dept, neg_skill


def generate_triplets_from_skills(
    evidence_by_dept: Dict[str, List[str]], rng: random.Random, max_triplets_per_dept: int
) -> List[Dict[str, str]]:
    departments = list(DEPARTMENT_SKILLS.keys())
    generated: List[Dict[str, str]] = []

    for dept, skills in DEPARTMENT_SKILLS.items():
        dept_evidence = evidence_by_dept.get(dept, [])
        augment_terms = VIETNAMESE_AUGMENT.get(dept, [])
        per_dept_count = 0

        for skill in skills:
            neg_dept, neg_skill = choose_negative_skill(dept, departments, rng)
            anchor = (
                f"JD SkillMapVN | Phòng ban: {dept} | "
                f"Yêu cầu trọng yếu: {skill} | Ngữ cảnh: thị trường lao động Việt Nam."
            )

            evidence_line = compact_line(rng.choice(dept_evidence), max_chars=280) if dept_evidence else ""
            augment_text = ", ".join(rng.sample(augment_terms, k=min(2, len(augment_terms)))) if augment_terms else ""
            positive = (
                f"CV phù hợp {dept}: có kinh nghiệm thực chiến {skill}, "
                f"triển khai công việc theo chuẩn KPI và công cụ ngành. {augment_text}. {evidence_line}".strip()
            )
            negative = (
                f"Ứng viên thiên về {neg_dept}, chủ yếu làm {neg_skill}, "
                f"không có nền tảng cốt lõi cho yêu cầu {skill} của {dept}."
            )
            generated.append(
                {
                    "anchor": anchor,
                    "positive": positive,
                    "negative": negative,
                    "department": dept,
                    "skill": skill,
                    "source": "generated-skill-map",
                }
            )
            per_dept_count += 1
            if per_dept_count >= max_triplets_per_dept:
                break

        for augment in augment_terms[: max(0, max_triplets_per_dept - per_dept_count)]:
            neg_dept, neg_skill = choose_negative_skill(dept, departments, rng)
            anchor = (
                f"JD SkillMapVN | Phòng ban: {dept} | "
                f"Từ khóa tăng cường dữ liệu Việt Nam: {augment}."
            )
            positive = (
                f"CV có kinh nghiệm {dept}: từng triển khai quy trình liên quan đến {augment}, "
                f"đồng thời đáp ứng các kỹ năng lõi {', '.join(skills[:2])}."
            )
            negative = (
                f"CV tập trung mảng {neg_dept}, dùng kỹ năng {neg_skill}; "
                f"thiếu kinh nghiệm trực tiếp với {augment}."
            )
            generated.append(
                {
                    "anchor": anchor,
                    "positive": positive,
                    "negative": negative,
                    "department": dept,
                    "skill": augment,
                    "source": "generated-vn-augment",
                }
            )

    return generated


def deduplicate_triplets(records: List[Dict[str, str]]) -> List[Dict[str, str]]:
    unique: List[Dict[str, str]] = []
    seen: Set[Tuple[str, str, str]] = set()
    for rec in records:
        anchor = rec.get("anchor", "").strip()
        positive = rec.get("positive", "").strip()
        negative = rec.get("negative", "").strip()
        if not anchor or not positive or not negative:
            continue
        key = (normalize_text(anchor), normalize_text(positive), normalize_text(negative))
        if key in seen:
            continue
        seen.add(key)
        unique.append(rec)
    return unique


def build_cross_encoder_pairs(triplets: List[Dict[str, str]]) -> List[Dict[str, Any]]:
    pairs: List[Dict[str, Any]] = []
    pair_id = 1
    for triplet in triplets:
        anchor = triplet["anchor"]
        positive = triplet["positive"]
        negative = triplet["negative"]
        source = triplet.get("source", "unknown")

        pairs.append(
            {
                "pair_id": pair_id,
                "text_a": anchor,
                "text_b": positive,
                "label": 1,
                "source_triplet": source,
            }
        )
        pair_id += 1

        pairs.append(
            {
                "pair_id": pair_id,
                "text_a": anchor,
                "text_b": negative,
                "label": 0,
                "source_triplet": source,
            }
        )
        pair_id += 1
    return pairs


def main() -> None:
    parser = argparse.ArgumentParser(description="Prepare train_data.json from existing data + external sources.")
    parser.add_argument("--input", default="./training/data/train_data.json")
    parser.add_argument("--output", default="./training/data/train_data.json")
    parser.add_argument(
        "--sources",
        nargs="*",
        default=[
            "./training/data/sources_jd_standard.docx",
            "./training/data/sources_github_repos_skill_cv_training.docx",
            "./training/data/sources_department_skill_repos.html",
            "./training/data/sources_jd_department_details.docx",
            "./training/data/sources_skill_set.docx",
        ],
    )
    parser.add_argument("--seed", type=int, default=42)
    parser.add_argument("--max-triplets-per-dept", type=int, default=10)
    args = parser.parse_args()

    rng = random.Random(args.seed)
    input_path = Path(args.input)
    output_path = Path(args.output)
    source_files = [Path(p) for p in args.sources]

    existing = load_existing_triplets(input_path)
    source_lines = load_source_lines(source_files)
    evidence_by_dept = find_evidence_by_department(source_lines)
    generated = generate_triplets_from_skills(
        evidence_by_dept=evidence_by_dept,
        rng=rng,
        max_triplets_per_dept=max(4, args.max_triplets_per_dept),
    )

    merged = deduplicate_triplets(existing + generated)
    for idx, item in enumerate(merged, start=1):
        item["triplet_id"] = idx

    cross_pairs = build_cross_encoder_pairs(merged)
    metadata = {
        "created_at_utc": datetime.now(timezone.utc).isoformat(),
        "total_triplets": len(merged),
        "total_cross_encoder_pairs": len(cross_pairs),
        "source_files": [str(p) for p in source_files if p.exists()],
        "augmentation_keywords": VIETNAMESE_AUGMENT,
        "department_skills": DEPARTMENT_SKILLS,
    }

    output = {
        "metadata": metadata,
        "hr_recruitment_triplets": merged,
        "cross_encoder_pairs": cross_pairs,
    }
    output_path.write_text(json.dumps(output, ensure_ascii=False, indent=2), encoding="utf-8")

    print(f"Prepared triplets: {len(merged)}")
    print(f"Prepared cross-encoder pairs: {len(cross_pairs)}")
    print(f"Output: {output_path}")


if __name__ == "__main__":
    main()
