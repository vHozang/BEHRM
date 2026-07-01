<template>
  <div class="space-y-6">
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-2">
      <div>
        <h1 class="text-xl sm:text-2xl font-bold">Lịch nghỉ lễ</h1>
        <p class="text-muted-foreground mt-1">
          Ngày nghỉ lễ được loại trừ khi tính ngày công và ngày phép (Điều 112 BLLĐ 2019)
        </p>
      </div>
      <div class="flex flex-wrap gap-2 w-full sm:w-auto">
        <BaseButton variant="outline" @click="openCreateModal" data-testid="button-add-holiday">
          + Thêm ngày lễ
        </BaseButton>
        <BaseButton @click="seedYear" :disabled="seeding" data-testid="button-seed-vn">
          {{ seeding ? 'Đang tạo...' : `Tạo lịch lễ chuẩn VN ${year}` }}
        </BaseButton>
      </div>
    </div>

    <div v-if="error" class="bg-destructive/10 border border-destructive/20 rounded-lg p-4">
      <p class="text-destructive font-medium">Lỗi kết nối API:</p>
      <p class="text-destructive/80 text-sm mt-1">{{ error }}</p>
    </div>

    <BaseCard>
      <div class="flex flex-col sm:flex-row sm:items-end gap-4">
        <div>
          <label class="block text-sm font-medium text-foreground mb-2">Năm</label>
          <select
            v-model.number="year"
            class="w-full sm:w-44 px-4 py-2.5 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
            data-testid="select-year"
          >
            <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
          </select>
        </div>
        <div class="text-sm text-muted-foreground sm:pb-3">
          <span class="font-medium text-foreground">{{ holidaysForYear.length }}</span> ngày nghỉ lễ trong năm {{ year }}
          <span v-if="totalDays !== holidaysForYear.length" class="ml-1">(luật định: 11 ngày)</span>
        </div>
      </div>
    </BaseCard>

    <BaseCard :title="`Ngày nghỉ lễ năm ${year}`">
      <div v-if="loading" class="text-center py-8 text-muted-foreground">Đang tải dữ liệu...</div>
      <div v-else-if="holidaysForYear.length === 0" class="text-center py-10">
        <p class="text-muted-foreground">Chưa có ngày nghỉ lễ nào cho năm {{ year }}.</p>
        <p class="text-sm text-muted-foreground mt-1">
          Bấm <span class="font-medium text-foreground">"Tạo lịch lễ chuẩn VN {{ year }}"</span> để tạo nhanh các ngày lễ theo luật.
        </p>
      </div>
      <BaseTable
        v-else
        :columns="columns"
        :data="holidaysForYear"
        test-id="table-holidays"
      >
        <template #cell-holiday_date="{ item }">
          <div>
            <span class="font-medium">{{ formatDate(item.holiday_date) }}</span>
            <span class="block text-xs text-muted-foreground">{{ weekdayName(item.holiday_date) }}</span>
          </div>
        </template>

        <template #cell-holiday_name="{ item }">
          <span class="font-medium">{{ item.holiday_name || '-' }}</span>
        </template>

        <template #cell-is_recurring="{ item }">
          <span
            v-if="isRecurring(item)"
            class="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300"
          >
            Hằng năm
          </span>
          <span v-else class="text-xs text-muted-foreground">Theo năm</span>
        </template>

        <template #cell-description="{ item }">
          <span class="text-sm text-muted-foreground">{{ item.description || '-' }}</span>
        </template>

        <template #actions="{ item }">
          <div class="flex items-center gap-2">
            <button
              @click="openEditModal(item)"
              class="p-1.5 rounded hover:bg-accent text-muted-foreground hover:text-foreground"
              title="Sửa"
              data-testid="button-edit-holiday"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
              </svg>
            </button>
            <button
              @click="remove(item)"
              class="p-1.5 rounded hover:bg-red-100 dark:hover:bg-red-900 text-red-600 dark:text-red-400 disabled:opacity-50"
              title="Xóa"
              :disabled="processing"
              data-testid="button-delete-holiday"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
              </svg>
            </button>
          </div>
        </template>
      </BaseTable>
    </BaseCard>

    <!-- Create / Edit modal -->
    <BaseModal
      v-model="showModal"
      :title="editingId ? 'Sửa ngày nghỉ lễ' : 'Thêm ngày nghỉ lễ'"
      data-testid="modal-holiday"
    >
      <div class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-foreground mb-2">Tên ngày lễ <span class="text-destructive">*</span></label>
          <input
            v-model="form.holiday_name"
            type="text"
            class="w-full px-4 py-2.5 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
            placeholder="VD: Quốc khánh"
            data-testid="input-holiday-name"
          />
        </div>

        <div>
          <label class="block text-sm font-medium text-foreground mb-2">Ngày <span class="text-destructive">*</span></label>
          <input
            v-model="form.holiday_date"
            type="date"
            class="w-full px-4 py-2.5 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
            data-testid="input-holiday-date"
          />
        </div>

        <div class="flex items-center gap-2">
          <input
            id="is_recurring"
            v-model="form.is_recurring"
            type="checkbox"
            class="h-4 w-4 rounded border-input text-primary focus:ring-ring"
            data-testid="checkbox-recurring"
          />
          <label for="is_recurring" class="text-sm text-foreground">Lặp lại hằng năm (ngày dương lịch cố định)</label>
        </div>

        <div>
          <label class="block text-sm font-medium text-foreground mb-2">Ghi chú</label>
          <textarea
            v-model="form.description"
            rows="2"
            class="w-full px-4 py-2.5 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
            placeholder="Ghi chú (không bắt buộc)"
            data-testid="input-holiday-description"
          ></textarea>
        </div>
      </div>

      <div v-if="formError" class="mt-4 p-3 bg-destructive/10 border border-destructive/20 rounded-lg">
        <p class="text-destructive text-sm">{{ formError }}</p>
      </div>

      <template #footer>
        <BaseButton variant="outline" @click="closeModal" :disabled="saving">Hủy</BaseButton>
        <BaseButton @click="handleSave" :disabled="saving" data-testid="button-submit-holiday">
          {{ saving ? 'Đang lưu...' : 'Lưu' }}
        </BaseButton>
      </template>
    </BaseModal>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import BaseCard from '../components/BaseCard.vue';
import BaseButton from '../components/BaseButton.vue';
import BaseTable from '../components/BaseTable.vue';
import BaseModal from '../components/BaseModal.vue';
import { holidayService } from '../services/holidayService';
import { useToast } from '../composables/useToast';

const toast = useToast();

const loading = ref(false);
const saving = ref(false);
const seeding = ref(false);
const processing = ref(false);
const error = ref('');
const formError = ref('');

const holidays = ref([]);
const year = ref(new Date().getFullYear());

const showModal = ref(false);
const editingId = ref(null);

const form = ref({
  holiday_name: '',
  holiday_date: '',
  is_recurring: false,
  description: ''
});

const columns = [
  { key: 'holiday_date', label: 'Ngày' },
  { key: 'holiday_name', label: 'Tên ngày lễ' },
  { key: 'is_recurring', label: 'Loại' },
  { key: 'description', label: 'Ghi chú' }
];

const yearOptions = computed(() => {
  const current = new Date().getFullYear();
  const set = new Set([current - 1, current, current + 1, current + 2]);
  holidays.value.forEach((h) => {
    const y = yearOf(h.holiday_date);
    if (y) set.add(y);
  });
  return Array.from(set).sort((a, b) => b - a);
});

const yearOf = (date) => {
  if (!date) return null;
  const y = parseInt(String(date).slice(0, 4), 10);
  return Number.isNaN(y) ? null : y;
};

const holidaysForYear = computed(() =>
  holidays.value
    .filter((h) => yearOf(h.holiday_date) === year.value)
    .sort((a, b) => String(a.holiday_date).localeCompare(String(b.holiday_date)))
);

const totalDays = computed(() => holidaysForYear.value.length);

const isRecurring = (item) =>
  item?.is_recurring === true || item?.is_recurring === 't' || item?.is_recurring === 1 || item?.is_recurring === '1';

const WEEKDAYS = ['Chủ nhật', 'Thứ 2', 'Thứ 3', 'Thứ 4', 'Thứ 5', 'Thứ 6', 'Thứ 7'];

const formatDate = (date) => {
  if (!date) return '-';
  const d = new Date(date);
  return Number.isNaN(d.getTime()) ? date : d.toLocaleDateString('vi-VN');
};

const weekdayName = (date) => {
  if (!date) return '';
  const d = new Date(date);
  return Number.isNaN(d.getTime()) ? '' : WEEKDAYS[d.getDay()];
};

const apiErrorMessage = (err, fallback) =>
  err?.response?.data?.message || err?.response?.data?.error || err?.message || fallback;

const loadHolidays = async () => {
  loading.value = true;
  error.value = '';
  try {
    holidays.value = await holidayService.getAll();
  } catch (err) {
    console.error('Error loading holidays:', err);
    error.value = apiErrorMessage(err, 'Không thể tải danh sách ngày lễ');
  } finally {
    loading.value = false;
  }
};

const resetForm = () => {
  form.value = { holiday_name: '', holiday_date: '', is_recurring: false, description: '' };
  editingId.value = null;
  formError.value = '';
};

const openCreateModal = () => {
  resetForm();
  // Pre-fill date to Jan 1 of the selected year for convenience.
  form.value.holiday_date = `${year.value}-01-01`;
  showModal.value = true;
};

const openEditModal = (item) => {
  editingId.value = item.id;
  form.value = {
    holiday_name: item.holiday_name || '',
    holiday_date: String(item.holiday_date || '').slice(0, 10),
    is_recurring: isRecurring(item),
    description: item.description || ''
  };
  formError.value = '';
  showModal.value = true;
};

const closeModal = () => {
  showModal.value = false;
  resetForm();
};

const handleSave = async () => {
  formError.value = '';
  if (!form.value.holiday_name.trim()) {
    formError.value = 'Vui lòng nhập tên ngày lễ';
    return;
  }
  if (!form.value.holiday_date) {
    formError.value = 'Vui lòng chọn ngày';
    return;
  }

  saving.value = true;
  try {
    const payload = {
      holiday_name: form.value.holiday_name.trim(),
      holiday_date: form.value.holiday_date,
      holiday_type: 'NATIONAL',
      is_recurring: form.value.is_recurring,
      description: form.value.description.trim()
    };
    if (editingId.value) {
      await holidayService.update(editingId.value, payload);
      toast.success('Đã cập nhật ngày lễ');
    } else {
      await holidayService.create(payload);
      toast.success('Đã thêm ngày lễ');
    }
    // Jump the year selector to the saved date's year so it stays visible.
    const savedYear = yearOf(form.value.holiday_date);
    if (savedYear) year.value = savedYear;
    closeModal();
    await loadHolidays();
  } catch (err) {
    console.error('Error saving holiday:', err);
    formError.value = apiErrorMessage(err, 'Có lỗi xảy ra khi lưu');
  } finally {
    saving.value = false;
  }
};

const remove = async (item) => {
  if (processing.value) return;
  if (!confirm(`Xóa ngày lễ "${item.holiday_name}" (${formatDate(item.holiday_date)})?`)) return;
  processing.value = true;
  try {
    await holidayService.remove(item.id);
    toast.success('Đã xóa ngày lễ');
    await loadHolidays();
  } catch (err) {
    console.error('Error deleting holiday:', err);
    toast.error(apiErrorMessage(err, 'Có lỗi xảy ra khi xóa'));
  } finally {
    processing.value = false;
  }
};

const seedYear = async () => {
  if (seeding.value) return;
  seeding.value = true;
  try {
    const result = await holidayService.seedVn(year.value);
    const created = Array.isArray(result?.created) ? result.created.length : 0;
    const skipped = Array.isArray(result?.skipped) ? result.skipped.length : 0;
    if (created > 0) {
      toast.success(`Đã tạo ${created} ngày lễ cho năm ${year.value}${skipped ? `, giữ nguyên ${skipped} ngày đã có` : ''}`);
    } else {
      toast.info(`Năm ${year.value} đã có đủ ${skipped} ngày lễ luật định`);
    }
    await loadHolidays();
  } catch (err) {
    console.error('Error seeding holidays:', err);
    toast.error(apiErrorMessage(err, 'Có lỗi xảy ra khi tạo lịch lễ'));
  } finally {
    seeding.value = false;
  }
};

onMounted(loadHolidays);
</script>
