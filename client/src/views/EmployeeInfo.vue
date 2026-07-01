
<template>
  <div class="p-6">
    <h1 class="text-2xl font-bold mb-6">Thông tin cá nhân</h1>
    
    <BaseCard v-if="employee">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div>
          <label class="text-sm font-medium text-gray-600">Mã nhân viên</label>
          <p class="text-lg">{{ employee.employee_code }}</p>
        </div>
        
        <div>
          <label class="text-sm font-medium text-gray-600">Họ tên</label>
          <p class="text-lg">{{ employee.full_name }}</p>
        </div>
        
        <div>
          <label class="text-sm font-medium text-gray-600">Email</label>
          <p class="text-lg">{{ employee.email }}</p>
        </div>
        
        <div>
          <label class="text-sm font-medium text-gray-600">Số điện thoại</label>
          <p class="text-lg">{{ employee.phone }}</p>
        </div>
        
        <div>
          <label class="text-sm font-medium text-gray-600">Ngày sinh</label>
          <p class="text-lg">{{ employee.date_of_birth }}</p>
        </div>
        
        <div>
          <label class="text-sm font-medium text-gray-600">Giới tính</label>
          <p class="text-lg">{{ employee.gender }}</p>
        </div>
        
        <div class="md:col-span-2">
          <label class="text-sm font-medium text-gray-600">Địa chỉ</label>
          <p class="text-lg">{{ employee.address }}</p>
        </div>
        
        <div>
          <label class="text-sm font-medium text-gray-600">Phòng ban</label>
          <p class="text-lg">{{ employee.department_name }}</p>
        </div>
        
        <div>
          <label class="text-sm font-medium text-gray-600">Chức vụ</label>
          <p class="text-lg">{{ employee.job_title_name }}</p>
        </div>
        
        <div>
          <label class="text-sm font-medium text-gray-600">Ngày vào làm</label>
          <p class="text-lg">{{ employee.hire_date }}</p>
        </div>
      </div>
    </BaseCard>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { employeeService } from '@/services/employeeService';
import BaseCard from '@/components/BaseCard.vue';

const employee = ref<any>(null);
const user = JSON.parse(localStorage.getItem('user') || '{}');

onMounted(async () => {
  try {
    // Assuming user has employee_id
    const response = await employeeService.getById(user.id);
    employee.value = response.data || response;
  } catch (error) {
    console.error('Failed to load employee info', error);
  }
});
</script>
