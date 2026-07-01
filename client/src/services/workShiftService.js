import axiosClient from './axiosClient';

const mapIncoming = (item) => {
  if (!item || typeof item !== 'object') return item;
  return {
    ...item,
    code: item.shift_code || item.code,
    name: item.shift_name || item.name,
  };
};

const mapOutgoing = (data) => {
  if (!data || typeof data !== 'object') return data;
  const mapped = { ...data };
  if (mapped.code !== undefined) {
    mapped.shift_code = mapped.code;
    delete mapped.code;
  }
  if (mapped.name !== undefined) {
    mapped.shift_name = mapped.name;
    delete mapped.name;
  }
  return mapped;
};

export const workShiftService = {
  // Get all work shifts
  getAll: async () => {
    const response = await axiosClient.get('/shift-types');
    if (Array.isArray(response.data)) {
      return response.data.map(mapIncoming);
    }
    return mapIncoming(response.data);
  },

  // Create work shift
  create: async (data) => {
    const mappedData = mapOutgoing(data);
    const response = await axiosClient.post('/shift-types', mappedData);
    return mapIncoming(response.data);
  },

  // Update work shift
  update: async (id, data) => {
    const mappedData = mapOutgoing(data);
    const response = await axiosClient.patch(`/shift-types/${id}`, mappedData);
    return mapIncoming(response.data);
  },

  // Delete work shift
  delete: async (id) => {
    const response = await axiosClient.delete(`/shift-types/${id}`);
    return response.data;
  }
};
