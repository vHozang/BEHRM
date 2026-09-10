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
      <BaseButton :variant="mode === 'templates' ? 'primary' : 'outline'" @click="mode = 'templates'">
        Mẫu & lịch sử
      </BaseButton>
    </BaseCard>

    <!-- Server-side report engine (primary) -->
    <div v-if="mode === 'server'" class="space-y-6">
      <BaseCard class="grid grid-cols-1 sm:grid-cols-3 gap-4 items-end">
        <div :class="needsPeriod ? '' : 'sm:col-span-2'">
          <label class="block text-xs font-semibold text-muted-foreground uppercase tracking-wider mb-1.5">Loại báo cáo</label>
          <select
            v-model="serverType"
            data-testid="select-report-type"
            class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground text-sm focus:outline-none focus:ring-2 focus:ring-ring"
          >
            <option v-for="t in serverReportTypes" :key="t.value" :value="t.value">{{ t.label }}</option>
          </select>
        </div>
        <!-- Báo cáo theo kỳ (chi phí lao động, tờ khai BHXH, quyết toán thuế) -->
        <div v-if="needsPeriod">
          <label class="block text-xs font-semibold text-muted-foreground uppercase tracking-wider mb-1.5">Kỳ lương</label>
          <select
            v-model="serverPeriodId"
            data-testid="select-report-period"
            class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground text-sm focus:outline-none focus:ring-2 focus:ring-ring"
          >
            <option value="">— Chọn kỳ —</option>
            <option v-for="p in salaryPeriods" :key="p.id" :value="p.id">
              {{ p.period_code }}{{ p.legal_entity_name ? ' · ' + p.legal_entity_name : '' }}
            </option>
          </select>
        </div>
        <BaseButton class="w-full" :disabled="serverLoading" @click="generateServerReport">
          {{ serverLoading ? 'Đang tạo...' : 'Tạo báo cáo' }}
        </BaseButton>
      </BaseCard>

      <BaseCard>
        <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
          <h3 class="font-bold text-lg text-foreground">Kết Quả Báo Cáo</h3>
          <div class="flex items-center gap-2">
            <span v-if="serverRows.length" class="text-xs font-semibold px-2.5 py-1 bg-primary/10 text-primary rounded-full">
              Tổng số dòng: {{ serverRows.length }}
            </span>
            <BaseButton v-if="serverRows.length" variant="outline" size="sm" data-testid="button-export-server-report" @click="exportServerReport">
              ⬇ Xuất CSV
            </BaseButton>
          </div>
        </div>

        <!-- Dòng tổng cộng của tờ khai BHXH / quyết toán thuế -->
        <div v-if="serverTotals" class="mb-4 p-3 rounded-xl bg-muted/50 grid grid-cols-2 sm:grid-cols-4 gap-2 text-sm">
          <div v-for="(v, k) in serverTotals" :key="k">
            <p class="text-[11px] text-muted-foreground">{{ k }}</p>
            <p class="font-semibold tabular-nums">{{ typeof v === 'number' ? v.toLocaleString('vi-VN') : v }}</p>
          </div>
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

    <ReportTemplatesPanel v-else-if="mode === 'templates'" />

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
            <BaseButton class="w-full" @click="exportReport">
              <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
              </svg>
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
import ReportTemplatesPanel from '../components/ReportTemplatesPanel.vue';
import { employeeService } from '../services/employeeService';
import { departmentService } from '../services/departmentService';
import { leaveService } from '../services/leaveService';
import { salaryService } from '../services/salaryService';
import { reportService } from '../services/reportService';
import { useToast } from '../composables/useToast';
import { downloadCsv } from '../utils/csv';

const toast = useToast();
const loading = ref(true);

// --- Server-side report engine (primary mode) ---
const mode = ref('server');
const serverReportTypes = [
  { value: 'hr-metrics', label: '★ Bảng chỉ số nhân sự (tỷ lệ đi muộn, vắng, phép, tuân thủ)' },
  { value: 'headcount', label: 'Thống kê nhân sự theo phòng ban' },
  { value: 'workforce-structure', label: 'Cơ cấu lao động (giới tính / tuổi / thâm niên / trình độ / HĐ)' },
  { value: 'leave-summary', label: 'Tổng hợp nghỉ phép' },
  { value: 'leave-liability', label: 'Quỹ phép phải trả (trích trước chi phí)' },
  { value: 'attendance-summary', label: 'Tổng hợp chấm công' },
  { value: 'payroll-summary', label: 'Tổng hợp bảng lương' },
  { value: 'labor-cost', label: 'Chi phí lao động theo phòng ban (cần chọn kỳ)' },
  { value: 'bhxh-declaration', label: 'Tờ khai BHXH theo kỳ (cần chọn kỳ)' },
  { value: 'pit-finalization', label: 'Quyết toán thuế TNCN theo kỳ (cần chọn kỳ)' },
];
// Các báo cáo tính theo 1 kỳ lương → bắt buộc chọn kỳ trước khi tạo.
const PERIOD_REPORTS = ['labor-cost', 'bhxh-declaration', 'pit-finalization'];
const serverType = ref('headcount');
const serverLoading = ref(false);
const serverRows = ref([]);
const salaryPeriods = ref([]);
const serverPeriodId = ref('');
const serverTotals = ref(null);   // dòng tổng cộng của tờ khai BHXH / quyết toán thuế
const needsPeriod = computed(() => PERIOD_REPORTS.includes(serverType.value));

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
  if (needsPeriod.value && !serverPeriodId.value) {
    toast.error('Vui lòng chọn kỳ lương cho báo cáo này');
    return;
  }
  try {
    serverLoading.value = true;
    serverRows.value = [];
    const filters = needsPeriod.value ? { period_id: Number(serverPeriodId.value) } : {};
    const res = await reportService.generate(serverType.value, filters);
    // BHXH / quyết toán thuế trả { rows: { rows: [...], totals: {...} } } — bóc thêm 1 lớp.
    let rows = res?.rows ?? res?.data?.rows ?? [];
    serverTotals.value = (!Array.isArray(rows) && rows?.totals) ? rows.totals : null;
    if (!Array.isArray(rows)) rows = rows?.rows ?? [];
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

// Xuất CSV cho báo cáo hệ thống (trước đây chỉ chế độ "tự thiết kế" mới xuất được).
const exportServerReport = () => {
  if (!serverRows.value.length) return;
  const cols = serverColumns.value;
  const rows = serverRows.value.map(r => cols.map(c => r?.[c] ?? ''));
  const label = serverReportTypes.find(t => t.value === serverType.value)?.label || serverType.value;
  const period = needsPeriod.value
    ? (salaryPeriods.value.find(p => String(p.id) === String(serverPeriodId.value))?.period_code || '')
    : '';
  const head = [[label + (period ? ` — kỳ ${period}` : '')], ['Ngày xuất', new Date().toLocaleString('vi-VN')], []];
  const foot = serverTotals.value
    ? [[], ['TỔNG CỘNG'], ...Object.entries(serverTotals.value).map(([k, v]) => [k, v])]
    : [];
  downloadCsv([...head, cols, ...rows, ...foot],
    `bao_cao_${serverType.value}${period ? '_' + period : ''}_${new Date().toISOString().slice(0, 10)}.csv`);
  toast.success('Đã xuất báo cáo ra CSV');
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
    { key: 'period_code', label: 'Kỳ lương', type: 'text' },
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
      rawData.value = res?.data || res || [];
    } else if (sourceType.value === 'leaves') {
      const res = await leaveService.getAll().catch(() => []);
      rawData.value = res?.data || res || [];
    }
  } catch (err) {
    console.error('Error compiling report data:', err);
    toast.error('Có lỗi xảy ra khi biên soạn dữ liệu');
  } finally {
    loading.value = false;
  }
};

// Export report as a UTF-8 CSV that opens cleanly in Excel.
const exportReport = () => {
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

  const dateStr = new Date().toISOString().split('T')[0];
  downloadCsv([headers, ...rows], `bao_cao_${sourceType.value}_${dateStr}.csv`);
  toast.success('Đã xuất báo cáo CSV thành công!');
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

  // Kỳ lương cho các báo cáo theo kỳ (chi phí lao động, BHXH, quyết toán thuế).
  try {
    const res = await salaryService.getPeriods({ per_page: 100 }).catch(() => []);
    salaryPeriods.value = Array.isArray(res) ? res : (res?.items || res?.data || []);
  } catch (err) {
    console.error('Error fetching salary periods:', err);
  }

  await loadSourceData();
});
</script>
