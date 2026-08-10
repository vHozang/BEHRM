import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { filterEmployeesByDepartment, findEmployeeByCode } from '../client/src/utils/employeeLookup.js';
import { formatMoneyInput, moneyDigits, parseMoneyInput } from '../client/src/utils/money.js';

assert.equal(formatMoneyInput(10000000, '.'), '10.000.000');
assert.equal(formatMoneyInput(10000000, ','), '10,000,000');
assert.equal(formatMoneyInput('001234567', '.'), '1.234.567');
assert.equal(parseMoneyInput('10.000.000'), 10000000);
assert.equal(parseMoneyInput('10,000,000'), 10000000);
assert.equal(parseMoneyInput('abc'), '');
assert.equal(moneyDigits(0), '0');

const employees = [
  { id: 1, employee_code: 'CN00001', full_name: 'Ca 2', department_id: 10 },
  { id: 2, employee_code: 'CN00002', full_name: 'Ca 3', department_id: 20 },
];
assert.equal(findEmployeeByCode(employees, ' cn00002 ')?.full_name, 'Ca 3');
assert.equal(findEmployeeByCode(employees, 'CN99999'), null);
assert.deepEqual(filterEmployeesByDepartment(employees, '10').map((employee) => employee.id), [1]);
assert.equal(filterEmployeesByDepartment(employees, '').length, 2);

const personnelSource = await readFile('client/src/views/PersonnelDecisions.vue', 'utf8');
assert.match(personnelSource, /v-model="selectedDepartmentId"/);
assert.match(personnelSource, /data-testid="input-employee-code"/);
assert.match(personnelSource, /selectedEmployee\?\.full_name/);
assert.match(personnelSource, /<BaseMoneyInput[^>]+input-new-salary/);

const settingsSource = await readFile('client/src/views/Settings.vue', 'utf8');
assert.match(settingsSource, /display\.money_group_separator/);
assert.match(settingsSource, /setMoneyGroupSeparator/);

console.log('Money input and employee selector regression checks passed.');
