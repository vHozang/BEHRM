
import axiosClient from './axiosClient';

export const activityLogService = {
  // Get activity logs with filters
  getAll: async (params) => {
    const response = await axiosClient.get('/activity-logs', { params });
    return response.data;
  }
};
