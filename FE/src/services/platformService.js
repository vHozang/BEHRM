import axiosClient from './axiosClient';

// Service for platform (cross-tenant) super-admin operations.
// Routes are under /platform and guarded by the platform.admin middleware (403
// for non super-admins). The list endpoint puts pagination in a top-level `meta`
// (not data.pagination) and returns the array directly in `data`, which
// axiosClient unwraps to a plain array on response.data.
export const platformService = {
  // GET /platform/tenants -> array of tenants (with legal_entities_count, employees_count)
  getTenants: async (params = {}) => {
    const response = await axiosClient.get('/platform/tenants', {
      params: { per_page: 100, ...params }
    });
    return Array.isArray(response.data) ? response.data : [];
  },

  // GET /platform/tenants/{id} -> { tenant, legal_entities, counts }
  getTenant: async (id) => {
    const response = await axiosClient.get(`/platform/tenants/${id}`);
    return response.data;
  },

  // POST /platform/tenants -> provision a new tenant + first admin.
  // payload: { tenant: { name, code? }, admin: { full_name, company_email, password } }
  provisionTenant: async (payload) => {
    const response = await axiosClient.post('/platform/tenants', payload);
    return response.data;
  }
};

export default platformService;
