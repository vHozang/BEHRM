<template>
  <teleport to="body">
    <transition name="drawer-fade">
      <div
        v-if="modelValue"
        class="fixed inset-0 z-[60] bg-black/50 backdrop-blur-sm"
        @click.self="onBackdrop"
        :data-testid="testId ? `${testId}-backdrop` : undefined"
      >
        <transition :name="side === 'left' ? 'drawer-slide-left' : 'drawer-slide-right'">
          <aside
            v-if="modelValue"
            class="absolute top-0 flex h-full w-full max-w-md flex-col bg-card text-card-foreground shadow-xl"
            :class="side === 'left' ? 'left-0 border-r border-card-border' : 'right-0 border-l border-card-border'"
            :style="widthStyle"
            role="dialog"
            aria-modal="true"
            :data-testid="testId"
          >
            <header v-if="!hideHeader" class="flex items-center justify-between gap-3 border-b border-card-border px-5 py-4">
              <div class="min-w-0">
                <slot name="header">
                  <h2 class="truncate text-base font-semibold">{{ title }}</h2>
                </slot>
              </div>
              <button
                type="button"
                class="rounded-lg p-1.5 text-muted-foreground hover-elevate active-elevate-2"
                @click="close"
                aria-label="Close"
              >
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </header>

            <div class="flex-1 overflow-y-auto px-5 py-4">
              <slot />
            </div>

            <footer v-if="$slots.footer" class="border-t border-card-border bg-muted/20 px-5 py-4">
              <slot name="footer" />
            </footer>
          </aside>
        </transition>
      </div>
    </transition>
  </teleport>
</template>

<script setup>
import { computed, watch, onUnmounted } from 'vue';

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  title: { type: String, default: '' },
  side: { type: String, default: 'right' }, // 'right' | 'left'
  width: { type: String, default: '' },     // e.g. '28rem'; overrides max-w
  hideHeader: { type: Boolean, default: false },
  closeOnBackdrop: { type: Boolean, default: true },
  testId: { type: String, default: undefined },
});

const emit = defineEmits(['update:modelValue', 'close']);

const widthStyle = computed(() => (props.width ? { width: props.width, maxWidth: '100%' } : {}));

function close() {
  emit('update:modelValue', false);
  emit('close');
}

function onBackdrop() {
  if (props.closeOnBackdrop) close();
}

function onKeydown(e) {
  if (e.key === 'Escape' && props.modelValue) close();
}

watch(
  () => props.modelValue,
  (open) => {
    if (typeof document === 'undefined') return;
    if (open) {
      document.addEventListener('keydown', onKeydown);
      document.body.style.overflow = 'hidden';
    } else {
      document.removeEventListener('keydown', onKeydown);
      document.body.style.overflow = '';
    }
  }
);

onUnmounted(() => {
  if (typeof document === 'undefined') return;
  document.removeEventListener('keydown', onKeydown);
  document.body.style.overflow = '';
});
</script>

<style scoped>
.drawer-fade-enter-active,
.drawer-fade-leave-active {
  transition: opacity 0.2s ease;
}
.drawer-fade-enter-from,
.drawer-fade-leave-to {
  opacity: 0;
}

.drawer-slide-right-enter-active,
.drawer-slide-right-leave-active,
.drawer-slide-left-enter-active,
.drawer-slide-left-leave-active {
  transition: transform 0.25s ease;
}
.drawer-slide-right-enter-from,
.drawer-slide-right-leave-to {
  transform: translateX(100%);
}
.drawer-slide-left-enter-from,
.drawer-slide-left-leave-to {
  transform: translateX(-100%);
}
</style>
