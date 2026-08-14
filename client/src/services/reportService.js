import axiosClient from './axiosClient';

export const reportService = {
  // Generate a server-side report. type in [headcount, leave-summary, payroll-summary, attendance-summary]
  // Returns data:{ type, rows, history_id }
  generate: async (type, filters = {}) => {
    const response = await axiosClient.post('/reports/generate', { type, filters });
    return response.data;
  },
  getCatalog: async () => (await axiosClient.get('/reports/catalog')).data,
  getTemplates: async (params = {}) => {
    const response = await axiosClient.get('/reports/templates', { params });
    return { items: Array.isArray(response.data) ? response.data : [], pagination: response.pagination || null };
  },
  getTemplate: async (id) => (await axiosClient.get(`/reports/templates/${id}`)).data,
  createTemplate: async (payload) => (await axiosClient.post('/reports/templates', payload)).data,
  updateTemplate: async (id, payload) => (await axiosClient.patch(`/reports/templates/${id}`, payload)).data,
  deleteTemplate: async (id) => (await axiosClient.delete(`/reports/templates/${id}`)).data,
  generateTemplate: async (id, filters = {}) => (await axiosClient.post('/reports/generate', { template_id: id, filters })).data,
  getHistory: async (params = {}) => {
    const response = await axiosClient.get('/reports/history', { params });
    return { items: Array.isArray(response.data) ? response.data : [], pagination: response.pagination || null };
  },
  downloadHistory: async (id) => (await axiosClient.get(`/reports/history/${id}/download`, { responseType: 'blob' })).data,
};
