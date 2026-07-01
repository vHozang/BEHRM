import { defineStore } from 'pinia';
import { ref } from 'vue';
import { departmentService } from '../services/departmentService';

export const useDepartmentStore = defineStore('department', () => {
  const departments = ref([]);
  const departmentTree = ref([]);
  const currentDepartment = ref(null);
  const loading = ref(false);
  const error = ref(null);

  const fetchDepartments = async () => {
    loading.value = true;
    error.value = null;
    try {
      departments.value = await departmentService.getAll();
    } catch (err) {
      error.value = err.message || 'Failed to fetch departments';
    } finally {
      loading.value = false;
    }
  };

  const fetchDepartmentTree = async () => {
    loading.value = true;
    error.value = null;
    try {
      departmentTree.value = await departmentService.getTree();
    } catch (err) {
      error.value = err.message || 'Failed to fetch department tree';
    } finally {
      loading.value = false;
    }
  };

  const fetchDepartmentById = async (id) => {
    loading.value = true;
    error.value = null;
    try {
      currentDepartment.value = await departmentService.getById(id);
    } catch (err) {
      error.value = err.message || 'Failed to fetch department';
    } finally {
      loading.value = false;
    }
  };

  const createDepartment = async (data) => {
    loading.value = true;
    error.value = null;
    try {
      const newDept = await departmentService.create(data);
      departments.value.push(newDept);
      return newDept;
    } catch (err) {
      error.value = err.message || 'Failed to create department';
      throw err;
    } finally {
      loading.value = false;
    }
  };

  const updateDepartment = async (id, data) => {
    loading.value = true;
    error.value = null;
    try {
      const updated = await departmentService.update(id, data);
      const index = departments.value.findIndex(d => d.id === id);
      if (index !== -1) {
        departments.value[index] = updated;
      }
      return updated;
    } catch (err) {
      error.value = err.message || 'Failed to update department';
      throw err;
    } finally {
      loading.value = false;
    }
  };

  const deleteDepartment = async (id) => {
    loading.value = true;
    error.value = null;
    try {
      await departmentService.delete(id);
      departments.value = departments.value.filter(d => d.id !== id);
    } catch (err) {
      error.value = err.message || 'Failed to delete department';
      throw err;
    } finally {
      loading.value = false;
    }
  };

  return {
    departments,
    departmentTree,
    currentDepartment,
    loading,
    error,
    fetchDepartments,
    fetchDepartmentTree,
    fetchDepartmentById,
    createDepartment,
    updateDepartment,
    deleteDepartment
  };
});
