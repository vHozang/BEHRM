import axiosClient from './axiosClient';

// Service for onboarding / offboarding checklists ("Hội nhập / Nghỉ việc").
// Dedicated /onboarding-checklists controller. A checklist owns a flat task
// list; ticking every task auto-completes the checklist (backend recompute).
//
// axiosClient unwraps {status,message,data}: list -> array (response.pagination),
// object responses -> response.data is the object.
export const onboardingService = {
  // GET /onboarding-checklists?type=&status=&employee_id= -> { items, pagination }
  getAll: async (params = {}) => {
    const response = await axiosClient.get('/onboarding-checklists', {
      params: { per_page: 50, ...params }
    });
    return {
      items: Array.isArray(response.data) ? response.data : [],
      pagination: response.pagination || null
    };
  },

  // GET /onboarding-checklists/{id} -> checklist with tasks
  get: async (id) => {
    const response = await axiosClient.get(`/onboarding-checklists/${id}`);
    return response.data;
  },

  // POST /onboarding-checklists { employee_id, type, start_date?, notes? }
  // -> checklist pre-filled with the standard tasks
  create: async (payload) => {
    const response = await axiosClient.post('/onboarding-checklists', payload);
    return response.data;
  },

  // PATCH /onboarding-checklists/{id}/status { status }
  updateStatus: async (id, status) => {
    const response = await axiosClient.patch(`/onboarding-checklists/${id}/status`, { status });
    return response.data;
  },

  // DELETE /onboarding-checklists/{id} (cascades tasks)
  remove: async (id) => {
    const response = await axiosClient.delete(`/onboarding-checklists/${id}`);
    return response.data;
  },

  // POST /onboarding-checklists/{id}/tasks { title, description?, due_date? }
  addTask: async (id, payload) => {
    const response = await axiosClient.post(`/onboarding-checklists/${id}/tasks`, payload);
    return response.data;
  },

  // PATCH /onboarding-checklists/{id}/tasks/{taskId} { is_done?/title?/... }
  updateTask: async (id, taskId, payload) => {
    const response = await axiosClient.patch(`/onboarding-checklists/${id}/tasks/${taskId}`, payload);
    return response.data;
  },

  // DELETE /onboarding-checklists/{id}/tasks/{taskId}
  removeTask: async (id, taskId) => {
    const response = await axiosClient.delete(`/onboarding-checklists/${id}/tasks/${taskId}`);
    return response.data;
  }
};

export default onboardingService;
