import { defineStore } from 'pinia';
import { ref } from 'vue';
import { leaveService } from '../services/leaveService';

export const useLeaveStore = defineStore('leave', () => {
  const requests = ref([]);
  const balances = ref([]);
  const types = ref([]);
  const currentRequest = ref(null);
  const loading = ref(false);
  const error = ref(null);

  const fetchRequests = async (params) => {
    loading.value = true;
    error.value = null;
    try {
      requests.value = await leaveService.getRequests(params);
    } catch (err) {
      error.value = err.message || 'Failed to fetch leave requests';
    } finally {
      loading.value = false;
    }
  };

  const fetchBalances = async (employee_id, year) => {
    loading.value = true;
    error.value = null;
    try {
      balances.value = await leaveService.getBalances(employee_id, year);
    } catch (err) {
      error.value = err.message || 'Failed to fetch leave balances';
    } finally {
      loading.value = false;
    }
  };

  const fetchTypes = async () => {
    loading.value = true;
    error.value = null;
    try {
      types.value = await leaveService.getTypes();
    } catch (err) {
      error.value = err.message || 'Failed to fetch leave types';
    } finally {
      loading.value = false;
    }
  };

  const createRequest = async (data) => {
    loading.value = true;
    error.value = null;
    try {
      const newRequest = await leaveService.createRequest(data);
      requests.value.unshift(newRequest);
      return newRequest;
    } catch (err) {
      error.value = err.message || 'Failed to create leave request';
      throw err;
    } finally {
      loading.value = false;
    }
  };

  const approveRequest = async (id, approved_by) => {
    loading.value = true;
    error.value = null;
    try {
      const result = await leaveService.approveRequest(id, approved_by);
      const index = requests.value.findIndex(r => r.id === id);
      if (index !== -1) {
        requests.value[index].status = 'approved';
      }
      return result;
    } catch (err) {
      error.value = err.message || 'Failed to approve request';
      throw err;
    } finally {
      loading.value = false;
    }
  };

  const rejectRequest = async (id, approved_by, reason) => {
    loading.value = true;
    error.value = null;
    try {
      const result = await leaveService.rejectRequest(id, approved_by, reason);
      const index = requests.value.findIndex(r => r.id === id);
      if (index !== -1) {
        requests.value[index].status = 'rejected';
      }
      return result;
    } catch (err) {
      error.value = err.message || 'Failed to reject request';
      throw err;
    } finally {
      loading.value = false;
    }
  };

  return {
    requests,
    balances,
    types,
    currentRequest,
    loading,
    error,
    fetchRequests,
    fetchBalances,
    fetchTypes,
    createRequest,
    approveRequest,
    rejectRequest
  };
});
