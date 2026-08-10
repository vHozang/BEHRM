import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const salaryService = await readFile('client/src/services/salaryService.js', 'utf8');
for (const binding of [
  'getPayslipReadiness',
  'publishPayslips',
  'getPayslipPublicationStatus',
  'getPayslipArchive',
  'getPayslipPdf',
  'emailPayslip',
  'getPayslipIssues',
  'retryPayslipIssue',
]) {
  assert.match(salaryService, new RegExp(`\\b${binding}\\b`), `salaryService thiếu ${binding}`);
}

const salaries = await readFile('client/src/views/Salaries.vue', 'utf8');
for (const marker of [
  'Phiếu lương chưa phát hành',
  'Xác nhận với danh sách loại trừ',
  'Phát hành & gửi phiếu',
  'getPayslipArchive',
  'downloadOfficialPdf',
]) {
  assert.ok(salaries.includes(marker), `Salaries.vue thiếu ${marker}`);
}

const portal = await readFile('client/src/views/EmployeePortal.vue', 'utf8');
for (const marker of ['Lịch sử phiếu đã phát hành', 'viewSalaryPdf', 'downloadSalaryPdf', 'emailSalaryPdf']) {
  assert.ok(portal.includes(marker), `EmployeePortal.vue thiếu ${marker}`);
}

const mobile = await readFile('client/src/views/mobile/MSalary.vue', 'utf8');
for (const marker of ['viewPdf', 'downloadPdf', 'emailPdf', 'route.query.payslip']) {
  assert.ok(mobile.includes(marker), `MSalary.vue thiếu ${marker}`);
}

const router = await readFile('client/src/router/index.js', 'utf8');
assert.ok(router.includes("'/m/salary'"), 'Router thiếu deep-link mobile tới phiếu lương');
assert.ok(router.includes("payslip_issues.view"), 'Router thiếu capability cho HR xem lỗi phiếu lương');

console.log('Payslip UI regression checks passed.');
