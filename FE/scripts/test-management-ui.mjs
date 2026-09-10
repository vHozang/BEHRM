import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import {
  certificateExpiryStatus,
  integrationEndpointLabel,
  normalizePercentage,
  notificationStatusEnabled
} from '../src/utils/managementUi.js';
import { isValidIsoDate, validateEmployeeDates } from '../src/utils/employeeDates.js';

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

assert.equal(isValidIsoDate('2026-08-04'), true);
assert.equal(isValidIsoDate('04/08/2026'), false);
assert.equal(isValidIsoDate('2026-02-30'), false);
assert.deepEqual(validateEmployeeDates('2026-08-04', '2026-08-03'), {
  hire_date: '',
  probation_end_date: 'Ngày hết thử việc không được trước ngày vào làm.'
});

const expectedBindings = [
  ['src/views/Settings.vue', ['getAutoRecruitHealth', 'saveNotificationTemplates']],
  ['src/views/Recruitment.vue', ['getAiFeedbackStats']],
  ['src/views/Employees.vue', ['importProbation']],
  ['src/views/EmployeeDetail.vue', ['getCertificates', 'addCertificate', 'deleteCertificate']],
  ['src/views/Salaries.vue', ['runBonus']],
  ['src/views/Leaves.vue', ['runAccrual']],
  ['src/components/RequestConfigurationPanel.vue', ['getTypes', 'getFlows', 'createType', 'updateType', 'deleteType', 'createFlow', 'updateFlow', 'deleteFlow']],
  ['src/components/SalaryPeriodsPanel.vue', ['suggestPeriod', 'createPeriod', 'updatePeriod', 'deletePeriod']],
  ['src/components/PayrollAdjustmentsPanel.vue', ['getAdjustments', 'saveAdjustment', 'updateAdjustment', 'submitAdjustment', 'approveAdjustment', 'rejectAdjustment', 'deleteAdjustment']],
  ['src/components/InsuranceClaimsPanel.vue', ['list', 'create', 'update', 'show', 'submit', 'review', 'payment', 'uploadCertificate', 'downloadCertificate']],
  ['src/components/LeaveOperationsPanel.vue', ['list', 'create', 'managerDecision', 'hrDecision', 'cancel']],
  ['src/components/ReportTemplatesPanel.vue', ['getCatalog', 'getTemplates', 'getTemplate', 'createTemplate', 'updateTemplate', 'deleteTemplate', 'getHistory', 'generateTemplate', 'downloadHistory']],
  ['src/views/Roles.vue', ['getPermissions', 'getRolePermissions', 'assignPermission', 'removePermission']],
  ['src/views/Holidays.vue', ['preview', 'seedVn']],
  ['src/views/EmployeeDetail.vue', ['detachFromOrg']]
];

for (const [file, bindings] of expectedBindings) {
  const source = await readFile(file, 'utf8');
  for (const binding of bindings) assert.match(source, new RegExp(`\\.${binding}\\b`), `${file} thiếu ${binding}`);
}

const expectedUiLiterals = [
  ['src/views/Requests.vue', ['RequestConfigurationPanel', 'request_type_id']],
  ['src/views/mobile/MRequests.vue', ['request_type_id', "getTypes({ status: 'ACTIVE' })"]],
  ['src/views/Salaries.vue', ['SalaryPeriodsPanel', 'PayrollAdjustmentsPanel', 'InsuranceClaimsPanel']],
  ['src/views/Contracts.vue', ['department_id', 'position_id', 'Phòng ban theo hợp đồng', 'Chức danh theo hợp đồng']],
  ['src/views/SalaryComponents.vue', ['resource="allowances"', 'resource="deductions"', 'resource="employee-allowances"', 'resource="employee-deductions"', 'resource="insurance-types"']],
  ['src/views/Assets.vue', ['resource="asset-categories"', 'resource="asset-locations"', 'resource="suppliers"', 'resource="asset-incidents"', 'resource="asset-maintenance"']],
  ['src/views/ServiceTickets.vue', ['resource="service-categories"', 'serviceTicketService.show', 'serviceTicketService.addUpdate']],
  ['src/views/EmployeeDetail.vue', ['resource="identity-documents"', 'resource="qualifications"', 'InsuranceClaimsPanel']],
  ['src/views/Leaves.vue', ['LeaveOperationsPanel']],
  ['src/views/ReportBuilder.vue', ['ReportTemplatesPanel']],
  ['src/views/Settings.vue', ['SettingsCatalogPanel']],
  ['src/views/RecruitmentPositions.vue', ['resource="recruitment-positions"']],
  ['src/services/axiosClient.js', ['withCredentials: true', 'coordinateRefresh', 'tokenNeedsRefresh', 'original._retry = true']],
  ['src/services/authRefreshCoordinator.js', ['hrm-auth-refresh-v1', 'hrm-auth-session-v1', 'BroadcastChannel', 'TOKEN_REFRESHED', 'SESSION_CLEARED']],
];

for (const [file, literals] of expectedUiLiterals) {
  const source = await readFile(file, 'utf8');
  for (const literal of literals) assert.ok(source.includes(literal), `${file} thiếu ${literal}`);
}

const employeesSource = await readFile('src/views/Employees.vue', 'utf8');
assert.ok(
  employeesSource.indexOf('Tổng nhân viên') < employeesSource.indexOf('data-testid="input-search-employee"'),
  'Dashboard nhân viên phải nằm trên bộ lọc'
);

console.log('Management UI regression checks passed.');
