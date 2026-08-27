export const mapJobTitle = (position) => {
  if (!position) return position;

  const name = position.position_name || position.name || position.job_title_name || position.title || '';
  const code = position.position_code || position.code || '';
  const status = position.status ?? position.is_active;

  return {
    ...position,
    name,
    code,
    job_title_name: name,
    title: name,
    is_active: ![false, 0, '0', 'f', 'false', 'INACTIVE'].includes(status),
  };
};

export const asJobTitleArray = (value) => {
  const rows = Array.isArray(value)
    ? value
    : (value?.items || value?.data || []);

  return Array.isArray(rows) ? rows.map(mapJobTitle) : [];
};

export const toJobTitlePayload = (position = {}) => ({
  position_code: String(position.position_code ?? position.code ?? '').trim().toUpperCase(),
  position_name: String(position.position_name ?? position.name ?? position.job_title_name ?? '').trim(),
  job_family_id: position.job_family_id || null,
  job_level: position.job_level || null,
  is_active: position.is_active ?? true,
});
