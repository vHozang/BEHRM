import axiosClient from './axiosClient';

/**
 * Phủ ca khi vắng đột xuất (coverage / điều người tăng ca + chuỗi giao ca).
 * - Quản lý: tạo yêu cầu phủ ca, mời người phủ, huỷ.
 * - Nhân viên: xem lời mời của mình, nhận/từ chối (nhận → tạo đơn tăng ca).
 */
export const shiftCoverageService = {
  // ── Quản lý ──
  getRequests: async (params) => {
    const res = await axiosClient.get('/shift-coverage-requests', { params });
    return res?.data || [];
  },
  createRequest: async (data) => {
    const res = await axiosClient.post('/shift-coverage-requests', data);
    return res?.data;
  },
  offer: async (requestId, data) => {
    const res = await axiosClient.post(`/shift-coverage-requests/${requestId}/offer`, data);
    return res?.data;
  },
  cancelRequest: async (requestId) => {
    const res = await axiosClient.post(`/shift-coverage-requests/${requestId}/cancel`);
    return res?.data;
  },

  // ── Nhân viên ──
  getMyOffers: async (employeeId, params = {}) => {
    const res = await axiosClient.get('/shift-coverage-offers', { params: { employee_id: employeeId, ...params } });
    return res?.data || [];
  },
  respond: async (offerId, decision) => {
    const res = await axiosClient.post(`/shift-coverage-offers/${offerId}/respond`, { decision });
    return res?.data;
  },
};
