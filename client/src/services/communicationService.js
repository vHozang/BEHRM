import axiosClient from './axiosClient';

export const communicationService = {
  // --- News ---
  getAllNews: async (params) => {
    const response = await axiosClient.get('/news', { params });
    return response.data;
  },
  createNews: async (data) => {
    const response = await axiosClient.post('/news', data);
    return response.data;
  },
  updateNews: async (id, data) => {
    const response = await axiosClient.put(`/news/${id}`, data);
    return response.data;
  },
  deleteNews: async (id) => {
    const response = await axiosClient.delete(`/news/${id}`);
    return response.data;
  },
  markRead: async (newsId) => {
    const response = await axiosClient.post(`/news/${newsId}/read`);
    return response.data;
  },

  // --- Policies ---
  getAllPolicies: async (params) => {
    const response = await axiosClient.get('/policies', { params });
    return response.data;
  },
  createPolicy: async (data) => {
    const response = await axiosClient.post('/policies', data);
    return response.data;
  },
  updatePolicy: async (id, data) => {
    const response = await axiosClient.put(`/policies/${id}`, data);
    return response.data;
  },
  deletePolicy: async (id) => {
    const response = await axiosClient.delete(`/policies/${id}`);
    return response.data;
  },
  acknowledge: async (policyId, data) => {
    const response = await axiosClient.post(`/policies/${policyId}/acknowledge`, data);
    return response.data;
  },
  acknowledgePolicy: async (id, data) => {
    const response = await axiosClient.post(`/policies/${id}/acknowledge`, data);
    return response.data;
  }
};
