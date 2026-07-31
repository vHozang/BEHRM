<template>
  <main class="min-h-screen bg-background px-4 py-10 grid place-items-center">
    <section class="w-full max-w-md rounded-2xl border border-border bg-card p-6 shadow-lg sm:p-8">
      <router-link to="/login" class="text-sm font-medium text-primary hover:underline">
        &larr; Quay lại đăng nhập
      </router-link>

      <div class="mt-6">
        <p class="text-xs font-bold uppercase tracking-[0.2em] text-primary">Bảo mật tài khoản</p>
        <h1 class="mt-2 text-3xl font-bold text-foreground">Đặt lại mật khẩu</h1>
        <p class="mt-3 text-sm leading-6 text-muted-foreground">
          Mật khẩu mới cần ít nhất 8 ký tự và phải có cả chữ lẫn số.
        </p>
      </div>

      <p v-if="!token" class="mt-7 rounded-lg border border-destructive/20 bg-destructive/10 p-4 text-sm text-destructive">
        Liên kết đặt lại mật khẩu không hợp lệ hoặc bị thiếu token.
      </p>

      <form v-else-if="!completed" class="mt-7 space-y-5" @submit.prevent="submit">
        <BaseInput
          v-model="password"
          type="password"
          label="Mật khẩu mới"
          placeholder="Tối thiểu 8 ký tự, gồm chữ và số"
          autocomplete="new-password"
          required
        />
        <BaseInput
          v-model="passwordConfirmation"
          type="password"
          label="Xác nhận mật khẩu"
          placeholder="Nhập lại mật khẩu mới"
          autocomplete="new-password"
          required
        />

        <p v-if="error" class="rounded-lg border border-destructive/20 bg-destructive/10 p-3 text-sm text-destructive">
          {{ error }}
        </p>

        <BaseButton type="submit" :loading="loading" class="w-full">
          Cập nhật mật khẩu
        </BaseButton>
      </form>

      <div v-else class="mt-7 rounded-xl border border-success/20 bg-success/10 p-5">
        <h2 class="font-semibold text-foreground">Mật khẩu đã được cập nhật</h2>
        <p class="mt-2 text-sm leading-6 text-muted-foreground">
          Tất cả phiên đăng nhập cũ đã bị thu hồi. Bạn có thể đăng nhập lại bằng mật khẩu mới.
        </p>
        <router-link to="/login" class="mt-4 inline-flex font-semibold text-primary hover:underline">
          Đi tới trang đăng nhập
        </router-link>
      </div>
    </section>
  </main>
</template>

<script setup>
import { ref } from 'vue';
import { useRoute } from 'vue-router';
import BaseButton from '@/components/BaseButton.vue';
import BaseInput from '@/components/BaseInput.vue';
import { authService } from '@/services/authService';

const route = useRoute();
const token = typeof route.query.token === 'string' ? route.query.token : '';
const password = ref('');
const passwordConfirmation = ref('');
const loading = ref(false);
const completed = ref(false);
const error = ref('');

const submit = async () => {
  error.value = '';

  if (password.value.length < 8 || !/[A-Za-z]/.test(password.value) || !/\d/.test(password.value)) {
    error.value = 'Mật khẩu mới phải có ít nhất 8 ký tự, gồm chữ và số.';
    return;
  }

  if (password.value !== passwordConfirmation.value) {
    error.value = 'Mật khẩu xác nhận không khớp.';
    return;
  }

  loading.value = true;
  try {
    await authService.resetPassword(token, password.value, passwordConfirmation.value);
    authService.clearSession();
    completed.value = true;
  } catch (err) {
    error.value = err.response?.data?.errors?.password?.[0]
      || err.response?.data?.message
      || 'Liên kết không hợp lệ, đã hết hạn hoặc đã được sử dụng.';
  } finally {
    loading.value = false;
  }
};
</script>
