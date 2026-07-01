<template>
  <div class="space-y-6">
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-2">
      <div>
        <h1 class="text-xl sm:text-2xl font-bold">Người phụ thuộc</h1>
        <p class="text-muted-foreground mt-1">
          Đăng ký người phụ thuộc để tính giảm trừ gia cảnh khi tính thuế TNCN
        </p>
      </div>
      <BaseButton
        @click="openCreateModal"
        :disabled="!selectedEmployeeId"
        data-testid="button-add-dependent"
        class="w-full sm:w-auto"
      >
        + Thêm người phụ thuộc
      </BaseButton>
    </div>

    <div v-if="error" class="bg-destructive/10 border border-destructive/20 rounded-lg p-4">
      <p class="text-destructive font-medium">Lỗi kết nối API:</p>
      <p class="text-destructive/80 text-sm mt-1">{{ error }}</p>
    </div>

    <BaseCard>
      <div class="flex flex-col sm:flex-row sm:items-end gap-4">
        <div class="flex-1">
          <label class="block text-sm font-medium text-foreground mb-2">Nhân viên</label>
          <select
            v-model="selectedEmployeeId"
            class="w-full px-4 py-2.5 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
            data-testid="select-employee"
          >
            <option value="">-- Chọn nhân viên --</option>
            <option v-for="opt in employeeOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
          </select>
        </div>
        <div class="text-sm text-muted-foreground sm:pb-3" v-if="selectedEmployeeId">
          Đang giảm trừ: <span class="font-medium text-foreground">{{ activeCount }}</span> người phụ thuộc
          ({{ formatCurrency(activeCount * PER_DEPENDENT) }}/tháng)
        </div>
      </div>
    </BaseCard>

    <BaseCard title="Danh sách người phụ thuộc">
      <div v-if="!selectedEmployeeId" class="text-center py-10 text-muted-foreground">
        Chọn một nhân viên để xem và quản lý người phụ thuộc.
      </div>
      <div v-else-if="loading" class="text-center py-8 text-muted-foreground">Đang tải dữ liệu...</div>
      <div v-else-if="dependents.length === 0" class="text-center py-10 text-muted-foreground">
        Nhân viên này chưa đăng ký người phụ thuộc nào.
      </div>
      <BaseTable
        v-else
        :columns="columns"
        :data="dependents"
        test-id="table-dependents"
      >
        <template #cell-full_name="{ item }">
          <span class="font-medium">{{ item.full_name || '-' }}</span>
        </template>

        <template #cell-date_of_birth="{ item }">
          <span class="text-sm">{{ formatDate(item.date_of_birth) }}</span>
        </template>

        <template #cell-period="{ item }">
          <span class="text-sm text-muted-foreground">
            {{ formatDate(item.start_date) }} → {{ item.end_date ? formatDate(item.end_date) : 'nay' }}
          </span>
        </template>

        <template #cell-status="{ item }">
          <span
            v-if="isActive(item)"
            class="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300"
          >
            Đang giảm trừ
          </span>
          <span
            v-else
            class="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-muted text-muted-foreground"
          >
            Ngừng
          </span>
        </template>

        <template #actions="{ item }">
          <div class="flex items-center gap-2">
            <button
              @click="openEditModal(item)"
              class="p-1.5 rounded hover:bg-accent text-muted-foreground hover:text-foreground"
              title="Sửa"
              data-testid="button-edit-dependent"
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
              data-testid="button-delete-dependent"
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
      :title="editingId ? 'Sửa người phụ thuộc' : 'Thêm người phụ thuộc'"
      data-testid="modal-dependent"
    >
      <div class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-foreground mb-2">Họ tên <span class="text-destructive">*</span></label>
          <input
            v-model="form.full_name"
            type="text"
            class="w-full px-4 py-2.5 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
            placeholder="VD: Nguyễn Văn A"
            data-testid="input-full-name"
          />
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-foreground mb-2">Quan hệ <span class="text-destructive">*</span></label>
            <select
              v-model="form.relationship"
              class="w-full px-4 py-2.5 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
              data-testid="select-relationship"
            >
              <option v-for="r in relationshipOptions" :key="r" :value="r">{{ r }}</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-foreground mb-2">Ngày sinh</label>
            <input
              v-model="form.date_of_birth"
              type="date"
              class="w-full px-4 py-2.5 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
              data-testid="input-dob"
            />
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium text-foreground mb-2">Mã số thuế người phụ thuộc</label>
          <input
            v-model="form.tax_code"
            type="text"
            class="w-full px-4 py-2.5 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
            placeholder="Không bắt buộc"
            data-testid="input-tax-code"
          />
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-foreground mb-2">Bắt đầu giảm trừ</label>
            <input
              v-model="form.start_date"
              type="date"
              class="w-full px-4 py-2.5 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
              data-testid="input-start-date"
            />
          </div>
          <div>
            <label class="block text-sm font-medium text-foreground mb-2">Kết thúc (nếu có)</label>
            <input
              v-model="form.end_date"
              type="date"
              class="w-full px-4 py-2.5 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
              data-testid="input-end-date"
            />
          </div>
        </div>

        <div class="flex items-center gap-2">
          <input
            id="dep_active"
            v-model="form.active"
            type="checkbox"
            class="h-4 w-4 rounded border-input text-primary focus:ring-ring"
            data-testid="checkbox-active"
          />
          <label for="dep_active" class="text-sm text-foreground">Đang hiệu lực (được tính giảm trừ gia cảnh)</label>
        </div>
      </div>

      <div v-if="formError" class="mt-4 p-3 bg-destructive/10 border border-destructive/20 rounded-lg">
        <p class="text-destructive text-sm">{{ formError }}</p>
      </div>

      <template #footer>
        <BaseButton variant="outline" @click="closeModal" :disabled="saving">Hủy</BaseButton>
        <BaseButton @click="handleSave" :disabled="saving" data-testid="button-submit-dependent">
          {{ saving ? 'Đang lưu...' : 'Lưu' }}
        </BaseButton>
      </template>
    </BaseModal>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import BaseCard from '../components/BaseCard.vue';
import BaseButton from '../components/BaseButton.vue';
import BaseTable from '../components/BaseTable.vue';
import BaseModal from '../components/BaseModal.vue';
import { dependentService } from '../services/dependentService';
import { employeeService } from '../services/employeeService';
import { settingsService } from '../services/settingsService';
import { useToast } from '../composables/useToast';

const toast = useToast();

// VN per-dependent monthly deduction (giảm trừ gia cảnh) — from tenant config,
// fallback to the 2026 statutory 4.4tr.
const PER_DEPENDENT = ref(4400000);

const loading = ref(false);
const saving = ref(false);
const processing = ref(false);
const error = ref('');
const formError = ref('');

const employees = ref([]);
const selectedEmployeeId = ref('');
const dependents = ref([]);

const showModal = ref(false);
const editingId = ref(null);

const relationshipOptions = ['Con', 'Vợ', 'Chồng', 'Bố', 'Mẹ', 'Khác'];

const form = ref({
  full_name: '',
  relationship: 'Con',
  date_of_birth: '',
  tax_code: '',
  start_date: '',
  end_date: '',
  active: true
});

const columns = [
  { key: 'full_name', label: 'Họ tên' },
  { key: 'relationship', label: 'Quan hệ' },
  { key: 'date_of_birth', label: 'Ngày sinh' },
  { key: 'period', label: 'Thời gian giảm trừ' },
  { key: 'status', label: 'Trạng thái' }
];

const employeeOptions = computed(() =>
  employees.value.map((e) => ({
    label: e.full_name ? `${e.full_name}${e.employee_code ? ` (${e.employee_code})` : ''}` : `NV #${e.id}`,
    value: String(e.id)
  }))
);

// Mirror the payroll engine: active = status null or not in the inactive set.
const INACTIVE = ['false', '0', 'inactive', 'expired', 'deleted'];
const isActive = (item) => {
  const s = item?.status;
  if (s === null || s === undefined || s === '') return true;
  return !INACTIVE.includes(String(s).toLowerCase());
};

const activeCount = computed(() => dependents.value.filter(isActive).length);

const formatDate = (date) => {
  if (!date) return '-';
  const d = new Date(date);
  return Number.isNaN(d.getTime()) ? date : d.toLocaleDateString('vi-VN');
};

const formatCurrency = (n) =>
  new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND', maximumFractionDigits: 0 }).format(n || 0);

const apiErrorMessage = (err, fallback) =>
  err?.response?.data?.message || err?.response?.data?.error || err?.message || fallback;

const loadEmployees = async () => {
  try {
    const data = await employeeService.getAll();
    employees.value = Array.isArray(data) ? data : (data?.items || data?.data || []);
  } catch (err) {
    console.error('Error loading employees:', err);
  }
};

const loadDependents = async () => {
  if (!selectedEmployeeId.value) {
    dependents.value = [];
    return;
  }
  loading.value = true;
  error.value = '';
  try {
    dependents.value = await dependentService.getAll({ employee_id: selectedEmployeeId.value });
  } catch (err) {
    console.error('Error loading dependents:', err);
    error.value = apiErrorMessage(err, 'Không thể tải danh sách người phụ thuộc');
  } finally {
    loading.value = false;
  }
};

watch(selectedEmployeeId, loadDependents);

const resetForm = () => {
  form.value = {
    full_name: '',
    relationship: 'Con',
    date_of_birth: '',
    tax_code: '',
    start_date: '',
    end_date: '',
    active: true
  };
  editingId.value = null;
  formError.value = '';
};

const openCreateModal = () => {
  if (!selectedEmployeeId.value) return;
  resetForm();
  showModal.value = true;
};

const openEditModal = (item) => {
  editingId.value = item.id;
  form.value = {
    full_name: item.full_name || '',
    relationship: item.relationship || 'Con',
    date_of_birth: String(item.date_of_birth || '').slice(0, 10),
    tax_code: item.tax_code || '',
    start_date: String(item.start_date || '').slice(0, 10),
    end_date: String(item.end_date || '').slice(0, 10),
    active: isActive(item)
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
  if (!form.value.full_name.trim()) {
    formError.value = 'Vui lòng nhập họ tên';
    return;
  }
  if (!form.value.relationship) {
    formError.value = 'Vui lòng chọn quan hệ';
    return;
  }

  saving.value = true;
  try {
    const payload = {
      employee_id: parseInt(selectedEmployeeId.value, 10),
      full_name: form.value.full_name.trim(),
      relationship: form.value.relationship,
      date_of_birth: form.value.date_of_birth || null,
      tax_code: form.value.tax_code.trim() || null,
      deduction_percent: 100,
      start_date: form.value.start_date || null,
      end_date: form.value.end_date || null,
      status: form.value.active ? 'ACTIVE' : 'INACTIVE'
    };
    if (editingId.value) {
      await dependentService.update(editingId.value, payload);
      toast.success('Đã cập nhật người phụ thuộc');
    } else {
      await dependentService.create(payload);
      toast.success('Đã thêm người phụ thuộc');
    }
    closeModal();
    await loadDependents();
  } catch (err) {
    console.error('Error saving dependent:', err);
    formError.value = apiErrorMessage(err, 'Có lỗi xảy ra khi lưu');
  } finally {
    saving.value = false;
  }
};

const remove = async (item) => {
  if (processing.value) return;
  if (!confirm(`Xóa người phụ thuộc "${item.full_name}"?`)) return;
  processing.value = true;
  try {
    await dependentService.remove(item.id);
    toast.success('Đã xóa người phụ thuộc');
    await loadDependents();
  } catch (err) {
    console.error('Error deleting dependent:', err);
    toast.error(apiErrorMessage(err, 'Có lỗi xảy ra khi xóa'));
  } finally {
    processing.value = false;
  }
};

const loadConfig = async () => {
  const map = await settingsService.getEffectiveMap();
  const v = Number(map['payroll.dependent_deduction']);
  if (!Number.isNaN(v) && v > 0) PER_DEPENDENT.value = v;
};

onMounted(() => { loadEmployees(); loadConfig(); });
</script>
