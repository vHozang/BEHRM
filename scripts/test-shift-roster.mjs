import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const service = await readFile('client/src/services/shiftRosterService.js', 'utf8');
for (const endpoint of [
  '/shift-roster/calendar',
  '/shift-roster/template',
  '/shift-roster/rotation/preview',
  '/shift-roster/rotation/apply',
  '/shift-roster/import/preview',
  '/shift-roster/import/apply',
]) {
  assert.ok(service.includes(endpoint), `shiftRosterService thiếu ${endpoint}`);
}

const management = await readFile('client/src/views/ShiftManagement.vue', 'utf8');
for (const marker of [
  'Xoay ca tự động',
  'Tải mẫu xếp ca',
  'Upload lịch Excel',
  'Ghi đè lịch sửa tay',
  'Khôi phục lịch nền',
  'current_shift_code',
  'is_day_off',
]) {
  assert.ok(management.includes(marker), `ShiftManagement.vue thiếu ${marker}`);
}

const router = await readFile('client/src/router/index.js', 'utf8');
assert.match(
  router,
  /path:\s*'shift-roster'[\s\S]*redirect:\s*\{\s*path:\s*'\/shifts',\s*query:\s*\{\s*tab:\s*'roster'/,
  'Route /shift-roster chưa chuyển về /shifts?tab=roster',
);

const portal = await readFile('client/src/views/EmployeePortal.vue', 'utf8');
for (const marker of ['weekly_rest_weekday', 'is_day_off', 'sourceRank', 'isShiftWorkday']) {
  assert.ok(portal.includes(marker), `EmployeePortal.vue thiếu ${marker}`);
}

console.log('Shift roster UI regression checks passed.');
