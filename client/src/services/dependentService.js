import axiosClient from './axiosClient';

// Service for tax dependents ("Người phụ thuộc" — giảm trừ gia cảnh PIT).
// CRUD through the generic /dependents resource. The payroll engine counts a
// dependent as active relief when its status is not in
// (false/0/inactive/expired/deleted) AND its start/end window covers the salary
// period — so the UI controls status + dates accordingly.
//
// axiosClient unwraps the {status,message,data} envelope (list -> array).
export const dependentService = {
  // GET /dependents?employee_id= -> array
  getAll: async (params = {}) => {
    const response = await axiosClient.get('/dependents', {
      params: { per_page: 100, ...params }
    });
    return Array.isArray(response.data) ? response.data : [];
  },

  create: async (payload) => {
    const response = await axiosClient.post('/dependents', payload);
    return response.data;
  },

  update: async (id, payload) => {
    const response = await axiosClient.put(`/dependents/${id}`, payload);
    return response.data;
  },

  remove: async (id) => {
    const response = await axiosClient.delete(`/dependents/${id}`);
    return response.data;
  }
};

export default dependentService;
