export const mapJobTitle = (position) => {
  if (!position) return position;

  const name = position.position_name || position.name || position.job_title_name || position.title || '';
  const code = position.position_code || position.code || '';

  return {
    ...position,
    name,
    code,
    job_title_name: name,
    title: name,
  };
};

export const asJobTitleArray = (value) => {
  const rows = Array.isArray(value)
    ? value
    : (value?.items || value?.data || []);

  return Array.isArray(rows) ? rows.map(mapJobTitle) : [];
};
