from __future__ import annotations

from typing import Any, Dict, List


def compare_reviews(ai_criteria: List[Dict[str, Any]], human_criteria: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
    ai_by_id = {str(item.get("id")): item for item in ai_criteria}
    disagreements = []
    for review in human_criteria:
        criterion_id = str(review.get("criterion_id") or review.get("id") or "")
        ai_item = ai_by_id.get(criterion_id)
        if not ai_item:
            continue
        ai_score = float(ai_item.get("score") or 0.0)
        human_score = float(review.get("score") or 0.0)
        delta = human_score - ai_score
        if abs(delta) < 1.0:
            continue
        disagreements.append({
            "criterion_id": criterion_id,
            "criterion": ai_item.get("criterion"),
            "ai_score": ai_score,
            "human_score": human_score,
            "delta": round(delta, 2),
            "reason": review.get("reason") or "",
            "severity": "significant" if abs(delta) >= 2.0 else "moderate",
        })
    return disagreements


def reference_score(ai_score: float, human_score: float) -> float:
    return round(max(0.0, min(1.0, ai_score * 0.4 + human_score * 0.6)), 4)


def training_eligibility(
    human_score: float,
    human_criteria: List[Dict[str, Any]],
    note: str,
    requested: bool,
) -> Dict[str, Any]:
    missing_reasons = [
        item for item in human_criteria
        if item.get("score") is not None and not str(item.get("reason") or "").strip()
    ]
    eligible = bool(requested and 0.0 <= human_score <= 1.0 and human_criteria and not missing_reasons)
    reasons = []
    if not requested:
        reasons.append("Reviewer did not approve this review for training.")
    if not human_criteria:
        reasons.append("Criterion-level scores are required.")
    if missing_reasons:
        reasons.append("Every criterion score must include a reason.")
    if not note.strip():
        reasons.append("A review note is recommended for auditability.")
    return {"eligible": eligible, "reasons": reasons}

