export const normalizeStatusForApi = (value) => {
  const status = String(value || 'ACTIVE').trim().toLowerCase();
  const map = {
    active: 'ACTIVE',
    probation: 'PROBATION',
    on_leave: 'ON_LEAVE',
    inactive: 'INACTIVE',
    resigned: 'TERMINATED',
    terminated: 'TERMINATED',
  };
  return map[status] || status.toUpperCase();
};

export const buildEmployeeCreatePayload = (data = {}) => {
  const profile = {
    ...(data.profile || {}),
    address: data.address || '',
    personal_phone: data.personal_phone || data.phone || '',
    bank_name: data.bank_name || '',
    bank_account: data.bank_account || '',
    personal_email: data.personal_email || '',
    id_number: data.id_number || '',
    id_issue_date: data.id_issue_date || '',
    id_issue_place: data.id_issue_place || '',
    tax_number: data.tax_number || '',
    insurance_number: data.insurance_number || '',
    emergency_contact_name: data.emergency_contact_name || '',
    emergency_contact_relationship: data.emergency_contact_relationship || data.relationship || '',
    emergency_contact_phone: data.emergency_contact_phone || '',
    ethnicity: data.ethnicity || '',
    religion: data.religion || '',
    marital_status: data.marital_status || '',
    hometown: data.hometown || '',
    permanent_address: data.permanent_address || '',
    education_level: data.education_level || '',
    nationality_name: data.nationality_name || '',
    probation_end_date: data.probation_end_date || '',
  };

  const employment = data.employment || {};
  const statusSource = data.status || employment.employment_status || data.employment_status || 'ACTIVE';

  return {
    employee_code: data.employee_code || data.code || '',
    full_name: data.full_name || '',
    gender: data.gender || null,
    date_of_birth: data.date_of_birth || data.dob || null,
    company_email: data.company_email || data.work_email || employment.company_email || employment.work_email || '',
    phone_number: data.phone_number || data.phone || data.personal_phone || '',
    personal_email: data.personal_email || '',
    status: normalizeStatusForApi(statusSource),
    hire_date: data.hire_date || employment.start_date || data.start_date || new Date().toISOString().split('T')[0],
    department_id: data.department_id || employment.department_id || null,
    position_id: data.position_id || data.job_title_id || employment.position_id || employment.job_title_id || null,
    manager_id: data.manager_id || employment.manager_id || null,
    profile,
  };
};
