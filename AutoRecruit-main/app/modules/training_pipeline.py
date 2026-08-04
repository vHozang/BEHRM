from __future__ import annotations

import json
from datetime import datetime, timezone
from typing import Any, Dict, List, Tuple

import numpy as np

from modules.candidate_scoring import FEATURE_NAMES


def utc_now() -> str:
    return datetime.now(timezone.utc).isoformat()


def split_examples(examples: List[Dict[str, Any]]) -> Tuple[List[Dict[str, Any]], List[Dict[str, Any]]]:
    ordered = sorted(examples, key=lambda item: (int(item.get("candidate_id") or 0), int(item.get("review_id") or 0)))
    if len(ordered) < 5:
        return ordered, ordered
    split_at = max(1, int(len(ordered) * 0.8))
    return ordered[:split_at], ordered[split_at:]


def _matrix(examples: List[Dict[str, Any]]) -> Tuple[np.ndarray, np.ndarray]:
    x = np.array([[float(item["features"].get(name) or 0.0) for name in FEATURE_NAMES] for item in examples], dtype=np.float64)
    y = np.array([float(item["human_score"]) for item in examples], dtype=np.float64)
    return x, y


def _mae(actual: np.ndarray, predicted: np.ndarray) -> float:
    return float(np.mean(np.abs(actual - predicted))) if len(actual) else 0.0


def train_calibrator(
    examples: List[Dict[str, Any]],
    ridge_alpha: float = 0.1,
    minimum_improvement: float = 0.001,
) -> Dict[str, Any]:
    train, test = split_examples(examples)
    train_x, train_y = _matrix(train)
    design = np.column_stack([np.ones(len(train_x)), train_x])
    regularizer = np.eye(design.shape[1], dtype=np.float64) * ridge_alpha
    regularizer[0, 0] = 0.0
    parameters = np.linalg.pinv(design.T @ design + regularizer) @ design.T @ train_y

    test_x, test_y = _matrix(test)
    predictions = np.clip(parameters[0] + test_x @ parameters[1:], 0.0, 1.0)
    baseline = np.array([float(item.get("ai_score") or 0.0) for item in test], dtype=np.float64)
    model_mae = _mae(test_y, predictions)
    baseline_mae = _mae(test_y, baseline)
    improvement = baseline_mae - model_mae
    return {
        "parameters": {
            "intercept": round(float(parameters[0]), 8),
            "coefficients": [round(float(value), 8) for value in parameters[1:]],
            "feature_names": FEATURE_NAMES,
        },
        "metrics": {
            "training_examples": len(train),
            "test_examples": len(test),
            "baseline_mae": round(baseline_mae, 6),
            "model_mae": round(model_mae, 6),
            "improvement": round(improvement, 6),
            "minimum_improvement": minimum_improvement,
            "passed": improvement >= minimum_improvement,
        },
    }


def dataset_payload(rows: List[Dict[str, Any]]) -> Dict[str, Any]:
    return {
        "schema_version": "training-dataset.v1",
        "created_at": utc_now(),
        "examples": rows,
        "example_count": len(rows),
        "feature_names": FEATURE_NAMES,
    }


def dumps(value: Any) -> str:
    return json.dumps(value, ensure_ascii=False, separators=(",", ":"))
