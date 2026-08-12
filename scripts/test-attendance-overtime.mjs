import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { statusVariant, statusVN } from '../client/src/utils/approvalSteps.js';

assert.equal(statusVN('OFFERED'), 'Chờ nhân viên phản hồi');
assert.equal(statusVN('DECLINED'), 'Từ chối');
assert.equal(statusVariant('OFFERED'), 'warning');
assert.equal(statusVariant('DECLINED'), 'destructive');

const service = await readFile('client/src/services/attendanceService.js', 'utf8');
for (const binding of [
  'regular_worked_minutes',
  'getRecordsPage',
  'getPayrollReviews',
  'decidePayrollReview',
  'createOvertimeTicket',
  'respondOvertimeTicket',
  'cancelOvertimeTicket',
  'payable_overtime_minutes',
]) {
  assert.match(service, new RegExp(`\\b${binding}\\b`), `attendanceService thiếu ${binding}`);
}

const attendance = await readFile('client/src/views/Attendance.vue', 'utf8');
for (const marker of [
  'Khấu trừ chờ HR duyệt',
  'Ca được gán',
  'Công hợp lệ',
  'Đến sớm',
  'Ở lại sau ca',
  'submitPayrollDecision',
  'attendance-pagination',
  'attendanceService.getCursorPage',
  'loadOverview(queryParams(), requestId)',
  'deviceLoadTimer = setTimeout(loadDeviceSyncStatus, 0)',
  'realtimeStartTimer = setTimeout',
]) {
  assert.ok(attendance.includes(marker), `Attendance.vue thiếu ${marker}`);
}
assert.ok(!attendance.includes('await loadDeviceSyncStatus()'), 'Device sync không được chặn đường tải chính');
assert.ok(!attendance.includes('label="Giờ tăng ca"'), 'Attendance.vue không được cho nhập tay giờ OT');

const overtime = await readFile('client/src/views/OvertimeRequests.vue', 'utf8');
for (const marker of [
  'Đơn đăng ký',
  'Ticket được giao',
  'Máy thực tế',
  'Giờ được thanh toán',
  'createOvertimeTicket',
  'respondOvertimeTicket',
  'payable_overtime_minutes',
]) {
  assert.ok(overtime.includes(marker), `OvertimeRequests.vue thiếu ${marker}`);
}

const salaries = await readFile('client/src/views/Salaries.vue', 'utf8');
assert.ok(salaries.includes('has_non_bypassable_issues'), 'Salaries.vue chưa chặn readiness bắt buộc');
assert.ok(salaries.includes('allow_partial'), 'Salaries.vue thiếu giải thích allow_partial');

const settings = await readFile('client/src/views/Settings.vue', 'utf8');
assert.ok(settings.includes('attendance-deduction-legal-warning'), 'Settings.vue thiếu cảnh báo pháp lý khấu trừ');

console.log('Attendance, payroll review and overtime UI regression checks passed.');
