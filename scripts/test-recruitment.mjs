import assert from 'node:assert/strict';
import {
  formatInterviewDateTime,
  interviewDateOnly,
  isUsableMeetingLink
} from '../client/src/utils/interview.js';
import { cvFilename, cvPreviewKind } from '../client/src/utils/cvPreview.js';
import {
  buildIndependentReviewRubric,
  calculateWeightedReviewScore
} from '../client/src/utils/recruitmentReview.js';

assert.equal(interviewDateOnly('2026-08-03'), '2026-08-03');
assert.equal(interviewDateOnly('2026-08-02T17:00:00.000000Z'), '2026-08-03');
assert.equal(formatInterviewDateTime({
  interview_date: '2026-08-02T17:00:00.000000Z',
  interview_time: '09:30:00'
}), '09:30 03/08/2026');
assert.equal(isUsableMeetingLink('https://meet.google.com/'), false);
assert.equal(isUsableMeetingLink('https://meet.google.com/not-a-room'), false);
assert.equal(isUsableMeetingLink('https://meet.google.com/abc-defg-hij'), true);
assert.equal(isUsableMeetingLink('https://zoom.us/j/123456789'), true);
assert.equal(cvPreviewKind('candidate.pdf', 'application/octet-stream'), 'pdf');
assert.equal(cvPreviewKind('candidate.bin', 'application/pdf'), 'pdf');
assert.equal(cvPreviewKind('candidate.docx', ''), 'docx');
assert.equal(cvPreviewKind('candidate.doc', 'application/msword'), 'download');
assert.equal(cvFilename({ id: 8, cv: { original_filename: 'CV Vu Ngoc Hoang.pdf' } }), 'CV Vu Ngoc Hoang.pdf');
assert.equal(calculateWeightedReviewScore([
  { weight: 60, score: 4 },
  { weight: 40, score: 3 }
]), 72);
const independentRubric = buildIndependentReviewRubric({
  meta: {
    ai_assessment: {
      criteria: [{ id: 'must_have:laravel', criterion: 'Laravel', weight: 100, score: 5 }]
    }
  }
});
assert.equal(independentRubric[0].criterion_id, 'must_have:laravel');
assert.equal(independentRubric[0].score, null);
assert.equal(Object.hasOwn(independentRubric[0], 'ai_score'), false);

console.log('Recruitment UI regression checks passed.');
