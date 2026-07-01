import axiosClient from './axiosClient';

// Service for the audit trail ("Nhật ký hệ thống"). Read-only.
// GET /audit-logs (tenant-scoped) supports filters: table_name, record_id,
// actor_employee_id, action, page, per_page. Each row carries a decoded
// `changes` object { before, after } (changed keys only for updates).
//
// The list response is the {items,pagination} envelope; axiosClient unwraps it
// to a plain array on response.data and sets response.pagination. We return both
// so the view can paginate.
export const auditLogService = {
  getAll: async (params = {}) => {
    const response = await axiosClient.get('/audit-logs', {
      params: { per_page: 25, ...params }
    });
    return {
      items: Array.isArray(response.data) ? response.data : [],
      pagination: response.pagination || null
    };
  }
};

export default auditLogService;
