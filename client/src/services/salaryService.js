import axiosClient from './axiosClient';

const acceptedPayload = (response) => response.data?.data ?? response.data;

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
  deletePeriod: async (id) => {
    const response = await axiosClient.delete(`/salary-periods/${id}`);
    return response.data;
  },
  suggestPeriod: async (month, legalEntityId) => {
    const response = await axiosClient.get('/salary-periods/suggestion', {
      params: { month, legal_entity_id: legalEntityId || undefined }
    });
    return response.data;
  },
  closePeriod: async (id, allowPartial = false) => {
    const response = await axiosClient.post(`/salary-periods/${id}/close`, { allow_partial: allowPartial });
    return response.data;
  },
  // Maker–checker: kế toán trình chốt / thu hồi–trả về.
  submitPeriod: async (id, allowPartial = false) => {
    const response = await axiosClient.post(`/salary-periods/${id}/submit`, { allow_partial: allowPartial });
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
  runBonus: async (payload) => {
    const response = await axiosClient.post('/payroll/bonus-run', payload);
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
  getPayslipReadiness: async (periodId) => {
    const response = await axiosClient.get(`/salary-periods/${periodId}/payslips/readiness`);
    return response.data;
  },
  publishPayslips: async (periodId) => {
    const response = await axiosClient.post(`/salary-periods/${periodId}/payslips/publish`);
    return acceptedPayload(response);
  },
  getPayslipPublicationStatus: async (periodId) => {
    const response = await axiosClient.get(`/salary-periods/${periodId}/payslips/status`);
    return response.data;
  },
  getPayslipPdf: async (detailId, download = false) => {
    const response = await axiosClient.get(`/salary-details/${detailId}/payslip/pdf`, {
      params: download ? { download: 1 } : {},
      responseType: 'blob'
    });
    return response.data;
  },
  emailPayslip: async (detailId) => {
    const response = await axiosClient.post(`/salary-details/${detailId}/payslip/email`);
    return acceptedPayload(response);
  },
  getPayslipArchive: async (periodId) => {
    const response = await axiosClient.get(`/salary-periods/${periodId}/payslips/archive`, {
      responseType: 'blob'
    });
    return response.data;
  },
  getPayslipIssues: async (params = {}) => {
    const response = await axiosClient.get('/payroll/payslip-issues', { params });
    return { items: Array.isArray(response.data) ? response.data : [], pagination: response.pagination || null };
  },
  retryPayslipIssue: async (id) => {
    const response = await axiosClient.post(`/payroll/payslip-issues/${id}/retry`);
    return acceptedPayload(response);
  },

  // Salary details and breakdowns are engine outputs. FE only reads them;
  // corrections go through payroll adjustments and a payroll rerun.
  getDetails: async (params) => {
    const response = await axiosClient.get('/salary-details', { params });
    return response.data;
  },

  // --- Adjustments ---
  getAdjustments: async (params) => {
    const response = await axiosClient.get('/payroll-adjustments', { params });
    return { items: Array.isArray(response.data) ? response.data : [], pagination: response.pagination || null };
  },
  saveAdjustment: async (data) => {
    const response = await axiosClient.post('/payroll-adjustments', data);
    return response.data;
  },
  updateAdjustment: async (id, data) => {
    const response = await axiosClient.put(`/payroll-adjustments/${id}`, data);
    return response.data;
  },
  deleteAdjustment: async (id) => {
    const response = await axiosClient.delete(`/payroll-adjustments/${id}`);
    return response.data;
  },
  submitAdjustment: async (id) => {
    const response = await axiosClient.post(`/payroll-adjustments/${id}/submit`);
    return response.data;
  },
  approveAdjustment: async (id) => {
    const response = await axiosClient.post(`/payroll-adjustments/${id}/approve`);
    return response.data;
  },
  rejectAdjustment: async (id, reason) => {
    const response = await axiosClient.post(`/payroll-adjustments/${id}/reject`, { reason });
    return response.data;
  }
};
