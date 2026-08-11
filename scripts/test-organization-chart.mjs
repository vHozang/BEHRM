import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const service = await readFile('client/src/services/organizationChartService.js', 'utf8');
assert.ok(service.includes('/organization-chart/structure'), 'Service chưa gọi endpoint sơ đồ đơn vị');

const chart = await readFile('client/src/views/OrganizationChart.vue', 'utf8');
for (const marker of [
  'Toàn công ty',
  'Chi nhánh',
  'Phòng ban',
  'breadcrumbs',
  'collapsed_by_default',
  'Tải PNG',
  'exportJson',
  'router.push',
]) {
  assert.ok(chart.includes(marker), `OrganizationChart.vue thiếu ${marker}`);
}

const node = await readFile('client/src/components/OrgTreeNode.vue', 'utf8');
for (const marker of ['Tổng nhân viên', 'Chưa gán người phụ trách', 'headcount_total', 'drilldown']) {
  assert.ok(node.includes(marker), `OrgTreeNode.vue thiếu ${marker}`);
}

const entities = await readFile('client/src/views/LegalEntities.vue', 'utf8');
assert.ok(entities.includes('head_employee_id'), 'Màn hình chi nhánh thiếu bộ chọn người đứng đầu');

const departments = await readFile('client/src/views/Departments.vue', 'utf8');
for (const marker of ['unit_type', 'WORKSHOP', 'TEAM']) {
  assert.ok(departments.includes(marker), `Màn hình phòng ban thiếu ${marker}`);
}

const layout = await readFile('client/src/views/Layout.vue', 'utf8');
const router = await readFile('client/src/router/index.js', 'utf8');
assert.ok(layout.includes("org_chart.view"), 'Sidebar chưa mở sơ đồ cho Trưởng phòng');
assert.ok(router.includes("org_chart.view"), 'Router chưa kiểm tra capability sơ đồ');

console.log('Organization chart UI regression checks passed.');
