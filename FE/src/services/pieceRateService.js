import axiosClient from './axiosClient';

// Công khoán theo sản phẩm (piece-rate). axiosClient bóc {status,message,data}.
export const pieceRateService = {
  getAll: async (params = {}) => {
    const res = await axiosClient.get('/piece-rate-entries', { params });
    return Array.isArray(res.data) ? res.data : (res.data?.items || res.data || []);
  },
  create: async (payload) => {
    const res = await axiosClient.post('/piece-rate-entries', payload);
    return res.data;
  },
  remove: async (id) => {
    const res = await axiosClient.delete(`/piece-rate-entries/${id}`);
    return res.data;
  }
};

export default pieceRateService;
