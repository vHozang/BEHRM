<template>
  <div class="py-1">
    <!-- Banner trạng thái hiện tại (kiểu Grab: trạng thái đơn nổi bật) -->
    <div :class="['flex items-center gap-3 rounded-2xl p-3.5 mb-5 border', banner.wrap]">
      <div :class="['w-11 h-11 rounded-full flex items-center justify-center shrink-0 shadow-sm', banner.iconBg]">
        <svg v-if="banner.kind === 'done'" class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" /></svg>
        <svg v-else-if="banner.kind === 'reject'" class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12" /></svg>
        <svg v-else class="w-6 h-6 text-white animate-pulse" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
      </div>
      <div class="min-w-0">
        <p :class="['font-bold leading-tight', banner.text]">{{ banner.title }}</p>
        <p class="text-xs text-muted-foreground truncate">{{ banner.subtitle }}</p>
      </div>
    </div>

    <!-- Tiến trình dọc (như theo dõi đơn Grab) -->
    <div class="relative pl-1">
      <div v-for="(step, i) in steps" :key="i" class="flex gap-3.5 relative pb-5 last:pb-0">
        <!-- Đoạn nối dọc tới node kế: xanh nếu node này đã xong -->
        <div
          v-if="i < steps.length - 1"
          :class="['absolute left-[22px] top-11 w-0.5 h-[calc(100%-2.75rem)] rounded-full transition-colors duration-500',
                   state(step) === 'done' ? 'bg-emerald-500' : 'bg-border']"
        ></div>

        <!-- Node -->
        <div
          :class="['w-11 h-11 rounded-full flex items-center justify-center border-2 shrink-0 z-10 bg-card transition-all duration-300',
                   nodeClass(state(step)), state(step) === 'current' ? 'ring-4 ring-amber-500/15' : '']"
        >
          <svg v-if="state(step) === 'done'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" /></svg>
          <svg v-else-if="state(step) === 'reject'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12" /></svg>
          <svg v-else-if="state(step) === 'current'" class="w-5 h-5 animate-pulse" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
          <span v-else class="text-xs font-bold text-muted-foreground">{{ i + 1 }}</span>
        </div>

        <!-- Nội dung bước -->
        <div class="flex-1 min-w-0 pt-0.5">
          <div class="flex items-center justify-between gap-2 flex-wrap">
            <p class="text-sm font-bold text-foreground leading-tight">{{ step.step_name }}</p>
            <span :class="['text-[10px] px-2 py-0.5 rounded-full font-semibold shrink-0', badgeClass(step.status)]">{{ statusLabel(step.status) }}</span>
          </div>
          <p v-if="step.approver_name" class="text-xs font-medium text-foreground/75 mt-0.5">{{ step.approver_name }}</p>
          <div v-if="step.comment" class="mt-1.5 text-xs bg-muted/60 border border-border/50 px-2.5 py-1.5 rounded-lg">
            <p class="italic text-muted-foreground">"{{ step.comment }}"</p>
          </div>
          <p v-if="step.action_date" class="text-[10px] text-muted-foreground/70 mt-1">{{ formatDate(step.action_date) }}</p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  steps: { type: Array, required: true, default: () => [] },
});

// Chuẩn hoá trạng thái thô (EN của ApprovalFlow hoặc VN cũ) → nhóm.
const norm = (status) => {
  const s = String(status || '').toUpperCase();
  if (['ĐÃ_DUYỆT', 'HOÀN_THÀNH', 'APPROVED', 'COMPLETED', 'DONE'].includes(s)) return 'done';
  if (['TỪ_CHỐI', 'REJECTED'].includes(s)) return 'reject';
  if (['CHỜ_DUYỆT', 'ĐANG_XỬ_LÝ', 'PENDING', 'IN_PROGRESS'].includes(s)) return 'pending';
  if (['ĐÃ_HỦY', 'CANCELLED', 'CANCELED'].includes(s)) return 'cancel';
  return 'idle';
};

const statusLabel = (status) => ({
  done: 'Đã duyệt', reject: 'Từ chối', pending: 'Chờ duyệt', cancel: 'Đã hủy', idle: 'Chưa tới',
}[norm(status)]);

// Bước đang chờ đầu tiên = "current" (đang xử lý, như tài xế đang giao).
const currentIndex = computed(() => props.steps.findIndex((s) => norm(s.status) === 'pending'));

// Trạng thái hiển thị của 1 node: done | reject | current | upcoming.
const state = (step) => {
  const n = norm(step.status);
  if (n === 'done') return 'done';
  if (n === 'reject' || n === 'cancel') return 'reject';
  const idx = props.steps.indexOf(step);
  if (idx === currentIndex.value) return 'current';
  return 'upcoming';
};

const nodeClass = (st) => ({
  done: 'bg-emerald-500 border-emerald-500 text-white shadow-emerald-500/20 shadow-md',
  reject: 'bg-rose-500 border-rose-500 text-white shadow-rose-500/20 shadow-md',
  current: 'bg-amber-50 dark:bg-amber-950/40 border-amber-500 text-amber-600 dark:text-amber-400',
  upcoming: 'bg-muted border-border text-muted-foreground',
}[st]);

const badgeClass = (status) => ({
  done: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
  reject: 'bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-400',
  cancel: 'bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-400',
  pending: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
  idle: 'bg-muted text-muted-foreground',
}[norm(status)]);

// Banner tổng: ưu tiên từ chối → đang chờ → đã xong.
const banner = computed(() => {
  const steps = props.steps;
  const rejected = steps.find((s) => norm(s.status) === 'reject' || norm(s.status) === 'cancel');
  if (rejected) {
    return {
      kind: 'reject', wrap: 'bg-rose-50 dark:bg-rose-950/20 border-rose-200 dark:border-rose-900',
      iconBg: 'bg-rose-500', text: 'text-rose-700 dark:text-rose-400',
      title: norm(rejected.status) === 'cancel' ? 'Đơn đã bị hủy' : 'Đơn bị từ chối',
      subtitle: rejected.approver_name ? `Bởi ${rejected.approver_name}` : '',
    };
  }
  const cur = currentIndex.value >= 0 ? steps[currentIndex.value] : null;
  if (cur) {
    return {
      kind: 'pending', wrap: 'bg-amber-50 dark:bg-amber-950/20 border-amber-200 dark:border-amber-900',
      iconBg: 'bg-amber-500', text: 'text-amber-700 dark:text-amber-400',
      title: 'Đang chờ duyệt',
      subtitle: cur.approver_name || cur.step_name,
    };
  }
  const last = steps[steps.length - 1];
  return {
    kind: 'done', wrap: 'bg-emerald-50 dark:bg-emerald-950/20 border-emerald-200 dark:border-emerald-900',
    iconBg: 'bg-emerald-500', text: 'text-emerald-700 dark:text-emerald-400',
    title: 'Đã hoàn tất duyệt',
    subtitle: last && last.approver_name ? `Duyệt bởi ${last.approver_name}` : '',
  };
});

const formatDate = (dateStr) => {
  if (!dateStr) return '';
  const d = new Date(dateStr);
  return d.toLocaleString('vi-VN', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
};
</script>
