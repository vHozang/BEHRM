<template>
  <div class="min-h-screen bg-background text-foreground">
    <main class="pb-24">
      <router-view />
    </main>

    <!-- Bottom tab bar (Grab-style) -->
    <nav class="fixed bottom-0 inset-x-0 z-40 bg-card border-t border-border grid grid-cols-5"
         style="padding-bottom: env(safe-area-inset-bottom)">
      <router-link v-for="t in tabs" :key="t.to" :to="t.to"
                   class="flex flex-col items-center gap-0.5 py-2 text-[11px] font-medium transition-colors"
                   :class="isActive(t) ? 'text-primary' : 'text-muted-foreground'">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" :d="t.icon" />
        </svg>
        {{ t.label }}
      </router-link>
    </nav>
  </div>
</template>

<script setup>
import { useRoute } from 'vue-router';

const route = useRoute();

const tabs = [
  { to: '/m', label: 'Trang chủ', icon: 'M3 12l9-9 9 9M5 10v10a1 1 0 001 1h3a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1h3a1 1 0 001-1V10' },
  { to: '/m/attendance', label: 'Chấm công', icon: 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z' },
  { to: '/m/requests', label: 'Đơn từ', icon: 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z' },
  { to: '/m/salary', label: 'Lương', icon: 'M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z' },
  { to: '/m/me', label: 'Tôi', icon: 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z' },
];

const isActive = (t) => t.to === '/m' ? route.path === '/m' : route.path.startsWith(t.to);
</script>
