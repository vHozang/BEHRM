import axiosClient from './axiosClient';

export const dashboardService = {
  getStats: async () => {
    const response = await axiosClient.get('/dashboard/stats');
    return response.data;
  }
};
