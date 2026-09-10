<template>
  <svg :class="className" fill="none" stroke="currentColor" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
  </svg>
</template>

<script setup lang="ts">
interface Props {
  className?: string;
}

withDefaults(defineProps<Props>(), {
  className: 'w-5 h-5'
});
</script>
