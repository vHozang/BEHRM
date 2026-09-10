import axiosClient from './axiosClient';

export const leaveAdvancementService = {
  list: async (params = {}) => {
    const response = await axiosClient.get('/leave-advancement-requests', { params });
    return { items: response.pageData?.items || response.data || [], pagination: response.pageData?.pagination || response.pagination || {} };
  },
  create: async (payload) => (await axiosClient.post('/leave-advancement-requests', payload)).data,
  managerDecision: async (id, payload) => (await axiosClient.post(`/leave-advancement-requests/${id}/manager-decision`, payload)).data,
  hrDecision: async (id, payload) => (await axiosClient.post(`/leave-advancement-requests/${id}/hr-decision`, payload)).data,
  cancel: async (id) => (await axiosClient.post(`/leave-advancement-requests/${id}/cancel`)).data,
};
