<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-3xl font-bold text-foreground">Lịch sử công tác</h1>
        <p class="text-muted-foreground mt-1">Quá trình giữ vị trí / phòng ban của nhân viên (tự ghi nhận khi điều chuyển).</p>
      </div>
      <BaseButton @click="openCreate">+ Thêm sự kiện thủ công</BaseButton>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <BaseCard class="p-4">
        <p class="text-2xl font-bold">{{ stats.total }}</p>
        <p class="text-sm text-muted-foreground">Tổng bản ghi</p>
      </BaseCard>
      <BaseCard class="p-4">
        <p class="text-2xl font-bold text-green-600">{{ stats.current }}</p>
        <p class="text-sm text-muted-foreground">Vị trí hiện tại</p>
      </BaseCard>
      <BaseCard class="p-4">
        <p class="text-2xl font-bold text-gray-600">{{ stats.past }}</p>
        <p class="text-sm text-muted-foreground">Đã kết thúc</p>
      </BaseCard>
    </div>

    <BaseCard>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
        <BaseSelect v-model="filters.employee" :options="employeeOptions" placeholder="Chọn nhân viên" />
        <BaseInput v-model="filters.search" placeholder="Tìm theo tên / phòng ban / chức danh..." />
        <BaseButton variant="ghost" @click="filters = { employee: '', search: '' }">Xóa lọc</BaseButton>
      </div>

      <div v-if="loading" class="text-center py-8 text-muted-foreground">Đang tải dữ liệu...</div>
      <div v-else-if="filteredHistory.length === 0" class="text-center py-10 text-muted-foreground">
        Chưa có lịch sử công tác.
      </div>
      <BaseTable
        v-else
        :columns="[
          { key: 'employee_name', label: 'Nhân viên' },
          { key: 'department_name', label: 'Phòng ban' },
          { key: 'job_title_name', label: 'Chức danh' },
          { key: 'start_date', label: 'Từ ngày' },
          { key: 'end_date', label: 'Đến ngày' },
          { key: 'is_current', label: 'Trạng thái' }
        ]"
        :data="filteredHistory"
      >
        <template #cell-employee_name="{ item }">
          <div class="flex items-center gap-2">
            <div class="w-8 h-8 rounded-full bg-primary text-primary-foreground flex items-center justify-center text-sm font-semibold">
              {{ getInitials(item._employee_name) }}
            </div>
            <div>
              <span class="font-medium">{{ item._employee_name || `NV #${item.employee_id}` }}</span>
              <p class="text-xs text-muted-foreground">{{ item._employee_code }}</p>
            </div>
          </div>
        </template>
        <template #cell-department_name="{ item }">{{ item._department_name || '—' }}</template>
        <template #cell-job_title_name="{ item }">{{ item._position_name || '—' }}</template>
        <template #cell-start_date="{ item }">{{ formatDate(item.start_date) }}</template>
        <template #cell-end_date="{ item }">{{ item.end_date ? formatDate(item.end_date) : 'Hiện tại' }}</template>
        <template #cell-is_current="{ item }">
          <BaseBadge :variant="isCurrent(item) ? 'success' : 'default'">
            {{ isCurrent(item) ? 'Hiện tại' : 'Đã kết thúc' }}
          </BaseBadge>
        </template>
      </BaseTable>
    </BaseCard>

    <BaseModal v-model="showCreate" title="Thêm sự kiện công tác" size="lg">
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <label class="block text-sm font-medium sm:col-span-2">
          Nhân viên <span class="text-destructive">*</span>
          <RemoteEmployeeSelect v-model="form.employee_id" />
        </label>
        <label class="block text-sm font-medium">
          Phòng ban
          <ResourceSelect v-model="form.department_id" resource="departments" label-key="department_name" code-key="department_code" />
        </label>
        <label class="block text-sm font-medium">
          Chức danh
          <ResourceSelect v-model="form.position_id" resource="positions" label-key="position_name" code-key="position_code" />
        </label>
        <BaseInput v-model="form.start_date" type="date" label="Ngày bắt đầu" required />
        <BaseInput v-model="form.end_date" type="date" label="Ngày kết thúc" />
        <BaseInput v-model="form.decision_number" label="Số quyết định" />
        <BaseInput v-model="form.decision_date" type="date" label="Ngày quyết định" />
        <label class="flex items-center gap-2 rounded-lg border border-input px-3 py-2 text-sm sm:col-span-2">
          <input v-model="form.is_current" type="checkbox" class="h-4 w-4 rounded" />
          Đặt làm vị trí hiện tại và kết thúc bản ghi hiện tại cũ
        </label>
        <label class="block text-sm font-medium sm:col-span-2">
          Ghi chú / căn cứ
          <textarea v-model="form.notes" rows="3" class="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2 font-normal" />
        </label>
      </div>
      <p v-if="formError" class="mt-4 rounded-lg border border-destructive/30 bg-destructive/10 p-3 text-sm text-destructive">{{ formError }}</p>
      <template #footer>
        <BaseButton variant="outline" :disabled="saving" @click="showCreate = false">Hủy</BaseButton>
        <BaseButton :disabled="saving" @click="saveHistory">{{ saving ? 'Đang lưu...' : 'Lưu sự kiện' }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import BaseButton from '../components/BaseButton.vue';
import BaseCard from '../components/BaseCard.vue';
import BaseTable from '../components/BaseTable.vue';
import BaseInput from '../components/BaseInput.vue';
import BaseSelect from '../components/BaseSelect.vue';
import BaseBadge from '../components/BaseBadge.vue';
import BaseModal from '../components/BaseModal.vue';
import RemoteEmployeeSelect from '../components/RemoteEmployeeSelect.vue';
import ResourceSelect from '../components/ResourceSelect.vue';
import { employeeService } from '../services/employeeService';
import { departmentService } from '../services/departmentService';
import { jobTitleService } from '../services/jobTitleService';
import { useToast } from '../composables/useToast';

const toast = useToast();
const loading = ref(true);
const saving = ref(false);
const showCreate = ref(false);
const formError = ref('');
const histories = ref([]);
const employeeOptions = ref([{ label: 'Tất cả nhân viên', value: '' }]);
const filters = ref({ employee: '', search: '' });
const emptyForm = () => ({ employee_id: '', department_id: '', position_id: '', start_date: new Date().toISOString().slice(0, 10), end_date: '', decision_number: '', decision_date: '', is_current: false, notes: '' });
const form = ref(emptyForm());

const getInitials = (name) => name ? name.split(' ').map(w => w[0]).join('').toUpperCase().slice(0, 2) : '';
const formatDate = (date) => date ? new Date(date).toLocaleDateString('vi-VN') : '-';
const isCurrent = (item) => item?.is_current === true || item?.is_current === 't' || item?.is_current === 1;

const stats = computed(() => ({
  total: histories.value.length,
  current: histories.value.filter(isCurrent).length,
  past: histories.value.filter((h) => !isCurrent(h)).length
}));

const filteredHistory = computed(() => {
  let result = histories.value;
  if (filters.value.employee) {
    result = result.filter((h) => String(h.employee_id) === String(filters.value.employee));
  }
  const q = filters.value.search.trim().toLowerCase();
  if (q) {
    result = result.filter((h) =>
      `${h._employee_name || ''} ${h._department_name || ''} ${h._position_name || ''}`.toLowerCase().includes(q)
    );
  }
  return result;
});

const asArray = (data) => Array.isArray(data) ? data : (data?.items || data?.data || []);

const openCreate = () => {
  form.value = emptyForm();
  formError.value = '';
  showCreate.value = true;
};

const saveHistory = async () => {
  if (!form.value.employee_id || !form.value.start_date) {
    formError.value = 'Vui lòng chọn nhân viên và ngày bắt đầu';
    return;
  }
  if (form.value.end_date && form.value.end_date < form.value.start_date) {
    formError.value = 'Ngày kết thúc không được trước ngày bắt đầu';
    return;
  }
  saving.value = true;
  formError.value = '';
  try {
    await employeeService.createHistory(Number(form.value.employee_id), {
      department_id: form.value.department_id ? Number(form.value.department_id) : null,
      position_id: form.value.position_id ? Number(form.value.position_id) : null,
      start_date: form.value.start_date,
      end_date: form.value.end_date || null,
      decision_number: form.value.decision_number || null,
      decision_date: form.value.decision_date || null,
      is_current: Boolean(form.value.is_current && !form.value.end_date),
      notes: form.value.notes || null,
    });
    showCreate.value = false;
    toast.success('Đã thêm sự kiện công tác');
    await loadData();
  } catch (err) {
    const errors = err.response?.data?.data?.errors;
    formError.value = errors ? Object.values(errors).flat().join(' ') : (err.response?.data?.message || 'Không thể lưu sự kiện công tác');
  } finally {
    saving.value = false;
  }
};

const loadData = async () => {
  loading.value = true;
  try {
    const [histRes, empRes, deptRes, posRes] = await Promise.all([
      employeeService.getAllHistories().catch(() => []),
      employeeService.getLookup().catch(() => []),
      departmentService.getAll().catch(() => []),
      jobTitleService.getAll().catch(() => [])
    ]);

    const employees = asArray(empRes);
    const departments = asArray(deptRes);
    const positions = asArray(posRes);

    const empMap = {}, deptMap = {}, posMap = {};
    employees.forEach((e) => { empMap[e.id] = e; });
    departments.forEach((d) => { deptMap[d.id] = d.department_name || d.name || ''; });
    positions.forEach((p) => { posMap[p.id] = p.position_name || p.name || p.job_title_name || ''; });

    const raw = asArray(histRes);
    histories.value = raw.map((h) => ({
      ...h,
      _employee_name: empMap[h.employee_id]?.full_name || '',
      _employee_code: empMap[h.employee_id]?.employee_code || '',
      _department_name: deptMap[h.department_id] || '',
      _position_name: posMap[h.position_id] || ''
    }));

    employeeOptions.value = [
      { label: 'Tất cả nhân viên', value: '' },
      ...employees.map((e) => ({ label: e.full_name, value: e.id }))
    ];
  } catch (err) {
    console.error('Error loading employment history:', err);
  } finally {
    loading.value = false;
  }
};

onMounted(loadData);
</script>
