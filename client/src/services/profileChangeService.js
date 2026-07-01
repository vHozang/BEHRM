import axiosClient from './axiosClient';

/**
 * Đơn đề nghị thay đổi thông tin cá nhân (profile change requests).
 * Nhân viên tạo đơn đổi các trường nhạy cảm (CCCD/MST/ngân hàng/BHXH...),
 * HR/quản trị duyệt thì hệ thống mới ghi vào hồ sơ.
 */
export const profileChangeService = {
  // Danh mục các trường được phép đề nghị đổi (tenant-configurable).
  getFields: async () => {
    const response = await axiosClient.get('/profile-change-requests/fields');
    return Array.isArray(response.data) ? response.data : [];
  },

  // Danh sách đơn. Non-manager tự động chỉ thấy đơn của mình.
  getAll: async (params = {}) => {
    const response = await axiosClient.get('/profile-change-requests', { params });
    return Array.isArray(response.data) ? response.data : (response.data?.items || []);
  },

  get: async (id) => {
    const response = await axiosClient.get(`/profile-change-requests/${id}`);
    return response.data;
  },

  // payload: { employee_id, changes: {field_key: new_value, ...}, reason }
  create: async (payload) => {
    const response = await axiosClient.post('/profile-change-requests', payload);
    return response.data;
  },

  approve: async (id, comment = null) => {
    const response = await axiosClient.post(`/profile-change-requests/${id}/approve`, comment ? { comment } : {});
    return response.data;
  },

  reject: async (id, comment = null) => {
    const response = await axiosClient.post(`/profile-change-requests/${id}/reject`, comment ? { decision_comment: comment } : {});
    return response.data;
  },

  // employeeId is sent so the self-service middleware/controller can verify ownership.
  cancel: async (id, employeeId = null) => {
    const response = await axiosClient.post(`/profile-change-requests/${id}/cancel`, employeeId ? { employee_id: employeeId } : {});
    return response.data;
  },
};
