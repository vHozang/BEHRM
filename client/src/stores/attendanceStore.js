import { defineStore } from 'pinia';
import { ref } from 'vue';
import { attendanceService } from '../services/attendanceService';

export const useAttendanceStore = defineStore('attendance', () => {
  const records = ref([]);
  const trends = ref([]);
  const todaySummary = ref(null);
  const loading = ref(false);
  const error = ref(null);

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
    checkOut
  };
});
