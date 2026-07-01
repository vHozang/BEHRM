import axiosClient from './axiosClient';

export const reportService = {
  // Generate a server-side report. type in [headcount, leave-summary, payroll-summary, attendance-summary]
  // Returns data:{ type, rows, history_id }
  generate: async (type, filters = {}) => {
    const response = await axiosClient.post('/reports/generate', { type, filters });
    return response.data;
  }
};
