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
  ['client/src/views/Leaves.vue', ['runAccrual']],
  ['client/src/components/RequestConfigurationPanel.vue', ['getTypes', 'getFlows', 'createType', 'updateType', 'deleteType', 'createFlow', 'updateFlow', 'deleteFlow']],
  ['client/src/components/SalaryPeriodsPanel.vue', ['suggestPeriod', 'createPeriod', 'updatePeriod', 'deletePeriod']],
  ['client/src/components/PayrollAdjustmentsPanel.vue', ['getAdjustments', 'saveAdjustment', 'updateAdjustment', 'submitAdjustment', 'approveAdjustment', 'rejectAdjustment', 'deleteAdjustment']],
  ['client/src/components/InsuranceClaimsPanel.vue', ['list', 'create', 'update', 'show', 'submit', 'review', 'payment', 'uploadCertificate', 'downloadCertificate']],
  ['client/src/components/LeaveOperationsPanel.vue', ['list', 'create', 'managerDecision', 'hrDecision', 'cancel']],
  ['client/src/components/ReportTemplatesPanel.vue', ['getCatalog', 'getTemplates', 'getTemplate', 'createTemplate', 'updateTemplate', 'deleteTemplate', 'getHistory', 'generateTemplate', 'downloadHistory']],
  ['client/src/views/Roles.vue', ['getPermissions', 'getRolePermissions', 'assignPermission', 'removePermission']],
  ['client/src/views/Holidays.vue', ['preview', 'seedVn']],
  ['client/src/views/EmployeeDetail.vue', ['detachFromOrg']]
];

for (const [file, bindings] of expectedBindings) {
  const source = await readFile(file, 'utf8');
  for (const binding of bindings) assert.match(source, new RegExp(`\\.${binding}\\b`), `${file} thiếu ${binding}`);
}

const expectedUiLiterals = [
  ['client/src/views/Requests.vue', ['RequestConfigurationPanel', 'request_type_id']],
  ['client/src/views/mobile/MRequests.vue', ['request_type_id', "getTypes({ status: 'ACTIVE' })"]],
  ['client/src/views/Salaries.vue', ['SalaryPeriodsPanel', 'PayrollAdjustmentsPanel', 'InsuranceClaimsPanel']],
  ['client/src/views/SalaryComponents.vue', ['resource="allowances"', 'resource="deductions"', 'resource="employee-allowances"', 'resource="employee-deductions"', 'resource="insurance-types"']],
  ['client/src/views/Assets.vue', ['resource="asset-categories"', 'resource="asset-locations"', 'resource="suppliers"', 'resource="asset-incidents"', 'resource="asset-maintenance"']],
  ['client/src/views/ServiceTickets.vue', ['resource="service-categories"', 'serviceTicketService.show', 'serviceTicketService.addUpdate']],
  ['client/src/views/EmployeeDetail.vue', ['resource="identity-documents"', 'resource="qualifications"', 'InsuranceClaimsPanel']],
  ['client/src/views/Leaves.vue', ['LeaveOperationsPanel']],
  ['client/src/views/ReportBuilder.vue', ['ReportTemplatesPanel']],
  ['client/src/views/Settings.vue', ['SettingsCatalogPanel']],
  ['client/src/views/RecruitmentPositions.vue', ['resource="recruitment-positions"']],
  ['client/src/services/axiosClient.js', ['withCredentials: true', 'coordinateRefresh', 'tokenNeedsRefresh', 'original._retry = true']],
  ['client/src/services/authRefreshCoordinator.js', ['hrm-auth-refresh-v1', 'hrm-auth-session-v1', 'BroadcastChannel', 'TOKEN_REFRESHED', 'SESSION_CLEARED']],
];

for (const [file, literals] of expectedUiLiterals) {
  const source = await readFile(file, 'utf8');
  for (const literal of literals) assert.ok(source.includes(literal), `${file} thiếu ${literal}`);
}

const employeesSource = await readFile('client/src/views/Employees.vue', 'utf8');
assert.ok(
  employeesSource.indexOf('Tổng nhân viên') < employeesSource.indexOf('data-testid="input-search-employee"'),
  'Dashboard nhân viên phải nằm trên bộ lọc'
);

console.log('Management UI regression checks passed.');
