
import axiosClient from './axiosClient';

const mapDepartment = (dept) => {
  if (!dept) return dept;
  let meta = {};
  try {
    meta = typeof dept.meta === 'string' ? JSON.parse(dept.meta) : (dept.meta || {});
  } catch (e) {}

  const status = dept.status ?? dept.is_active;
  
  return {
    ...dept,
    name: dept.department_name || dept.name || '',
    code: dept.department_code || dept.code || '',
    is_active: ![false, 0, '0', 'f', 'false', 'INACTIVE'].includes(status),
    parent_id: meta.parent_id ?? meta.parent_department_id ?? dept.parent_id ?? null,
    manager_id: meta.manager_id || dept.manager_id || null,
    cost_center: meta.cost_center || dept.cost_center || '',
    budget: meta.budget ?? dept.budget ?? null,
  };
};

export const departmentService = {
  // Get all departments
  getAll: async () => {
    const response = await axiosClient.get('/departments');
    const data = response.data || [];
    return Array.isArray(data) ? data.map(mapDepartment) : data;
  },

  // Get department by ID
  getById: async (id) => {
    const response = await axiosClient.get(`/departments/${id}`);
    return mapDepartment(response.data);
  },

  // Create new department
  create: async (data) => {
    const payload = {
      department_code: data.code,
      department_name: data.name,
      manager_id: data.manager_id,
      parent_id: data.parent_id,
      status: data.is_active ?? true
    };
    const response = await axiosClient.post('/departments', payload);
    return response.data;
  },

  // Update department
  update: async (id, data) => {
    const payload = { ...data };
    if (payload.code !== undefined) {
      payload.department_code = payload.code;
      delete payload.code;
    }
    if (payload.name !== undefined) {
      payload.department_name = payload.name;
      delete payload.name;
    }
    if (payload.parent_id !== undefined) {
      payload.parent_id = payload.parent_id || null;
    }
    if (payload.is_active !== undefined) {
      payload.status = payload.is_active;
      delete payload.is_active;
    }
    const response = await axiosClient.patch(`/departments/${id}`, payload);
    return response.data;
  },

  // Delete department
  delete: async (id) => {
    const response = await axiosClient.delete(`/departments/${id}`);
    return response.data;
  },

  // ── Members (matrix-aware) ──────────────────────────
  // GET /departments/{id}/members -> { primary: [...], secondary: [...] }
  getMembers: async (id) => {
    const response = await axiosClient.get(`/departments/${id}/members`);
    return response.data || { primary: [], secondary: [] };
  },

  // Add a SECONDARY (kiêm nhiệm) member to a department.
  addMember: async (id, employeeId, roleInDept = null) => {
    const response = await axiosClient.post(`/departments/${id}/members`, {
      employee_id: employeeId,
      role_in_dept: roleInDept
    });
    return response.data;
  },

  removeMember: async (id, employeeId) => {
    const response = await axiosClient.delete(`/departments/${id}/members/${employeeId}`);
    return response.data;
  },

  // Set an employee's PRIMARY department = this dept (add new / transfer in).
  assignEmployee: async (id, employeeId) => {
    const response = await axiosClient.post(`/departments/${id}/assign-employee`, { employee_id: employeeId });
    return response.data;
  },

  // Remove an employee's PRIMARY department (→ chưa phân phòng).
  unassignEmployee: async (id, employeeId) => {
    const response = await axiosClient.post(`/departments/${id}/remove-employee`, { employee_id: employeeId });
    return response.data;
  },

  // Headcount + monthly salary cost per department.
  getCostSummary: async () => {
    const response = await axiosClient.get('/departments/cost-summary');
    const data = Array.isArray(response.data) ? response.data : (response.data?.items || []);
    const map = {};
    data.forEach((r) => { map[Number(r.department_id)] = { headcount: Number(r.headcount || 0), total_salary: Number(r.total_salary || 0) }; });
    return map;
  }
};
