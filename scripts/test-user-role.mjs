import assert from 'node:assert/strict';
import { primaryUserRoleLabel, roleDisplayName, userRoleLabels } from '../client/src/utils/userRole.js';

const employee = { id: 1, roles: [{ role_code: 'EMPLOYEE', role_name: 'Employee' }] };
const manager = {
  id: 2,
  roles: [
    { role_code: 'EMPLOYEE', role_name: 'Employee' },
    { role_code: 'MANAGER', role_name: 'Manager' },
  ],
};
const hr = {
  id: 3,
  roles: [
    { role_code: 'EMPLOYEE', role_name: 'Employee' },
    { role_code: 'HR', role_name: 'Human Resources' },
  ],
};
const admin = {
  id: 4,
  roles: [
    { role_code: 'MANAGER', role_name: 'Manager' },
    { role_code: 'ADMIN', role_name: 'Administrator' },
    { role_code: 'EMPLOYEE', role_name: 'Employee' },
  ],
};

assert.equal(primaryUserRoleLabel(employee), 'Nhân viên');
assert.equal(primaryUserRoleLabel(manager), 'Trưởng phòng');
assert.equal(primaryUserRoleLabel(hr), 'Nhân sự');
assert.equal(primaryUserRoleLabel(admin), 'Quản trị viên');
assert.deepEqual(userRoleLabels(manager), ['Trưởng phòng']);
assert.deepEqual(userRoleLabels(admin), ['Quản trị viên', 'Trưởng phòng']);
assert.equal(roleDisplayName({ role_code: 'ACCOUNTANT', role_name: 'Accountant' }), 'Kế toán');
assert.equal(roleDisplayName({ role_code: 'CUSTOM_MANAGER', role_name: 'Quản lý vùng' }), 'Quản lý vùng');
assert.equal(primaryUserRoleLabel({ is_super_admin: true, roles: [] }), 'Quản trị viên hệ thống');
assert.equal(primaryUserRoleLabel({ roles: [] }), 'Nhân viên');

console.log('User role presentation checks passed.');
