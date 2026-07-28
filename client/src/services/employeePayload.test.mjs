import assert from 'node:assert/strict';
import { buildEmployeeCreatePayload } from './employeePayload.js';

const personalStep = {
  code: 'EMP0099',
  full_name: 'Nguyen Van Test',
  work_email: 'test.employee@company.com',
  gender: 'M',
  dob: '1995-05-20',
  personal_email: 'test.personal@example.com',
  personal_phone: '0901234567',
  address: '12 Nguyen Trai, Quan 1',
  ethnicity: 'Kinh',
  religion: 'Khong',
  marital_status: 'SINGLE',
  nationality_name: 'Viet Nam',
  hometown: 'Ha Noi',
  education_level: 'Dai hoc',
  permanent_address: '34 Le Loi, Ha Noi',
};

const workStep = {
  employment: {
    department_id: 2,
    job_title_id: 5,
    manager_id: 1,
    start_date: '2026-07-02',
    employment_status: 'active',
    employment_type: 'full_time',
  },
  probation_end_date: '2026-09-01',
};

const profileStep = {
  id_number: '012345678901',
  id_issue_date: '2020-01-15',
  id_issue_place: 'Cuc CSQLHC ve TTXH',
  tax_number: '1234567890',
  insurance_number: 'BHXH123456',
  bank_name: 'Vietcombank',
  bank_account: '1020304050',
  emergency_contact_name: 'Nguyen Thi A',
  emergency_contact_relationship: 'Me',
  emergency_contact_phone: '0912345678',
};

const formSubmitPayload = {
  ...personalStep,
  ...workStep,
  ...profileStep,
};

const payload = buildEmployeeCreatePayload(formSubmitPayload);

assert.equal(payload.employee_code, 'EMP0099');
assert.equal(payload.full_name, 'Nguyen Van Test');
assert.equal(payload.company_email, 'test.employee@company.com');
assert.equal(payload.personal_email, 'test.personal@example.com');
assert.equal(payload.phone_number, '0901234567');
assert.equal(payload.gender, 'M');
assert.equal(payload.date_of_birth, '1995-05-20');
assert.equal(payload.hire_date, '2026-07-02');
assert.equal(payload.status, 'ACTIVE');
assert.equal(payload.department_id, 2);
assert.equal(payload.position_id, 5);
assert.equal(payload.manager_id, 1);

assert.deepEqual(payload.profile, {
  address: '12 Nguyen Trai, Quan 1',
  personal_phone: '0901234567',
  bank_name: 'Vietcombank',
  bank_account: '1020304050',
  personal_email: 'test.personal@example.com',
  id_number: '012345678901',
  id_issue_date: '2020-01-15',
  id_issue_place: 'Cuc CSQLHC ve TTXH',
  tax_number: '1234567890',
  insurance_number: 'BHXH123456',
  emergency_contact_name: 'Nguyen Thi A',
  emergency_contact_relationship: 'Me',
  emergency_contact_phone: '0912345678',
  ethnicity: 'Kinh',
  religion: 'Khong',
  marital_status: 'SINGLE',
  hometown: 'Ha Noi',
  permanent_address: '34 Le Loi, Ha Noi',
  education_level: 'Dai hoc',
  nationality_name: 'Viet Nam',
  probation_end_date: '2026-09-01',
});

console.log('employeePayload create submit test passed');
