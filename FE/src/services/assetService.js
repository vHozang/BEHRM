import axiosClient from './axiosClient';

export const assetService = {
  // --- Assets ---
  getAll: async (params) => {
    const response = await axiosClient.get('/assets', { params });
    return response.data;
  },
  getById: async (id) => {
    const response = await axiosClient.get(`/assets/${id}`);
    return response.data;
  },
  create: async (data) => {
    const response = await axiosClient.post('/assets', data);
    return response.data;
  },
  update: async (id, data) => {
    const response = await axiosClient.put(`/assets/${id}`, data);
    return response.data;
  },
  delete: async (id) => {
    const response = await axiosClient.delete(`/assets/${id}`);
    return response.data;
  },

  // --- Assignments ---
  getAllAssignments: async (params) => {
    const response = await axiosClient.get('/asset-assignments', { params });
    return response.data;
  },
  createAssignment: async (data) => {
    const response = await axiosClient.post('/asset-assignments', data);
    return response.data;
  },
  updateAssignment: async (id, data) => {
    const response = await axiosClient.put(`/asset-assignments/${id}`, data);
    return response.data;
  },
  deleteAssignment: async (id) => {
    const response = await axiosClient.delete(`/asset-assignments/${id}`);
    return response.data;
  }
};
