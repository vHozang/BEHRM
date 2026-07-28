import axiosClient from './axiosClient';

export const personnelDecisionService = {
  getAll: async (params = {}) => {
    const response = await axiosClient.get('/personnel-decisions', { params });
    return response.data;
  },
  create: async (payload) => {
    const response = await axiosClient.post('/personnel-decisions', payload);
    return response.data;
  },
  approve: async (id) => {
    const response = await axiosClient.post(`/personnel-decisions/${id}/approve`);
    return response.data;
  },
  reject: async (id, comment) => {
    const response = await axiosClient.post(`/personnel-decisions/${id}/reject`, comment ? { comment } : {});
    return response.data;
  },
};
