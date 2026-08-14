<template>
  <BaseCard>
    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <div><h3 class="font-semibold">Điều chỉnh lương</h3><p class="mt-1 text-xs text-muted-foreground">Maker–checker: người tạo trình, người khác duyệt, engine áp dụng khi chạy lương.</p></div>
      <BaseButton v-if="canCreate" @click="openCreate">+ Tạo điều chỉnh</BaseButton>
    </div>
    <div class="overflow-x-auto rounded-xl border border-border">
      <table class="w-full min-w-[920px] text-sm">
        <thead class="bg-muted/40 text-left text-xs uppercase text-muted-foreground"><tr>
          <th class="px-3 py-2.5">Nhân viên</th><th class="px-3 py-2.5">Kỳ</th><th class="px-3 py-2.5">Loại</th>
          <th class="px-3 py-2.5">Số tiền</th><th class="px-3 py-2.5">Trạng thái</th><th class="px-3 py-2.5">Ghi chú</th><th class="px-3 py-2.5"></th>
        </tr></thead>
        <tbody>
          <tr v-for="row in rows" :key="row.id" class="border-t border-border/70">
            <td class="px-3 py-3"><p class="font-medium">{{ row.full_name }}</p><p class="text-xs text-muted-foreground">{{ row.employee_code }}</p></td>
            <td class="px-3 py-3">{{ row.period_code }}</td><td class="px-3 py-3">{{ typeLabel(row.adjustment_type) }}</td>
            <td class="px-3 py-3 font-semibold">{{ formatMoney(row.amount) }}</td><td class="px-3 py-3">{{ statusLabel(row.status) }}</td><td class="px-3 py-3">{{ row.note || '—' }}</td>
            <td class="px-3 py-3 text-right whitespace-nowrap">
              <button v-if="canEdit(row)" class="text-xs font-semibold text-primary hover:underline" @click="edit(row)">Sửa</button>
              <button v-if="canEdit(row)" class="ml-3 text-xs font-semibold text-primary hover:underline" @click="submit(row)">Trình</button>
              <button v-if="canEdit(row)" class="ml-3 text-xs font-semibold text-destructive hover:underline" @click="remove(row)">Xóa</button>
              <button v-if="canApprove && row.status === 'SUBMITTED'" class="ml-3 text-xs font-semibold text-emerald-600 hover:underline" @click="approve(row)">Duyệt</button>
              <button v-if="canApprove && row.status === 'SUBMITTED'" class="ml-3 text-xs font-semibold text-destructive hover:underline" @click="reject(row)">Từ chối</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <div v-if="pagination.last_page > 1" class="mt-4 flex items-center justify-between text-sm"><span class="text-muted-foreground">Trang {{ pagination.current_page }}/{{ pagination.last_page }}</span><div class="flex gap-2"><BaseButton variant="outline" :disabled="pagination.current_page <= 1" @click="changePage(-1)">Trước</BaseButton><BaseButton variant="outline" :disabled="pagination.current_page >= pagination.last_page" @click="changePage(1)">Sau</BaseButton></div></div>

    <BaseModal v-model="modalOpen" :title="form.id ? 'Sửa điều chỉnh lương' : 'Tạo điều chỉnh lương'">
      <div class="space-y-4">
        <RemoteEmployeeSelect v-model="form.employee_id" label="Nhân viên" :initial-label="form.employee_label || ''" />
        <BaseSelect v-model="form.paid_period_id" label="Kỳ lương" :options="periodOptions" required />
        <BaseSelect v-model="form.adjustment_type" label="Loại điều chỉnh" :options="typeOptions" required />
        <BaseInput v-model="form.amount" type="number" label="Số tiền" required />
        <label class="block text-sm font-medium">Ghi chú<textarea v-model="form.note" rows="3" class="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2" /></label>
        <p v-if="formError" class="rounded-lg bg-destructive/10 p-3 text-sm text-destructive">{{ formError }}</p>
      </div>
      <template #footer><BaseButton variant="outline" @click="modalOpen = false">Hủy</BaseButton><BaseButton :disabled="saving" @click="save">{{ saving ? 'Đang lưu...' : 'Lưu nháp' }}</BaseButton></template>
    </BaseModal>
  </BaseCard>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import BaseButton from './BaseButton.vue';
import BaseCard from './BaseCard.vue';
import BaseInput from './BaseInput.vue';
import BaseModal from './BaseModal.vue';
import BaseSelect from './BaseSelect.vue';
import RemoteEmployeeSelect from './RemoteEmployeeSelect.vue';
import { salaryService } from '../services/salaryService';
import { authService } from '../services/authService';
import { useToast } from '../composables/useToast';

const props = defineProps({ periods: { type: Array, default: () => [] } });
const toast = useToast();
const rows = ref([]); const modalOpen = ref(false); const saving = ref(false); const formError = ref(''); const form = ref({});
const pagination = reactive({ current_page: 1, last_page: 1, total: 0 });
const actorId = Number(authService.getUser()?.employee_id || authService.getUser()?.id || 0);
const canCreate = computed(() => authService.hasCapability('payroll.adjustments.create'));
const canApprove = computed(() => authService.hasCapability('payroll.adjustments.approve'));
const periodOptions = computed(() => props.periods.filter((period) => period.status === 'OPEN').map((period) => ({ value: String(period.id), label: `${period.period_code} · ${period.period_name}` })));
const typeOptions = [
  { value: 'EARNING', label: 'Tăng thu nhập' }, { value: 'BONUS', label: 'Thưởng' }, { value: 'THANG_13', label: 'Lương tháng 13' },
  { value: 'DEDUCTION', label: 'Khấu trừ' }, { value: 'ADVANCE', label: 'Thu hồi tạm ứng' }, { value: 'OTHER_DEDUCTION', label: 'Khấu trừ khác' },
];
const typeLabel = (value) => typeOptions.find((option) => option.value === value)?.label || value;
const statusLabel = (value) => ({ DRAFT: 'Nháp', SUBMITTED: 'Chờ duyệt', APPROVED: 'Đã duyệt', REJECTED: 'Từ chối', APPLIED: 'Đã áp dụng' }[value] || value);
const formatMoney = (value) => new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND', maximumFractionDigits: 0 }).format(Number(value) || 0);
const apiError = (error) => error.response?.data?.message || 'Không thể xử lý điều chỉnh';
const canEdit = (row) => canCreate.value && row.status === 'DRAFT' && (Number(row.created_by) === actorId || authService.getAccess().full);
const blank = () => ({ employee_id: '', employee_label: '', paid_period_id: periodOptions.value[0]?.value || '', adjustment_type: 'EARNING', amount: '', note: '' });

const load = async () => {
  try {
    const result = await salaryService.getAdjustments({ page: pagination.current_page, per_page: 25 });
    rows.value = result.items; Object.assign(pagination, result.pagination || { current_page: 1, last_page: 1, total: rows.value.length });
  } catch (error) { toast.error(apiError(error)); }
};
const openCreate = () => { form.value = blank(); formError.value = ''; modalOpen.value = true; };
const edit = (row) => { form.value = { ...row, employee_id: String(row.employee_id), employee_label: `${row.employee_code} · ${row.full_name}`, paid_period_id: String(row.paid_period_id) }; modalOpen.value = true; };
const save = async () => {
  if (!form.value.employee_id || !form.value.paid_period_id || Number(form.value.amount) <= 0) { formError.value = 'Chọn nhân viên, kỳ lương và nhập số tiền lớn hơn 0'; return; }
  saving.value = true; formError.value = '';
  try {
    const payload = { employee_id: Number(form.value.employee_id), paid_period_id: Number(form.value.paid_period_id), adjustment_type: form.value.adjustment_type, amount: Number(form.value.amount), note: form.value.note || null };
    if (form.value.id) await salaryService.updateAdjustment(form.value.id, payload); else await salaryService.saveAdjustment(payload);
    modalOpen.value = false; toast.success('Đã lưu nháp điều chỉnh'); await load();
  } catch (error) { formError.value = apiError(error); } finally { saving.value = false; }
};
const submit = async (row) => { try { await salaryService.submitAdjustment(row.id); toast.success('Đã trình điều chỉnh'); await load(); } catch (error) { toast.error(apiError(error)); } };
const approve = async (row) => { try { await salaryService.approveAdjustment(row.id); toast.success('Đã duyệt điều chỉnh'); await load(); } catch (error) { toast.error(apiError(error)); } };
const reject = async (row) => { const reason = window.prompt('Lý do từ chối:'); if (!reason) return; try { await salaryService.rejectAdjustment(row.id, reason); toast.success('Đã từ chối điều chỉnh'); await load(); } catch (error) { toast.error(apiError(error)); } };
const remove = async (row) => { if (!window.confirm('Xóa nháp điều chỉnh này?')) return; try { await salaryService.deleteAdjustment(row.id); toast.success('Đã xóa nháp'); await load(); } catch (error) { toast.error(apiError(error)); } };
const changePage = (delta) => { pagination.current_page += delta; load(); };
onMounted(load);
</script>
