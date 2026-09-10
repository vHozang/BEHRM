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
  const timesheetPageCache = new Map();
  const timesheetOverviewCache = new Map();
  const timesheetRequests = new Map();
  const timesheetCacheTtlMs = 60_000;
  const maxTimesheetCacheEntries = 40;
  let timesheetCacheGeneration = 0;

  const cacheKey = (params = {}) => {
    const user = authService.getUser() || {};
    const access = authService.getAccess();
    const scopedParams = {
      tenant_id: user.tenant_id || '',
      legal_entity_id: params.legal_entity_id || user.legal_entity_id || '',
      viewer_employee_id: user.employee_id || user.id || '',
      access_scope: access.full ? 'full' : [...access.modules].sort().join(','),
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
  const getCachedTimesheetPage = (params) => {
    const key = cacheKey(params);
    const cached = timesheetPageCache.get(key);
    if (!cached) return null;

    // Touch the entry so the bounded Map behaves like a small LRU cache.
    timesheetPageCache.delete(key);
    timesheetPageCache.set(key, cached);
    return {
      ...cached,
      fresh: Date.now() - cached.cachedAt <= timesheetCacheTtlMs,
    };
  };
  const setCachedTimesheetPage = (params, value) => {
    const key = cacheKey(params);
    timesheetPageCache.delete(key);
    timesheetPageCache.set(key, { value, cachedAt: Date.now() });
    while (timesheetPageCache.size > maxTimesheetCacheEntries) {
      timesheetPageCache.delete(timesheetPageCache.keys().next().value);
    }
  };
  const getCachedTimesheetOverview = (params) => timesheetOverviewCache.get(cacheKey(params)) || null;
  const setCachedTimesheetOverview = (params, value) => timesheetOverviewCache.set(cacheKey(params), { value, cachedAt: Date.now() });
  const fetchTimesheetOverview = async (params, { force = false } = {}) => {
    const cached = getCachedTimesheetOverview(params);
    if (!force && cached && Date.now() - cached.cachedAt <= timesheetCacheTtlMs) return cached.value;
    const { month, ...query } = params;
    const value = await attendanceService.getTimesheetOverview(month, query);
    setCachedTimesheetOverview(params, value);
    return value;
  };
  const fetchTimesheetPage = (params, { force = false } = {}) => {
    const key = cacheKey(params);
    const cached = getCachedTimesheetPage(params);
    if (!force && cached?.fresh) return Promise.resolve(cached.value);
    if (timesheetRequests.has(key)) return timesheetRequests.get(key);

    const generation = timesheetCacheGeneration;
    const { month, ...query } = params;
    let request;
    request = attendanceService.getTimesheet(month, {
      ...query,
      refresh: force ? 1 : undefined,
    })
      .then((value) => {
        if (generation === timesheetCacheGeneration) {
          setCachedTimesheetPage(params, value);
        }
        return value;
      })
      .finally(() => {
        if (timesheetRequests.get(key) === request) timesheetRequests.delete(key);
      });
    timesheetRequests.set(key, request);
    return request;
  };
  const prefetchTimesheetPage = (params) => fetchTimesheetPage(params).catch(() => null);
  const invalidateTimesheetCache = () => {
    timesheetCacheGeneration += 1;
    timesheetPageCache.clear();
    timesheetOverviewCache.clear();
    timesheetRequests.clear();
  };
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
    invalidateTimesheetCache();
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
    getCachedTimesheetPage,
    fetchTimesheetPage,
    prefetchTimesheetPage,
    fetchTimesheetOverview,
    invalidateTimesheetCache,
    prefetchFirstPage,
    invalidateAttendanceCache,
  };
});
