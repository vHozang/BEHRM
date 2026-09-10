
import axios from 'axios';
import {
  broadcastSessionCleared,
  coordinateRefresh,
  subscribeSessionCleared,
} from './authRefreshCoordinator';

const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || '/api/v1';

// Same-origin is the production default; Vite proxies /api during local development.
const axiosClient = axios.create({
  baseURL: API_BASE_URL,
  timeout: 30000,
  withCredentials: true,
  headers: {
    'Content-Type': 'application/json',
  },
});

const refreshClient = axios.create({
  baseURL: API_BASE_URL,
  timeout: 30000,
  withCredentials: true,
  headers: { 'Content-Type': 'application/json' },
});

const redirectToLogin = () => {
  if (typeof window !== 'undefined' && window.location.pathname !== '/login') {
    window.location.href = '/login';
  }
};

export const clearStoredSession = ({ broadcast = false, redirect = false } = {}) => {
  ['auth_token', 'auth_expires_at', 'user', 'user_email', 'role', 'user_role', 'access']
    .forEach((key) => localStorage.removeItem(key));
  if (broadcast) broadcastSessionCleared();
  if (redirect) redirectToLogin();
};

subscribeSessionCleared(() => clearStoredSession({ redirect: true }));

export const storeAccessToken = (payload = {}) => {
  const token = payload.access_token || payload.token;
  if (!token) return null;
  localStorage.setItem('auth_token', token);
  const expiresAt = payload.expires_at
    || (payload.expires_in ? new Date(Date.now() + Number(payload.expires_in) * 1000).toISOString() : null);
  if (expiresAt) localStorage.setItem('auth_expires_at', expiresAt);
  return token;
};

const tokenNeedsRefresh = () => {
  const expiresAt = Date.parse(localStorage.getItem('auth_expires_at') || '');
  return Number.isFinite(expiresAt) && expiresAt - Date.now() <= 5 * 60 * 1000;
};

const isAuthEndpoint = (url = '') => /\/auth\/(login|refresh|logout|forgot-password|reset-password)/.test(String(url));

export const refreshAccessToken = async () => {
  return coordinateRefresh({
    readToken: () => localStorage.getItem('auth_token'),
    isTokenFresh: () => {
      const token = localStorage.getItem('auth_token');
      return !!token && !tokenNeedsRefresh();
    },
    performRefresh: async () => {
      const legacyToken = localStorage.getItem('auth_token');
      const response = await refreshClient.post('/auth/refresh', null, {
        headers: legacyToken ? { Authorization: `Bearer ${legacyToken}` } : {},
      });
      const payload = response.data?.data ?? response.data ?? {};
      return storeAccessToken(payload);
    },
  });
};

// Request interceptor - Attach auth token
axiosClient.interceptors.request.use(
  async (config) => {
    let token = localStorage.getItem('auth_token');
    if (token && tokenNeedsRefresh() && !isAuthEndpoint(config.url) && config.skipAuthRefresh !== true) {
      try {
        token = await refreshAccessToken();
      } catch {
        // The response interceptor handles an eventual 401 and session cleanup.
      }
    }
    if (token) {
      config.headers.Authorization = `Bearer ${token}`;
    }
    // Upload tệp: phải để axios tự đặt multipart/form-data KÈM boundary. Giữ
    // Content-Type mặc định application/json sẽ làm server không đọc được tệp (422).
    if (typeof FormData !== 'undefined' && config.data instanceof FormData) {
      delete config.headers['Content-Type'];
    }
    return config;
  },
  (error) => {
    return Promise.reject(error);
  }
);

// Response interceptor - Handle errors globally
axiosClient.interceptors.response.use(
  (response) => {
    // Automatically unwrap Doan2 response format to make it compatible with Doan1 Vue components
    if (response.data
      && Number(response.data.status) >= 200
      && Number(response.data.status) < 300
      && response.data.data !== undefined) {
      const payload = response.data.data;
      // Giữ lại message gốc để FE hiển thị (vd "Đã duyệt cấp X/Y").
      response.apiMessage = response.data.message;
      
      const normalizeItem = (item) => {
        if (!item || typeof item !== 'object') return item;
        
        // Map nested department object to flat strings (preventing [object Object] in templates)
        if (item.department && typeof item.department === 'object') {
          item.department_id = item.department.id || item.department_id;
          item.department_name = item.department.department_name || item.department.name || '';
          item.department = item.department_name;
        }
        
        // Map nested position object to job_title fields
        if (item.position && typeof item.position === 'object') {
          item.job_title_id = item.position.id || item.job_title_id;
          item.job_title_name = item.position.position_name || item.position.name || '';
          item.job_title = item.job_title_name;
        }
        
        if (item.status && !item.employment_status) {
          item.employment_status = typeof item.status === 'string' ? item.status.toLowerCase() : String(item.status);
        }
        
        if (item.company_email && !item.work_email) {
          item.work_email = item.company_email;
        }

        if (item.work_date) {
          item.attendance_date = item.work_date;
          item.record_date = item.work_date;
        }

        // Parse and merge meta if exists
        if (item.meta) {
          let parsedMeta = item.meta;
          if (typeof item.meta === 'string') {
            try {
              parsedMeta = JSON.parse(item.meta);
            } catch (e) {
              console.error('Failed to parse item.meta:', e);
            }
          }
          if (parsedMeta && typeof parsedMeta === 'object' && !Array.isArray(parsedMeta)) {
            const excludeKeys = ['id', 'created_at', 'updated_at', 'status'];
            for (const key of Object.keys(parsedMeta)) {
              if (!excludeKeys.includes(key) && !(key in item)) {
                item[key] = parsedMeta[key];
              }
            }
          }
        }
        
        return item;
      };
      
      // Case 1: GenericResourceController format (has items array and pagination object)
      if (payload && typeof payload === 'object' && Array.isArray(payload.items)) {
        // Keep the complete envelope for cursor-based APIs. Existing callers
        // still receive the normalized plain array on response.data.
        response.pageData = {
          ...payload,
          items: payload.items.map(normalizeItem),
        };
        response.data = payload.items.map(normalizeItem);
        response.pagination = payload.pagination || null;
        response.summary = payload.summary || null;
      }
      // Case 2: Standard Laravel Paginator format (has current_page and data array)
      else if (payload && typeof payload === 'object' && payload.current_page !== undefined && Array.isArray(payload.data)) {
        response.data = payload.data.map(normalizeItem);
        response.pagination = payload;
      } 
      // Case 3: Plain array or object (non-paginated)
      else {
        if (Array.isArray(payload)) {
          response.data = payload.map(normalizeItem);
        } else {
          response.data = normalizeItem(payload);
        }
      }
    }
    return response;
  },
  (error) => {
    const original = error.config || {};
    if (error.response?.status === 401 && !original._retry && !isAuthEndpoint(original.url)) {
      original._retry = true;
      return refreshAccessToken()
        .then((token) => {
          if (!token) throw error;
          original.headers = original.headers || {};
          original.headers.Authorization = `Bearer ${token}`;
          return axiosClient(original);
        })
        .catch((refreshError) => {
          if ([401, 403].includes(refreshError.response?.status)) {
            clearStoredSession({ broadcast: true, redirect: true });
          }
          return Promise.reject(refreshError);
        });
    }
    
    // Handle other errors
    const errorMessage = error.response?.data?.message || error.message || 'An error occurred';
    console.error('API Error:', errorMessage);
    
    return Promise.reject(error);
  }
);

export default axiosClient;
