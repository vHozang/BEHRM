const assert = require('node:assert/strict');

process.env.DEVICE_TOKEN = 'dev_test';
const {
  enrichRecordData40,
  installNodeZkLibRecordDecoder,
  isEligibleForAutomaticUpload,
  mapPunchState,
  mapVerify,
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

const stateNames = [
  'CHECK_IN',
  'CHECK_OUT',
  'BREAK_OUT',
  'BREAK_IN',
  'OVERTIME_IN',
  'OVERTIME_OUT',
];
stateNames.forEach((state, code) => assert.equal(mapPunchState(code), state));
assert.equal(mapPunchState('check-in'), 'CHECK_IN');
assert.equal(mapPunchState('OT_OUT'), 'OVERTIME_OUT');
assert.equal(mapPunchState(99), 'AUTO');
assert.equal(mapPunchState(undefined), 'AUTO');

assert.equal(mapVerify(1), 'fingerprint');
assert.equal(mapVerify('15'), 'face');
assert.equal(mapVerify(2), 'card');

const recordData = Buffer.alloc(40);
recordData.writeUInt8(15, 26);
recordData.writeUInt8(1, 31);
assert.deepEqual(enrichRecordData40(recordData, { deviceUserId: '42' }), {
  deviceUserId: '42',
  verifyMode: 15,
  attendanceState: 1,
});

installNodeZkLibRecordDecoder();
const decoded = require('node-zklib/utils').decodeRecordData40(recordData);
assert.equal(decoded.verifyMode, 15);
assert.equal(decoded.attendanceState, 1);

console.log('bridge state and delay tests passed');
