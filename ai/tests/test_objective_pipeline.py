import json
import os
import sys
import unittest
from unittest.mock import Mock, patch

sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "app"))

from modules.candidate_scoring import build_assessment
from modules.human_feedback import compare_reviews, reference_score, training_eligibility
from modules.jd_rubric import build_jd_rubric
from modules.resume_parser import MinerUAdapter, redact_scoring_text
from modules.training_pipeline import train_calibrator
from main import parse_jd


class ObjectivePipelineTest(unittest.TestCase):
    def test_jd_parser_keeps_must_and_nice_to_have_sections_separate(self):
        jd = parse_jd(
            "Backend Developer (PHP/Laravel)\n"
            "Must-have: PHP, Laravel, REST API, MySQL, Git.\n"
            "Nice-to-have: Docker, Redis, Python, FastAPI.\n"
            "Minimum 2 years of relevant backend development experience."
        )

        self.assertEqual(
            ["git", "laravel", "mysql", "php", "rest_api"],
            jd["must_have"],
        )
        self.assertEqual(["docker", "fastapi", "python", "redis"], jd["nice_to_have"])
        self.assertEqual(2, jd["min_years"])

    def test_rubric_is_explicit_and_normalized(self):
        rubric = build_jd_rubric({
            "must_have": ["Laravel", "SQL"],
            "nice_to_have": ["Docker"],
            "min_years": 3,
        })

        self.assertEqual(100.0, round(sum(item["weight"] for item in rubric), 2))
        self.assertTrue(any(item["id"] == "must_have:laravel" and item["required"] for item in rubric))

    def test_assessment_contains_evidence_and_no_protected_attributes(self):
        profile = {
            "skills": [{"name": "Laravel", "evidence": "Built APIs with Laravel", "source_page": 2}],
            "work_experience": [{"years_total": 3, "evidence": "2023-2026 Backend Developer"}],
            "extraction_confidence": 0.9,
        }
        scores = {
            "semantic_score": 0.8,
            "must_have_score": 1.0,
            "nice_score": 0.0,
            "exp_score": 1.0,
            "project_score": 0.7,
            "final_score": 0.84,
        }
        assessment = build_assessment(
            {"must_have": ["Laravel"], "nice_to_have": [], "min_years": 3},
            profile,
            scores,
            {"parser_name": "mineru"},
        )

        self.assertEqual(84.0, assessment["ai_score"])
        self.assertFalse(assessment["protected_attributes_used"])
        self.assertEqual(2, assessment["criteria"][0]["source_page"])

    def test_redaction_removes_contact_and_protected_attributes(self):
        redacted = redact_scoring_text(
            "Nguyen Van A\nEmail: a@example.com\n0901234567\nGender: Male\nLaravel",
            "Nguyen Van A",
        )
        self.assertNotIn("a@example.com", redacted)
        self.assertNotIn("0901234567", redacted)
        self.assertNotIn("Male", redacted)
        self.assertIn("Laravel", redacted)

    @patch.dict(os.environ, {"MINERU_URL": "http://mineru.test", "MINERU_BACKEND": "pipeline"})
    @patch("modules.resume_parser.requests.post")
    def test_mineru_adapter_uses_content_list_with_page_evidence(self, post):
        response = Mock(ok=True)
        response.json.return_value = {
            "version": "3.4.4",
            "results": {
                "candidate": {
                    "md_content": "# CV",
                    "content_list": json.dumps([
                        {"type": "text", "text": "Laravel Developer", "page_idx": 1, "bbox": [1, 2, 3, 4]},
                    ]),
                },
            },
        }
        post.return_value = response

        parsed = MinerUAdapter().parse("candidate.pdf", b"pdf")

        self.assertEqual("mineru", parsed.parser_name)
        self.assertEqual(2, parsed.content_blocks[0]["page"])
        self.assertIn("Laravel Developer", parsed.text)

    def test_structured_feedback_requires_reasons_before_training(self):
        criteria = [{"criterion_id": "must_have:laravel", "score": 4, "reason": "Interview evidence"}]
        eligible = training_eligibility(0.8, criteria, "Reviewed", True)
        disagreements = compare_reviews(
            [{"id": "must_have:laravel", "criterion": "Laravel", "score": 2}],
            criteria,
        )

        self.assertTrue(eligible["eligible"])
        self.assertEqual(0.76, reference_score(0.7, 0.8))
        self.assertEqual("significant", disagreements[0]["severity"])

    def test_batch_calibrator_reports_offline_evaluation(self):
        examples = []
        for index in range(20):
            score = index / 20
            examples.append({
                "candidate_id": index,
                "review_id": index,
                "ai_score": score,
                "human_score": min(1.0, score * 0.9 + 0.05),
                "features": {
                    "semantic_score": score,
                    "must_have_score": score,
                    "nice_score": score,
                    "exp_score": score,
                    "project_score": score,
                },
            })

        result = train_calibrator(examples)

        self.assertEqual(5, len(result["parameters"]["coefficients"]))
        self.assertIn("baseline_mae", result["metrics"])
        self.assertIn("passed", result["metrics"])

    def test_calibrator_does_not_pass_when_it_only_matches_the_baseline(self):
        examples = [{
            "candidate_id": index,
            "review_id": index,
            "ai_score": 0.5,
            "human_score": 0.5,
            "features": {
                "semantic_score": 0.5,
                "must_have_score": 0.5,
                "nice_score": 0.5,
                "exp_score": 0.5,
                "project_score": 0.5,
            },
        } for index in range(20)]

        result = train_calibrator(examples)

        self.assertEqual(0.0, result["metrics"]["baseline_mae"])
        self.assertFalse(result["metrics"]["passed"])


if __name__ == "__main__":
    unittest.main()
