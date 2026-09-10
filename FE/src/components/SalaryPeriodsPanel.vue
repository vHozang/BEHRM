<template>
  <BaseCard>
    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <h3 class="font-semibold">Quản lý kỳ lương</h3>
        <p class="mt-1 text-xs text-muted-foreground">Chỉ kỳ OPEN được sửa hoặc xóa; kỳ có bảng lương không thể xóa.</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <BaseButton variant="outline" @click="$emit('reload')">Tải lại</BaseButton>
        <BaseButton @click="openCurrentMonth">Tạo kỳ tháng hiện tại</BaseButton>
        <BaseButton variant="outline" @click="openCreate">+ Kỳ tùy chọn</BaseButton>
      </div>
    </div>
    <div class="overflow-x-auto rounded-xl border border-border">
      <table class="w-full min-w-[780px] text-sm">
        <thead class="bg-muted/40 text-left text-xs uppercase text-muted-foreground"><tr>
          <th class="px-3 py-2.5">Mã kỳ</th><th class="px-3 py-2.5">Tên kỳ</th><th class="px-3 py-2.5">Pháp nhân</th>
          <th class="px-3 py-2.5">Từ ngày</th><th class="px-3 py-2.5">Đến ngày</th><th class="px-3 py-2.5">Trạng thái</th><th class="px-3 py-2.5"></th>
        </tr></thead>
        <tbody>
          <tr v-for="period in periods" :key="period.id" class="border-t border-border/70">
            <td class="px-3 py-3 font-mono text-xs">{{ period.period_code }}</td>
            <td class="px-3 py-3 font-medium">{{ period.period_name }}</td>
            <td class="px-3 py-3">{{ period.legal_entity_name || `#${period.legal_entity_id}` }}</td>
            <td class="px-3 py-3">{{ dateOnly(period.start_date) }}</td><td class="px-3 py-3">{{ dateOnly(period.end_date) }}</td>
            <td class="px-3 py-3"><span class="rounded-full bg-muted px-2 py-1 text-xs font-semibold">{{ statusLabel(period.status) }}</span></td>
            <td class="px-3 py-3 text-right whitespace-nowrap">
              <button v-if="period.status === 'OPEN'" class="text-xs font-semibold text-primary hover:underline" @click="edit(period)">Sửa</button>
              <button v-if="period.status === 'OPEN'" class="ml-3 text-xs font-semibold text-destructive hover:underline" @click="remove(period)">Xóa</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <BaseModal v-model="modalOpen" :title="form.id ? 'Sửa kỳ lương' : 'Tạo kỳ lương'">
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <BaseInput v-model="form.period_code" label="Mã kỳ" required />
        <BaseInput v-model="form.period_name" label="Tên kỳ" required />
        <BaseSelect v-if="entityOptions.length > 1" v-model="form.legal_entity_id" label="Pháp nhân" :options="entityOptions" required />
        <BaseSelect v-model="form.period_type" label="Loại kỳ" :options="periodTypeOptions" required />
        <BaseInput v-model="form.start_date" type="date" label="Từ ngày" required />
        <BaseInput v-model="form.end_date" type="date" label="Đến ngày" required />
      </div>
      <p v-if="formError" class="mt-4 rounded-lg bg-destructive/10 p-3 text-sm text-destructive">{{ formError }}</p>
      <template #footer>
        <BaseButton variant="outline" :disabled="saving" @click="modalOpen = false">Hủy</BaseButton>
        <BaseButton :disabled="saving" @click="save">{{ saving ? 'Đang lưu...' : 'Lưu kỳ lương' }}</BaseButton>
      </template>
    </BaseModal>
  </BaseCard>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import BaseButton from './BaseButton.vue';
import BaseCard from './BaseCard.vue';
import BaseInput from './BaseInput.vue';
import BaseModal from './BaseModal.vue';
import BaseSelect from './BaseSelect.vue';
import { salaryService } from '../services/salaryService';
import { legalEntityService } from '../services/legalEntityService';
import { authService } from '../services/authService';
import { useToast } from '../composables/useToast';

const props = defineProps({ periods: { type: Array, default: () => [] } });
const emit = defineEmits(['reload']);
const toast = useToast();
const entities = ref([]);
const modalOpen = ref(false);
const saving = ref(false);
const formError = ref('');
const form = ref({});
const currentUser = authService.getUser() || {};
const entityOptions = computed(() => {
  const rows = entities.value.length ? entities.value : [{ id: currentUser.legal_entity_id, name: currentUser.legal_entity_name || 'Pháp nhân hiện tại' }];
  return rows.filter((item) => item.id).map((item) => ({ value: String(item.id), label: item.name }));
});
const periodTypeOptions = [{ value: 'MONTHLY', label: 'Theo tháng' }, { value: 'BONUS', label: 'Thưởng/đợt' }, { value: 'OTHER', label: 'Khác' }];
const blank = () => ({ period_code: '', period_name: '', period_type: 'MONTHLY', start_date: '', end_date: '', legal_entity_id: String(currentUser.legal_entity_id || entityOptions.value[0]?.value || '') });
const dateOnly = (value) => String(value || '').slice(0, 10);
const statusLabel = (value) => ({ OPEN: 'Đang mở', 'CHỜ_DUYỆT': 'Chờ duyệt', CLOSED: 'Đã chốt', PAID: 'Đã trả' }[value] || value);
const apiError = (error) => {
  const errors = error.response?.data?.data?.errors;
  return errors ? Object.values(errors).flat().join(' ') : (error.response?.data?.message || 'Không thể lưu kỳ lương');
};

const openCreate = () => { form.value = blank(); formError.value = ''; modalOpen.value = true; };
const openCurrentMonth = async () => {
  try {
    const month = new Date().toISOString().slice(0, 7);
    const suggestion = await salaryService.suggestPeriod(month, currentUser.legal_entity_id || entityOptions.value[0]?.value);
    if (suggestion.existing_period_id) {
      toast.error('Kỳ tháng hiện tại đã tồn tại trong pháp nhân này');
      return;
    }
    form.value = { ...blank(), ...suggestion, legal_entity_id: String(suggestion.legal_entity_id) };
    modalOpen.value = true;
  } catch (error) { toast.error(apiError(error)); }
};
const edit = (period) => {
  form.value = { ...period, start_date: dateOnly(period.start_date), end_date: dateOnly(period.end_date), legal_entity_id: String(period.legal_entity_id) };
  formError.value = '';
  modalOpen.value = true;
};
const save = async () => {
  saving.value = true; formError.value = '';
  try {
    const payload = { ...form.value, legal_entity_id: Number(form.value.legal_entity_id) };
    delete payload.id; delete payload.status; delete payload.legal_entity_name; delete payload.salary_details_count;
    if (form.value.id) await salaryService.updatePeriod(form.value.id, payload);
    else await salaryService.createPeriod(payload);
    modalOpen.value = false; toast.success('Đã lưu kỳ lương'); emit('reload');
  } catch (error) { formError.value = apiError(error); }
  finally { saving.value = false; }
};
const remove = async (period) => {
  if (!window.confirm(`Xóa kỳ ${period.period_code}?`)) return;
  try { await salaryService.deletePeriod(period.id); toast.success('Đã xóa kỳ lương'); emit('reload'); }
  catch (error) { toast.error(apiError(error)); }
};

onMounted(async () => {
  if (!authService.getAccess().full) return;
  try { entities.value = await legalEntityService.getAll({ status: 'ACTIVE' }); } catch { entities.value = []; }
});
</script>
