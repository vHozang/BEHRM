<template>
  <main class="min-h-screen bg-background px-4 py-10 grid place-items-center">
    <section class="w-full max-w-md rounded-2xl border border-border bg-card p-6 shadow-lg sm:p-8">
      <router-link to="/login" class="text-sm font-medium text-primary hover:underline">
        &larr; Quay lại đăng nhập
      </router-link>

      <div class="mt-6">
        <p class="text-xs font-bold uppercase tracking-[0.2em] text-primary">Khôi phục tài khoản</p>
        <h1 class="mt-2 text-3xl font-bold text-foreground">Quên mật khẩu?</h1>
        <p class="mt-3 text-sm leading-6 text-muted-foreground">
          Nhập email công ty. Nếu tài khoản tồn tại, hệ thống sẽ gửi một liên kết đặt lại mật khẩu có hiệu lực trong 60 phút.
        </p>
      </div>

      <form v-if="!submitted" class="mt-7 space-y-5" @submit.prevent="submit">
        <BaseInput
          v-model="email"
          type="email"
          label="Email công ty"
          placeholder="tenban@company.com"
          autocomplete="email"
          required
        />

        <p v-if="error" class="rounded-lg border border-destructive/20 bg-destructive/10 p-3 text-sm text-destructive">
          {{ error }}
        </p>

        <BaseButton type="submit" :loading="loading" class="w-full">
          Gửi liên kết đặt lại mật khẩu
        </BaseButton>
      </form>

      <div v-else class="mt-7 rounded-xl border border-success/20 bg-success/10 p-5">
        <h2 class="font-semibold text-foreground">Hãy kiểm tra email của bạn</h2>
        <p class="mt-2 text-sm leading-6 text-muted-foreground">
          Nếu <strong>{{ email }}</strong> thuộc một tài khoản hợp lệ, email hướng dẫn đã được gửi. Hãy kiểm tra cả thư rác.
        </p>
      </div>
    </section>
  </main>
</template>

<script setup>
import { ref } from 'vue';
import BaseButton from '@/components/BaseButton.vue';
import BaseInput from '@/components/BaseInput.vue';
import { authService } from '@/services/authService';

const email = ref('');
const loading = ref(false);
const submitted = ref(false);
const error = ref('');

const submit = async () => {
  error.value = '';
  loading.value = true;

  try {
    await authService.forgotPassword(email.value.trim());
    submitted.value = true;
  } catch (err) {
    error.value = err.response?.data?.errors?.company_email?.[0]
      || err.response?.data?.message
      || 'Không thể gửi yêu cầu lúc này. Vui lòng thử lại.';
  } finally {
    loading.value = false;
  }
};
</script>
