import axiosClient from './axiosClient';

export const organizationChartService = {
  getStructure: async (params = {}) => {
    const response = await axiosClient.get('/organization-chart/structure', { params });
    return response.data || {};
  }
};

export default organizationChartService;
