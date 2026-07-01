import axiosClient from './axiosClient';

// Quản lý máy chấm công của công ty (đa-tenant). axiosClient tự bóc {status,message,data}.
export const attendanceDeviceService = {
  getAll: async () => {
    const res = await axiosClient.get('/attendance-devices');
    return Array.isArray(res.data) ? res.data : (res.data?.items || res.data || []);
  },

  create: async (payload) => {
    const res = await axiosClient.post('/attendance-devices', payload);
    return res.data;
  },

  update: async (id, payload) => {
    const res = await axiosClient.patch(`/attendance-devices/${id}`, payload);
    return res.data;
  },

  remove: async (id) => {
    const res = await axiosClient.delete(`/attendance-devices/${id}`);
    return res.data;
  },

  rotateToken: async (id) => {
    const res = await axiosClient.post(`/attendance-devices/${id}/rotate-token`);
    return res.data;
  }
};

export default attendanceDeviceService;
