export const normalizeEmployeeCode = (value) => String(value ?? '').trim().toUpperCase();

export const findEmployeeByCode = (employees, code) => {
  const normalized = normalizeEmployeeCode(code);
  if (!normalized) return null;
  return (employees || []).find((employee) => normalizeEmployeeCode(employee?.employee_code) === normalized) || null;
};

export const filterEmployeesByDepartment = (employees, departmentId) => {
  if (departmentId === '' || departmentId === null || departmentId === undefined) return employees || [];
  return (employees || []).filter((employee) => String(employee?.department_id || '') === String(departmentId));
};
