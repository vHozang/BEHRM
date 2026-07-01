<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-3xl font-bold text-foreground">Lịch sử công tác</h1>
        <p class="text-muted-foreground mt-1">Quá trình giữ vị trí / phòng ban của nhân viên (tự ghi nhận khi điều chuyển).</p>
      </div>
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
import { employeeService } from '../services/employeeService';
import { departmentService } from '../services/departmentService';
import { jobTitleService } from '../services/jobTitleService';

const loading = ref(true);
const histories = ref([]);
const employeeOptions = ref([{ label: 'Tất cả nhân viên', value: '' }]);
const filters = ref({ employee: '', search: '' });

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

const loadData = async () => {
  loading.value = true;
  try {
    const [histRes, empRes, deptRes, posRes] = await Promise.all([
      employeeService.getAllHistories().catch(() => []),
      employeeService.getAll().catch(() => []),
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
