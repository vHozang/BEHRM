import axiosClient from './axiosClient';

// Service for public holidays ("Ngày nghỉ lễ").
// CRUD goes through the generic /holidays resource; the two VN-specific helpers
// (preview + seed) hit the dedicated HolidayController routes.
//
// axiosClient already unwraps the {status,message,data} envelope:
//   - list responses    -> response.data is a plain array (response.pagination set)
//   - object responses   -> response.data is the unwrapped object
export const holidayService = {
  // GET /holidays -> array of holiday rows
  getAll: async (params = {}) => {
    const response = await axiosClient.get('/holidays', {
      params: { per_page: 100, ...params }
    });
    return Array.isArray(response.data) ? response.data : [];
  },

  // POST /holidays -> created row
  create: async (payload) => {
    const response = await axiosClient.post('/holidays', payload);
    return response.data;
  },

  // PUT /holidays/{id} -> updated row
  update: async (id, payload) => {
    const response = await axiosClient.put(`/holidays/${id}`, payload);
    return response.data;
  },

  // DELETE /holidays/{id}
  remove: async (id) => {
    const response = await axiosClient.delete(`/holidays/${id}`);
    return response.data;
  },

  // GET /holidays/statutory-preview?year= -> { year, holidays: [...] } (no DB writes)
  preview: async (year) => {
    const response = await axiosClient.get('/holidays/statutory-preview', {
      params: { year }
    });
    return response.data;
  },

  // POST /holidays/seed-vn { year } -> { year, created: [...], skipped: [...] } (idempotent)
  seedVn: async (year) => {
    const response = await axiosClient.post('/holidays/seed-vn', { year });
    return response.data;
  }
};

export default holidayService;
