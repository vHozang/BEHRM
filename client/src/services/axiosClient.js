
import axios from 'axios';

// API base URL: production (Vercel) đặt biến VITE_API_BASE_URL trỏ về domain VPS
// Laravel (vd https://api.tencongty.com/api/v1). Local mặc định localhost.
const axiosClient = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || 'http://localhost/api/v1',
  timeout: 30000,
  headers: {
    'Content-Type': 'application/json',
  },
});

// Request interceptor - Attach auth token
axiosClient.interceptors.request.use(
  (config) => {
    const token = localStorage.getItem('auth_token');
    if (token) {
      config.headers.Authorization = `Bearer ${token}`;
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
    if (response.data && (response.data.status === 200 || response.data.status === 201) && response.data.data !== undefined) {
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
              if (!excludeKeys.includes(key)) {
                item[key] = parsedMeta[key];
              }
            }
          }
        }
        
        return item;
      };
      
      // Case 1: GenericResourceController format (has items array and pagination object)
      if (payload && typeof payload === 'object' && Array.isArray(payload.items)) {
        response.data = payload.items.map(normalizeItem);
        response.pagination = payload.pagination || null;
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
    // Handle 401 Unauthorized
    if (error.response?.status === 401) {
      localStorage.removeItem('auth_token');
      window.location.href = '/login';
    }
    
    // Handle other errors
    const errorMessage = error.response?.data?.message || error.message || 'An error occurred';
    console.error('API Error:', errorMessage);
    
    return Promise.reject(error);
  }
);

export default axiosClient;
