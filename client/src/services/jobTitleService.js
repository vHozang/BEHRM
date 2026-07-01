
import axiosClient from './axiosClient';

export const jobTitleService = {
  // Get all job titles
  getAll: async () => {
    const response = await axiosClient.get('/positions');
    return response.data;
  },

  // Create job title
  create: async (data) => {
    const response = await axiosClient.post('/positions', data);
    return response.data;
  },

  // Update job title
  update: async (id, data) => {
    const response = await axiosClient.patch(`/positions/${id}`, data);
    return response.data;
  },

  // Delete job title
  delete: async (id) => {
    const response = await axiosClient.delete(`/positions/${id}`);
    return response.data;
  }
};
