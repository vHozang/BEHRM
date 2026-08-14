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

const timesheet = await readFile('client/src/views/MonthlyTimesheet.vue', 'utf8');
for (const marker of [
  'getCachedTimesheetPage',
  'fetchTimesheetPage',
  'prefetchTimesheetPage',
  'prefetchAdjacentPages',
  'loading.value = !cached',
]) {
  assert.ok(timesheet.includes(marker), `MonthlyTimesheet.vue thiếu ${marker}`);
}

const axiosClient = await readFile('client/src/services/axiosClient.js', 'utf8');
assert.match(
  axiosClient,
  /Number\(response\.data\.status\)\s*>=\s*200[\s\S]*Number\(response\.data\.status\)\s*<\s*300/,
  'axiosClient phải bóc envelope 202 để MonthlyTimesheet nhận run_id',
);
assert.ok(timesheet.includes('getAttendanceOperation(run.run_id)'), 'MonthlyTimesheet phải poll đúng run_id của tác vụ 202');

const attendanceStore = await readFile('client/src/stores/attendanceStore.js', 'utf8');
for (const marker of [
  'timesheetPageCache',
  'timesheetRequests',
  'timesheetCacheTtlMs',
  'maxTimesheetCacheEntries',
]) {
  assert.ok(attendanceStore.includes(marker), `attendanceStore thiếu ${marker}`);
}

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
assert.ok(!overtime.includes("canAccessModule('time')"), 'Quyền quản lý OT không được suy từ module time');
assert.ok(overtime.includes('canApproveRequest(item)'), 'OT phải ẩn thao tác tự duyệt ở UI');

const adjustments = await readFile('client/src/views/AttendanceAdjustments.vue', 'utf8');
for (const marker of ['canManageAdjustments', 'canReviewAdjustment(item)', 'canCancelAdjustment(item)']) {
  assert.ok(adjustments.includes(marker), `AttendanceAdjustments.vue thiếu khóa UI ${marker}`);
}
assert.ok(!adjustments.includes("canAccessModule('time')"), 'Quyền duyệt điều chỉnh không được suy từ module time');

const salaries = await readFile('client/src/views/Salaries.vue', 'utf8');
assert.ok(salaries.includes('has_non_bypassable_issues'), 'Salaries.vue chưa chặn readiness bắt buộc');
assert.ok(salaries.includes('allow_partial'), 'Salaries.vue thiếu giải thích allow_partial');

const settings = await readFile('client/src/views/Settings.vue', 'utf8');
assert.ok(settings.includes('attendance-deduction-legal-warning'), 'Settings.vue thiếu cảnh báo pháp lý khấu trừ');

console.log('Attendance, payroll review and overtime UI regression checks passed.');
