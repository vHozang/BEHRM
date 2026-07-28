<template>
  <div>
    <!-- Hero gradient -->
    <div class="bg-gradient-to-br from-primary to-primary/70 text-primary-foreground px-5 pt-10 pb-16 rounded-b-3xl">
      <div class="flex items-center justify-between">
        <div>
          <p class="text-sm opacity-90">{{ greeting }},</p>
          <h1 class="text-xl font-bold">{{ user?.full_name || 'Nhân viên' }}</h1>
          <p class="text-xs opacity-75 mt-0.5">{{ todayLabel }}</p>
        </div>
        <div class="w-12 h-12 rounded-full bg-white/20 flex items-center justify-center text-lg font-bold">
          {{ initials }}
        </div>
      </div>
    </div>

    <!-- Card chấm công hôm nay (nổi trên hero) -->
    <div class="px-4 -mt-10">
      <div class="rounded-2xl bg-card border border-border shadow-lg p-4">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-xs text-muted-foreground">Hôm nay</p>
            <p class="font-bold" :class="today ? 'text-green-600' : 'text-amber-600'">
              {{ today ? (today.check_out_time ? 'Đã hoàn tất' : 'Đang làm việc') : 'Chưa chấm công' }}
            </p>
            <p v-if="today" class="text-xs text-muted-foreground mt-0.5">
              Vào {{ hhmm(today.check_in_time) }}<span v-if="today.check_out_time"> · Ra {{ hhmm(today.check_out_time) }}</span>
            </p>
          </div>
          <router-link to="/m/attendance"
                       class="px-4 py-2.5 rounded-full bg-primary text-primary-foreground text-sm font-bold active:scale-95 transition-transform">
            {{ !today ? 'Chấm công' : (!today.check_out_time ? 'Chấm ra' : 'Xem công') }}
          </router-link>
        </div>
      </div>
    </div>

    <!-- Quick actions -->
    <div class="px-4 mt-5 grid grid-cols-4 gap-3">
      <router-link v-for="a in actions" :key="a.to" :to="a.to"
                   class="flex flex-col items-center gap-1.5 py-3 rounded-2xl bg-card border border-border active:scale-95 transition-transform">
        <div class="w-10 h-10 rounded-full flex items-center justify-center" :class="a.bg">
          <svg class="w-5 h-5" :class="a.fg" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" :d="a.icon" />
          </svg>
        </div>
        <span class="text-[11px] font-medium text-center leading-tight">{{ a.label }}</span>
      </router-link>
    </div>

    <!-- Số dư phép + công tháng -->
    <div class="px-4 mt-5 grid grid-cols-2 gap-3">
      <div class="rounded-2xl bg-card border border-border p-4">
        <p class="text-xs text-muted-foreground">Phép năm còn lại</p>
        <p class="text-2xl font-extrabold text-primary mt-1">
          {{ annualLeaveLeft ?? '—' }}<span class="text-sm font-medium text-muted-foreground"> ngày</span>
        </p>
      </div>
      <div class="rounded-2xl bg-card border border-border p-4">
        <p class="text-xs text-muted-foreground">Công tháng {{ new Date().getMonth() + 1 }}</p>
        <p class="text-2xl font-extrabold mt-1">
          {{ monthPresent }}<span class="text-sm font-medium text-muted-foreground"> ngày</span>
        </p>
      </div>
    </div>

    <!-- Đơn gần đây -->
    <div class="px-4 mt-5">
      <div class="flex items-center justify-between mb-2">
        <h2 class="font-bold text-sm">Đơn từ gần đây</h2>
        <router-link to="/m/requests" class="text-xs text-primary font-medium">Xem tất cả →</router-link>
      </div>
      <div v-if="recentRequests.length === 0" class="rounded-2xl bg-card border border-border p-4 text-center text-sm text-muted-foreground">
        Chưa có đơn nào
      </div>
      <div v-for="r in recentRequests" :key="r.id"
           class="rounded-2xl bg-card border border-border p-3.5 mb-2 flex items-center justify-between">
        <div>
          <p class="text-sm font-semibold">{{ typeName(r.leave_type_id) }}</p>
          <p class="text-xs text-muted-foreground">{{ dmy(r.start_date) }} – {{ dmy(r.end_date) }}</p>
        </div>
        <span class="text-[11px] font-bold px-2.5 py-1 rounded-full" :class="statusChip(r.status)">
          {{ statusLabel(r.status) }}
        </span>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { authService } from '../../services/authService';
import { attendanceService } from '../../services/attendanceService';
import { leaveService } from '../../services/leaveService';
import { hhmm, dmy, statusChip, statusLabel, todayISO } from './mformat';

const user = authService.getUser();
const empId = user?.employee_id;

const records = ref([]);
const requests = ref([]);
const balances = ref([]);
const types = ref([]);

const typeName = (id) => types.value.find(t => Number(t.id) === Number(id))?.leave_type_name || 'Đơn nghỉ';

const greeting = computed(() => {
  const h = new Date().getHours();
  return h < 12 ? 'Chào buổi sáng' : h < 18 ? 'Chào buổi chiều' : 'Chào buổi tối';
});
const todayLabel = new Date().toLocaleDateString('vi-VN', { weekday: 'long', day: 'numeric', month: 'long' });
const initials = computed(() => (user?.full_name || 'NV').split(' ').slice(-2).map(w => w[0]).join('').toUpperCase());

const today = computed(() => records.value.find(r => String(r.attendance_date || '').slice(0, 10) === todayISO()));
const monthPresent = computed(() => {
  const ym = todayISO().slice(0, 7);
  return records.value.filter(r => String(r.attendance_date || '').slice(0, 7) === ym && r.status !== 'absent').length;
});
const annualLeaveLeft = computed(() => {
  // API trả {leave_type_code, year, entitlement, used, remaining} — ưu tiên năm hiện tại.
  const rows = balances.value.filter(x => x.leave_type_code === 'ANNUAL');
  const b = rows.find(x => Number(x.year) === new Date().getFullYear()) || rows[0];
  return b ? Number(b.remaining ?? (Number(b.entitlement || 0) - Number(b.used || 0))) : null;
});
const recentRequests = computed(() => requests.value.slice(0, 3));

const actions = [
  { to: '/m/attendance', label: 'Chấm công', icon: 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z', bg: 'bg-green-100 dark:bg-green-900/40', fg: 'text-green-600' },
  { to: '/m/requests', label: 'Nghỉ phép', icon: 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z', bg: 'bg-blue-100 dark:bg-blue-900/40', fg: 'text-blue-600' },
  { to: '/m/salary', label: 'Phiếu lương', icon: 'M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2z', bg: 'bg-amber-100 dark:bg-amber-900/40', fg: 'text-amber-600' },
  { to: '/m/me', label: 'Hồ sơ', icon: 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z', bg: 'bg-purple-100 dark:bg-purple-900/40', fg: 'text-purple-600' },
];

onMounted(async () => {
  if (!empId) return;
  // 3 nguồn độc lập — lỗi nguồn nào bỏ nguồn đó, không chặn màn hình.
  // Chỉ lấy THÁNG HIỆN TẠI (backend lọc month/year) — tránh tải toàn bộ lịch sử
  // nhiều trang khi công nhân có hàng trăm bản ghi (chậm, tưởng lỗi).
  const _now = new Date();
  attendanceService.getRecords({ employee_id: empId, month: _now.getMonth() + 1, year: _now.getFullYear(), per_page: 200 })
    .then(d => { records.value = Array.isArray(d) ? d : (d?.items || []); }).catch(() => {});
  leaveService.getRequests({ employee_id: empId })
    .then(d => { requests.value = Array.isArray(d) ? d : (d?.items || []); }).catch(() => {});
  leaveService.getBalances(empId)
    .then(d => { balances.value = Array.isArray(d) ? d : (d?.items || []); }).catch(() => {});
  leaveService.getTypes()
    .then(t => { types.value = Array.isArray(t) ? t : (t?.items || []); }).catch(() => {});
});
</script>
