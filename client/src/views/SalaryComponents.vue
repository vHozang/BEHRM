<template>
  <div class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <h1 class="text-2xl font-bold text-foreground sm:text-3xl">Thành phần & dữ liệu lương</h1>
        <p class="mt-1 text-muted-foreground">Quản lý danh mục và các khoản đầu vào mà engine lương thực sự sử dụng.</p>
      </div>
      <BaseButton v-if="activeTab === 'components'" @click="openComponentModal">+ Thêm thành phần</BaseButton>
    </div>

    <div class="flex gap-2 overflow-x-auto rounded-xl border border-border bg-card p-2">
      <button
        v-for="tab in tabs"
        :key="tab.value"
        class="whitespace-nowrap rounded-lg px-3 py-2 text-sm font-semibold transition-colors"
        :class="activeTab === tab.value ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-muted'"
        @click="activeTab = tab.value"
      >
        {{ tab.label }}
      </button>
    </div>

    <BaseCard v-if="activeTab === 'components'">
      <BaseTable :columns="componentColumns" :data="salaryComponents">
        <template #cell-name="{ item }"><span :class="{ 'line-through text-muted-foreground': !truthy(item.is_active) }">{{ item.name }}</span></template>
        <template #cell-type="{ item }">{{ String(item.type).toLowerCase() === 'earning' ? 'Thu nhập' : 'Khấu trừ' }}</template>
        <template #cell-is_active="{ item }"><BaseBadge :variant="truthy(item.is_active) ? 'success' : 'secondary'">{{ truthy(item.is_active) ? 'Hoạt động' : 'Tạm ngưng' }}</BaseBadge></template>
        <template #actions="{ item }">
          <button class="text-xs font-semibold text-primary hover:underline" @click="editComponent(item)">Sửa</button>
          <button class="ml-3 text-xs font-semibold text-destructive hover:underline" @click="removeComponent(item)">Xóa</button>
        </template>
      </BaseTable>
    </BaseCard>

    <ResourceCrudPanel
      v-else-if="activeTab === 'allowances'"
      resource="allowances"
      title="Phụ cấp"
      description="Cờ chịu thuế, đóng bảo hiểm và phương pháp tính được engine đọc trực tiếp."
      :columns="allowanceColumns"
      :fields="allowanceFields"
      :defaults="{ status: 'ACTIVE', calculation_method: 'FIXED', is_taxable: true, is_insurable: false }"
      @changed="loadInputCatalogs"
    />

    <ResourceCrudPanel
      v-else-if="activeTab === 'deductions'"
      resource="deductions"
      title="Khấu trừ"
      :columns="deductionColumns"
      :fields="deductionFields"
      :defaults="{ status: 'ACTIVE', deduction_type: 'FIXED', is_mandatory: false }"
      @changed="loadInputCatalogs"
    />

    <ResourceCrudPanel
      v-else-if="activeTab === 'employee-allowances'"
      resource="employee-allowances"
      title="Gán phụ cấp cho nhân viên"
      description="Khoảng hiệu lực trùng cùng nhân viên và phụ cấp sẽ bị backend chặn."
      :columns="assignmentColumns('allowance')"
      :fields="allowanceAssignmentFields"
      :defaults="assignmentDefaults"
    />

    <ResourceCrudPanel
      v-else-if="activeTab === 'employee-deductions'"
      resource="employee-deductions"
      title="Gán khấu trừ cho nhân viên"
      description="Chỉ khoản có hiệu lực trong kỳ mới được đưa vào lương."
      :columns="assignmentColumns('deduction')"
      :fields="deductionAssignmentFields"
      :defaults="assignmentDefaults"
    />

    <ResourceCrudPanel
      v-else
      resource="insurance-types"
      title="Loại bảo hiểm"
      :columns="insuranceColumns"
      :fields="insuranceFields"
      :defaults="{ status: 'ACTIVE' }"
    />

    <BaseModal v-model="componentModal" :title="componentForm.id ? 'Sửa thành phần lương' : 'Thêm thành phần lương'">
      <div class="space-y-4">
        <BaseInput v-model="componentForm.code" label="Mã" required />
        <BaseInput v-model="componentForm.name" label="Tên thành phần" required />
        <BaseSelect v-model="componentForm.type" label="Loại" :options="componentTypeOptions" required />
        <BaseSelect v-model="componentForm.category" label="Danh mục" :options="componentCategoryOptions" required />
        <label class="flex items-center gap-2 text-sm"><input v-model="componentForm.is_taxable" type="checkbox" class="h-4 w-4 rounded" /> Tính thuế</label>
        <label class="flex items-center gap-2 text-sm"><input v-model="componentForm.is_active" type="checkbox" class="h-4 w-4 rounded" /> Hoạt động</label>
      </div>
      <template #footer>
        <BaseButton variant="outline" @click="componentModal = false">Hủy</BaseButton>
        <BaseButton :disabled="saving" @click="saveComponent">{{ saving ? 'Đang lưu...' : 'Lưu' }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import BaseBadge from '../components/BaseBadge.vue';
import BaseButton from '../components/BaseButton.vue';
import BaseCard from '../components/BaseCard.vue';
import BaseInput from '../components/BaseInput.vue';
import BaseModal from '../components/BaseModal.vue';
import BaseSelect from '../components/BaseSelect.vue';
import BaseTable from '../components/BaseTable.vue';
import ResourceCrudPanel from '../components/ResourceCrudPanel.vue';
import axiosClient from '../services/axiosClient';
import { salaryService } from '../services/salaryService';
import { useToast } from '../composables/useToast';

const toast = useToast();
const activeTab = ref('components');
const salaryComponents = ref([]);
const allowances = ref([]);
const deductions = ref([]);
const componentModal = ref(false);
const saving = ref(false);
const componentForm = ref({});

const tabs = [
  { value: 'components', label: 'Thành phần lương' },
  { value: 'allowances', label: 'Phụ cấp' },
  { value: 'deductions', label: 'Khấu trừ' },
  { value: 'employee-allowances', label: 'Gán phụ cấp' },
  { value: 'employee-deductions', label: 'Gán khấu trừ' },
  { value: 'insurance-types', label: 'Loại bảo hiểm' },
];

const statusOptions = [{ value: 'ACTIVE', label: 'Hoạt động' }, { value: 'INACTIVE', label: 'Tạm ngưng' }];
const componentTypeOptions = [{ value: 'earning', label: 'Thu nhập' }, { value: 'deduction', label: 'Khấu trừ' }];
const componentCategoryOptions = [
  { value: 'basic', label: 'Lương cơ bản' }, { value: 'allowance', label: 'Phụ cấp' },
  { value: 'bonus', label: 'Thưởng' }, { value: 'tax', label: 'Thuế' },
  { value: 'insurance', label: 'Bảo hiểm' }, { value: 'other', label: 'Khác' },
];
const componentColumns = [
  { key: 'code', label: 'Mã' }, { key: 'name', label: 'Tên thành phần' },
  { key: 'type', label: 'Loại' }, { key: 'category', label: 'Danh mục' },
  { key: 'is_active', label: 'Trạng thái' },
];
const allowanceColumns = [
  { key: 'allowance_code', label: 'Mã', mono: true }, { key: 'allowance_name', label: 'Tên phụ cấp' },
  { key: 'calculation_method', label: 'Cách tính' }, { key: 'is_taxable', label: 'Chịu thuế' },
  { key: 'is_insurable', label: 'Đóng BH' }, { key: 'status', label: 'Trạng thái' },
];
const allowanceFields = [
  { key: 'allowance_code', label: 'Mã phụ cấp', required: true },
  { key: 'allowance_name', label: 'Tên phụ cấp', required: true },
  { key: 'allowance_type', label: 'Loại', type: 'select', options: [{ value: 'FIXED', label: 'Cố định' }, { value: 'VARIABLE', label: 'Biến đổi' }] },
  { key: 'calculation_method', label: 'Phương pháp', type: 'select', options: [{ value: 'FIXED', label: 'Số tiền' }, { value: 'PERCENTAGE', label: 'Tỷ lệ' }] },
  { key: 'is_taxable', label: 'Thuế', type: 'checkbox', checkboxLabel: 'Tính vào thu nhập chịu thuế' },
  { key: 'is_insurable', label: 'Bảo hiểm', type: 'checkbox', checkboxLabel: 'Tính vào nền bảo hiểm' },
  { key: 'status', label: 'Trạng thái', type: 'select', options: statusOptions },
  { key: 'description', label: 'Mô tả', type: 'textarea', full: true, nullable: true },
];
const deductionColumns = [
  { key: 'deduction_code', label: 'Mã', mono: true }, { key: 'deduction_name', label: 'Tên khấu trừ' },
  { key: 'deduction_type', label: 'Loại' }, { key: 'is_mandatory', label: 'Bắt buộc' }, { key: 'status', label: 'Trạng thái' },
];
const deductionFields = [
  { key: 'deduction_code', label: 'Mã khấu trừ', required: true },
  { key: 'deduction_name', label: 'Tên khấu trừ', required: true },
  { key: 'deduction_type', label: 'Loại', type: 'select', options: [{ value: 'FIXED', label: 'Số tiền' }, { value: 'PERCENTAGE', label: 'Tỷ lệ' }] },
  { key: 'is_mandatory', label: 'Bắt buộc', type: 'checkbox' },
  { key: 'status', label: 'Trạng thái', type: 'select', options: statusOptions },
  { key: 'description', label: 'Mô tả', type: 'textarea', full: true, nullable: true },
];
const assignmentDefaults = computed(() => ({ effective_date: new Date().toISOString().slice(0, 10), is_active: true }));
const assignmentColumns = (kind) => [
  { key: 'employee_code', label: 'Mã NV' }, { key: 'employee_name', label: 'Nhân viên' },
  { key: 'component_name', label: kind === 'allowance' ? 'Phụ cấp' : 'Khấu trừ' },
  { key: 'amount', label: 'Số tiền', format: (value) => formatMoney(value) },
  { key: 'percentage', label: 'Tỷ lệ %' }, { key: 'effective_date', label: 'Hiệu lực' }, { key: 'expiry_date', label: 'Đến ngày' },
];
const allowanceOptions = computed(() => allowances.value.map((item) => ({ value: item.id, label: `${item.allowance_code} · ${item.allowance_name}` })));
const deductionOptions = computed(() => deductions.value.map((item) => ({ value: item.id, label: `${item.deduction_code} · ${item.deduction_name}` })));
const assignmentBaseFields = [
  { key: 'employee_id', label: 'Nhân viên', type: 'employee', initialLabelKey: 'employee_label', required: true, cast: 'number', full: true },
  { key: 'amount', label: 'Số tiền', type: 'number', min: 0, step: 1000, cast: 'number', nullable: true },
  { key: 'percentage', label: 'Tỷ lệ (%)', type: 'number', min: 0, step: 0.01, cast: 'number', nullable: true },
  { key: 'effective_date', label: 'Ngày hiệu lực', type: 'date', required: true },
  { key: 'expiry_date', label: 'Ngày kết thúc', type: 'date', nullable: true },
  { key: 'is_active', label: 'Trạng thái', type: 'checkbox', checkboxLabel: 'Đang áp dụng' },
  { key: 'notes', label: 'Ghi chú', type: 'textarea', full: true, nullable: true },
];
const allowanceAssignmentFields = computed(() => [
  assignmentBaseFields[0],
  { key: 'allowance_id', label: 'Phụ cấp', type: 'select', options: allowanceOptions.value, required: true, cast: 'number' },
  ...assignmentBaseFields.slice(1),
]);
const deductionAssignmentFields = computed(() => [
  assignmentBaseFields[0],
  { key: 'deduction_id', label: 'Khấu trừ', type: 'select', options: deductionOptions.value, required: true, cast: 'number' },
  ...assignmentBaseFields.slice(1),
]);
const insuranceColumns = [
  { key: 'insurance_type_code', label: 'Mã', mono: true }, { key: 'insurance_type_name', label: 'Tên loại bảo hiểm' }, { key: 'status', label: 'Trạng thái' },
];
const insuranceFields = [
  { key: 'insurance_type_code', label: 'Mã loại bảo hiểm', required: true },
  { key: 'insurance_type_name', label: 'Tên loại bảo hiểm', required: true },
  { key: 'status', label: 'Trạng thái', type: 'select', options: statusOptions },
  { key: 'description', label: 'Mô tả', type: 'textarea', full: true, nullable: true },
];

const truthy = (value) => [true, 1, '1', 't', 'true'].includes(value);
const formatMoney = (value) => new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 0 }).format(Number(value) || 0);
const arrayFrom = (response) => Array.isArray(response.data) ? response.data : (response.pageData?.items || []);

const loadComponents = async () => {
  const response = await salaryService.getComponents();
  salaryComponents.value = Array.isArray(response) ? response : (response?.items || []);
};
const loadInputCatalogs = async () => {
  const [allowanceResponse, deductionResponse] = await Promise.all([
    axiosClient.get('/allowances', { params: { per_page: 100, status: 'ACTIVE' } }),
    axiosClient.get('/deductions', { params: { per_page: 100, status: 'ACTIVE' } }),
  ]);
  allowances.value = arrayFrom(allowanceResponse);
  deductions.value = arrayFrom(deductionResponse);
};

const blankComponent = () => ({ code: '', name: '', type: 'earning', category: 'allowance', is_taxable: true, is_active: true });
const openComponentModal = () => { componentForm.value = blankComponent(); componentModal.value = true; };
const editComponent = (item) => { componentForm.value = { ...item, is_taxable: truthy(item.is_taxable), is_active: truthy(item.is_active) }; componentModal.value = true; };
const saveComponent = async () => {
  if (!componentForm.value.code || !componentForm.value.name) return toast.error('Nhập mã và tên thành phần');
  saving.value = true;
  try {
    if (componentForm.value.id) await salaryService.updateComponent(componentForm.value.id, componentForm.value);
    else await salaryService.createComponent(componentForm.value);
    componentModal.value = false;
    toast.success('Đã lưu thành phần lương');
    await loadComponents();
  } catch (error) {
    toast.error(error.response?.data?.message || 'Không thể lưu thành phần lương');
  } finally { saving.value = false; }
};
const removeComponent = async (item) => {
  if (!window.confirm(`Xóa thành phần "${item.name}"?`)) return;
  try {
    await salaryService.deleteComponent(item.id);
    toast.success('Đã xóa thành phần lương');
    await loadComponents();
  } catch (error) {
    const violations = error.response?.data?.data?.violations;
    toast.error(Array.isArray(violations) ? violations[0] : (error.response?.data?.message || 'Không thể xóa thành phần đang được sử dụng'));
  }
};

onMounted(async () => {
  try { await Promise.all([loadComponents(), loadInputCatalogs()]); }
  catch (error) { toast.error(error.response?.data?.message || 'Không tải được dữ liệu lương'); }
});
</script>
