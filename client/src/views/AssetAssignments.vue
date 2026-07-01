<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-3xl font-bold text-foreground">Bàn Giao Thiết Bị</h1>
        <p class="text-muted-foreground mt-1">Lịch sử cấp phát và hoàn trả tài sản cho cán bộ nhân viên</p>
      </div>
      <BaseButton @click="openCreateModal">+ Cấp phát tài sản</BaseButton>
    </div>

    <!-- Table Card -->
    <BaseCard>
      <BaseTable
        :columns="[
          { key: 'asset', label: 'Tài sản' },
          { key: 'employee', label: 'Người sử dụng' },
          { key: 'assigned_date', label: 'Ngày bàn giao' },
          { key: 'return_date', label: 'Ngày hoàn trả' },
          { key: 'condition', label: 'Tình trạng bàn giao' },
          { key: 'status', label: 'Trạng thái' }
        ]"
        :data="assignments"
      >
        <template #cell-asset="{ item }">
          <div class="font-bold text-foreground">{{ item.asset_code }}</div>
          <div class="text-xs text-muted-foreground">{{ item.asset_name }}</div>
        </template>

        <template #cell-employee="{ item }">
          <div class="font-medium text-foreground">{{ item.employee_name || `Mã NV: ${item.employee_id}` }}</div>
          <div class="text-[10px] text-muted-foreground">Mã NV: {{ item.employee_code || '-' }}</div>
        </template>

        <template #cell-assigned_date="{ item }">
          <span>{{ formatDate(item.assigned_date || item.assignment_date) }}</span>
        </template>

        <template #cell-return_date="{ item }">
          <span v-if="item.returned_date || item.return_date">{{ formatDate(item.returned_date || item.return_date) }}</span>
          <span v-else class="text-xs text-muted-foreground italic">Đang sử dụng</span>
        </template>

        <template #cell-condition="{ item }">
          <span class="text-xs text-muted-foreground">{{ item.condition_details || item.handover_condition || '-' }}</span>
        </template>

        <template #cell-status="{ item }">
          <BaseBadge :variant="item.status === 'returned' || item.return_date ? 'secondary' : 'success'">
            {{ item.status === 'returned' || item.return_date ? 'Đã hoàn trả' : 'Đang bàn giao' }}
          </BaseBadge>
        </template>

        <template #actions="{ item }">
          <div class="flex gap-1">
            <button 
              v-if="!item.returned_date && !item.return_date"
              @click="returnItem(item)" 
              class="px-2.5 py-1.5 text-xs font-medium rounded-md bg-amber-100 text-amber-700 hover:bg-amber-200 dark:bg-amber-900/30 dark:text-amber-400 dark:hover:bg-amber-900/50 transition-colors"
            >
              Thu hồi
            </button>
            <button 
              @click="deleteItem(item.id)" 
              class="px-2.5 py-1.5 text-xs font-medium rounded-md bg-destructive/10 text-destructive hover:bg-destructive/20 transition-colors"
            >
              Xóa log
            </button>
          </div>
        </template>
      </BaseTable>
    </BaseCard>

    <!-- Assign Modal -->
    <BaseModal v-model="showModal" title="Cấp phát tài sản cho nhân viên">
      <div class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-foreground mb-1">Chọn tài sản khả dụng <span class="text-destructive">*</span></label>
          <select 
            v-model="form.asset_id" 
            class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground text-sm focus:outline-none focus:ring-2 focus:ring-ring"
            required
          >
            <option value="">-- Chọn tài sản --</option>
            <option v-for="ast in availableAssets" :key="ast.id" :value="ast.id">{{ ast.asset_code }} - {{ ast.name }}</option>
          </select>
        </div>

        <div>
          <label class="block text-sm font-medium text-foreground mb-1">Cấp phát cho nhân viên <span class="text-destructive">*</span></label>
          <select 
            v-model="form.employee_id" 
            class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground text-sm focus:outline-none focus:ring-2 focus:ring-ring"
            required
          >
            <option value="">-- Chọn nhân viên nhận --</option>
            <option v-for="emp in employees" :key="emp.id" :value="emp.id">{{ emp.full_name }} ({{ emp.employee_code }})</option>
          </select>
        </div>

        <BaseInput v-model="form.assigned_date" type="date" label="Ngày bàn giao" required />

        <div>
          <label class="block text-sm font-medium text-foreground mb-1">Tình trạng thiết bị khi bàn giao</label>
          <textarea 
            v-model="form.condition" 
            class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground text-sm focus:outline-none focus:ring-2 focus:ring-ring" 
            rows="2" 
            placeholder="Ví dụ: Máy mới 100%, nguyên hộp, kèm sạc zin..."
          ></textarea>
        </div>
      </div>
      <template #footer>
        <BaseButton variant="outline" @click="showModal = false">Hủy</BaseButton>
        <BaseButton @click="submitForm">Xác nhận</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import BaseButton from '../components/BaseButton.vue';
import BaseCard from '../components/BaseCard.vue';
import BaseTable from '../components/BaseTable.vue';
import BaseModal from '../components/BaseModal.vue';
import BaseInput from '../components/BaseInput.vue';
import BaseBadge from '../components/BaseBadge.vue';
import { assetService } from '../services/assetService';
import { employeeService } from '../services/employeeService';
import { useToast } from '../composables/useToast';

const toast = useToast();
const assignments = ref([]);
const availableAssets = ref([]);
const employees = ref([]);
const showModal = ref(false);

const form = ref({
  asset_id: '',
  employee_id: '',
  assigned_date: '',
  condition: ''
});

const loadData = async () => {
  try {
    const [assRes, astRes, empRes] = await Promise.all([
      assetService.getAllAssignments(),
      assetService.getAll().catch(() => ({ data: [] })),
      employeeService.getAll().catch(() => ({ data: [] }))
    ]);
    assignments.value = assRes?.data || assRes || [];
    
    const allAssets = astRes?.data || astRes || [];
    // Only available assets can be assigned
    availableAssets.value = allAssets.filter(a => a.status === 'available');
    employees.value = empRes?.data?.data || empRes?.data || empRes || [];
  } catch (err) {
    console.error('Error loading assignments:', err);
  }
};

const formatDate = (date) => {
  if (!date) return '-';
  return new Date(date).toLocaleDateString('vi-VN');
};

const openCreateModal = () => {
  form.value = {
    asset_id: '',
    employee_id: '',
    assigned_date: new Date().toISOString().substring(0, 10),
    condition: 'Hoạt động tốt, phụ kiện đi kèm đầy đủ'
  };
  showModal.value = true;
};

const submitForm = async () => {
  if (!form.value.asset_id || !form.value.employee_id || !form.value.assigned_date) {
    toast.error('Vui lòng chọn đầy đủ các thông tin bắt buộc');
    return;
  }
  try {
    const data = {
      asset_id: form.value.asset_id,
      employee_id: form.value.employee_id,
      assigned_date: form.value.assigned_date,
      assignment_date: form.value.assigned_date,
      condition_details: form.value.condition,
      status: 'assigned'
    };
    await assetService.createAssignment(data);
    
    // Auto update asset status to assigned
    await assetService.update(form.value.asset_id, { status: 'assigned' });
    
    toast.success('Bàn giao tài sản thành công');
    showModal.value = false;
    await loadData();
  } catch (err) {
    console.error('Error saving assignment:', err);
    toast.error('Có lỗi xảy ra khi bàn giao');
  }
};

const returnItem = async (item) => {
  const note = prompt('Nhập tình trạng thiết bị khi hoàn trả:', 'Hoạt động bình thường, không trầy xước');
  if (note === null) return;
  
  try {
    const today = new Date().toISOString().substring(0, 10);
    await assetService.updateAssignment(item.id, {
      return_date: today,
      returned_date: today,
      condition_details: `${item.condition_details || ''} | Trả ngày ${today}: ${note}`,
      status: 'returned'
    });
    
    // Auto restore asset status to available
    await assetService.update(item.asset_id, { status: 'available' });
    
    toast.success('Thu hồi tài sản thành công');
    await loadData();
  } catch (err) {
    console.error('Error returning asset:', err);
    toast.error('Có lỗi xảy ra khi thu hồi');
  }
};

const deleteItem = async (id) => {
  if (!confirm('Bạn có chắc chắn muốn xóa log lịch sử này?')) return;
  try {
    await assetService.deleteAssignment(id);
    toast.success('Xóa dòng lịch sử thành công');
    await loadData();
  } catch (err) {
    console.error('Error deleting assignment:', err);
    toast.error('Có lỗi xảy ra khi xóa');
  }
};

onMounted(async () => {
  await loadData();
});
</script>
