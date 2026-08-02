const assert = require('node:assert/strict');

process.env.DEVICE_TOKEN = 'dev_test';
const {
  isEligibleForAutomaticUpload,
  normalizeUploadDelay,
} = require('./bridge');

const now = Date.UTC(2026, 7, 2, 5, 0, 0);

assert.equal(isEligibleForAutomaticUpload(now - 15 * 60 * 1000, 15, now), true);
assert.equal(isEligibleForAutomaticUpload(now - 14 * 60 * 1000, 15, now), false);
assert.equal(isEligibleForAutomaticUpload(now - 1 * 60 * 1000, 15, now), false);
assert.equal(isEligibleForAutomaticUpload(now - 1 * 60 * 1000, 1, now), true);
assert.equal(normalizeUploadDelay(0), 1);
assert.equal(normalizeUploadDelay(2000), 1440);
assert.equal(normalizeUploadDelay('invalid'), 15);

console.log('bridge delay tests passed');
