import axiosClient from './axiosClient';

export const serviceTicketService = {
  list: async (params = {}) => {
    const response = await axiosClient.get('/service-tickets', { params });
    return { items: response.pageData?.items || response.data || [], pagination: response.pageData?.pagination || response.pagination || {}, summary: response.pageData?.summary || response.summary || {} };
  },
  show: async (id) => (await axiosClient.get(`/service-tickets/${id}`)).data,
  create: async (payload) => (await axiosClient.post('/service-tickets', payload)).data,
  update: async (id, payload) => (await axiosClient.patch(`/service-tickets/${id}`, payload)).data,
  addUpdate: async (id, comment) => (await axiosClient.post(`/service-tickets/${id}/updates`, { comment })).data,
  cancel: async (id, comment = null) => (await axiosClient.post(`/service-tickets/${id}/cancel`, { comment })).data,
  categories: async () => (await axiosClient.get('/service-categories', { params: { status: 'ACTIVE', per_page: 100 } })).data,
};
