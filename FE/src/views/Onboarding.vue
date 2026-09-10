<template>
  <div class="space-y-6">
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-2">
      <div>
        <h1 class="text-xl sm:text-2xl font-bold">Hội nhập & Nghỉ việc</h1>
        <p class="text-muted-foreground mt-1">
          Danh sách công việc khi nhân viên mới vào (onboarding) hoặc nghỉ việc (offboarding).
        </p>
      </div>
      <BaseButton @click="openCreateModal" data-testid="button-add-checklist" class="w-full sm:w-auto">
        + Tạo checklist
      </BaseButton>
    </div>

    <div v-if="error" class="bg-destructive/10 border border-destructive/20 rounded-lg p-4">
      <p class="text-destructive font-medium">Lỗi kết nối API:</p>
      <p class="text-destructive/80 text-sm mt-1">{{ error }}</p>
    </div>

    <BaseCard>
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 items-end">
        <div>
          <label class="block text-sm font-medium text-foreground mb-2">Loại</label>
          <select v-model="filters.type" class="w-full px-4 py-2.5 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring" data-testid="select-filter-type">
            <option value="">Tất cả</option>
            <option value="ONBOARDING">Hội nhập (mới vào)</option>
            <option value="OFFBOARDING">Nghỉ việc</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-foreground mb-2">Trạng thái</label>
          <select v-model="filters.status" class="w-full px-4 py-2.5 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring" data-testid="select-filter-status">
            <option value="">Tất cả</option>
            <option value="IN_PROGRESS">Đang thực hiện</option>
            <option value="COMPLETED">Hoàn tất</option>
            <option value="CANCELLED">Đã hủy</option>
          </select>
        </div>
        <div class="flex gap-2">
          <BaseButton variant="outline" @click="loadChecklists" :disabled="loading">{{ loading ? 'Đang tải...' : 'Áp dụng' }}</BaseButton>
          <BaseButton variant="ghost" @click="resetFilters" :disabled="loading">Xóa lọc</BaseButton>
        </div>
      </div>
    </BaseCard>

    <BaseCard title="Danh sách checklist">
      <div v-if="loading" class="text-center py-8 text-muted-foreground">Đang tải dữ liệu...</div>
      <div v-else-if="checklists.length === 0" class="text-center py-10 text-muted-foreground">Chưa có checklist nào.</div>
      <BaseTable v-else :columns="columns" :data="checklists" test-id="table-checklists">
        <template #cell-employee="{ item }">
          <span class="font-medium">{{ employeeName(item) }}</span>
        </template>
        <template #cell-type="{ item }">
          <span :class="['inline-flex items-center px-2 py-0.5 rounded-full text-xs', item.type === 'OFFBOARDING' ? 'bg-orange-100 text-orange-700 dark:bg-orange-900 dark:text-orange-300' : 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300']">
            {{ item.type === 'OFFBOARDING' ? 'Nghỉ việc' : 'Hội nhập' }}
          </span>
        </template>
        <template #cell-progress="{ item }">
          <div class="flex items-center gap-2 min-w-[140px]">
            <div class="flex-1 h-2 rounded-full bg-muted overflow-hidden">
              <div class="h-full bg-primary" :style="{ width: progressPct(item) + '%' }"></div>
            </div>
            <span class="text-xs text-muted-foreground whitespace-nowrap">{{ item.done_tasks_count ?? 0 }}/{{ item.tasks_count ?? 0 }}</span>
          </div>
        </template>
        <template #cell-status="{ item }">
          <span :class="['inline-flex items-center px-2 py-0.5 rounded-full text-xs', statusClass(item.status)]">{{ statusLabel(item.status) }}</span>
        </template>
        <template #actions="{ item }">
          <div class="flex items-center gap-2">
            <button @click="openDetail(item)" class="px-2 py-1 text-sm rounded text-primary hover:bg-primary hover:text-primary-foreground transition-colors" data-testid="button-view-checklist">Xem</button>
            <button @click="remove(item)" class="p-1.5 rounded hover:bg-red-100 dark:hover:bg-red-900 text-red-600 dark:text-red-400 disabled:opacity-50" title="Xóa" :disabled="processing">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
            </button>
          </div>
        </template>
      </BaseTable>
    </BaseCard>

    <!-- Create modal -->
    <BaseModal v-model="showCreateModal" title="Tạo checklist" data-testid="modal-create-checklist">
      <div class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-foreground mb-2">Nhân viên <span class="text-destructive">*</span></label>
          <select v-model="form.employee_id" class="w-full px-4 py-2.5 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring" data-testid="select-employee">
            <option value="">-- Chọn nhân viên --</option>
            <option v-for="opt in employeeOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-foreground mb-2">Loại <span class="text-destructive">*</span></label>
          <select v-model="form.type" class="w-full px-4 py-2.5 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring" data-testid="select-type">
            <option value="ONBOARDING">Hội nhập (nhân viên mới)</option>
            <option value="OFFBOARDING">Nghỉ việc</option>
          </select>
          <p class="text-xs text-muted-foreground mt-1">Hệ thống sẽ tự tạo các công việc chuẩn theo loại đã chọn.</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-foreground mb-2">{{ form.type === 'OFFBOARDING' ? 'Ngày làm việc cuối' : 'Ngày bắt đầu / ngày vào' }}</label>
          <input v-model="form.start_date" type="date" class="w-full px-4 py-2.5 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring" data-testid="input-start-date" />
        </div>
        <div>
          <label class="block text-sm font-medium text-foreground mb-2">Ghi chú</label>
          <textarea v-model="form.notes" rows="2" class="w-full px-4 py-2.5 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring" data-testid="input-notes"></textarea>
        </div>
      </div>
      <div v-if="formError" class="mt-4 p-3 bg-destructive/10 border border-destructive/20 rounded-lg"><p class="text-destructive text-sm">{{ formError }}</p></div>
      <template #footer>
        <BaseButton variant="outline" @click="closeCreateModal" :disabled="saving">Hủy</BaseButton>
        <BaseButton @click="handleCreate" :disabled="saving" data-testid="button-submit-checklist">{{ saving ? 'Đang tạo...' : 'Tạo' }}</BaseButton>
      </template>
    </BaseModal>

    <!-- Detail modal -->
    <BaseModal v-model="showDetailModal" :title="detail ? `${detail.type === 'OFFBOARDING' ? 'Nghỉ việc' : 'Hội nhập'}: ${employeeName(detail)}` : 'Chi tiết'" data-testid="modal-checklist-detail">
      <div v-if="detailLoading" class="text-center py-6 text-muted-foreground">Đang tải...</div>
      <div v-else-if="detail" class="space-y-4">
        <div class="flex items-center gap-3">
          <span :class="['inline-flex items-center px-2 py-0.5 rounded-full text-xs', statusClass(detail.status)]">{{ statusLabel(detail.status) }}</span>
          <div class="flex-1 h-2 rounded-full bg-muted overflow-hidden">
            <div class="h-full bg-primary" :style="{ width: detailProgressPct + '%' }"></div>
          </div>
          <span class="text-xs text-muted-foreground">{{ detailDoneCount }}/{{ detail.tasks?.length ?? 0 }}</span>
        </div>
        <p v-if="detail.start_date" class="text-sm text-muted-foreground">Ngày: {{ formatDate(detail.start_date) }}</p>
        <p v-if="detail.notes" class="text-sm text-muted-foreground">Ghi chú: {{ detail.notes }}</p>

        <ul class="space-y-1">
          <li v-for="task in detail.tasks" :key="task.id" class="flex items-center gap-3 border border-border rounded px-3 py-2">
            <input
              type="checkbox"
              :checked="isDone(task)"
              :disabled="taskBusy || detail.status === 'CANCELLED'"
              @change="toggleTask(task)"
              class="h-4 w-4 rounded border-input text-primary focus:ring-ring"
              :data-testid="`checkbox-task-${task.id}`"
            />
            <span :class="['flex-1 text-sm', isDone(task) ? 'line-through text-muted-foreground' : 'text-foreground']">{{ task.title }}</span>
            <span v-if="task.due_date" class="text-xs text-muted-foreground">{{ formatDate(task.due_date) }}</span>
            <button @click="removeTask(task)" :disabled="taskBusy" class="p-1 rounded hover:bg-red-100 dark:hover:bg-red-900 text-red-600 dark:text-red-400" title="Xóa công việc">
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
            </button>
          </li>
        </ul>

        <div class="flex gap-2 pt-1">
          <input v-model="newTaskTitle" @keyup.enter="addTask" type="text" placeholder="Thêm công việc..." class="flex-1 px-3 py-2 rounded-lg border border-input bg-background text-foreground text-sm focus:outline-none focus:ring-2 focus:ring-ring" data-testid="input-new-task" />
          <BaseButton variant="outline" @click="addTask" :disabled="taskBusy || !newTaskTitle.trim()">Thêm</BaseButton>
        </div>
      </div>
      <template #footer>
        <BaseButton v-if="detail && detail.status !== 'CANCELLED'" variant="ghost" @click="cancelChecklist" :disabled="taskBusy" class="text-orange-600">Hủy checklist</BaseButton>
        <BaseButton variant="outline" @click="showDetailModal = false">Đóng</BaseButton>
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
import { onboardingService } from '../services/onboardingService';
import { employeeService } from '../services/employeeService';
import { useToast } from '../composables/useToast';

const toast = useToast();

const loading = ref(false);
const saving = ref(false);
const processing = ref(false);
const taskBusy = ref(false);
const detailLoading = ref(false);
const error = ref('');
const formError = ref('');

const checklists = ref([]);
const employees = ref([]);
const filters = ref({ type: '', status: '' });

const showCreateModal = ref(false);
const showDetailModal = ref(false);
const detail = ref(null);

const form = ref({ employee_id: '', type: 'ONBOARDING', start_date: '', notes: '' });
const newTaskTitle = ref('');

const columns = [
  { key: 'employee', label: 'Nhân viên' },
  { key: 'type', label: 'Loại' },
  { key: 'progress', label: 'Tiến độ' },
  { key: 'status', label: 'Trạng thái' }
];

const employeeOptions = computed(() =>
  employees.value.map((e) => ({ label: e.full_name ? `${e.full_name}${e.employee_code ? ` (${e.employee_code})` : ''}` : `NV #${e.id}`, value: String(e.id) }))
);

const STATUS = { IN_PROGRESS: 'Đang thực hiện', COMPLETED: 'Hoàn tất', CANCELLED: 'Đã hủy' };
const statusLabel = (s) => STATUS[String(s || '').toUpperCase()] || s || '-';
const statusClass = (s) => {
  switch (String(s || '').toUpperCase()) {
    case 'COMPLETED': return 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300';
    case 'CANCELLED': return 'bg-muted text-muted-foreground';
    default: return 'bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-300';
  }
};

const isDone = (task) => task?.is_done === true || task?.is_done === 't' || task?.is_done === 1;
const progressPct = (item) => {
  const total = item.tasks_count ?? 0;
  return total > 0 ? Math.round(((item.done_tasks_count ?? 0) / total) * 100) : 0;
};
const detailDoneCount = computed(() => (detail.value?.tasks || []).filter(isDone).length);
const detailProgressPct = computed(() => {
  const total = detail.value?.tasks?.length ?? 0;
  return total > 0 ? Math.round((detailDoneCount.value / total) * 100) : 0;
});

const employeeName = (item) => item?.employee?.full_name || item?.full_name || `NV #${item?.employee_id ?? '?'}`;

const formatDate = (date) => {
  if (!date) return '-';
  const d = new Date(date);
  return Number.isNaN(d.getTime()) ? date : d.toLocaleDateString('vi-VN');
};

const apiErrorMessage = (err, fallback) => err?.response?.data?.message || err?.response?.data?.error || err?.message || fallback;

const loadChecklists = async () => {
  loading.value = true;
  error.value = '';
  try {
    const params = {};
    if (filters.value.type) params.type = filters.value.type;
    if (filters.value.status) params.status = filters.value.status;
    const { items } = await onboardingService.getAll(params);
    checklists.value = items;
  } catch (err) {
    console.error('Error loading checklists:', err);
    error.value = apiErrorMessage(err, 'Không thể tải danh sách checklist');
  } finally {
    loading.value = false;
  }
};

const loadEmployees = async () => {
  try {
    const data = await employeeService.getLookup();
    employees.value = Array.isArray(data) ? data : (data?.items || data?.data || []);
  } catch (err) {
    console.error('Error loading employees:', err);
  }
};

const resetFilters = () => { filters.value = { type: '', status: '' }; loadChecklists(); };

const openCreateModal = () => { form.value = { employee_id: '', type: 'ONBOARDING', start_date: '', notes: '' }; formError.value = ''; showCreateModal.value = true; };
const closeCreateModal = () => { showCreateModal.value = false; };

const handleCreate = async () => {
  formError.value = '';
  if (!form.value.employee_id) { formError.value = 'Vui lòng chọn nhân viên'; return; }
  saving.value = true;
  try {
    const payload = {
      employee_id: parseInt(form.value.employee_id, 10),
      type: form.value.type,
      start_date: form.value.start_date || null,
      notes: form.value.notes.trim() || null
    };
    await onboardingService.create(payload);
    toast.success('Đã tạo checklist');
    closeCreateModal();
    await loadChecklists();
  } catch (err) {
    console.error('Error creating checklist:', err);
    formError.value = apiErrorMessage(err, 'Có lỗi xảy ra khi tạo checklist');
  } finally {
    saving.value = false;
  }
};

const openDetail = async (item) => {
  detail.value = null;
  newTaskTitle.value = '';
  detailLoading.value = true;
  showDetailModal.value = true;
  try {
    detail.value = await onboardingService.get(item.id);
  } catch (err) {
    console.error('Error loading checklist detail:', err);
    toast.error(apiErrorMessage(err, 'Không thể tải chi tiết'));
    showDetailModal.value = false;
  } finally {
    detailLoading.value = false;
  }
};

const refreshDetail = async () => {
  if (!detail.value) return;
  detail.value = await onboardingService.get(detail.value.id);
};

const toggleTask = async (task) => {
  if (taskBusy.value || !detail.value) return;
  taskBusy.value = true;
  try {
    await onboardingService.updateTask(detail.value.id, task.id, { is_done: !isDone(task) });
    await refreshDetail();
    await loadChecklists();
  } catch (err) {
    console.error('Error toggling task:', err);
    toast.error(apiErrorMessage(err, 'Có lỗi khi cập nhật công việc'));
  } finally {
    taskBusy.value = false;
  }
};

const addTask = async () => {
  if (taskBusy.value || !detail.value || !newTaskTitle.value.trim()) return;
  taskBusy.value = true;
  try {
    await onboardingService.addTask(detail.value.id, { title: newTaskTitle.value.trim() });
    newTaskTitle.value = '';
    await refreshDetail();
    await loadChecklists();
  } catch (err) {
    console.error('Error adding task:', err);
    toast.error(apiErrorMessage(err, 'Có lỗi khi thêm công việc'));
  } finally {
    taskBusy.value = false;
  }
};

const removeTask = async (task) => {
  if (taskBusy.value || !detail.value) return;
  if (!confirm(`Xóa công việc "${task.title}"?`)) return;
  taskBusy.value = true;
  try {
    await onboardingService.removeTask(detail.value.id, task.id);
    await refreshDetail();
    await loadChecklists();
  } catch (err) {
    console.error('Error removing task:', err);
    toast.error(apiErrorMessage(err, 'Có lỗi khi xóa công việc'));
  } finally {
    taskBusy.value = false;
  }
};

const cancelChecklist = async () => {
  if (taskBusy.value || !detail.value) return;
  if (!confirm('Hủy checklist này?')) return;
  taskBusy.value = true;
  try {
    await onboardingService.updateStatus(detail.value.id, 'CANCELLED');
    toast.success('Đã hủy checklist');
    await refreshDetail();
    await loadChecklists();
  } catch (err) {
    console.error('Error cancelling checklist:', err);
    toast.error(apiErrorMessage(err, 'Có lỗi khi hủy checklist'));
  } finally {
    taskBusy.value = false;
  }
};

const remove = async (item) => {
  if (processing.value) return;
  if (!confirm(`Xóa checklist của "${employeeName(item)}"?`)) return;
  processing.value = true;
  try {
    await onboardingService.remove(item.id);
    toast.success('Đã xóa checklist');
    await loadChecklists();
  } catch (err) {
    console.error('Error deleting checklist:', err);
    toast.error(apiErrorMessage(err, 'Có lỗi khi xóa checklist'));
  } finally {
    processing.value = false;
  }
};

onMounted(() => { loadChecklists(); loadEmployees(); });
</script>
