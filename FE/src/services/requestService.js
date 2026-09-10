import axiosClient from './axiosClient';

export const requestService = {
  getAll: async (params) => {
    const response = await axiosClient.get('/requests', { params });
    return response.data;
  },

  get: async (id) => {
    const response = await axiosClient.get(`/requests/${id}`);
    return response.data;
  },

  create: async (data) => {
    const response = await axiosClient.post('/requests', data);
    return response.data;
  },

  update: async (id, data) => {
    const response = await axiosClient.patch(`/requests/${id}`, data);
    return response.data;
  },

  remove: async (id) => {
    const response = await axiosClient.delete(`/requests/${id}`);
    return response.data;
  },

  getTypes: async (params = {}) => {
    const response = await axiosClient.get('/request-types', { params: { per_page: 100, ...params } });
    return response.data;
  },

  createType: async (data) => {
    const response = await axiosClient.post('/request-types', data);
    return response.data;
  },

  updateType: async (id, data) => {
    const response = await axiosClient.patch(`/request-types/${id}`, data);
    return response.data;
  },

  deleteType: async (id) => {
    const response = await axiosClient.delete(`/request-types/${id}`);
    return response.data;
  },

  getFlows: async (params = {}) => {
    const response = await axiosClient.get('/approval-flows', { params: { per_page: 100, ...params } });
    return response.data;
  },

  createFlow: async (data) => {
    const response = await axiosClient.post('/approval-flows', data);
    return response.data;
  },

  updateFlow: async (id, data) => {
    const response = await axiosClient.patch(`/approval-flows/${id}`, data);
    return response.data;
  },

  deleteFlow: async (id) => {
    const response = await axiosClient.delete(`/approval-flows/${id}`);
    return response.data;
  },

  approve: async (id, payload) => {
    const response = await axiosClient.post(`/requests/${id}/approve`, payload || {});
    return response.data;
  },

  reject: async (id, payload) => {
    const response = await axiosClient.post(`/requests/${id}/reject`, payload || {});
    return response.data;
  },

  cancel: async (id) => {
    const response = await axiosClient.post(`/requests/${id}/cancel`);
    return response.data;
  },

  // ── Chứng từ đính kèm ──
  getAttachments: async (id) => {
    const response = await axiosClient.get(`/requests/${id}/attachments`);
    return response.data;
  },

  uploadAttachment: async (id, file) => {
    const fd = new FormData();
    fd.append('file', file);
    const response = await axiosClient.post(`/requests/${id}/attachments`, fd);
    return response.data;
  },

  // responseType blob: đây là tệp nhị phân, KHÔNG để interceptor bóc như JSON.
  downloadAttachment: async (id, attachmentId) => {
    const response = await axiosClient.get(`/requests/${id}/attachments/${attachmentId}`, { responseType: 'blob' });
    return response.data;
  }
};
