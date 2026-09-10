<template>
  <div
    class="surface-panel relative flex flex-col gap-3 p-5 transition-smooth"
    :class="hoverable ? 'hover:shadow-md hover:-translate-y-0.5 hover:border-primary/20' : ''"
    :data-testid="testId"
  >
    <div class="flex items-start justify-between gap-3">
      <div class="min-w-0">
        <p class="truncate text-xs font-medium uppercase tracking-wide text-muted-foreground">{{ label }}</p>
        <div class="mt-1 flex items-baseline gap-2">
          <span class="text-2xl font-bold leading-none text-foreground">
            <slot name="value">{{ value }}</slot>
          </span>
          <span
            v-if="delta != null"
            class="inline-flex items-center gap-0.5 text-xs font-semibold"
            :class="deltaClass"
          >
            <svg v-if="deltaDirection !== 'flat'" class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path v-if="deltaDirection === 'up'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 15l7-7 7 7" />
              <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7" />
            </svg>
            {{ deltaLabel }}
          </span>
        </div>
        <p v-if="sublabel" class="mt-1 truncate text-xs text-muted-foreground">{{ sublabel }}</p>
      </div>

      <div
        v-if="$slots.icon"
        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl"
        :class="iconWrapClass"
      >
        <slot name="icon" />
      </div>
    </div>

    <!-- Optional sparkline / chart area -->
    <div v-if="$slots.sparkline" class="h-10 w-full">
      <slot name="sparkline" />
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  label: { type: String, default: '' },
  value: { type: [String, Number], default: '' },
  sublabel: { type: String, default: '' },
  // delta: numeric change. Positive => up (good), negative => down. Use deltaPositive to invert semantics.
  delta: { type: [String, Number], default: null },
  // override semantic color: 'success' | 'danger' | null (auto from sign)
  tone: { type: String, default: null },
  // accent tone for the icon chip: 'primary' | 'success' | 'warning' | 'danger' | 'info' | 'ai'
  accent: { type: String, default: 'primary' },
  hoverable: { type: Boolean, default: true },
  testId: { type: String, default: undefined },
});

const deltaDirection = computed(() => {
  if (props.delta == null) return 'flat';
  const n = typeof props.delta === 'number' ? props.delta : parseFloat(String(props.delta).replace(/[^0-9.\-]/g, ''));
  if (Number.isNaN(n) || n === 0) return 'flat';
  return n > 0 ? 'up' : 'down';
});

const deltaLabel = computed(() => {
  if (props.delta == null) return '';
  if (typeof props.delta === 'string') return props.delta;
  const n = props.delta;
  return `${n > 0 ? '+' : ''}${n}%`;
});

const deltaClass = computed(() => {
  const tone = props.tone || (deltaDirection.value === 'down' ? 'danger' : deltaDirection.value === 'up' ? 'success' : 'muted');
  return {
    success: 'text-success',
    danger: 'text-destructive',
    muted: 'text-muted-foreground',
  }[tone] || 'text-muted-foreground';
});

const iconWrapClass = computed(() => {
  return {
    primary: 'bg-primary/10 text-primary',
    success: 'bg-success/10 text-success',
    warning: 'bg-warning/10 text-warning',
    danger: 'bg-destructive/10 text-destructive',
    info: 'bg-info/10 text-info',
    ai: 'bg-ai/10 text-ai',
  }[props.accent] || 'bg-primary/10 text-primary';
});
</script>
