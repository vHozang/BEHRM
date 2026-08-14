import axiosClient from './axiosClient';

// Service for attendance regularization requests ("Đơn điều chỉnh công").
// Backend endpoints under /attendance-adjustments. Envelope is {status,message,data}
// for list/detail; this service returns response.data so callers read .data / .items.
export const regularizationService = {
  // GET /attendance-adjustments -> { status, message, data: { items, pagination } }
  // params: { employee_id?, status?, work_date?, page?, per_page? }
  getAll: async (params) => {
    const response = await axiosClient.get('/attendance-adjustments', { params });
    return {
      items: Array.isArray(response.data) ? response.data : [],
      pagination: response.pagination || null,
    };
  },

  // GET /attendance-adjustments/{id}
  get: async (id) => {
    const response = await axiosClient.get(`/attendance-adjustments/${id}`);
    return response.data;
  },

  // POST /attendance-adjustments -> 201 (status PENDING)
  // payload: { employee_id, work_date, requested_check_in_time?, requested_check_out_time?, requested_status?, reason }
  create: async (payload) => {
    const response = await axiosClient.post('/attendance-adjustments', payload);
    return response.data;
  },

  // POST /attendance-adjustments/{id}/approve (self-approval guard -> 422)
  approve: async (id, payload = {}) => {
    const response = await axiosClient.post(`/attendance-adjustments/${id}/approve`, payload);
    return { data: response.data, message: response.apiMessage };
  },

  // POST /attendance-adjustments/{id}/reject { decision_comment? }
  reject: async (id, payload = {}) => {
    const response = await axiosClient.post(`/attendance-adjustments/${id}/reject`, payload);
    return response.data;
  },

  // POST /attendance-adjustments/{id}/cancel (requester only)
  cancel: async (id) => {
    const response = await axiosClient.post(`/attendance-adjustments/${id}/cancel`);
    return response.data;
  }
};

export default regularizationService;
