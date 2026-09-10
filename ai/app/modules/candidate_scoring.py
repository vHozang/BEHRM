from __future__ import annotations

from typing import Any, Dict, List, Optional

from modules.jd_rubric import build_jd_rubric, rubric_overall_score, score_rubric


DEFAULT_MODEL_VERSION = "objective-rubric-v1"
DEFAULT_PROMPT_VERSION = "resume-scoring-v1"
FEATURE_NAMES = ["semantic_score", "must_have_score", "nice_score", "exp_score", "project_score"]


def component_vector(scores: Dict[str, Any]) -> List[float]:
    return [float(scores.get(name) or 0.0) for name in FEATURE_NAMES]


def apply_calibration(scores: Dict[str, Any], model: Optional[Dict[str, Any]]) -> float:
    if not model:
        return float(scores.get("final_score") or 0.0)
    parameters = model.get("parameters") or {}
    coefficients = parameters.get("coefficients") or []
    if len(coefficients) != len(FEATURE_NAMES):
        return float(scores.get("final_score") or 0.0)
    prediction = float(parameters.get("intercept") or 0.0)
    prediction += sum(weight * value for weight, value in zip(coefficients, component_vector(scores)))
    return max(0.0, min(1.0, prediction))


def build_assessment(
    jd: Dict[str, Any],
    cv_profile: Dict[str, Any],
    scores: Dict[str, Any],
    parser_metadata: Dict[str, Any],
    active_model: Optional[Dict[str, Any]] = None,
) -> Dict[str, Any]:
    rubric = build_jd_rubric(jd)
    criteria = score_rubric(rubric, cv_profile, scores)
    rubric_score = rubric_overall_score(criteria) / 100.0
    base_score = float(scores.get("final_score") or rubric_score)
    calibrated_score = apply_calibration(scores, active_model)
    extraction_confidence = float(cv_profile.get("extraction_confidence") or 0.0)
    evidence_ratio = sum(1 for item in criteria if item.get("evidence") != "No supporting evidence found") / max(len(criteria), 1)
    confidence = round(min(1.0, extraction_confidence * 0.6 + evidence_ratio * 0.4), 4)
    model_version = (active_model or {}).get("version") or DEFAULT_MODEL_VERSION

    return {
        "schema_version": "ai-assessment.v1",
        "model_version": model_version,
        "prompt_version": DEFAULT_PROMPT_VERSION,
        "ai_score": round(base_score * 100.0, 2),
        "rubric_score": round(rubric_score * 100.0, 2),
        "calibrated_score": round(calibrated_score * 100.0, 2),
        "confidence": confidence,
        "criteria": criteria,
        "component_scores": {name: scores.get(name) for name in FEATURE_NAMES},
        "parser": parser_metadata,
        "protected_attributes_used": False,
        "human_review_required": confidence < 0.75,
    }

