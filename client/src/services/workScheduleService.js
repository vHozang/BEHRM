import axiosClient from './axiosClient';

const mapIncoming = (item) => {
  if (!item || typeof item !== 'object') return item;
  return {
    ...item,
    work_date: item.effective_date || item.work_date,
    shift_id: item.shift_type_id || item.shift_id,
  };
};

const mapOutgoing = (data) => {
  if (!data || typeof data !== 'object') return data;
  const mapped = { ...data };
  if (mapped.work_date !== undefined) {
    mapped.effective_date = mapped.work_date;
    delete mapped.work_date;
  }
  if (mapped.shift_id !== undefined) {
    mapped.shift_type_id = mapped.shift_id;
    delete mapped.shift_id;
  }
  return mapped;
};

export const workScheduleService = {
  // Get work schedules with filters
  getAll: async (params) => {
    const mappedParams = mapOutgoing(params);
    const response = await axiosClient.get('/shift-assignments', { params: mappedParams });
    if (Array.isArray(response.data)) {
      return response.data.map(mapIncoming);
    }
    return mapIncoming(response.data);
  },

  // Create work schedule
  create: async (data) => {
    const mappedData = mapOutgoing(data);
    const response = await axiosClient.post('/shift-assignments', mappedData);
    return mapIncoming(response.data);
  },

  // Update work schedule
  update: async (id, data) => {
    const mappedData = mapOutgoing(data);
    const response = await axiosClient.patch(`/shift-assignments/${id}`, mappedData);
    return mapIncoming(response.data);
  },

  // Delete work schedule
  delete: async (id) => {
    const response = await axiosClient.delete(`/shift-assignments/${id}`);
    return response.data;
  },

  // Get shift swap requests
  getSwaps: async (params) => {
    const response = await axiosClient.get('/shift-swaps', { params });
    return response.data;
  },

  // Request a shift swap
  requestSwap: async (data) => {
    const response = await axiosClient.post('/shift-swaps', data);
    return response.data;
  },

  // Approve a shift swap request
  approveSwap: async (id) => {
    const response = await axiosClient.post(`/shift-swaps/${id}/approve`);
    return response.data;
  },

  // Sinh lịch ca xoay theo tuần.
  generateRoster: async (payload) => {
    const response = await axiosClient.post('/shift-roster/generate', payload);
    return response.data;
  }
};
