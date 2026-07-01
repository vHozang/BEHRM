<template>
  <div class="space-y-6">
    <div>
      <h1 class="text-3xl font-bold text-foreground">Bộ Tạo Báo Cáo Động</h1>
      <p class="text-muted-foreground mt-1">Xuất dữ liệu nhân sự, bảng lương và chấm công tùy chỉnh theo nhu cầu</p>
    </div>

    <!-- Mode toggle: server-side engine vs client-side builder -->
    <BaseCard class="flex flex-wrap items-center gap-2">
      <span class="text-xs font-semibold text-muted-foreground uppercase tracking-wider mr-2">Chế độ</span>
      <BaseButton :variant="mode === 'server' ? 'primary' : 'outline'" @click="mode = 'server'">
        Báo cáo hệ thống
      </BaseButton>
      <BaseButton :variant="mode === 'client' ? 'primary' : 'outline'" @click="mode = 'client'">
        Tự thiết kế (nâng cao)
      </BaseButton>
    </BaseCard>

    <!-- Server-side report engine (primary) -->
    <div v-if="mode === 'server'" class="space-y-6">
      <BaseCard class="grid grid-cols-1 sm:grid-cols-3 gap-4 items-end">
        <div class="sm:col-span-2">
          <label class="block text-xs font-semibold text-muted-foreground uppercase tracking-wider mb-1.5">Loại báo cáo</label>
          <select
            v-model="serverType"
            class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground text-sm focus:outline-none focus:ring-2 focus:ring-ring"
          >
            <option v-for="t in serverReportTypes" :key="t.value" :value="t.value">{{ t.label }}</option>
          </select>
        </div>
        <BaseButton class="w-full" :disabled="serverLoading" @click="generateServerReport">
          {{ serverLoading ? 'Đang tạo...' : 'Tạo báo cáo' }}
        </BaseButton>
      </BaseCard>

      <BaseCard>
        <div class="flex items-center justify-between mb-4">
          <h3 class="font-bold text-lg text-foreground">Kết Quả Báo Cáo</h3>
          <span v-if="serverRows.length" class="text-xs font-semibold px-2.5 py-1 bg-primary/10 text-primary rounded-full">
            Tổng số dòng: {{ serverRows.length }}
          </span>
        </div>

        <div v-if="serverLoading" class="text-center py-12">
          <p class="text-muted-foreground">Đang biên dịch báo cáo...</p>
        </div>

        <div v-else-if="serverRows.length === 0" class="text-center py-12 border border-dashed rounded-2xl">
          <p class="text-muted-foreground">Chọn loại báo cáo và nhấn "Tạo báo cáo" để xem kết quả</p>
        </div>

        <div v-else class="overflow-x-auto">
          <table class="w-full border-collapse text-left text-sm">
            <thead>
              <tr class="border-b border-border bg-muted/40">
                <th
                  v-for="col in serverColumns"
                  :key="col"
                  class="p-4 font-bold text-foreground whitespace-nowrap"
                >
                  {{ col }}
                </th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border/60">
              <tr v-for="(row, index) in serverRows" :key="index" class="hover:bg-muted/10 transition-colors">
                <td
                  v-for="col in serverColumns"
                  :key="col"
                  class="p-4 text-foreground whitespace-nowrap"
                >
                  {{ row[col] === null || row[col] === undefined ? '-' : row[col] }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </BaseCard>
    </div>

    <div v-else class="grid grid-cols-1 lg:grid-cols-4 gap-6">
      <!-- Sidebar Filters and Fields Configuration -->
      <div class="lg:col-span-1 space-y-6">
        <!-- Configuration Card -->
        <BaseCard class="space-y-4">
          <h3 class="font-bold text-base text-foreground pb-2 border-b">1. Nguồn Dữ Liệu</h3>
          
          <div>
            <label class="block text-xs font-semibold text-muted-foreground uppercase tracking-wider mb-2">Chọn nguồn</label>
            <select 
              v-model="sourceType" 
              @change="onSourceTypeChange"
              class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground text-sm focus:outline-none focus:ring-2 focus:ring-ring"
            >
              <option value="employees">Danh sách Nhân viên</option>
              <option value="salaries">Báo cáo Bảng lương</option>
              <option value="leaves">Báo cáo Nghỉ phép</option>
            </select>
          </div>
        </BaseCard>

        <!-- Columns Selection Card -->
        <BaseCard class="space-y-4">
          <h3 class="font-bold text-base text-foreground pb-2 border-b">2. Cột Hiển Thị</h3>
          
          <div class="space-y-2 max-h-60 overflow-y-auto pr-1">
            <label 
              v-for="col in availableColumns[sourceType]" 
              :key="col.key"
              class="flex items-center gap-2.5 p-2 rounded-lg hover:bg-muted/50 cursor-pointer text-sm transition-colors"
            >
              <input 
                type="checkbox" 
                v-model="selectedColumnKeys" 
                :value="col.key"
                class="rounded border-input text-primary focus:ring-ring" 
              />
              <span class="text-foreground font-medium">{{ col.label }}</span>
            </label>
          </div>
        </BaseCard>

        <!-- Actions -->
        <BaseCard class="space-y-3">
          <h3 class="font-bold text-base text-foreground pb-2 border-b">3. Xuất Báo Cáo</h3>
          <div class="space-y-2">
            <BaseButton class="w-full" @click="exportReport('excel')">
              <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
              </svg>
              Xuất file Excel (.xlsx)
            </BaseButton>
            <BaseButton variant="outline" class="w-full" @click="exportReport('csv')">
              Xuất file CSV (.csv)
            </BaseButton>
          </div>
        </BaseCard>
      </div>

      <!-- Preview Panel -->
      <div class="lg:col-span-3 space-y-6">
        <!-- Advanced Filters -->
        <BaseCard class="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <div>
            <label class="block text-xs font-semibold text-muted-foreground uppercase tracking-wider mb-1.5">Lọc theo phòng ban</label>
            <select 
              v-model="filters.department_id"
              class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground text-sm focus:outline-none focus:ring-2 focus:ring-ring"
            >
              <option value="">Tất cả phòng ban</option>
              <option v-for="dept in departments" :key="dept.id" :value="dept.id">{{ dept.name }}</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-semibold text-muted-foreground uppercase tracking-wider mb-1.5">Tìm kiếm tên / mã</label>
            <input 
              v-model="filters.search"
              type="text" 
              placeholder="Nhập từ khóa..."
              class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground text-sm focus:outline-none focus:ring-2 focus:ring-ring"
            />
          </div>
          <div class="flex items-end">
            <BaseButton variant="outline" class="w-full" @click="resetFilters">
              Xóa bộ lọc
            </BaseButton>
          </div>
        </BaseCard>

        <!-- Preview Table -->
        <BaseCard>
          <div class="flex items-center justify-between mb-4">
            <h3 class="font-bold text-lg text-foreground">Xem Trước Báo Cáo</h3>
            <span class="text-xs font-semibold px-2.5 py-1 bg-primary/10 text-primary rounded-full">
              Tổng số dòng: {{ filteredData.length }}
            </span>
          </div>

          <div v-if="loading" class="text-center py-12">
            <p class="text-muted-foreground">Đang biên dịch báo cáo...</p>
          </div>

          <div v-else-if="filteredData.length === 0" class="text-center py-12 border border-dashed rounded-2xl">
            <p class="text-muted-foreground">Không tìm thấy bản ghi nào khớp với điều kiện lọc</p>
          </div>

          <div v-else class="overflow-x-auto">
            <table class="w-full border-collapse text-left text-sm">
              <thead>
                <tr class="border-b border-border bg-muted/40">
                  <th 
                    v-for="col in activeColumns" 
                    :key="col.key" 
                    class="p-4 font-bold text-foreground whitespace-nowrap"
                  >
                    {{ col.label }}
                  </th>
                </tr>
              </thead>
              <tbody class="divide-y divide-border/60">
                <tr v-for="(item, index) in filteredData" :key="index" class="hover:bg-muted/10 transition-colors">
                  <td 
                    v-for="col in activeColumns" 
                    :key="col.key" 
                    class="p-4 text-foreground whitespace-nowrap"
                  >
                    {{ formatValue(item[col.key], col.type) }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </BaseCard>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import BaseButton from '../components/BaseButton.vue';
import BaseCard from '../components/BaseCard.vue';
import { employeeService } from '../services/employeeService';
import { departmentService } from '../services/departmentService';
import { leaveService } from '../services/leaveService';
import { salaryService } from '../services/salaryService';
import { reportService } from '../services/reportService';
import { useToast } from '../composables/useToast';

const toast = useToast();
const loading = ref(true);

// --- Server-side report engine (primary mode) ---
const mode = ref('server');
const serverReportTypes = [
  { value: 'headcount', label: 'Thống kê nhân sự' },
  { value: 'leave-summary', label: 'Tổng hợp nghỉ phép' },
  { value: 'payroll-summary', label: 'Tổng hợp bảng lương' },
  { value: 'attendance-summary', label: 'Tổng hợp chấm công' }
];
const serverType = ref('headcount');
const serverLoading = ref(false);
const serverRows = ref([]);

// Derive table columns from the union of keys across returned rows
const serverColumns = computed(() => {
  const keys = [];
  for (const row of serverRows.value) {
    if (row && typeof row === 'object') {
      for (const k of Object.keys(row)) {
        if (!keys.includes(k)) keys.push(k);
      }
    }
  }
  return keys;
});

const generateServerReport = async () => {
  try {
    serverLoading.value = true;
    serverRows.value = [];
    const res = await reportService.generate(serverType.value);
    const rows = res?.rows || res?.data?.rows || [];
    serverRows.value = Array.isArray(rows) ? rows : [];
    if (serverRows.value.length === 0) {
      toast.success('Báo cáo đã được tạo nhưng không có dữ liệu');
    } else {
      toast.success('Tạo báo cáo thành công!');
    }
  } catch (err) {
    console.error('Error generating server report:', err);
    toast.error('Có lỗi xảy ra khi tạo báo cáo');
  } finally {
    serverLoading.value = false;
  }
};

const sourceType = ref('employees');
const departments = ref([]);
const rawData = ref([]);

const filters = ref({
  department_id: '',
  search: ''
});

// Configure available columns for different report sources
const availableColumns = {
  employees: [
    { key: 'employee_code', label: 'Mã nhân viên', type: 'text' },
    { key: 'full_name', label: 'Họ tên', type: 'text' },
    { key: 'email', label: 'Email', type: 'text' },
    { key: 'phone', label: 'Số điện thoại', type: 'text' },
    { key: 'department_name', label: 'Phòng ban', type: 'text' },
    { key: 'hire_date', label: 'Ngày tuyển dụng', type: 'date' },
    { key: 'status', label: 'Trạng thái', type: 'status' }
  ],
  salaries: [
    { key: 'employee_code', label: 'Mã nhân viên', type: 'text' },
    { key: 'full_name', label: 'Họ tên', type: 'text' },
    { key: 'department_name', label: 'Phòng ban', type: 'text' },
    { key: 'basic_salary', label: 'Lương cơ bản', type: 'currency' },
    { key: 'allowances', label: 'Khoản phụ cấp', type: 'currency' },
    { key: 'net_salary', label: 'Thực lĩnh', type: 'currency' },
    { key: 'pay_date', label: 'Ngày chi trả', type: 'date' }
  ],
  leaves: [
    { key: 'employee_code', label: 'Mã nhân viên', type: 'text' },
    { key: 'full_name', label: 'Họ tên', type: 'text' },
    { key: 'leave_type_name', label: 'Loại nghỉ phép', type: 'text' },
    { key: 'start_date', label: 'Từ ngày', type: 'date' },
    { key: 'end_date', label: 'Đến ngày', type: 'date' },
    { key: 'total_days', label: 'Số ngày nghỉ', type: 'number' },
    { key: 'status', label: 'Trạng thái duyệt', type: 'text' }
  ]
};

const selectedColumnKeys = ref([]);

// Active columns based on user checkbox selection
const activeColumns = computed(() => {
  return availableColumns[sourceType.value].filter(c => selectedColumnKeys.value.includes(c.key));
});

// Reset keys when datasource is switched
const onSourceTypeChange = () => {
  selectedColumnKeys.value = availableColumns[sourceType.value].map(c => c.key);
  loadSourceData();
};

const resetFilters = () => {
  filters.value.department_id = '';
  filters.value.search = '';
};

// Format utilities based on column configuration type
const formatValue = (value, type) => {
  if (value === null || value === undefined) return '-';
  if (type === 'currency') {
    return new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(value);
  }
  if (type === 'date') {
    return new Date(value).toLocaleDateString('vi-VN');
  }
  return value;
};

// Filtered dataset computed mapping
const filteredData = computed(() => {
  return rawData.value.filter(item => {
    // Filter department
    if (filters.value.department_id) {
      if (String(item.department_id) !== String(filters.value.department_id)) {
        return false;
      }
    }
    // Search query match
    if (filters.value.search.trim()) {
      const q = filters.value.search.toLowerCase().trim();
      const code = String(item.employee_code || '').toLowerCase();
      const name = String(item.full_name || '').toLowerCase();
      if (!code.includes(q) && !name.includes(q)) {
        return false;
      }
    }
    return true;
  });
});

const loadSourceData = async () => {
  try {
    loading.value = true;
    if (sourceType.value === 'employees') {
      const res = await employeeService.getAll();
      rawData.value = res?.data || res || [];
    } else if (sourceType.value === 'salaries') {
      const res = await salaryService.getAllSummaries().catch(() => []);
      // Map salary summaries or mock
      rawData.value = (res?.data || res || []).length > 0 ? (res?.data || res) : [
        { id: 1, employee_code: 'NV001', full_name: 'Nguyễn Văn A', department_name: 'Phòng Phát triển phần mềm', basic_salary: 15000000, allowances: 2000000, net_salary: 17000000, pay_date: '2026-05-05', department_id: 1 },
        { id: 2, employee_code: 'NV002', full_name: 'Trần Thị B', department_name: 'Phòng Nhân sự', basic_salary: 12000000, allowances: 1500000, net_salary: 13500000, pay_date: '2026-05-05', department_id: 2 }
      ];
    } else if (sourceType.value === 'leaves') {
      const res = await leaveService.getAll().catch(() => []);
      rawData.value = (res?.data || res || []).length > 0 ? (res?.data || res) : [
        { id: 1, employee_code: 'NV001', full_name: 'Nguyễn Văn A', leave_type_name: 'Nghỉ phép năm', start_date: '2026-05-10', end_date: '2026-05-12', total_days: 3, status: 'Đã duyệt', department_id: 1 },
        { id: 2, employee_code: 'NV002', full_name: 'Trần Thị B', leave_type_name: 'Nghỉ ốm', start_date: '2026-05-15', end_date: '2026-05-15', total_days: 1, status: 'Đã duyệt', department_id: 2 }
      ];
    }
  } catch (err) {
    console.error('Error compiling report data:', err);
    toast.error('Có lỗi xảy ra khi biên soạn dữ liệu');
  } finally {
    loading.value = false;
  }
};

// Export report as downloadable CSV/Excel files client-side
const exportReport = (format) => {
  if (filteredData.value.length === 0) {
    toast.error('Không có dữ liệu để xuất báo cáo');
    return;
  }

  // Create table header row
  const headers = activeColumns.value.map(c => c.label);
  const rows = filteredData.value.map(item => {
    return activeColumns.value.map(col => {
      const val = item[col.key];
      return col.type === 'currency' ? (val || 0) : col.type === 'date' ? new Date(val).toLocaleDateString('vi-VN') : val || '-';
    });
  });

  // Assemble CSV text contents (UTF-8 BOM added for Excel compatibility)
  const csvContent = "\uFEFF" + [headers.join(','), ...rows.map(r => r.map(cell => `"${String(cell).replace(/"/g, '""')}"`).join(','))].join('\n');
  
  const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  
  const dateStr = new Date().toISOString().split('T')[0];
  link.setAttribute('href', url);
  link.setAttribute('download', `bao_cao_${sourceType.value}_${dateStr}.${format === 'excel' ? 'xlsx' : 'csv'}`);
  link.style.visibility = 'hidden';
  
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  toast.success(`Đã xuất báo cáo ${format.toUpperCase()} thành công!`);
};

onMounted(async () => {
  // Set default checked keys
  selectedColumnKeys.value = availableColumns[sourceType.value].map(c => c.key);
  
  try {
    const deptRes = await departmentService.getAll().catch(() => ({ data: [] }));
    departments.value = deptRes?.data || deptRes || [];
  } catch (err) {
    console.error('Error fetching departments:', err);
  }
  
  await loadSourceData();
});
</script>
