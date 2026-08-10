import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import {
  certificateExpiryStatus,
  integrationEndpointLabel,
  normalizePercentage,
  notificationStatusEnabled
} from '../client/src/utils/managementUi.js';

assert.equal(integrationEndpointLabel('http://100.105.84.89:8000'), 'Mac (ưu tiên)');
assert.equal(integrationEndpointLabel('http://100.95.129.101:8000'), 'Windows (dự phòng)');
assert.equal(normalizePercentage(0.875), 87.5);
assert.equal(normalizePercentage(72), 72);
assert.equal(notificationStatusEnabled('ACTIVE'), true);
assert.equal(notificationStatusEnabled('INACTIVE'), false);

const now = new Date('2026-08-04T12:00:00');
assert.equal(certificateExpiryStatus(null, now).label, 'Không thời hạn');
assert.equal(certificateExpiryStatus('2026-08-03', now).label, 'Hết hạn');
assert.equal(certificateExpiryStatus('2026-08-20', now).label, 'Sắp hết hạn');
assert.equal(certificateExpiryStatus('2027-01-01', now).label, 'Còn hạn');

const expectedBindings = [
  ['client/src/views/Settings.vue', ['getAutoRecruitHealth', 'saveNotificationTemplates']],
  ['client/src/views/Recruitment.vue', ['getAiFeedbackStats']],
  ['client/src/views/Employees.vue', ['importProbation']],
  ['client/src/views/EmployeeDetail.vue', ['getCertificates', 'addCertificate', 'deleteCertificate']],
  ['client/src/views/Salaries.vue', ['runBonus']],
  ['client/src/views/Leaves.vue', ['runAccrual']]
];

for (const [file, bindings] of expectedBindings) {
  const source = await readFile(file, 'utf8');
  for (const binding of bindings) assert.match(source, new RegExp(`\\.${binding}\\b`), `${file} thiếu ${binding}`);
}

const employeesSource = await readFile('client/src/views/Employees.vue', 'utf8');
assert.ok(
  employeesSource.indexOf('Tổng nhân viên') < employeesSource.indexOf('data-testid="input-search-employee"'),
  'Dashboard nhân viên phải nằm trên bộ lọc'
);

console.log('Management UI regression checks passed.');
