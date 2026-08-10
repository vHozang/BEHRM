import axiosClient from './axiosClient';

const responseData = (response) => response?.data?.data || response?.data || response;

const filenameFromHeader = (header, fallback) => {
  const match = String(header || '').match(/filename\*?=(?:UTF-8''|"?)([^";]+)/i);
  return match ? decodeURIComponent(match[1].replace(/"/g, '').trim()) : fallback;
};

export const shiftRosterService = {
  getCalendar: async ({ department_id, week_start } = {}) => {
    const response = await axiosClient.get('/shift-roster/calendar', {
      params: { department_id, week_start },
    });
    return responseData(response);
  },

  downloadTemplate: async ({ department_id, week_start }) => {
    const response = await axiosClient.get('/shift-roster/template', {
      params: { department_id, week_start },
      responseType: 'blob',
      timeout: 60000,
    });
    return {
      blob: response.data,
      filename: filenameFromHeader(
        response.headers?.['content-disposition'],
        `Mau_Xep_Ca_${week_start}.xlsx`,
      ),
    };
  },

  previewRotation: async (payload) => {
    const response = await axiosClient.post('/shift-roster/rotation/preview', payload);
    return responseData(response);
  },

  applyRotation: async (previewToken, overwriteManual = false) => {
    const response = await axiosClient.post('/shift-roster/rotation/apply', {
      preview_token: previewToken,
      overwrite_manual: overwriteManual,
    });
    return responseData(response);
  },

  previewImport: async (departmentId, file) => {
    const form = new FormData();
    form.append('department_id', String(departmentId));
    form.append('file', file);
    const response = await axiosClient.post('/shift-roster/import/preview', form, {
      timeout: 60000,
    });
    return responseData(response);
  },

  applyImport: async (previewToken, overwriteManual = false) => {
    const response = await axiosClient.post('/shift-roster/import/apply', {
      preview_token: previewToken,
      overwrite_manual: overwriteManual,
    });
    return responseData(response);
  },
};
