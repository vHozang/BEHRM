<template>
  <div id="app">
    <router-view />
    <ToastContainer />
  </div>
</template>

<script setup lang="ts">
import { onMounted } from 'vue';
import ToastContainer from './components/ToastContainer.vue';
import { useTheme } from './composables/useTheme';
import { useI18n } from './i18n';

const { initTheme, toggleTheme } = useTheme();
const { locale } = useI18n();

onMounted(() => {
  // Single source of truth for theme: read persisted/system preference, apply .dark on <html>.
  initTheme();
  // Reflect current locale on <html lang>.
  document.documentElement.setAttribute('lang', locale.value);
});

// Keep the legacy global hook working for anything that calls window.toggleTheme().
(window as any).toggleTheme = toggleTheme;
</script>

<style>
#app {
  min-height: 100vh;
  background-color: hsl(var(--background));
  color: hsl(var(--foreground));
}
</style>
