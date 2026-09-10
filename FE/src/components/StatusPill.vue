<template>
  <span
    class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium"
    :class="pillClass"
    :data-testid="testId"
  >
    <span v-if="dot" class="h-1.5 w-1.5 rounded-full" :class="dotClass"></span>
    <slot>{{ label || status }}</slot>
  </span>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  // raw status string (any casing / language). Mapped to a tone below.
  status: { type: String, default: '' },
  // optional display label override
  label: { type: String, default: '' },
  // force a tone instead of inferring: success | warning | danger | info | ai | neutral | primary
  tone: { type: String, default: null },
  dot: { type: Boolean, default: true },
  testId: { type: String, default: undefined },
});

// Map common HRM statuses (VN + EN) to a semantic tone.
const STATUS_TONE = {
  // success / positive
  active: 'success', approved: 'success', completed: 'success', done: 'success',
  paid: 'success', present: 'success', signed: 'success', open: 'success',
  'đã duyệt': 'success', 'hoạt động': 'success', 'hoàn thành': 'success',
  'có mặt': 'success', 'đã ký': 'success', 'đã thanh toán': 'success',
  // warning / pending
  pending: 'warning', processing: 'warning', draft: 'warning', late: 'warning',
  review: 'warning', waiting: 'warning',
  'chờ duyệt': 'warning', 'đang xử lý': 'warning', 'nháp': 'warning',
  'đi muộn': 'warning', 'chờ': 'warning',
  // danger / negative
  rejected: 'danger', failed: 'danger', expired: 'danger', absent: 'danger',
  cancelled: 'danger', canceled: 'danger', terminated: 'danger', inactive: 'danger',
  'từ chối': 'danger', 'thất bại': 'danger', 'hết hạn': 'danger',
  'vắng mặt': 'danger', 'đã hủy': 'danger', 'ngừng': 'danger',
  // info
  new: 'info', scheduled: 'info', info: 'info',
  'mới': 'info', 'đã lên lịch': 'info',
};

const resolvedTone = computed(() => {
  if (props.tone) return props.tone;
  const key = (props.status || '').toString().trim().toLowerCase();
  return STATUS_TONE[key] || 'neutral';
});

const pillClass = computed(() => ({
  success: 'bg-success/10 text-success ring-1 ring-success/20',
  warning: 'bg-warning/10 text-warning ring-1 ring-warning/20',
  danger: 'bg-destructive/10 text-destructive ring-1 ring-destructive/20',
  info: 'bg-info/10 text-info ring-1 ring-info/20',
  ai: 'bg-ai/10 text-ai ring-1 ring-ai/20',
  primary: 'bg-primary/10 text-primary ring-1 ring-primary/20',
  neutral: 'bg-muted text-muted-foreground ring-1 ring-border',
}[resolvedTone.value] || 'bg-muted text-muted-foreground ring-1 ring-border'));

const dotClass = computed(() => ({
  success: 'bg-success',
  warning: 'bg-warning',
  danger: 'bg-destructive',
  info: 'bg-info',
  ai: 'bg-ai',
  primary: 'bg-primary',
  neutral: 'bg-muted-foreground',
}[resolvedTone.value] || 'bg-muted-foreground'));
</script>
