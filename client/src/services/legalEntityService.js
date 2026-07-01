import axiosClient from './axiosClient';

// Service for legal entities ("Chi nhánh / Pháp nhân") — multi-entity org units
// within a tenant. Dedicated /legal-entities controller (tenant-scoped, with
// employees_count / contracts_count and delete guards).
//
// axiosClient unwraps {status,message,data}: list -> array (response.pagination
// holds paging); object responses -> response.data is the object.
export const legalEntityService = {
  // GET /legal-entities?status=&search= -> array
  getAll: async (params = {}) => {
    const response = await axiosClient.get('/legal-entities', {
      params: { per_page: 100, ...params }
    });
    return Array.isArray(response.data) ? response.data : [];
  },

  create: async (payload) => {
    const response = await axiosClient.post('/legal-entities', payload);
    return response.data;
  },

  update: async (id, payload) => {
    const response = await axiosClient.put(`/legal-entities/${id}`, payload);
    return response.data;
  },

  // DELETE /legal-entities/{id} -> 409 with data.violations when in use / last entity.
  remove: async (id) => {
    const response = await axiosClient.delete(`/legal-entities/${id}`);
    return response.data;
  }
};

export default legalEntityService;
