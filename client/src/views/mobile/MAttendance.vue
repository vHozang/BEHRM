<template>
  <div class="px-4 pt-8">
    <h1 class="text-lg font-bold mb-1">Chấm công</h1>
    <p class="text-xs text-muted-foreground mb-6">{{ todayLabel }}</p>

    <!-- Đồng hồ + nút chấm lớn -->
    <div class="flex flex-col items-center">
      <p class="text-4xl font-extrabold tabular-nums tracking-tight">{{ clock }}</p>
      <button @click="punch" :disabled="busy || done"
              class="mt-5 w-40 h-40 rounded-full flex flex-col items-center justify-center gap-1 text-lg font-bold shadow-xl active:scale-95 transition-transform disabled:opacity-60"
              :class="done ? 'bg-muted text-muted-foreground' : 'bg-primary text-primary-foreground'">
        <svg class="w-9 h-9" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        {{ busy ? '...' : buttonLabel }}
      </button>
      <p v-if="msg" class="mt-3 text-sm font-medium" :class="msgError ? 'text-red-600' : 'text-green-600'">{{ msg }}</p>
    </div>

    <!-- Hôm nay -->
    <div class="mt-8 rounded-2xl bg-card border border-border p-4 grid grid-cols-3 text-center">
      <div>
        <p class="text-[11px] text-muted-foreground">Giờ vào</p>
        <p class="font-bold mt-0.5">{{ hhmm(today?.check_in_time) }}</p>
      </div>
      <div class="border-x border-border">
        <p class="text-[11px] text-muted-foreground">Giờ ra</p>
        <p class="font-bold mt-0.5">{{ hhmm(today?.check_out_time) }}</p>
      </div>
      <div>
        <p class="text-[11px] text-muted-foreground">Số giờ</p>
        <p class="font-bold mt-0.5">{{ today?.total_work_hours ? Number(today.total_work_hours).toFixed(1) + 'h' : '—' }}</p>
      </div>
    </div>

    <!-- Lịch sử tháng này -->
    <h2 class="font-bold text-sm mt-6 mb-2">Tháng này</h2>
    <div v-if="monthRecords.length === 0" class="rounded-2xl bg-card border border-border p-4 text-center text-sm text-muted-foreground">
      Chưa có dữ liệu chấm công
    </div>
    <div v-for="r in monthRecords" :key="r.id"
         class="rounded-2xl bg-card border border-border p-3.5 mb-2 flex items-center justify-between">
      <div>
        <p class="text-sm font-semibold">{{ dmy(r.attendance_date) }}</p>
        <p class="text-xs text-muted-foreground">
          {{ hhmm(r.check_in_time) }} → {{ hhmm(r.check_out_time) }}
        </p>
      </div>
      <span class="text-[11px] font-bold px-2.5 py-1 rounded-full" :class="attChip(r.status)">{{ attLabel(r.status) }}</span>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { authService } from '../../services/authService';
import { attendanceService } from '../../services/attendanceService';
import { hhmm, dmy, attChip, attLabel, todayISO } from './mformat';

const empId = authService.getUser()?.employee_id;
const records = ref([]);
const busy = ref(false);
const msg = ref('');
const msgError = ref(false);

const todayLabel = new Date().toLocaleDateString('vi-VN', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });

const clock = ref('');
const tick = () => { clock.value = new Date().toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit', second: '2-digit' }); };
tick();
const timer = setInterval(tick, 1000);
onUnmounted(() => clearInterval(timer));

const today = computed(() => records.value.find(r => String(r.attendance_date || '').slice(0, 10) === todayISO()));
const done = computed(() => !!(today.value && today.value.check_out_time));
const buttonLabel = computed(() => !today.value ? 'Chấm công vào' : (done.value ? 'Đã hoàn tất' : 'Chấm công ra'));

const monthRecords = computed(() => {
  const ym = todayISO().slice(0, 7);
  return records.value
    .filter(r => String(r.attendance_date || '').slice(0, 7) === ym)
    .sort((a, b) => String(b.attendance_date).localeCompare(String(a.attendance_date)));
});

const load = async () => {
  if (!empId) return;
  try {
    // Chỉ tháng hiện tại (backend lọc) — màn này hiển thị "Tháng này", không cần
    // tải toàn bộ lịch sử nhiều trang.
    const _now = new Date();
    const d = await attendanceService.getRecords({ employee_id: empId, month: _now.getMonth() + 1, year: _now.getFullYear(), per_page: 200 });
    records.value = Array.isArray(d) ? d : (d?.items || []);
  } catch { /* giữ list cũ */ }
};

const punch = async () => {
  if (!empId || busy.value || done.value) return;
  busy.value = true;
  msg.value = '';
  try {
    if (!today.value) {
      await attendanceService.checkIn(empId, { source: 'mobile-web' });
      msg.value = 'Chấm công vào thành công!';
    } else {
      await attendanceService.checkOut(empId, { source: 'mobile-web' });
      msg.value = 'Chấm công ra thành công!';
    }
    msgError.value = false;
    await load();
  } catch (e) {
    msgError.value = true;
    msg.value = e.response?.data?.message || 'Chấm công thất bại';
  } finally {
    busy.value = false;
  }
};

onMounted(load);
</script>
