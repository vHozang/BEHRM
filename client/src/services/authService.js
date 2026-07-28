
import axiosClient from './axiosClient';

export const authService = {
  // Login
  login: async (email, password) => {
    const response = await axiosClient.post('/auth/login', {
      company_email: email,
      password
    });
    
    // Doan2 trả { status, message, data: { access_token, employee, access } } NHƯNG
    // axiosClient đã BÓC 1 lớp `data` (response.data = payload). Phải đọc cả 2 dạng:
    // dạng đã-bóc (response.data.access) trước, dạng thô (response.data.data.access) sau —
    // nếu chỉ đọc data.data thì với interceptor hiện tại luôn undefined.
    const token = response.data?.data?.access_token || response.data?.access_token || response.data?.token;
    const user = response.data?.data?.employee || response.data?.employee || response.data?.user || response.data?.data?.user;
    // Server không gửi access thì fail closed về portal nhân viên.
    const access = response.data?.data?.access || response.data?.access || { full: false, modules: [] };

    if (user) {
      // Bản ghi login LÀ employee (id = employee_id) nhưng nhiều view đọc
      // user.employee_id để self-scope API (RBAC 403 nếu thiếu) → gắn alias 1
      // lần ở đây thay vì fallback rải rác từng view. Không lưu password_hash.
      user.employee_id = user.employee_id || user.id;
      delete user.password_hash;
    }

    if (token) {
      localStorage.setItem('auth_token', token);
      localStorage.setItem('user', JSON.stringify(user || {}));
      localStorage.setItem('access', JSON.stringify(access));

      if (user) {
        localStorage.setItem('user_email', user.company_email || '');
        // Admin shell if full access or any admin module is granted; else portal.
        const isAdminShell = access.full === true || (Array.isArray(access.modules) && access.modules.length > 0);
        localStorage.setItem('user_role', isAdminShell ? 'admin' : 'employee');
      }
    }

    return response.data;
  },

  // Get current authenticated user
  me: async () => {
    const response = await axiosClient.get('/auth/me');
    return response.data;
  },

  // Refresh the access token
  refresh: async () => {
    const response = await axiosClient.post('/auth/refresh');
    const token = response.data?.data?.access_token || response.data?.access_token || response.data?.token;
    if (token) {
      localStorage.setItem('auth_token', token);
    }
    return response.data;
  },

  // Change password for logged-in user
  changePassword: async (newPassword) => {
    const response = await axiosClient.post('/auth/change-password', {
      password: newPassword
    });
    return response.data;
  },

  // Logout
  logout: () => {
    localStorage.removeItem('auth_token');
    localStorage.removeItem('user');
    localStorage.removeItem('user_email');
    localStorage.removeItem('role');
    localStorage.removeItem('user_role');
    localStorage.removeItem('access');
    window.location.href = '/login';
  },

  // Effective module access ({ full, modules, enabled }) stored at login.
  getAccess: () => {
    try {
      const a = JSON.parse(localStorage.getItem('access') || '{}');
      return {
        full: a.full === true,
        modules: Array.isArray(a.modules) ? a.modules : [],
        enabled: Array.isArray(a.enabled) ? a.enabled : null
      };
    } catch {
      return { full: false, modules: [], enabled: null };
    }
  },

  // Whether the current user may access a given module (sidebar area).
  // Two gates: the role must grant it AND the company must have it enabled.
  // 'settings' is always reachable for admins so they can re-enable others.
  canAccessModule: (module) => {
    const a = authService.getAccess();
    if (!module) return true;
    const roleAllows = a.full || a.modules.includes(module);
    if (!roleAllows) return false;
    if (module === 'settings') return true;
    if (a.enabled === null) return true; // enablement unknown → don't restrict
    return a.enabled.includes(module);
  },

  // Health check (GET)
  healthCheck: async () => {
    const response = await axiosClient.get('/health');
    return response.data;
  },

  // Health check (POST)
  healthCheckPost: async () => {
    const response = await axiosClient.post('/health');
    return response.data;
  },

  // Check if user is authenticated
  isAuthenticated: () => {
    return !!localStorage.getItem('auth_token');
  },

  // Get current token
  getToken: () => {
    return localStorage.getItem('auth_token');
  },

  // Get current user role
  getUserRole: () => {
    return localStorage.getItem('user_role') || 'employee';
  },

  // Check if current user is admin
  isAdmin: () => {
    const role = localStorage.getItem('user_role');
    return role === 'admin';
  },

  // Check if current user is employee (non-admin)
  isEmployee: () => {
    const role = localStorage.getItem('user_role');
    return role === 'employee';
  },

  // Check if current user is a platform super-admin (cross-tenant operator).
  // Backend marks this on the employee row (is_super_admin) and also enforces
  // it via the platform.admin middleware (403); this is only for UI gating.
  isSuperAdmin: () => {
    try {
      const user = JSON.parse(localStorage.getItem('user') || '{}');
      return user?.is_super_admin === true || user?.is_super_admin === 1 || user?.is_super_admin === 't';
    } catch {
      return false;
    }
  },

  // Get current user email
  getUserEmail: () => {
    return localStorage.getItem('user_email') || '';
  },

  // Get current user data
  getUser: () => {
    try {
      const raw = localStorage.getItem('user');
      const user = raw ? JSON.parse(raw) : null;
      if (user && !user.employee_id && user.id) user.employee_id = user.id; // session cũ chưa có alias
      return user;
    } catch {
      return null;
    }
  }
};
