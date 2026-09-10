from __future__ import annotations

import re
from typing import Any, Dict, List, Optional


def build_jd_rubric(jd: Dict[str, Any]) -> List[Dict[str, Any]]:
    criteria: List[Dict[str, Any]] = []
    must_have = list(jd.get("must_have") or [])
    nice_to_have = list(jd.get("nice_to_have") or [])
    min_years = int(jd.get("min_years") or 0)

    groups = []
    if must_have:
        groups.append(("must_have", 50.0))
    if nice_to_have:
        groups.append(("nice_to_have", 15.0))
    if min_years:
        groups.append(("experience", 20.0))
    groups.extend([("project", 10.0), ("overall_relevance", 5.0)])
    total = sum(weight for _, weight in groups) or 1.0
    normalized = {name: weight * 100.0 / total for name, weight in groups}

    def skill_criteria(values: List[str], group: str, required: bool) -> None:
        if not values:
            return
        each_weight = normalized[group] / len(values)
        for index, value in enumerate(values, start=1):
            slug = re.sub(r"[^a-z0-9]+", "-", value.lower()).strip("-") or str(index)
            criteria.append({
                "id": f"{group}:{slug}",
                "group": group,
                "criterion": value,
                "description": f"Evidence that the candidate has {value}",
                "weight": round(each_weight, 4),
                "max_score": 5,
                "required": required,
            })

    skill_criteria(must_have, "must_have", True)
    skill_criteria(nice_to_have, "nice_to_have", False)
    if min_years:
        criteria.append({
            "id": "experience:min-years",
            "group": "experience",
            "criterion": f"At least {min_years} years of relevant experience",
            "description": "Relevant work experience supported by the CV",
            "weight": round(normalized["experience"], 4),
            "max_score": 5,
            "required": True,
        })
    criteria.append({
        "id": "project:relevant-evidence",
        "group": "project",
        "criterion": "Relevant project evidence",
        "description": "Projects, products, or portfolio evidence related to the JD",
        "weight": round(normalized["project"], 4),
        "max_score": 5,
        "required": False,
    })
    criteria.append({
        "id": "overall:semantic-relevance",
        "group": "overall_relevance",
        "criterion": "Overall job relevance",
        "description": "Semantic relevance after protected personal attributes are removed",
        "weight": round(normalized["overall_relevance"], 4),
        "max_score": 5,
        "required": False,
    })
    return criteria


def _skill_evidence(cv_profile: Dict[str, Any], skill: str) -> Optional[Dict[str, Any]]:
    for item in cv_profile.get("skills") or []:
        if str(item.get("name", "")).lower() == skill.lower():
            return item
    return None


def score_rubric(
    rubric: List[Dict[str, Any]],
    cv_profile: Dict[str, Any],
    component_scores: Dict[str, Any],
) -> List[Dict[str, Any]]:
    scored = []
    years = int(((cv_profile.get("work_experience") or [{}])[0]).get("years_total") or 0)
    confidence = float(cv_profile.get("extraction_confidence") or 0.0)

    for criterion in rubric:
        item = dict(criterion)
        group = item["group"]
        evidence = "No supporting evidence found"
        source_page = None
        score = 0.0

        if group in {"must_have", "nice_to_have"}:
            match = _skill_evidence(cv_profile, item["criterion"])
            if match:
                score = 5.0
                evidence = match.get("evidence") or evidence
                source_page = match.get("source_page")
        elif group == "experience":
            match = re.search(r"(\d+)", item["criterion"])
            required_years = int(match.group(1)) if match else 0
            score = min(5.0, 5.0 * years / required_years) if required_years else 5.0
            evidence = (cv_profile.get("work_experience") or [{}])[0].get("evidence") or evidence
        elif group == "project":
            raw_score = component_scores.get("project_score")
            score = max(0.0, min(5.0, float(raw_score or 0.0) * 5.0))
            if score > 0:
                evidence = "Relevant project or product evidence was detected in the CV."
        else:
            score = max(0.0, min(5.0, float(component_scores.get("semantic_score") or 0.0) * 5.0))
            evidence = "Calculated from redacted CV content and JD semantic similarity."

        item.update({
            "score": round(score, 2),
            "evidence": evidence,
            "source_page": source_page,
            "confidence": round(confidence if evidence != "No supporting evidence found" else confidence * 0.5, 4),
        })
        scored.append(item)
    return scored


def rubric_overall_score(criteria: List[Dict[str, Any]]) -> float:
    total_weight = sum(float(item.get("weight") or 0.0) for item in criteria)
    if total_weight <= 0:
        return 0.0
    weighted = sum(
        float(item.get("weight") or 0.0)
        * float(item.get("score") or 0.0)
        / max(float(item.get("max_score") or 5.0), 1.0)
        for item in criteria
    )
    return round(max(0.0, min(100.0, weighted * 100.0 / total_weight)), 2)

