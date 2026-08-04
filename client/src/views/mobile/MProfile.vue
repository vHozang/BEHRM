<template>
  <div>
    <!-- Header -->
    <div class="bg-gradient-to-br from-primary to-primary/70 text-primary-foreground px-5 pt-10 pb-8 rounded-b-3xl flex items-center gap-4">
      <div class="w-16 h-16 rounded-full bg-white/20 flex items-center justify-center text-xl font-bold shrink-0">
        {{ initials }}
      </div>
      <div>
        <h1 class="text-lg font-bold">{{ user?.full_name || 'Nhân viên' }}</h1>
        <p class="text-xs opacity-90">{{ user?.employee_code }} · {{ user?.company_email }}</p>
      </div>
    </div>

    <!-- Info -->
    <div class="px-4 mt-4">
      <div class="rounded-2xl bg-card border border-border divide-y divide-border">
        <div v-for="row in info" :key="row.label" class="flex justify-between items-center p-3.5 text-sm">
          <span class="text-muted-foreground">{{ row.label }}</span>
          <span class="font-medium text-right">{{ row.value || '—' }}</span>
        </div>
      </div>

      <!-- Actions -->
      <div class="rounded-2xl bg-card border border-border divide-y divide-border mt-4">
        <router-link to="/m/contracts" class="w-full flex items-center justify-between p-3.5 text-sm font-medium active:bg-muted/50">
          <span>Hợp đồng lao động</span>
          <svg class="w-4 h-4 text-muted-foreground" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
        </router-link>
        <button @click="goDesktop" class="w-full flex items-center justify-between p-3.5 text-sm font-medium active:bg-muted/50">
          <span>Dùng bản đầy đủ (desktop)</span>
          <svg class="w-4 h-4 text-muted-foreground" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
        </button>
        <button @click="logout" class="w-full flex items-center justify-between p-3.5 text-sm font-semibold text-red-600 active:bg-muted/50">
          <span>Đăng xuất</span>
          <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import { authService } from '../../services/authService';
import { dmy } from './mformat';

const router = useRouter();
const user = authService.getUser();

const initials = computed(() => (user?.full_name || 'NV').split(' ').slice(-2).map(w => w[0]).join('').toUpperCase());

const info = [
  { label: 'Mã nhân viên', value: user?.employee_code },
  { label: 'Số điện thoại', value: user?.phone_number },
  { label: 'Ngày vào làm', value: dmy(user?.hire_date) },
  { label: 'Trạng thái', value: user?.status === 'ACTIVE' ? 'Đang làm việc' : user?.status },
];

const goDesktop = () => {
  localStorage.setItem('prefer_desktop', '1'); // guard sẽ không tự đá về /m nữa
  router.push('/employee-portal');
};

const logout = () => authService.logout();
</script>
