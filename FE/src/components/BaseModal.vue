<template>
  <Teleport to="body">
    <Transition name="modal">
      <div
        v-if="modelValue"
        class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-4 bg-black/50 backdrop-blur-sm overflow-y-auto"
        @click="handleBackdropClick"
        :data-testid="testId"
      >
        <div
          :class="modalClasses"
          @click.stop
          role="dialog"
          aria-modal="true"
        >
          <!-- Header -->
          <div v-if="$slots.header || title" class="flex items-center justify-between border-b border-border pb-3 sm:pb-4">
            <slot name="header">
              <h2 class="text-xl font-semibold text-foreground">{{ title }}</h2>
            </slot>
            <button
              v-if="closable"
              @click="close"
              class="text-muted-foreground hover:text-foreground transition-colors"
              data-testid="button-modal-close"
            >
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>

          <!-- Content -->
          <div class="py-4 sm:py-6">
            <slot />
          </div>

          <!-- Footer -->
          <div v-if="$slots.footer" class="flex flex-col sm:flex-row justify-end gap-2 sm:gap-3 border-t border-border pt-3 sm:pt-4">
            <slot name="footer" />
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup lang="ts">
import { computed } from 'vue';

interface Props {
  modelValue: boolean;
  title?: string;
  size?: 'sm' | 'md' | 'lg' | 'xl';
  closable?: boolean;
  closeOnBackdrop?: boolean;
  testId?: string;
}

const props = withDefaults(defineProps<Props>(), {
  size: 'md',
  closable: true,
  closeOnBackdrop: true
});

const emit = defineEmits<{
  'update:modelValue': [value: boolean];
  close: [];
}>();

const modalClasses = computed(() => {
  const base = 'bg-card rounded-xl p-4 sm:p-6 shadow-xl max-h-[85vh] sm:max-h-[90vh] overflow-y-auto w-full';

  const sizes = {
    sm: 'max-w-md',
    md: 'max-w-2xl',
    lg: 'max-w-4xl',
    xl: 'max-w-6xl'
  };

  return `${base} ${sizes[props.size]}`;
});

const close = () => {
  emit('update:modelValue', false);
  emit('close');
};

const handleBackdropClick = () => {
  if (props.closeOnBackdrop) {
    close();
  }
};
</script>

<style scoped>
.modal-enter-active,
.modal-leave-active {
  transition: opacity 0.2s ease;
}

.modal-enter-from,
.modal-leave-to {
  opacity: 0;
}

.modal-enter-active > div,
.modal-leave-active > div {
  transition: transform 0.2s ease, opacity 0.2s ease;
}

.modal-enter-from > div,
.modal-leave-to > div {
  transform: scale(0.95);
  opacity: 0;
}
</style>