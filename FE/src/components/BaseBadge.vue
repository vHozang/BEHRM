
<template>
  <span :class="badgeClasses" :data-testid="testId">
    <slot />
  </span>
</template>

<script setup lang="ts">
import { computed } from 'vue';

interface Props {
  variant?: 'default' | 'success' | 'warning' | 'error' | 'info';
  size?: 'sm' | 'default';
  testId?: string;
}

const props = withDefaults(defineProps<Props>(), {
  variant: 'default',
  size: 'default'
});

const badgeClasses = computed(() => {
  const base = 'inline-flex items-center justify-center rounded-full font-medium whitespace-nowrap transition-all';
  
  const sizes = {
    sm: 'px-2 py-0.5 text-xs',
    default: 'px-3 py-1 text-xs'
  };
  
  const variants = {
    default: 'bg-secondary text-secondary-foreground border border-secondary-border',
    success: 'bg-success/10 text-success border border-success/20 dark:bg-success/20 dark:border-success/30',
    warning: 'bg-warning/10 text-warning border border-warning/20 dark:bg-warning/20 dark:border-warning/30',
    error: 'bg-destructive/10 text-destructive border border-destructive/20 dark:bg-destructive/20 dark:border-destructive/30',
    info: 'bg-info/10 text-info border border-info/20 dark:bg-info/20 dark:border-info/30'
  };
  
  return `${base} ${sizes[props.size]} ${variants[props.variant]}`;
});
</script>
