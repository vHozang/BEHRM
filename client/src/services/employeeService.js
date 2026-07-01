
import axiosClient from './axiosClient';

const mapEmployee = (emp) => {
  if (!emp) return emp;

  let profileObj = {};
  if (emp.profile) {
    if (typeof emp.profile === 'string') {
      try {
        profileObj = JSON.parse(emp.profile);
      } catch (e) {
        console.error('Failed to parse employee profile json', e);
      }
    } else if (typeof emp.profile === 'object') {
      profileObj = emp.profile;
    }
  }

  const personal_email = profileObj.personal_email || emp.personal_email || '';
  const personal_phone = profileObj.personal_phone || emp.personal_phone || emp.phone_number || emp.phone || '';
  const address = profileObj.address || profileObj.current_address || emp.current_address || emp.permanent_address || emp.address || '';
  const bank_name = profileObj.bank_name || emp.bank_name || '';
  const bank_account = profileObj.bank_account || emp.bank_account || '';

  return {
    ...emp,
    ...profileObj,
    personal_email,
    personal_phone,
    address,
    bank_name,
    bank_account,
    
    // Identity & Emergency contact unpacking
    id_number: profileObj.id_number || emp.id_number || '',
    id_issue_date: profileObj.id_issue_date || emp.id_issue_date || '',
    id_issue_place: profileObj.id_issue_place || emp.id_issue_place || '',
    tax_number: profileObj.tax_number || emp.tax_number || '',
    insurance_number: profileObj.insurance_number || emp.insurance_number || '',
    emergency_contact_name: profileObj.emergency_contact_name || profileObj.emergency_contact?.name || emp.emergency_contact_name || '',
    emergency_contact_relationship: profileObj.emergency_contact_relationship || profileObj.emergency_contact?.relationship || profileObj.relationship || emp.relationship || emp.emergency_contact_relationship || '',
    emergency_contact_phone: profileObj.emergency_contact_phone || profileObj.emergency_contact?.phone_number || emp.emergency_contact_phone || '',

    job_title: emp.position?.position_name || emp.position_name || emp.job_title || '',
    job_title_name: emp.position?.position_name || emp.position_name || emp.job_title_name || '',
    job_title_id: emp.position_id || emp.position?.id || emp.job_title_id || '',
    department: emp.department?.department_name || emp.department_name || emp.department || '',
    department_name: emp.department?.department_name || emp.department_name || '',
    email: emp.company_email || personal_email || emp.email || '',
    work_email: emp.company_email || emp.work_email || '',
    phone: personal_phone || emp.phone_number || emp.phone || '',
    dob: emp.date_of_birth || emp.dob || '',
    date_of_birth: emp.date_of_birth || emp.dob || '',
    hire_date: emp.hire_date || emp.start_date || '',
    start_date: emp.hire_date || emp.start_date || '',
    code: emp.employee_code || emp.code || '',
    employment_status: ({
      ACTIVE: 'active', 'ĐANG_LÀM_VIỆC': 'active',
      PROBATION: 'probation', 'THỬ_VIỆC': 'probation',
      ON_LEAVE: 'on_leave',
      TERMINATED: 'terminated', 'ĐÃ_NGHỈ_VIỆC': 'terminated',
      INACTIVE: 'inactive', RESIGNED: 'resigned',
    }[emp.status]) || (emp.status ? String(emp.status).toLowerCase() : (emp.employment_status || 'active'))
  };
};

export const employeeService = {
  // Get all employees
  getAll: async (params) => {
    const response = await axiosClient.get('/employees', { params });
    const data = response.data || [];
    return Array.isArray(data) ? data.map(mapEmployee) : data;
  },

  // Get organization chart (nested tree)
  getOrgChart: async (rootId = null) => {
    const params = rootId ? { root_id: rootId } : {};
    const response = await axiosClient.get('/employees/org-chart', { params });
    return response.data;
  },

  // Get employee by ID
  getById: async (id) => {
    const response = await axiosClient.get(`/employees/${id}`);
    return mapEmployee(response.data);
  },

  // Create new employee
  // IMPORTANT: Laravel backend requires employment data nested in 'employment' object
  create: async (data) => {
    // Extract fields that belong to the profile JSONB column
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
    };

    // Flatten payload: department_id, position_id (job_title_id), and company_email (work_email) at root
    const payload = {
      employee_code: data.employee_code || data.code || '',
      full_name: data.full_name || '',
      gender: data.gender || null,
      date_of_birth: data.date_of_birth || data.dob || null,
      company_email: data.company_email || data.work_email || (data.employment && (data.employment.company_email || data.employment.work_email)) || '',
      phone_number: data.phone_number || data.phone || data.personal_phone || '',
      personal_email: data.personal_email || '',
      status: typeof (data.status || (data.employment && data.employment.employment_status) || data.employment_status || 'ACTIVE') === 'string'
        ? (data.status || (data.employment && data.employment.employment_status) || data.employment_status || 'ACTIVE').toUpperCase()
        : 'ACTIVE',
      hire_date: data.hire_date || (data.employment && data.employment.start_date) || data.start_date || new Date().toISOString().split('T')[0],
      department_id: data.department_id || (data.employment && data.employment.department_id) || null,
      position_id: data.position_id || data.job_title_id || (data.employment && (data.employment.position_id || data.employment.job_title_id)) || null,
      profile: profile
    };

    const response = await axiosClient.post('/employees', payload);
    return response.data;
  },

  // Update employee
  update: async (id, data) => {
    // 1. Fetch current employee to retrieve their existing profile and prevent overwrites
    let currentEmployee = {};
    try {
      const response = await axiosClient.get(`/employees/${id}`);
      currentEmployee = response.data || {};
    } catch (e) {
      console.warn('Failed to fetch current employee for profile merge', e);
    }
    
    const existingProfileObj = typeof currentEmployee.profile === 'string'
      ? JSON.parse(currentEmployee.profile || '{}')
      : (currentEmployee.profile || {});

    // 2. Extract and merge profile fields
    const profile = {
      ...existingProfileObj,
      ...(data.profile || {})
    };
    
    const profileKeys = [
      'address', 'personal_phone', 'bank_name', 'bank_account', 'personal_email',
      'id_number', 'id_issue_date', 'id_issue_place', 'tax_number', 'insurance_number',
      'emergency_contact_name', 'emergency_contact_relationship', 'emergency_contact_phone',
      // Extended VN "employee 360" fields (stored in profile JSONB — no migration).
      'ethnicity', 'religion', 'marital_status', 'hometown', 'permanent_address',
      'education_level', 'nationality_name', 'probation_end_date', 'avatar_url'
    ];
    
    profileKeys.forEach(key => {
      let val = undefined;
      if (key === 'address' && data.address !== undefined) {
        val = data.address;
      } else if (key === 'personal_phone') {
        if (data.personal_phone !== undefined) val = data.personal_phone;
        else if (data.phone !== undefined) val = data.phone;
      } else if (key === 'emergency_contact_relationship') {
        if (data.emergency_contact_relationship !== undefined) val = data.emergency_contact_relationship;
        else if (data.relationship !== undefined) val = data.relationship;
      } else if (data[key] !== undefined) {
        val = data[key];
      }
      
      if (val !== undefined) {
        profile[key] = val;
      }
    });

    // 3. Extract and map flat properties
    let deptId = data.department_id;
    let posId = data.position_id || data.job_title_id;
    let compEmail = data.company_email || data.work_email;
    let hireDate = data.hire_date || data.start_date;
    let statusVal = data.status || data.employment_status;
    
    if (data.employment && typeof data.employment === 'object') {
      const emp = data.employment;
      deptId = deptId || emp.department_id;
      posId = posId || emp.position_id || emp.job_title_id;
      compEmail = compEmail || emp.company_email || emp.work_email;
      hireDate = hireDate || emp.hire_date || emp.start_date;
      statusVal = statusVal || emp.status || emp.employment_status;
    }

    const flatPayload = {};
    
    if (data.employee_code !== undefined || data.code !== undefined) {
      flatPayload.employee_code = data.employee_code || data.code;
    }
    if (data.full_name !== undefined) {
      flatPayload.full_name = data.full_name;
    }
    if (data.gender !== undefined) {
      flatPayload.gender = data.gender;
    }
    if (data.date_of_birth !== undefined || data.dob !== undefined) {
      flatPayload.date_of_birth = data.date_of_birth || data.dob;
    }
    if (compEmail !== undefined) {
      flatPayload.company_email = compEmail;
    }
    if (data.phone_number !== undefined || data.phone !== undefined || data.personal_phone !== undefined) {
      flatPayload.phone_number = data.phone_number || data.phone || data.personal_phone;
    }
    if (data.personal_email !== undefined) {
      flatPayload.personal_email = data.personal_email;
    }
    if (statusVal !== undefined) {
      flatPayload.status = typeof statusVal === 'string' ? statusVal.toUpperCase() : statusVal;
    }
    if (hireDate !== undefined) {
      flatPayload.hire_date = hireDate;
    }
    if (deptId !== undefined) {
      flatPayload.department_id = deptId;
    }
    if (posId !== undefined) {
      flatPayload.position_id = posId;
    }
    if (data.avatar_url !== undefined) {
      flatPayload.avatar_url = data.avatar_url;
    }
    if (data.nationality_id !== undefined) {
      flatPayload.nationality_id = data.nationality_id;
    }
    if (data.manager_id !== undefined) {
      // Empty string -> null (clear manager); otherwise numeric id.
      flatPayload.manager_id = data.manager_id === '' || data.manager_id === null ? null : data.manager_id;
    }

    flatPayload.profile = profile;

    const response = await axiosClient.patch(`/employees/${id}`, flatPayload);
    return response.data;
  },

  // Departments of an employee: primary + secondary (kiêm nhiệm) memberships.
  getDepartments: async (id) => {
    const response = await axiosClient.get(`/employees/${id}/departments`);
    return response.data || { primary: null, secondary: [] };
  },

  // Reassign reporting line (manager) — used by the org chart.
  // Lightweight PATCH: only touches manager_id, không kéo theo profile.
  // managerId null/'' => gỡ quản lý (đưa lên gốc).
  updateManager: async (id, managerId) => {
    const response = await axiosClient.patch(`/employees/${id}`, {
      manager_id: managerId === '' || managerId === null || managerId === undefined ? null : Number(managerId),
    });
    return response.data;
  },

  // Delete employee
  delete: async (id) => {
    const response = await axiosClient.delete(`/employees/${id}`);
    return response.data;
  },

  // Remove from org chart: reparent direct reports to this person's manager and
  // mark them TERMINATED (safe — no hard delete). Used by the org chart.
  detachFromOrg: async (id) => {
    const response = await axiosClient.post(`/employees/${id}/detach-from-org`);
    return response.data;
  },

  // Get extended profile (qualifications, certificates, identity docs,
  // social insurance, dependents) in one call.
  getProfile: async (employeeId) => {
    const response = await axiosClient.get(`/employees/${employeeId}/profile`);
    return response.data;
  },

  // Get employment histories for an employee
  getHistories: async (employeeId) => {
    const response = await axiosClient.get(`/employees/${employeeId}/employment-histories`);
    return response.data;
  },

  // Get all employment histories across the tenant (generic resource, paginated).
  getAllHistories: async (params) => {
    const response = await axiosClient.get('/employment-histories', { params: { per_page: 100, ...params } });
    return Array.isArray(response.data) ? response.data : (response.data?.items || response.data?.data || []);
  },

  // Create employment history
  createHistory: async (employeeId, data) => {
    const payload = { ...data, employee_id: employeeId };
    const response = await axiosClient.post(`/employment-histories`, payload);
    return response.data;
  },

  // Get employee salaries
  getSalaries: async (employeeId) => {
    const response = await axiosClient.get(`/salary-details`, { params: { employee_id: employeeId } });
    return response.data;
  },

  // Create employee salary
  createSalary: async (employeeId, data) => {
    const payload = { ...data, employee_id: employeeId };
    const response = await axiosClient.post(`/salary-details`, payload);
    return response.data;
  },

  // Update employee salary
  updateSalary: async (employeeId, salaryId, data) => {
    const response = await axiosClient.patch(`/salary-details/${salaryId}`, data);
    return response.data;
  },

  // Delete employee salary
  deleteSalary: async (employeeId, salaryId) => {
    const response = await axiosClient.delete(`/salary-details/${salaryId}`);
    return response.data;
  }
};
