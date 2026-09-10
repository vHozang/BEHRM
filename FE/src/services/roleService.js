import axiosClient from './axiosClient';

export const roleService = {
  // Get all roles
  getAll: async () => {
    const response = await axiosClient.get('/roles');
    return response.data;
  },

  // Create role
  create: async (data) => {
    const response = await axiosClient.post('/roles', data);
    return response.data;
  },

  // Update role
  update: async (id, data) => {
    const response = await axiosClient.patch(`/roles/${id}`, data);
    return response.data;
  },

  // Delete role
  delete: async (id) => {
    const response = await axiosClient.delete(`/roles/${id}`);
    return response.data;
  },

  // ── Role membership (employee_roles) ──────────────────
  // Who holds a role (drives both module access and approval rights).
  getMembers: async (roleId) => {
    const response = await axiosClient.get('/employee-roles', { params: { role_id: roleId, per_page: 100 } });
    return Array.isArray(response.data) ? response.data : (response.data?.items || response.data?.data || []);
  },

  addMember: async (employeeId, roleId) => {
    const response = await axiosClient.post('/employee-roles', {
      employee_id: employeeId,
      role_id: roleId,
      is_active: true,
      effective_date: new Date().toISOString().slice(0, 10)
    });
    return response.data;
  },

  removeMember: async (employeeRoleId) => {
    const response = await axiosClient.delete(`/employee-roles/${employeeRoleId}`);
    return response.data;
  },

  // Get all permissions in the system
  getPermissions: async () => {
    const response = await axiosClient.get('/permissions');
    return response.data;
  },

  // Get permissions assigned to a specific role
  getRolePermissions: async (roleId) => {
    const response = await axiosClient.get('/role-permissions', {
      params: { role_id: roleId }
    });
    return response.data;
  },

  // Assign a permission to a role
  assignPermission: async (data) => {
    const response = await axiosClient.post('/role-permissions', data);
    return response.data;
  },

  // Remove a permission assignment by its id
  removePermission: async (id) => {
    const response = await axiosClient.delete(`/role-permissions/${id}`);
    return response.data;
  }
};
