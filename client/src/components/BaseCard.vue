
<template>
  <div :class="cardClasses" :data-testid="testId">
    <div v-if="$slots.header || title" class="px-5 py-4 sm:px-6 border-b border-card-border/70">
      <slot name="header">
        <h3 class="text-base font-semibold text-card-foreground">{{ title }}</h3>
      </slot>
    </div>
    
    <div class="p-5 sm:p-6">
      <slot />
    </div>
    
    <div v-if="$slots.footer" class="px-5 py-4 sm:px-6 border-t border-card-border/70 bg-muted/20">
      <slot name="footer" />
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';

interface Props {
  title?: string;
  hoverable?: boolean;
  testId?: string;
}

const props = withDefaults(defineProps<Props>(), {
  hoverable: false
});

const cardClasses = computed(() => {
  const base = 'bg-card/95 border border-card-border rounded-xl shadow-sm soft-ring transition-smooth';
  const hover = props.hoverable ? 'hover:shadow-md hover:-translate-y-0.5 hover:border-primary/20 cursor-pointer' : '';
  return `${base} ${hover}`;
});
</script>
