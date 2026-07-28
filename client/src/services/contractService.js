import axiosClient from './axiosClient';

const mapContract = (contract) => {
  if (!contract) return contract;
  
  let meta = {};
  try {
    meta = typeof contract.meta === 'string' ? JSON.parse(contract.meta) : (contract.meta || {});
  } catch (e) {}

  const startDate = contract.start_date || meta.effective_date || meta.start_date || '';
  const endDate = contract.end_date || meta.expiry_date || meta.end_date || '';
  const departmentName =
    contract.department?.department_name ||
    contract.department_name ||
    contract.employee?.department?.department_name ||
    '';

  return {
    ...contract,
    start_date: startDate,
    end_date: endDate,
    contract_code: contract.contract_number || contract.contract_code || '',
    employee_name: contract.employee?.full_name || contract.employee_name || '',
    employee_code: contract.employee?.employee_code || contract.employee_code || '',
    department_name: departmentName,
    contract_type_name: contract.contract_type?.contract_type_name || contract.contract_type?.name || contract.contract_type_name || '',
    basic_salary: meta.basic_salary || contract.basic_salary || 0,
    allowances: meta.allowances || contract.allowances || 0,
    sign_date: meta.sign_date || contract.sign_date || contract.created_at || '',
    notes: meta.notes || contract.notes || '',
    signature: meta.signature || contract.signature || '',
  };
};

export const contractService = {
  getPage: async (params = {}) => {
    const response = await axiosClient.get('/contracts', { params: { per_page: 50, ...params } });
    return {
      items: Array.isArray(response.data) ? response.data.map(mapContract) : [],
      pagination: response.pagination || null
    };
  },

  getLookup: async () => {
    const response = await axiosClient.get('/contracts/lookup');
    return Array.isArray(response.data) ? response.data : [];
  },

  getAll: async (params = {}) => {
    const response = await axiosClient.get('/contracts', { params: { ...params, per_page: 2000 } });
    const data = response.data || [];
    return Array.isArray(data) ? data.map(mapContract) : data;
  },

  getById: async (id) => {
    const response = await axiosClient.get(`/contracts/${id}`);
    return mapContract(response.data);
  },

  create: async (data) => {
    const payload = {
      contract_number: data.contract_code,
      employee_id: data.employee_id,
      contract_type_id: data.contract_type_id,
      start_date: data.start_date,
      end_date: data.end_date,
      meta: {
        basic_salary: data.basic_salary,
        allowances: data.allowances,
        notes: data.notes,
        signature: data.signature,
        sign_date: data.sign_date
      }
    };
    const response = await axiosClient.post('/contracts', payload);
    return response.data;
  },

  update: async (id, data) => {
    const payload = {
      contract_number: data.contract_code,
      employee_id: data.employee_id,
      contract_type_id: data.contract_type_id,
      start_date: data.start_date,
      end_date: data.end_date,
      meta: {
        basic_salary: data.basic_salary,
        allowances: data.allowances,
        notes: data.notes,
        signature: data.signature,
        sign_date: data.sign_date
      }
    };
    const response = await axiosClient.patch(`/contracts/${id}`, payload);
    return response.data;
  },

  activate: async (id) => {
    const response = await axiosClient.post(`/contracts/${id}/activate`);
    return response.data;
  },

  terminate: async (id, payload) => {
    const response = await axiosClient.post(`/contracts/${id}/terminate`, payload || {});
    return response.data;
  },

  getTypes: async () => {
    const response = await axiosClient.get('/contract-types');
    const data = response.data || [];
    return Array.isArray(data)
      ? data.map(t => ({ ...t, name: t.contract_type_name || t.name || '' }))
      : data;
  },

  getChangeLogs: async (contractId) => {
    const response = await axiosClient.get('/contract-change-logs', {
      params: contractId ? { contract_id: contractId } : {}
    });
    return response.data;
  },

  // ── Document & signing ──────────────────────────────────
  // Templates (mẫu hợp đồng). Backend dùng template_name; map sang `name`.
  getTemplates: async () => {
    const response = await axiosClient.get('/contract-templates');
    const data = Array.isArray(response.data) ? response.data : [];
    return data.map(t => ({ ...t, name: t.template_name || t.name || '' }));
  },
  getPlaceholders: async () => {
    const response = await axiosClient.get('/contract-templates/placeholders');
    return Array.isArray(response.data) ? response.data : [];
  },
  createTemplate: async (payload) => {
    const response = await axiosClient.post('/contract-templates', payload);
    return response.data;
  },
  updateTemplate: async (id, payload) => {
    const response = await axiosClient.patch(`/contract-templates/${id}`, payload);
    return response.data;
  },
  deleteTemplate: async (id) => {
    const response = await axiosClient.delete(`/contract-templates/${id}`);
    return response.data;
  },

  // Render filled HTML for a contract (admin or owner).
  render: async (id, templateId = null) => {
    const response = await axiosClient.get(`/contracts/${id}/render`, {
      params: templateId ? { template_id: templateId } : {}
    });
    return response.data; // { html, template_id, fields, sign_status, signature }
  },
  sendForSign: async (id, templateId = null) => {
    const response = await axiosClient.post(`/contracts/${id}/send-sign`, templateId ? { template_id: templateId } : {});
    return response.data;
  },
  requestOtp: async (id) => {
    const response = await axiosClient.post(`/contracts/${id}/request-otp`);
    return response.data; // { sent, expires_in, dev_otp }
  },
  sign: async (id, payload) => {
    const response = await axiosClient.post(`/contracts/${id}/sign`, payload);
    return response.data;
  }
};

// Open rendered contract HTML in a new window and trigger print (→ Save as PDF).
export const printContractHtml = (html, title = 'Hợp đồng lao động') => {
  const w = window.open('', '_blank');
  if (!w) return;
  w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>${title}</title>
    <style>body{margin:32px;font-family:'Times New Roman',serif;color:#111}@media print{body{margin:0}}
    .docx-wrapper{background:#fff!important;padding:0!important;box-shadow:none!important;display:block!important}
    .docx-wrapper>section.docx{box-shadow:none!important;margin:0 auto!important;width:auto!important;min-height:auto!important}</style>
    </head><body>${html}<script>window.onload=function(){setTimeout(function(){window.print()},200)}<\/script></body></html>`);
  w.document.close();
};
