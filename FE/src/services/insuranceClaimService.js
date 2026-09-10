import axiosClient from './axiosClient';

export const insuranceClaimService = {
  list: async (params = {}) => {
    const response = await axiosClient.get('/insurance-claims', { params });
    return { items: response.pageData?.items || response.data || [], pagination: response.pageData?.pagination || response.pagination || {} };
  },
  show: async (id) => (await axiosClient.get(`/insurance-claims/${id}`)).data,
  create: async (payload) => (await axiosClient.post('/insurance-claims', payload)).data,
  update: async (id, payload) => (await axiosClient.patch(`/insurance-claims/${id}`, payload)).data,
  remove: async (id) => (await axiosClient.delete(`/insurance-claims/${id}`)).data,
  submit: async (id) => (await axiosClient.post(`/insurance-claims/${id}/submit`)).data,
  review: async (id, payload) => (await axiosClient.post(`/insurance-claims/${id}/review`, payload)).data,
  payment: async (id, payload) => (await axiosClient.post(`/insurance-claims/${id}/payment`, payload)).data,
  uploadCertificate: async (id, file) => {
    const data = new FormData(); data.append('file', file);
    return (await axiosClient.post(`/insurance-claims/${id}/certificate`, data)).data;
  },
  downloadCertificate: async (id) => (await axiosClient.get(`/insurance-claims/${id}/certificate`, { responseType: 'blob' })).data,
};
