import axiosClient from './axiosClient';

export const salaryService = {
  getAllSummaries: async (params = {}) => {
    const rows = [];
    let page = 1;
    let lastPage;

    do {
      const response = await axiosClient.get('/salary-details', {
        params: { ...params, per_page: 100, page }
      });
      rows.push(...(Array.isArray(response.data) ? response.data : []));
      lastPage = Number(response.pagination?.last_page || 1);
      page++;
    } while (page <= lastPage);

    return rows.map(detail => ({
      ...detail,
      employee_code: detail.employee?.employee_code || detail.employee_code,
      full_name: detail.employee?.full_name || detail.full_name,
      department_id: detail.employee?.department_id || detail.department_id,
      department_name: detail.employee?.department?.department_name || detail.department_name,
      period_code: detail.period?.period_code || detail.period_code,
      basic_salary: detail.base_salary ?? detail.basic_salary ?? 0,
      allowances: detail.allowance_total ?? detail.allowances ?? 0,
      pay_date: detail.period?.end_date || detail.pay_date || detail.updated_at,
    }));
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
    const response = await axiosClient.get('/salary-periods', { params: { per_page: 100 } });
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
  // Maker–checker: kế toán trình chốt / thu hồi–trả về.
  submitPeriod: async (id) => {
    const response = await axiosClient.post(`/salary-periods/${id}/submit`);
    return response.data;
  },
  reopenPeriod: async (id, comment) => {
    const response = await axiosClient.post(`/salary-periods/${id}/reopen`, comment ? { comment } : {});
    return response.data;
  },
  // Chạy engine tính lương VN cho một kỳ (idempotent với kỳ đang mở)
  runPayroll: async (periodId) => {
    const response = await axiosClient.post('/payroll/run', { salary_period_id: periodId });
    return response.data;
  },
  // Poll tiến độ tính lương chạy nền (queue). Trả { run_status: PROCESSING|DONE|FAILED|IDLE, processed, total, error }.
  runStatus: async (periodId) => {
    const response = await axiosClient.get('/payroll/run-status', { params: { salary_period_id: periodId } });
    return response.data;
  },
  // Phiếu lương chi tiết (salary_detail + breakdowns + công tháng)
  getPayslip: async (detailId) => {
    const response = await axiosClient.get(`/salary-details/${detailId}/payslip`);
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
