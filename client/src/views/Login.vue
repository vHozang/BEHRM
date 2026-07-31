<template>
  <div class="min-h-screen grid place-items-center bg-background px-4 py-10">
    <div class="w-full max-w-5xl overflow-hidden rounded-2xl border border-border bg-card shadow-lg">
      <div class="grid min-h-[560px] lg:grid-cols-[1.05fr_0.95fr]">
        <section class="hidden bg-primary p-10 text-primary-foreground lg:flex lg:flex-col lg:justify-between">
          <div>
            <div class="mb-6 inline-flex rounded-full border border-white/20 bg-white/10 px-3 py-1 text-xs font-semibold">
              HRM AI Platform
            </div>
            <h1 class="text-4xl font-bold leading-tight">Quản trị nhân sự trực quan, nhanh và gọn hơn.</h1>
            <p class="mt-4 max-w-md text-sm leading-6 text-white/80">Theo dõi nhân viên, chấm công, nghỉ phép, tuyển dụng và báo cáo từ một dashboard thống nhất.</p>
          </div>
          <div class="grid grid-cols-3 gap-3">
            <div class="rounded-xl border border-white/15 bg-white/10 p-4">
              <p class="text-2xl font-bold">AI</p>
              <p class="mt-1 text-xs text-white/70">Tuyển dụng</p>
            </div>
            <div class="rounded-xl border border-white/15 bg-white/10 p-4">
              <p class="text-2xl font-bold">360</p>
              <p class="mt-1 text-xs text-white/70">Hồ sơ</p>
            </div>
            <div class="rounded-xl border border-white/15 bg-white/10 p-4">
              <p class="text-2xl font-bold">24/7</p>
              <p class="mt-1 text-xs text-white/70">Vận hành</p>
            </div>
          </div>
        </section>

        <section class="flex items-center justify-center p-6 sm:p-10">
          <div class="w-full max-w-md space-y-8">
      <div>
        <h2 class="text-center text-3xl font-extrabold" style="font-family: 'Montserrat', sans-serif;">
          <span style="color: #124DA3;">CODE</span><span style="color: #F37022;">DEN</span><span
            style="color: #4EB748;">NGU</span>
        </h2>
        <p class="mt-2 text-center text-sm text-gray-600">
          Đăng nhập để tiếp tục
        </p>
      </div>

      <form class="space-y-6" @submit.prevent="handleLogin">
        <div class="space-y-4">
          <BaseInput v-model="email" type="email" label="Email" placeholder="admin@example.com" required />

          <BaseInput v-model="password" type="password" label="Mật khẩu" placeholder="••••••••" required />
        </div>

        <div class="flex justify-end">
          <router-link to="/forgot-password" class="text-sm font-medium text-primary hover:underline">
            Quên mật khẩu?
          </router-link>
        </div>

        <div v-if="error" class="text-red-600 text-sm text-center">
          {{ error }}
        </div>

        <div>
          <BaseButton type="submit" :loading="loading" class="w-full">
            Đăng nhập
          </BaseButton>
        </div>
      </form>

      <!-- Đăng nhập nhanh (demo) — tiết kiệm thời gian lúc bảo vệ đồ án -->
      <div class="pt-2">
        <p class="text-center text-xs text-muted-foreground mb-2">Đăng nhập nhanh (demo)</p>
        <div class="grid grid-cols-2 gap-2">
          <button v-for="acc in demoAccounts" :key="acc.email" type="button"
            :disabled="loading" @click="quickLogin(acc)"
            class="rounded-lg border border-border px-3 py-2 text-sm font-medium hover:bg-muted transition-colors disabled:opacity-50">
            {{ acc.label }}
          </button>
        </div>
      </div>
          </div>
        </section>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { authService } from '@/services/authService';
import BaseInput from '@/components/BaseInput.vue';
import BaseButton from '@/components/BaseButton.vue';
import { useNotificationStore } from '@/stores/notificationStore';

const router = useRouter();
const notificationStore = useNotificationStore();
const email = ref('');
const password = ref('');
const loading = ref(false);
const error = ref('');

// Tài khoản demo cho nút đăng nhập nhanh (5 tầng quyền khác nhau).
const demoAccounts = [
  { label: 'Admin', email: 'an.nguyen@company.com', password: 'test1234' },
  { label: 'Trưởng phòng', email: 'cuong.le@company.com', password: 'demo1234' },
  { label: 'HR', email: 'mai.tran@company.com', password: 'demo1234' },
  { label: 'Kế toán', email: 'phuc.trinh@company.com', password: 'ketoan1234' },
  { label: 'Nhân viên', email: 'huong.pham@company.com', password: 'demo1234' },
];

const quickLogin = (acc: { email: string; password: string }) => {
  email.value = acc.email;
  password.value = acc.password;
  return handleLogin();
};

const handleLogin = async () => {
  loading.value = true;
  error.value = '';

  try {
    const responseData = await authService.login(email.value, password.value);
    
    // Auth token, user, access and user_role are stored by authService.login.
    // Admin shell = full access OR any admin module granted (role-based RBAC).
    const access = authService.getAccess();
    const isAdmin = access.full === true || access.modules.length > 0;
    const role = isAdmin ? 'admin' : 'employee';
    localStorage.setItem('role', JSON.stringify({ code: role }));
    localStorage.setItem('user_role', role);

    // Get user from localStorage to show welcome message
    const user = JSON.parse(localStorage.getItem('user') || '{}');
    notificationStore.addSuccess(`Chào mừng ${user.full_name || user.name || 'bạn'} quay trở lại!`);

    // Redirect based on role
    if (isAdmin) {
      router.push('/');
    } else {
      router.push('/employee-portal');
    }
  } catch (err: any) {
    error.value = err.response?.data?.message || err.response?.data?.error || 'Đăng nhập thất bại';
    notificationStore.addError('Đăng nhập thất bại: ' + error.value);
  } finally {
    loading.value = false;
  }
};
</script>
