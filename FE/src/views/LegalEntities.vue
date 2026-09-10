<template>
  <div class="space-y-6">
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-2">
      <div>
        <h1 class="text-xl sm:text-2xl font-bold">Chi nhánh / Pháp nhân</h1>
        <p class="text-muted-foreground mt-1">
          Quản lý các công ty/chi nhánh (pháp nhân) trong tổ chức. Nhân viên, hợp đồng và lương đều gắn theo pháp nhân.
        </p>
      </div>
      <BaseButton @click="openCreateModal" data-testid="button-add-entity" class="w-full sm:w-auto">
        + Thêm chi nhánh
      </BaseButton>
    </div>

    <div v-if="error" class="bg-destructive/10 border border-destructive/20 rounded-lg p-4">
      <p class="text-destructive font-medium">Lỗi kết nối API:</p>
      <p class="text-destructive/80 text-sm mt-1">{{ error }}</p>
    </div>

    <BaseCard title="Danh sách chi nhánh / pháp nhân">
      <div v-if="loading" class="text-center py-8 text-muted-foreground">Đang tải dữ liệu...</div>
      <div v-else-if="entities.length === 0" class="text-center py-10 text-muted-foreground">
        Chưa có chi nhánh nào.
      </div>
      <BaseTable
        v-else
        :columns="columns"
        :data="entities"
        test-id="table-entities"
      >
        <template #cell-name="{ item }">
          <div>
            <span class="font-medium">{{ item.name || '-' }}</span>
            <span v-if="item.code" class="block text-xs text-muted-foreground">{{ item.code }}</span>
          </div>
        </template>

        <template #cell-tax_code="{ item }">
          <span class="text-sm">{{ item.tax_code || '-' }}</span>
        </template>

        <template #cell-counts="{ item }">
          <span class="text-sm text-muted-foreground">
            {{ item.employees_count ?? 0 }} NV · {{ item.contracts_count ?? 0 }} HĐ
          </span>
        </template>

        <template #cell-head="{ item }">
          <div class="text-sm">
            <span :class="headName(item) === 'Chưa gán' ? 'font-medium text-amber-700' : 'font-medium text-foreground'">{{ headName(item) }}</span>
            <span v-if="headCode(item)" class="block text-xs text-muted-foreground">{{ headCode(item) }}</span>
          </div>
        </template>

        <template #cell-status="{ item }">
          <span
            :class="[
              'inline-flex items-center px-2 py-0.5 rounded-full text-xs',
              isActive(item)
                ? 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300'
                : 'bg-muted text-muted-foreground'
            ]"
          >
            {{ isActive(item) ? 'Đang hoạt động' : (item.status || 'Ngừng') }}
          </span>
        </template>

        <template #actions="{ item }">
          <div class="flex items-center gap-2">
            <button
              @click="openEditModal(item)"
              class="p-1.5 rounded hover:bg-accent text-muted-foreground hover:text-foreground"
              title="Sửa"
              data-testid="button-edit-entity"
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
              data-testid="button-delete-entity"
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
      :title="editingId ? 'Sửa chi nhánh / pháp nhân' : 'Thêm chi nhánh / pháp nhân'"
      data-testid="modal-entity"
    >
      <div class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-foreground mb-2">Tên công ty / pháp nhân <span class="text-destructive">*</span></label>
          <input
            v-model="form.name"
            type="text"
            class="w-full px-4 py-2.5 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
            placeholder="VD: Công ty TNHH ABC - Chi nhánh Hà Nội"
            data-testid="input-name"
          />
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-foreground mb-2">Mã</label>
            <input
              v-model="form.code"
              type="text"
              class="w-full px-4 py-2.5 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
              placeholder="VD: HN"
              data-testid="input-code"
            />
          </div>
          <div>
            <label class="block text-sm font-medium text-foreground mb-2">Mã số thuế</label>
            <input
              v-model="form.tax_code"
              type="text"
              class="w-full px-4 py-2.5 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
              data-testid="input-tax-code"
            />
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium text-foreground mb-2">Địa chỉ</label>
          <textarea
            v-model="form.address"
            rows="2"
            class="w-full px-4 py-2.5 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
            data-testid="input-address"
          ></textarea>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-foreground mb-2">Người đại diện (ký HĐ — Bên A)</label>
            <input
              v-model="form.representative"
              type="text"
              class="w-full px-4 py-2.5 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
              placeholder="VD: Nguyễn Văn A"
            />
          </div>
          <div>
            <label class="block text-sm font-medium text-foreground mb-2">Chức vụ người đại diện</label>
            <input
              v-model="form.representative_title"
              type="text"
              class="w-full px-4 py-2.5 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
              placeholder="VD: Giám đốc / Tổng Giám đốc"
            />
          </div>
        </div>
        <p class="text-xs text-muted-foreground -mt-2">Hai trường này tự điền vào phần <b>BÊN A</b> khi tạo/in hợp đồng lao động.</p>

        <BaseSelect
          v-model="form.head_employee_id"
          label="Người đứng đầu chi nhánh"
          :options="headOptions"
          :disabled="!editingId"
          :hint="editingId ? 'Chỉ hiển thị nhân viên đang làm việc thuộc đúng chi nhánh này.' : 'Hãy lưu chi nhánh trước, phân nhân viên vào chi nhánh rồi quay lại chọn người đứng đầu.'"
        />

        <div class="flex items-center gap-2">
          <input
            id="entity_active"
            v-model="form.active"
            type="checkbox"
            class="h-4 w-4 rounded border-input text-primary focus:ring-ring"
            data-testid="checkbox-active"
          />
          <label for="entity_active" class="text-sm text-foreground">Đang hoạt động</label>
        </div>
      </div>

      <div v-if="formError" class="mt-4 p-3 bg-destructive/10 border border-destructive/20 rounded-lg">
        <p class="text-destructive text-sm">{{ formError }}</p>
      </div>

      <template #footer>
        <BaseButton variant="outline" @click="closeModal" :disabled="saving">Hủy</BaseButton>
        <BaseButton @click="handleSave" :disabled="saving" data-testid="button-submit-entity">
          {{ saving ? 'Đang lưu...' : 'Lưu' }}
        </BaseButton>
      </template>
    </BaseModal>
  </div>
</template>

<script setup>
import { computed, ref, onMounted } from 'vue';
import BaseCard from '../components/BaseCard.vue';
import BaseButton from '../components/BaseButton.vue';
import BaseTable from '../components/BaseTable.vue';
import BaseModal from '../components/BaseModal.vue';
import BaseSelect from '../components/BaseSelect.vue';
import { legalEntityService } from '../services/legalEntityService';
import { employeeService } from '../services/employeeService';
import { useToast } from '../composables/useToast';

const toast = useToast();

const loading = ref(false);
const saving = ref(false);
const processing = ref(false);
const error = ref('');
const formError = ref('');

const entities = ref([]);
const employees = ref([]);

const showModal = ref(false);
const editingId = ref(null);

const form = ref({ name: '', code: '', tax_code: '', address: '', representative: '', representative_title: '', head_employee_id: '', active: true });

const columns = [
  { key: 'name', label: 'Tên / Mã' },
  { key: 'tax_code', label: 'Mã số thuế' },
  { key: 'head', label: 'Người đứng đầu' },
  { key: 'counts', label: 'Quy mô' },
  { key: 'status', label: 'Trạng thái' }
];

const isActive = (item) => String(item?.status || '').toUpperCase() === 'ACTIVE' || !item?.status;

const metaOf = (item) => {
  if (item?.meta && typeof item.meta === 'object') return item.meta;
  try { return JSON.parse(item?.meta || '{}') || {}; } catch { return {}; }
};
const employeeFor = id => employees.value.find(employee => String(employee.id) === String(id));
const headName = item => employeeFor(metaOf(item).head_employee_id)?.full_name || 'Chưa gán';
const headCode = item => employeeFor(metaOf(item).head_employee_id)?.employee_code || '';
const headOptions = computed(() => {
  const options = [{ label: 'Chưa gán người đứng đầu', value: '' }];
  if (!editingId.value) return options;
  return options.concat(employees.value
    .filter(employee => Number(employee.legal_entity_id) === Number(editingId.value)
      && ['active', 'probation'].includes(employee.employment_status))
    .map(employee => ({
      value: String(employee.id),
      label: `${employee.full_name}${employee.employee_code ? ` (${employee.employee_code})` : ''}`
    })));
});

const apiErrorMessage = (err, fallback) =>
  err?.response?.data?.message || err?.response?.data?.error || err?.message || fallback;

const loadEntities = async () => {
  loading.value = true;
  error.value = '';
  try {
    [entities.value, employees.value] = await Promise.all([
      legalEntityService.getAll(),
      employeeService.getLookup(true)
    ]);
  } catch (err) {
    console.error('Error loading legal entities:', err);
    error.value = apiErrorMessage(err, 'Không thể tải danh sách chi nhánh');
  } finally {
    loading.value = false;
  }
};

const resetForm = () => {
  form.value = { name: '', code: '', tax_code: '', address: '', representative: '', representative_title: '', head_employee_id: '', active: true };
  editingId.value = null;
  formError.value = '';
};

const openCreateModal = () => {
  resetForm();
  showModal.value = true;
};

const openEditModal = (item) => {
  editingId.value = item.id;
  let meta = item.meta && typeof item.meta === 'object' ? item.meta : {};
  if (typeof item.meta === 'string') { try { meta = JSON.parse(item.meta) || {}; } catch (e) { meta = {}; } }
  form.value = {
    name: item.name || '',
    code: item.code || '',
    tax_code: item.tax_code || '',
    address: item.address || '',
    representative: meta.representative || '',
    representative_title: meta.representative_title || '',
    head_employee_id: meta.head_employee_id ? String(meta.head_employee_id) : '',
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
  if (!form.value.name.trim()) {
    formError.value = 'Vui lòng nhập tên công ty / pháp nhân';
    return;
  }

  saving.value = true;
  try {
    const payload = {
      name: form.value.name.trim(),
      code: form.value.code.trim() || null,
      tax_code: form.value.tax_code.trim() || null,
      address: form.value.address.trim() || null,
      status: form.value.active ? 'ACTIVE' : 'INACTIVE',
      meta: {
        representative: form.value.representative.trim() || null,
        representative_title: form.value.representative_title.trim() || null,
        head_employee_id: editingId.value && form.value.head_employee_id ? Number(form.value.head_employee_id) : null,
      },
    };
    if (editingId.value) {
      await legalEntityService.update(editingId.value, payload);
      toast.success('Đã cập nhật chi nhánh');
    } else {
      await legalEntityService.create(payload);
      toast.success('Đã thêm chi nhánh');
    }
    closeModal();
    await loadEntities();
  } catch (err) {
    console.error('Error saving legal entity:', err);
    formError.value = apiErrorMessage(err, 'Có lỗi xảy ra khi lưu');
  } finally {
    saving.value = false;
  }
};

const remove = async (item) => {
  if (processing.value) return;
  if (!confirm(`Xóa chi nhánh "${item.name}"?`)) return;
  processing.value = true;
  try {
    await legalEntityService.remove(item.id);
    toast.success('Đã xóa chi nhánh');
    await loadEntities();
  } catch (err) {
    console.error('Error deleting legal entity:', err);
    // Surface the backend's business-rule violations (409) clearly.
    const violations = err?.response?.data?.data?.violations;
    if (Array.isArray(violations) && violations.length) {
      toast.error(violations[0]);
    } else {
      toast.error(apiErrorMessage(err, 'Có lỗi xảy ra khi xóa'));
    }
  } finally {
    processing.value = false;
  }
};

onMounted(loadEntities);
</script>
