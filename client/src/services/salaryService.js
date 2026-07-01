import axiosClient from './axiosClient';

export const salaryService = {
  getAllSummaries: async (params) => {
    return salaryService.getDetails(params);
  },

  // Get all salary components
  getComponents: async () => {
    const response = await axiosClient.get('/salary-components');
    return response.data;
  },

  // Create salary component
  createComponent: async (data) => {
    const response = await axiosClient.post('/salary-components', data);
    return response.data;
  },

  // Update salary component
  updateComponent: async (id, data) => {
    const response = await axiosClient.patch(`/salary-components/${id}`, data);
    return response.data;
  },

  // Delete salary component
  deleteComponent: async (id) => {
    const response = await axiosClient.delete(`/salary-components/${id}`);
    return response.data;
  },

  // --- Periods ---
  getPeriods: async () => {
    const response = await axiosClient.get('/salary-periods');
    return response.data;
  },
  createPeriod: async (data) => {
    const response = await axiosClient.post('/salary-periods', data);
    return response.data;
  },
  updatePeriod: async (id, data) => {
    const response = await axiosClient.put(`/salary-periods/${id}`, data);
    return response.data;
  },
  closePeriod: async (id) => {
    const response = await axiosClient.post(`/salary-periods/${id}/close`);
    return response.data;
  },

  // --- Details ---
  getDetails: async (params) => {
    const response = await axiosClient.get('/salary-details', { params });
    return response.data;
  },
  saveDetail: async (data) => {
    const response = await axiosClient.post('/salary-details', data);
    return response.data;
  },
  updateDetail: async (id, data) => {
    const response = await axiosClient.put(`/salary-details/${id}`, data);
    return response.data;
  },
  deleteDetail: async (id) => {
    const response = await axiosClient.delete(`/salary-details/${id}`);
    return response.data;
  },

  // --- Breakdowns ---
  getBreakdowns: async (params) => {
    const response = await axiosClient.get('/salary-breakdowns', { params });
    return response.data;
  },
  saveBreakdown: async (data) => {
    const response = await axiosClient.post('/salary-breakdowns', data);
    return response.data;
  },
  updateBreakdown: async (id, data) => {
    const response = await axiosClient.put(`/salary-breakdowns/${id}`, data);
    return response.data;
  },
  deleteBreakdown: async (id) => {
    const response = await axiosClient.delete(`/salary-breakdowns/${id}`);
    return response.data;
  },

  // --- Adjustments ---
  getAdjustments: async (params) => {
    const response = await axiosClient.get('/payroll-adjustments', { params });
    return response.data;
  },
  saveAdjustment: async (data) => {
    const response = await axiosClient.post('/payroll-adjustments', data);
    return response.data;
  },
  updateAdjustment: async (id, data) => {
    const response = await axiosClient.put(`/payroll-adjustments/${id}`, data);
    return response.data;
  }
};
