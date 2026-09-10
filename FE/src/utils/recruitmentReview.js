export const calculateWeightedReviewScore = (criteria = []) => {
  const scored = criteria.filter(item => item.score !== null && item.score !== undefined);
  if (!scored.length) return 0;
  const totalWeight = scored.reduce((sum, item) => sum + Number(item.weight || 0), 0);
  if (totalWeight <= 0) return 0;
  const weighted = scored.reduce((sum, item) => (
    sum + Number(item.weight || 0) * Number(item.score || 0) / 5
  ), 0);
  return Math.round(weighted * 100 / totalWeight);
};

export const buildIndependentReviewRubric = (candidate = {}) => {
  const assessmentRubric = candidate?.meta?.ai_assessment?.criteria || [];
  const legacySkills = [
    ...(candidate?.ai_matched_skills_json || []),
    ...(candidate?.ai_missing_skills_json || [])
  ];
  const rubric = assessmentRubric.length
    ? assessmentRubric
    : (legacySkills.length ? legacySkills.map((skill, index) => ({
        id: `legacy-skill:${String(skill).toLowerCase().replace(/[^a-z0-9]+/g, '-') || index}`,
        criterion: skill,
        weight: 100 / Math.max(legacySkills.length, 1)
      })) : [{ id: 'legacy:overall', criterion: 'Mức độ phù hợp tổng thể với JD', weight: 100 }]);

  return rubric.map(item => ({
    criterion_id: item.id,
    criterion: item.criterion,
    weight: Number(item.weight || 0),
    score: null,
    reason: '',
    evidence: ''
  }));
};

