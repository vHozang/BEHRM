import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { employeeService } from '../services/employeeService';

export const useEmployeeStore = defineStore('employee', () => {
  const employees = ref([]);
  const currentEmployee = ref(null);
  const loading = ref(false);
  const error = ref(null);

  const activeEmployees = computed(() => 
    employees.value.filter(e => 
      e.employment_status === 'active'
    )
  );

  const fetchEmployees = async (params) => {
    loading.value = true;
    error.value = null;
    try {
      employees.value = await employeeService.getAll(params);
    } catch (err) {
      error.value = err.message || 'Failed to fetch employees';
    } finally {
      loading.value = false;
    }
  };

  const fetchEmployeeById = async (id) => {
    loading.value = true;
    error.value = null;
    try {
      currentEmployee.value = await employeeService.getById(id);
    } catch (err) {
      error.value = err.message || 'Failed to fetch employee';
    } finally {
      loading.value = false;
    }
  };

  const createEmployee = async (data) => {
    loading.value = true;
    error.value = null;
    try {
      const newEmployee = await employeeService.create(data);
      employees.value.push(newEmployee);
      return newEmployee;
    } catch (err) {
      error.value = err.message || 'Failed to create employee';
      throw err;
    } finally {
      loading.value = false;
    }
  };

  const updateEmployee = async (id, data) => {
    loading.value = true;
    error.value = null;
    try {
      const updated = await employeeService.update(id, data);
      const index = employees.value.findIndex(e => e.id === id);
      if (index !== -1) {
        employees.value[index] = updated;
      }
      if (currentEmployee.value?.id === id) {
        currentEmployee.value = updated;
      }
      return updated;
    } catch (err) {
      error.value = err.message || 'Failed to update employee';
      throw err;
    } finally {
      loading.value = false;
    }
  };

  const deleteEmployee = async (id) => {
    loading.value = true;
    error.value = null;
    try {
      await employeeService.delete(id);
      employees.value = employees.value.filter(e => e.id !== id);
    } catch (err) {
      error.value = err.message || 'Failed to delete employee';
      throw err;
    } finally {
      loading.value = false;
    }
  };

  return {
    employees,
    currentEmployee,
    loading,
    error,
    activeEmployees,
    fetchEmployees,
    fetchEmployeeById,
    createEmployee,
    updateEmployee,
    deleteEmployee
  };
});
