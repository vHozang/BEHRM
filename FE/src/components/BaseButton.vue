
<template>
  <button
    :type="type"
    :class="buttonClasses"
    :disabled="disabled || loading"
    @click="handleClick"
    :data-testid="testId"
  >
    <span v-if="loading" class="inline-block w-4 h-4 border-2 border-current border-t-transparent rounded-full animate-spin mr-2"></span>
    <slot />
  </button>
</template>

<script setup lang="ts">
import { computed } from 'vue';

interface Props {
  variant?: 'primary' | 'secondary' | 'outline' | 'ghost' | 'destructive';
  size?: 'sm' | 'default' | 'lg';
  type?: 'button' | 'submit' | 'reset';
  disabled?: boolean;
  loading?: boolean;
  testId?: string;
}

const props = withDefaults(defineProps<Props>(), {
  variant: 'primary',
  size: 'default',
  type: 'button',
  disabled: false,
  loading: false
});

const emit = defineEmits<{
  click: [event: MouseEvent];
}>();

const buttonClasses = computed(() => {
  const base = 'inline-flex items-center justify-center rounded-xl font-medium transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed shadow-sm';
  
  const sizes = {
    sm: 'min-h-8 px-3 text-sm',
    default: 'min-h-10 px-5 py-2.5',
    lg: 'min-h-12 px-7 text-lg'
  };
  
  const variants = {
    primary: 'bg-primary text-primary-foreground hover:shadow-md hover:scale-[1.02] active:scale-[0.98] focus:ring-primary',
    secondary: 'bg-secondary text-secondary-foreground hover:bg-secondary/80 border border-secondary-border focus:ring-secondary',
    outline: 'border-2 border-border bg-transparent text-foreground hover:bg-muted focus:ring-ring',
    ghost: 'text-foreground hover:bg-muted/50 focus:ring-ring',
    destructive: 'bg-destructive text-destructive-foreground hover:shadow-md hover:scale-[1.02] active:scale-[0.98] focus:ring-destructive'
  };
  
  return `${base} ${sizes[props.size]} ${variants[props.variant]}`;
});

const handleClick = (event: MouseEvent) => {
  if (!props.disabled && !props.loading) {
    emit('click', event);
  }
};
</script>
