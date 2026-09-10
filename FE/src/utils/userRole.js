const SYSTEM_ROLE_LABELS = {
  ADMIN: 'Quản trị viên',
  TENANT_ADMIN: 'Quản trị viên tổ chức',
  HR: 'Nhân sự',
  MANAGER: 'Trưởng phòng',
  DEPT_HEAD: 'Trưởng phòng',
  ACCOUNTANT: 'Kế toán',
  EMPLOYEE: 'Nhân viên',
};

const LEGACY_ROLE_NAMES = {
  ADMIN: ['admin', 'administrator', 'quản trị'],
  TENANT_ADMIN: ['tenant admin', 'tenant administrator'],
  HR: ['hr', 'human resources'],
  MANAGER: ['manager', 'quản lý'],
  DEPT_HEAD: ['department head', 'dept head'],
  ACCOUNTANT: ['accountant'],
  EMPLOYEE: ['employee', 'staff'],
};

const ROLE_PRIORITY = {
  ADMIN: 10,
  TENANT_ADMIN: 20,
  HR: 30,
  ACCOUNTANT: 40,
  MANAGER: 50,
  DEPT_HEAD: 50,
  EMPLOYEE: 1000,
};

const roleCode = (role) => String(role?.role_code || role?.code || '').trim().toUpperCase();

export const roleDisplayName = (role) => {
  const code = roleCode(role);
  const storedName = String(role?.role_name || role?.name || '').trim();
  const legacyNames = LEGACY_ROLE_NAMES[code] || [];

  if (SYSTEM_ROLE_LABELS[code] && (!storedName || legacyNames.includes(storedName.toLowerCase()))) {
    return SYSTEM_ROLE_LABELS[code];
  }

  return storedName || SYSTEM_ROLE_LABELS[code] || code;
};

export const userRoleLabels = (user) => {
  const roles = Array.isArray(user?.roles) ? user.roles : [];
  const businessRoles = roles.filter((role) => roleCode(role) !== 'EMPLOYEE');
  const visibleRoles = businessRoles.length > 0 ? businessRoles : roles;

  return visibleRoles
    .map((role, index) => ({
      label: roleDisplayName(role),
      priority: ROLE_PRIORITY[roleCode(role)] ?? 500,
      index,
    }))
    .filter((item) => item.label)
    .sort((left, right) => left.priority - right.priority || left.index - right.index)
    .map((item) => item.label)
    .filter((label, index, labels) => labels.indexOf(label) === index);
};

export const primaryUserRoleLabel = (user) => {
  const labels = userRoleLabels(user);
  if (labels.length > 0) return labels[0];
  if (user?.is_super_admin === true || user?.is_super_admin === 1 || user?.is_super_admin === 't') {
    return 'Quản trị viên hệ thống';
  }
  return 'Nhân viên';
};
