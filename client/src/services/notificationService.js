import axiosClient from './axiosClient';

/**
 * Thông báo cá nhân bền (chuông). receiver = NV đang đăng nhập (backend tự khoá).
 * GET trả mảng items (axiosClient bóc {items,unread_count} → items); unread tự
 * suy từ read_at để khỏi phụ thuộc field bị bóc mất.
 */
export const notificationService = {
  getAll: async () => {
    const res = await axiosClient.get('/notifications');
    if (Array.isArray(res?.data)) return res.data;
    return res?.data?.items || [];
  },
  markRead: async (id) => (await axiosClient.post(`/notifications/${id}/read`))?.data,
  markAllRead: async () => (await axiosClient.post('/notifications/read-all'))?.data,
};
