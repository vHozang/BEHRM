<template>
  <div class="px-4 pt-8">
    <h1 class="text-lg font-bold mb-4">Đơn từ của tôi</h1>

    <!-- Filter chips -->
    <div class="flex gap-2 mb-4 overflow-x-auto">
      <button v-for="f in filters" :key="f.value" @click="filter = f.value"
              class="px-3.5 py-1.5 rounded-full text-xs font-semibold whitespace-nowrap border transition-colors"
              :class="filter === f.value ? 'bg-primary text-primary-foreground border-primary' : 'bg-card text-muted-foreground border-border'">
        {{ f.label }}
      </button>
    </div>

    <!-- List -->
    <div v-if="filtered.length === 0" class="rounded-2xl bg-card border border-border p-6 text-center text-sm text-muted-foreground">
      Không có đơn nào
    </div>
    <div v-for="r in filtered" :key="r.id" class="rounded-2xl bg-card border border-border p-4 mb-2.5">
      <div class="flex items-start justify-between gap-2">
        <div>
          <p class="text-sm font-bold">{{ typeName(r.leave_type_id) }}</p>
          <p class="text-xs text-muted-foreground mt-0.5">{{ dmy(r.start_date) }} – {{ dmy(r.end_date) }} · {{ Number(r.total_days) }} ngày</p>
        </div>
        <span class="text-[11px] font-bold px-2.5 py-1 rounded-full shrink-0" :class="statusChip(r.status)">
          {{ statusLabel(r.status) }}
        </span>
      </div>
      <p v-if="r.reason" class="text-xs text-muted-foreground mt-2 line-clamp-2">{{ r.reason }}</p>
      <button v-if="r.status === 'pending'" @click="cancel(r)"
              class="mt-2.5 text-xs font-semibold text-red-600 active:opacity-60">
        Hủy đơn
      </button>
    </div>

    <!-- FAB tạo đơn -->
    <button @click="openForm"
            class="fixed bottom-24 right-4 z-40 w-14 h-14 rounded-full bg-primary text-primary-foreground shadow-xl flex items-center justify-center active:scale-95 transition-transform">
      <svg class="w-7 h-7" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
      </svg>
    </button>

    <!-- Bottom-sheet form -->
    <div v-if="formOpen" class="fixed inset-0 z-50 bg-black/50" @click.self="formOpen = false">
      <div class="absolute bottom-0 inset-x-0 bg-card rounded-t-3xl p-5 max-h-[85vh] overflow-y-auto"
           style="padding-bottom: calc(env(safe-area-inset-bottom) + 20px)">
        <div class="w-10 h-1 rounded-full bg-muted-foreground/30 mx-auto mb-4"></div>
        <h2 class="font-bold mb-4">Tạo đơn mới</h2>

        <label class="block text-xs font-semibold text-muted-foreground mb-1">Loại đơn</label>
        <select v-model="form.leave_type_id" class="w-full rounded-xl border border-border bg-background p-3 text-sm mb-3">
          <option v-for="t in types" :key="t.id" :value="t.id">{{ t.leave_type_name }}</option>
        </select>

        <label class="block text-xs font-semibold text-muted-foreground mb-1">Thời lượng</label>
        <div class="flex gap-2 mb-3">
          <button v-for="d in [{ v: 'full_day', l: 'Cả ngày' }, { v: 'half_day', l: 'Nửa ngày' }]" :key="d.v"
                  @click="form.duration_type = d.v"
                  class="flex-1 py-2.5 rounded-xl text-sm font-semibold border transition-colors"
                  :class="form.duration_type === d.v ? 'bg-primary text-primary-foreground border-primary' : 'bg-background border-border'">
            {{ d.l }}
          </button>
        </div>

        <div class="grid grid-cols-2 gap-3 mb-3">
          <div>
            <label class="block text-xs font-semibold text-muted-foreground mb-1">Từ ngày</label>
            <input v-model="form.start_date" type="date" class="w-full rounded-xl border border-border bg-background p-3 text-sm" />
          </div>
          <div>
            <label class="block text-xs font-semibold text-muted-foreground mb-1">Đến ngày</label>
            <input v-model="form.end_date" type="date" :min="form.start_date"
                   :disabled="form.duration_type === 'half_day'"
                   class="w-full rounded-xl border border-border bg-background p-3 text-sm disabled:opacity-50" />
          </div>
        </div>

        <label class="block text-xs font-semibold text-muted-foreground mb-1">Lý do</label>
        <textarea v-model="form.reason" rows="2" placeholder="Nhập lý do..."
                  class="w-full rounded-xl border border-border bg-background p-3 text-sm mb-4"></textarea>

        <p v-if="formError" class="text-xs text-red-600 mb-3">{{ formError }}</p>

        <button @click="submit" :disabled="saving"
                class="w-full py-3.5 rounded-xl bg-primary text-primary-foreground font-bold active:scale-[0.98] transition-transform disabled:opacity-60">
          {{ saving ? 'Đang gửi...' : `Gửi đơn (${totalDays} ngày)` }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { authService } from '../../services/authService';
import { leaveService } from '../../services/leaveService';
import { dmy, statusChip, statusLabel, todayISO } from './mformat';

const empId = authService.getUser()?.employee_id;
const requests = ref([]);
const types = ref([]);
const filter = ref('all');
const formOpen = ref(false);
const saving = ref(false);
const formError = ref('');

const filters = [
  { value: 'all', label: 'Tất cả' },
  { value: 'pending', label: 'Chờ duyệt' },
  { value: 'approved', label: 'Đã duyệt' },
  { value: 'rejected', label: 'Từ chối' },
];

const filtered = computed(() =>
  (filter.value === 'all' ? requests.value : requests.value.filter(r => r.status === filter.value)));

const typeName = (id) => types.value.find(t => Number(t.id) === Number(id))?.leave_type_name || 'Đơn nghỉ';

const form = ref({ leave_type_id: '', duration_type: 'full_day', start_date: todayISO(), end_date: todayISO(), reason: '' });

const totalDays = computed(() => {
  if (form.value.duration_type === 'half_day') return 0.5;
  const a = new Date(form.value.start_date), b = new Date(form.value.end_date);
  const d = Math.round((b - a) / 86400000) + 1;
  return d > 0 ? d : 0;
});

const openForm = () => {
  formError.value = '';
  form.value = { leave_type_id: types.value[0]?.id || '', duration_type: 'full_day', start_date: todayISO(), end_date: todayISO(), reason: '' };
  formOpen.value = true;
};

const load = async () => {
  if (!empId) return;
  try {
    const d = await leaveService.getRequests({ employee_id: empId });
    requests.value = (Array.isArray(d) ? d : (d?.items || []))
      .sort((a, b) => String(b.start_date).localeCompare(String(a.start_date)));
  } catch { /* giữ list cũ */ }
};

const submit = async () => {
  formError.value = '';
  if (!form.value.leave_type_id) { formError.value = 'Chọn loại đơn'; return; }
  if (totalDays.value <= 0) { formError.value = 'Khoảng ngày không hợp lệ'; return; }
  saving.value = true;
  try {
    await leaveService.createRequest({
      employee_id: empId,
      leave_type_id: form.value.leave_type_id,
      start_date: form.value.start_date,
      end_date: form.value.duration_type === 'half_day' ? form.value.start_date : form.value.end_date,
      total_days: totalDays.value,
      duration_type: form.value.duration_type,
      reason: form.value.reason,
    });
    formOpen.value = false;
    await load();
  } catch (e) {
    const errs = e.response?.data?.data?.errors;
    formError.value = errs ? Object.values(errs).flat().join(' ') : (e.response?.data?.message || 'Gửi đơn thất bại');
  } finally {
    saving.value = false;
  }
};

const cancel = async (r) => {
  if (!window.confirm('Hủy đơn này?')) return;
  try {
    await leaveService.cancel(r.id);
    await load();
  } catch (e) {
    window.alert(e.response?.data?.message || 'Không hủy được đơn');
  }
};

onMounted(async () => {
  load();
  try {
    const t = await leaveService.getTypes();
    types.value = Array.isArray(t) ? t : (t?.items || []);
  } catch { /* dropdown rỗng, submit sẽ báo lỗi */ }
});
</script>
