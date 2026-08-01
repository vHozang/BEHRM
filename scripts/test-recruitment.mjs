import assert from 'node:assert/strict';
import {
  formatInterviewDateTime,
  interviewDateOnly,
  isUsableMeetingLink
} from '../client/src/utils/interview.js';

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

console.log('Recruitment UI regression checks passed.');
