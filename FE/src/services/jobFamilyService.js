
import axiosClient from './axiosClient';

export const jobFamilyService = {
  // Get all job families
  getAll: async () => {
    const response = await axiosClient.get('/job-families');
    return response.data;
  },

  // Create job family
  create: async (data) => {
    const response = await axiosClient.post('/job-families', data);
    return response.data;
  },

  // Update job family
  update: async (id, data) => {
    const response = await axiosClient.patch(`/job-families/${id}`, data);
    return response.data;
  },

  // Delete job family
  delete: async (id) => {
    const response = await axiosClient.delete(`/job-families/${id}`);
    return response.data;
  }
};
