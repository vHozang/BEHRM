import axiosClient from './axiosClient';

const leaveStatusMap = {
  PENDING: 'pending',
  'CHỜ_DUYỆT': 'pending',
  'CHỜ DUYỆT': 'pending',
  APPROVED: 'approved',
  'ĐÃ_DUYỆT': 'approved',
  'ĐÃ DUYỆT': 'approved',
  REJECTED: 'rejected',
  'TỪ_CHỐI': 'rejected',
  'TỪ CHỐI': 'rejected',
  CANCELLED: 'cancelled',
  CANCELED: 'cancelled',
  'ĐÃ_HỦY': 'cancelled',
  'ĐÃ HỦY': 'cancelled',
  UNKNOWN: 'unknown'
};

const toBackendLeaveStatus = {
  pending: 'PENDING',
  approved: 'APPROVED',
  rejected: 'REJECTED',
  cancelled: 'CANCELLED',
  canceled: 'CANCELLED'
};

function normalizeLeaveRequest(request) {
  if (!request || typeof request !== 'object') return request;
  const rawStatus = request.status == null ? 'UNKNOWN' : String(request.status);
  return {
    ...request,
    status: leaveStatusMap[rawStatus.toUpperCase()] || rawStatus.toLowerCase()
  };
}

function normalizeLeaveList(data) {
  return Array.isArray(data) ? data.map(normalizeLeaveRequest) : normalizeLeaveRequest(data);
}

function leavePayload(data) {
  const payload = { ...data };
  if (payload.status) {
    payload.status = toBackendLeaveStatus[payload.status] || payload.status;
  }
  return payload;
}

export const leaveService = {
  getAll: async (params) => {
    return leaveService.getRequests(params);
  },

  getAllRequests: async (params) => {
    return leaveService.getRequests(params);
  },

  getTypes: async () => {
    const response = await axiosClient.get('/leave-types');
    return response.data;
  },

  getBalances: async (employee_id) => {
    const response = await axiosClient.get(`/employees/${employee_id}/leave-balances`);
    return response.data;
  },

  getRequests: async (params) => {
    const response = await axiosClient.get('/leave-requests', { params });
    return normalizeLeaveList(response.data);
  },

  getRequest: async (id) => {
    const response = await axiosClient.get(`/leave-requests/${id}`);
    return normalizeLeaveRequest(response.data);
  },

  createRequest: async (data) => {
    const response = await axiosClient.post('/leave-requests', leavePayload({
      employee_id: data.employee_id,
      leave_type_id: data.leave_type_id,
      start_date: data.start_date,
      end_date: data.end_date,
      total_days: data.total_days,
      reason: data.reason,
      duration_type: data.duration_type || 'full_day',
      half_session: data.half_session,
      hours: data.hours,
      status: data.status || 'pending'
    }));
    return normalizeLeaveRequest(response.data);
  },

  updateRequest: async (id, data) => {
    const response = await axiosClient.patch(`/leave-requests/${id}`, leavePayload(data));
    return normalizeLeaveRequest(response.data);
  },

  approveRequest: async (id, approved_by) => {
    const response = await axiosClient.post(`/leave-requests/${id}/approve`, {
      reviewer_id: approved_by
    });
    return normalizeLeaveRequest(response.data);
  },

  rejectRequest: async (id, approved_by) => {
    const response = await axiosClient.post(`/leave-requests/${id}/reject`, {
      reviewer_id: approved_by
    });
    return normalizeLeaveRequest(response.data);
  },

  cancel: async (id) => {
    const response = await axiosClient.post(`/leave-requests/${id}/cancel`);
    return normalizeLeaveRequest(response.data);
  },

  runAccrual: async (year) => {
    const response = await axiosClient.post('/leave/accrual/run', { year });
    return response.data;
  }
};
