import axiosClient from './axiosClient';

// Tenant business-rule settings (Cấu hình nghiệp vụ).
// /settings/catalog returns groups of editable params with current effective
// values; /settings/save upserts per-tenant overrides. axiosClient unwraps the
// {status,message,data} envelope so response.data is the data object.
export const settingsService = {
  getCatalog: async () => {
    const response = await axiosClient.get('/settings/catalog');
    return response.data?.groups || [];
  },

  save: async (items) => {
    const response = await axiosClient.post('/settings/save', { items });
    return response.data;
  },

  getAutoRecruitHealth: async () => {
    const response = await axiosClient.get('/settings/integrations/autorecruit/health');
    return response.data;
  },

  getNotificationTemplates: async () => {
    const response = await axiosClient.get('/settings/notifications');
    return Array.isArray(response.data) ? response.data : (response.data?.items || []);
  },

  saveNotificationTemplates: async (items, deletedIds = []) => {
    const response = await axiosClient.put('/settings/notifications', {
      items,
      deleted_ids: deletedIds
    });
    return Array.isArray(response.data) ? response.data : (response.data?.items || []);
  },

  // Flat { key: value } map of all effective settings — for components that need
  // a few values (e.g. dependent deduction, contract thresholds). Returns {} on error.
  getEffectiveMap: async () => {
    try {
      const groups = await settingsService.getCatalog();
      const map = {};
      groups.forEach((g) => (g.items || []).forEach((it) => { map[it.key] = it.value; }));
      return map;
    } catch {
      return {};
    }
  }
};

export default settingsService;
