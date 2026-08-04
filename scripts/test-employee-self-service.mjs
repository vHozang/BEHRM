import assert from 'node:assert/strict';
import { roleDemoAccounts, shiftDemoAccounts } from '../client/src/config/demoAccounts.js';
import { contractSignatureImage, contractSignStatus, contractStatusLabel } from '../client/src/utils/employeeContract.js';

assert.equal(roleDemoAccounts.length, 5);
assert.deepEqual(shiftDemoAccounts.map(account => account.label), [
  'Nhân viên ca 1',
  'Nhân viên ca 2',
  'Nhân viên ca 3',
]);
assert.deepEqual(shiftDemoAccounts.map(account => account.employeeCode), ['CN00003', 'CN00001', 'CN00002']);
assert.deepEqual(shiftDemoAccounts.map(account => account.shiftCode), ['CA1', 'CA2', 'CA3']);
assert.ok(shiftDemoAccounts.every(account => account.email.endsWith('@devtapcode.io.vn')));

const pending = { meta: JSON.stringify({ sign_status: 'PENDING_SIGN' }) };
const signed = { meta: { sign_status: 'SIGNED', signature: { image: 'data:image/png;base64,c2lnbmVk' } } };
assert.equal(contractSignStatus(pending), 'PENDING_SIGN');
assert.equal(contractStatusLabel(pending), 'Cần ký');
assert.equal(contractStatusLabel(signed), 'Đã ký');
assert.equal(contractSignatureImage(signed), 'data:image/png;base64,c2lnbmVk');

console.log('Employee self-service regression checks passed.');
