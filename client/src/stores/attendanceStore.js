import { defineStore } from 'pinia';
import { ref } from 'vue';
import { attendanceService } from '../services/attendanceService';
import { authService } from '../services/authService';

export const useAttendanceStore = defineStore('attendance', () => {
  const records = ref([]);
  const trends = ref([]);
  const todaySummary = ref(null);
  const loading = ref(false);
  const error = ref(null);
  const pageCache = new Map();
  const overviewCache = new Map();

  const cacheKey = (params = {}) => {
    const user = authService.getUser() || {};
    const scopedParams = {
      tenant_id: user.tenant_id || '',
      legal_entity_id: params.legal_entity_id || user.legal_entity_id || '',
      ...params,
    };
    return JSON.stringify(Object.keys(scopedParams).sort().reduce((result, key) => {
      if (scopedParams[key] !== undefined && scopedParams[key] !== null && scopedParams[key] !== '') {
        result[key] = scopedParams[key];
      }
      return result;
    }, {}));
  };

  const getCachedPage = (params) => pageCache.get(cacheKey(params)) || null;
  const setCachedPage = (params, value) => pageCache.set(cacheKey(params), { value, cachedAt: Date.now() });
  const getCachedOverview = (params) => overviewCache.get(cacheKey(params)) || null;
  const setCachedOverview = (params, value) => overviewCache.set(cacheKey(params), { value, cachedAt: Date.now() });
  const prefetchFirstPage = async (params = {}) => {
    const pageParams = { ...params };
    delete pageParams.cursor;
    if (getCachedPage(pageParams) && getCachedOverview(pageParams)) return;

    const [page, overview] = await Promise.all([
      attendanceService.getCursorPage(pageParams),
      attendanceService.getOverview(pageParams),
    ]);
    setCachedPage(pageParams, page);
    setCachedOverview(pageParams, overview);
  };
  const invalidateAttendanceCache = () => {
    pageCache.clear();
    overviewCache.clear();
  };

  const fetchRecords = async (params) => {
    loading.value = true;
    error.value = null;
    try {
      records.value = await attendanceService.getRecords(params);
    } catch (err) {
      error.value = err.message || 'Failed to fetch attendance records';
    } finally {
      loading.value = false;
    }
  };

  const fetchTrends = async (params) => {
    loading.value = true;
    error.value = null;
    try {
      trends.value = await attendanceService.getTrends(params);
    } catch (err) {
      error.value = err.message || 'Failed to fetch attendance trends';
    } finally {
      loading.value = false;
    }
  };

  const fetchTodaySummary = async () => {
    loading.value = true;
    error.value = null;
    try {
      todaySummary.value = await attendanceService.getTodaySummary();
    } catch (err) {
      error.value = err.message || 'Failed to fetch today summary';
    } finally {
      loading.value = false;
    }
  };

  const checkIn = async (employee_id) => {
    loading.value = true;
    error.value = null;
    try {
      const result = await attendanceService.checkIn(employee_id);
      await fetchTodaySummary();
      return result;
    } catch (err) {
      error.value = err.message || 'Failed to check in';
      throw err;
    } finally {
      loading.value = false;
    }
  };

  const checkOut = async (employee_id) => {
    loading.value = true;
    error.value = null;
    try {
      const result = await attendanceService.checkOut(employee_id);
      await fetchTodaySummary();
      return result;
    } catch (err) {
      error.value = err.message || 'Failed to check out';
      throw err;
    } finally {
      loading.value = false;
    }
  };

  return {
    records,
    trends,
    todaySummary,
    loading,
    error,
    fetchRecords,
    fetchTrends,
    fetchTodaySummary,
    checkIn,
    checkOut,
    getCachedPage,
    setCachedPage,
    getCachedOverview,
    setCachedOverview,
    prefetchFirstPage,
    invalidateAttendanceCache,
  };
});
