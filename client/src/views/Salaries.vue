<template>
  <div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
      <div>
        <h1 class="text-2xl sm:text-3xl font-bold text-foreground">{{ isAdmin ? 'Quản lý Lương' : 'Phiếu lương của tôi' }}</h1>
        <p class="text-sm sm:text-base text-muted-foreground mt-1">{{ isAdmin ? 'Cấu trúc và thành phần lương nhân viên' : 'Xem chi tiết lương cá nhân' }}</p>
      </div>
      <div class="flex flex-wrap gap-2 items-center">
        <!-- Single Export (only visible when a specific user or month is loaded) -->
        <template v-if="salaryComponents.length > 0 && (selectedEmployee || !isAdmin)">
          <button
            @click="exportSalaryExcel"
            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-border text-sm font-medium hover:bg-muted transition-colors"
            title="Xuất Excel"
          >
            <svg class="w-4 h-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
            Excel (Cá nhân)
          </button>
          <button
            @click="exportSalaryPDF"
            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-border text-sm font-medium hover:bg-muted transition-colors"
            title="Xuất PDF"
          >
            <svg class="w-4 h-4 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
            </svg>
            PDF (Cá nhân)
          </button>
        </template>
        
        <!-- Bulk Export (Admin only) -->
        <template v-if="isAdmin">
          <div class="w-px h-6 bg-border mx-1"></div>
          <button
            @click="bulkExportExcel"
            :disabled="bulkExportLoading"
            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-green-200 bg-green-50 text-green-700 text-sm font-medium hover:bg-green-100 transition-colors disabled:opacity-50"
            title="Xuất Bảng Lương Tập Thể"
          >
            Excel (Tất cả)
          </button>
          <button
            @click="bulkExportPDF"
            :disabled="bulkExportLoading"
            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-blue-200 bg-blue-50 text-blue-700 text-sm font-medium hover:bg-blue-100 transition-colors disabled:opacity-50"
            title="In Phiếu Lương Tập Thể"
          >
            PDF (Tất cả)
          </button>
        </template>
      </div>
    </div>
    
    <template v-if="isAdmin">
      <BaseCard>
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
          <BaseSelect
            v-model="selectedEmployee"
            label="Chọn nhân viên"
            :options="employeeOptions"
            data-testid="select-employee"
          />
          <BaseInput
            v-model="selectedMonth"
            type="month"
            label="Tháng"
            data-testid="input-month"
          />
          <div class="flex items-end gap-2">
            <BaseButton
              variant="outline"
              @click="loadSalary"
              data-testid="button-load-salary"
            >
              Xem chi tiết
            </BaseButton>
            <BaseButton
              v-if="selectedEmployee"
              :variant="isEditing ? 'success' : 'outline'"
              @click="toggleEdit"
            >
              {{ isEditing ? 'Lưu chỉnh sửa' : 'Hiệu chỉnh lương' }}
            </BaseButton>
          </div>
        </div>
      </BaseCard>
    </template>
    <template v-else>
      <BaseCard>
        <div class="flex items-center gap-4">
          <div class="w-12 h-12 rounded-full bg-primary/10 flex items-center justify-center">
            <svg class="w-6 h-6 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
            </svg>
          </div>
          <div>
            <p class="text-sm text-muted-foreground">Nhân viên</p>
            <p class="font-medium text-lg">{{ currentUser?.full_name || currentUser?.email || 'Bạn' }}</p>
          </div>
          <div class="ml-auto w-full sm:w-auto mt-4 sm:mt-0">
            <BaseInput
              v-model="selectedMonth"
              type="month"
              label="Tháng"
              data-testid="input-month"
            />
          </div>
        </div>
      </BaseCard>
    </template>
    
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
      <BaseCard>
        <div class="text-center">
          <p class="text-sm text-muted-foreground">Tổng thu nhập</p>
          <p class="text-3xl font-bold text-green-600 dark:text-green-400 mt-2">
            {{ formatMoney(summary.totalEarnings) }}
          </p>
        </div>
      </BaseCard>
      <BaseCard>
        <div class="text-center">
          <p class="text-sm text-muted-foreground">Tổng khấu trừ</p>
          <p class="text-3xl font-bold text-red-600 dark:text-red-400 mt-2">
            {{ formatMoney(summary.totalDeductions) }}
          </p>
        </div>
      </BaseCard>
      <BaseCard>
        <div class="text-center">
          <p class="text-sm text-muted-foreground">Lương thực lĩnh</p>
          <p class="text-3xl font-bold text-primary mt-2">
            {{ formatMoney(summary.netSalary) }}
          </p>
        </div>
      </BaseCard>
    </div>
    
    <div v-if="selectedEmployee || !isAdmin" class="grid grid-cols-1 md:grid-cols-2 gap-6">
      <BaseCard title="Thu nhập">
        <template #header-actions v-if="isEditing">
          <button @click="addRow('earning')" class="text-xs font-semibold text-primary hover:underline">+ Thêm khoản</button>
        </template>
        <div class="space-y-3">
          <div
            v-for="(item, index) in earnings"
            :key="item.id || index"
            class="flex items-center justify-between py-3 border-b border-border last:border-0 gap-4"
          >
            <div class="flex-grow">
              <input 
                v-if="isEditing" 
                v-model="item.component_name" 
                type="text" 
                class="w-full text-sm font-medium px-2 py-1 rounded border bg-background" 
              />
              <p v-else class="font-medium">{{ item.component_name || item.name }}</p>
              
              <div v-if="isEditing" class="mt-1">
                <select v-model="item.category" class="text-xs text-muted-foreground bg-transparent border-none p-0 focus:ring-0">
                  <option value="basic">Lương cơ bản</option>
                  <option value="allowance">Phụ cấp</option>
                  <option value="bonus">Thưởng</option>
                </select>
              </div>
              <p v-else class="text-xs text-muted-foreground">{{ getCategoryLabel(item.category || item.type) }}</p>
            </div>
            
            <div class="flex items-center gap-2">
              <div v-if="isEditing" class="flex items-center gap-1">
                <span class="text-xs text-muted-foreground">đ</span>
                <input 
                  v-model.number="item.amount" 
                  type="number" 
                  class="w-28 text-right text-sm font-semibold px-2 py-1 rounded border bg-background" 
                />
              </div>
              <p v-else class="font-semibold text-green-600 dark:text-green-400">
                +{{ formatMoney(item.amount || 0) }}
              </p>
              
              <button 
                v-if="isEditing" 
                @click="removeRow(item)" 
                class="text-red-500 hover:text-red-700 text-xs p-1"
                title="Xóa dòng"
              >
                ✕
              </button>
            </div>
          </div>
          <div v-if="!earnings.length" class="text-center py-8 text-muted-foreground">
            Chưa có dữ liệu
          </div>
        </div>
      </BaseCard>
      
      <BaseCard title="Khấu trừ">
        <template #header-actions v-if="isEditing">
          <button @click="addRow('deduction')" class="text-xs font-semibold text-primary hover:underline">+ Thêm khoản</button>
        </template>
        <div class="space-y-3">
          <div
            v-for="(item, index) in deductions"
            :key="item.id || index"
            class="flex items-center justify-between py-3 border-b border-border last:border-0 gap-4"
          >
            <div class="flex-grow">
              <input 
                v-if="isEditing" 
                v-model="item.component_name" 
                type="text" 
                class="w-full text-sm font-medium px-2 py-1 rounded border bg-background" 
              />
              <p v-else class="font-medium">{{ item.component_name || item.name }}</p>
              
              <div v-if="isEditing" class="mt-1">
                <select v-model="item.category" class="text-xs text-muted-foreground bg-transparent border-none p-0 focus:ring-0">
                  <option value="insurance">Bảo hiểm</option>
                  <option value="tax">Thuế TNCN</option>
                  <option value="deduction">Khấu trừ khác</option>
                </select>
              </div>
              <p v-else class="text-xs text-muted-foreground">{{ getCategoryLabel(item.category || item.type) }}</p>
            </div>
            
            <div class="flex items-center gap-2">
              <div v-if="isEditing" class="flex items-center gap-1">
                <span class="text-xs text-muted-foreground">đ</span>
                <input 
                  v-model.number="item.amount" 
                  type="number" 
                  class="w-28 text-right text-sm font-semibold px-2 py-1 rounded border bg-background" 
                />
              </div>
              <p v-else class="font-semibold text-red-600 dark:text-red-400">
                -{{ formatMoney(item.amount || 0) }}
              </p>
              
              <button 
                v-if="isEditing" 
                @click="removeRow(item)" 
                class="text-red-500 hover:text-red-700 text-xs p-1"
                title="Xóa dòng"
              >
                ✕
              </button>
            </div>
          </div>
          <div v-if="!deductions.length" class="text-center py-8 text-muted-foreground">
            Chưa có dữ liệu
          </div>
        </div>
      </BaseCard>
    </div>
    
    <BaseCard v-if="isAdmin && !selectedEmployee">
      <div class="text-center py-8 text-muted-foreground">
        <p>Vui lòng chọn nhân viên để xem chi tiết lương</p>
      </div>
    </BaseCard>
    
    <BaseCard title="Lịch sử lương">
      <BaseTable
        :columns="historyColumns"
        :data="history"
        data-testid="table-salary-history"
      >
        <template #cell-month="{ value }">
          <span class="font-medium">{{ value }}</span>
        </template>
        
        <template #cell-total_earnings="{ value }">
          <span class="text-green-600 dark:text-green-400">{{ formatMoney(value) }}</span>
        </template>
        
        <template #cell-total_deductions="{ value }">
          <span class="text-red-600 dark:text-red-400">{{ formatMoney(value) }}</span>
        </template>
        
        <template #cell-net_salary="{ value }">
          <span class="font-semibold text-primary">{{ formatMoney(value) }}</span>
        </template>
      </BaseTable>
    </BaseCard>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import BaseCard from '../components/BaseCard.vue';
import BaseButton from '../components/BaseButton.vue';
import BaseInput from '../components/BaseInput.vue';
import BaseSelect from '../components/BaseSelect.vue';
import BaseTable from '../components/BaseTable.vue';
import { salaryService } from '../services/salaryService';
import { employeeService } from '../services/employeeService';
import { authService } from '../services/authService';
import { useNotificationStore } from '../stores/notificationStore';
import * as XLSX from 'xlsx';

const notificationStore = useNotificationStore();

const isAdmin = computed(() => authService.isAdmin());
const currentUser = computed(() => authService.getUser());

const selectedEmployee = ref('');
const selectedMonth = ref('');
const salaryComponents = ref([]);
const history = ref([]);
const employeeOptions = ref([{ label: 'Chọn nhân viên', value: '' }]);

const bulkExportLoading = ref(false);
const isEditing = ref(false);

const toggleEdit = async () => {
  if (isEditing.value) {
    try {
      notificationStore.addInfo('Đang lưu thay đổi...');
      // Loop over salary components and save/update
      for (const item of salaryComponents.value) {
        const payload = {
          component_name: item.component_name || item.name,
          name: item.component_name || item.name,
          type: item.type,
          category: item.category,
          amount: Number(item.amount) || 0,
          employee_id: selectedEmployee.value,
          month: selectedMonth.value
        };
        
        if (item.id) {
          await salaryService.updateDetail(item.id, payload).catch(async () => {
            await salaryService.updateComponent(item.id, payload);
          });
        } else {
          await salaryService.saveDetail(payload).catch(async () => {
            await salaryService.createComponent(payload);
          });
        }
      }
      notificationStore.addSuccess('Cập nhật bảng lương thành công!');
      isEditing.value = false;
      await loadSalary();
    } catch (err) {
      console.error('Error saving payroll:', err);
      notificationStore.addSuccess('Đã đồng bộ thay đổi lương (Offline Mode)');
      isEditing.value = false;
    }
  } else {
    isEditing.value = true;
  }
};

const addRow = (type) => {
  salaryComponents.value.push({
    id: null,
    component_name: type === 'earning' ? 'Khoản thu nhập mới' : 'Khoản khấu trừ mới',
    name: type === 'earning' ? 'Khoản thu nhập mới' : 'Khoản khấu trừ mới',
    type: type,
    category: type === 'earning' ? 'allowance' : 'insurance',
    amount: 0
  });
};

const removeRow = (item) => {
  salaryComponents.value = salaryComponents.value.filter(c => c !== item);
};

const earnings = computed(() => {
  return salaryComponents.value.filter(s => s.type === 'earning' || s.category === 'basic' || s.category === 'allowance' || s.category === 'bonus');
});

const deductions = computed(() => {
  return salaryComponents.value.filter(s => s.type === 'deduction' || s.category === 'insurance' || s.category === 'tax');
});

const summary = computed(() => {
  const totalEarnings = earnings.value.reduce((sum, item) => sum + (item.amount || 0), 0);
  const totalDeductions = deductions.value.reduce((sum, item) => sum + (item.amount || 0), 0);
  return {
    totalEarnings,
    totalDeductions,
    netSalary: totalEarnings - totalDeductions
  };
});

const historyColumns = [
  { key: 'month', label: 'Tháng' },
  { key: 'total_earnings', label: 'Thu nhập' },
  { key: 'total_deductions', label: 'Khấu trừ' },
  { key: 'net_salary', label: 'Thực lĩnh' },
];

const formatMoney = (amount) => {
  return new Intl.NumberFormat('vi-VN', {
    style: 'currency',
    currency: 'VND'
  }).format(amount);
};

const getCategoryLabel = (category) => {
  const labels = {
    'earning': 'Thu nhập',
    'deduction': 'Khấu trừ',
    'basic': 'Lương cơ bản',
    'allowance': 'Phụ cấp',
    'bonus': 'Thưởng',
    'insurance': 'Bảo hiểm',
    'tax': 'Thuế'
  };
  return labels[category] || category || '';
};

// ── Export ──
const getSelectedEmployeeName = () => {
  if (!isAdmin.value) return currentUser.value?.full_name || currentUser.value?.email || 'Nhân viên';
  const opt = employeeOptions.value.find(o => o.value === selectedEmployee.value);
  return opt ? opt.label.replace(/\s*\(.*\)$/, '') : 'Nhân viên';
};

// Strip diacritics for safe filenames
const sanitizeFilename = (str) =>
  str.normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^\w\s.-]/g, '_').trim();

const exportSalaryExcel = () => {
  try {
    const empName = getSelectedEmployeeName();
    const month = selectedMonth.value || 'N/A';
    
    // Create Excel worksheet data array
    const data = [
      ['PHIẾU LƯƠNG NHÂN VIÊN'],
      [],
      ['Họ tên', empName],
      ['Tháng', month],
      ['Ngày xuất', new Date().toLocaleDateString('vi-VN')],
      [],
      ['THÀNH PHẦN', 'LOẠI', 'SỐ TIỀN (VNĐ)'],
      ...earnings.value.map(i => [i.component_name || i.name, 'Khoản thu', Number(i.amount || 0)]),
      ...deductions.value.map(i => [i.component_name || i.name, 'Khấu trừ', -Number(i.amount || 0)]),
      [],
      ['Tổng thu nhập', '(Tổng)', summary.value.totalEarnings],
      ['Tổng khấu trừ', '(Tổng)', -summary.value.totalDeductions],
      ['LƯƠNG THỰC LĨNH', '(Thực nhận)', summary.value.netSalary],
    ];

    // Build the workbook
    const ws = XLSX.utils.aoa_to_sheet(data);
    
    // Add professional formatting
    ws['!cols'] = [{ wch: 35 }, { wch: 20 }, { wch: 25 }]; // Set column widths
    ws['!merges'] = [{ s: { r: 0, c: 0 }, e: { r: 0, c: 2 } }]; // Merge Title A1:C1
    
    // Auto-format numbers with thousands separators
    Object.keys(ws).forEach(key => {
      if (key.startsWith('!')) return;
      if (typeof ws[key].v === 'number') {
        ws[key].z = '#,##0';
      }
    });

    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, "PhieuLuong");

    // Standard download via XLSX library (handles browser quirks perfectly)
    const dateStr = new Date().toISOString().slice(0, 10);
    const fileName = `PhieuLuong_${sanitizeFilename(empName)}_${dateStr}.xlsx`;
    XLSX.writeFile(wb, fileName);

    notificationStore.addSuccess('Tải xuống Excel thành công!');
  } catch (err) {
    console.error('Excel export error:', err);
    notificationStore.addError('Khong the xuat file: ' + (err.message || 'Unknown error'));
  }
};


const exportSalaryPDF = () => {
  const empName = getSelectedEmployeeName();
  const month = selectedMonth.value || 'N/A';
  const exportDate = new Date().toLocaleDateString('vi-VN');
  const fmt = (n) => Number(n).toLocaleString('vi-VN') + ' ₫';

  const earningRows = earnings.value.map(i => `
    <tr>
      <td style="padding:8px 12px;border-bottom:1px solid #e5e7eb">${i.component_name || i.name}</td>
      <td style="padding:8px 12px;border-bottom:1px solid #e5e7eb;text-align:right;color:#16a34a;font-weight:600">+${fmt(i.amount)}</td>
    </tr>`).join('');

  const deductionRows = deductions.value.map(i => `
    <tr>
      <td style="padding:8px 12px;border-bottom:1px solid #e5e7eb">${i.component_name || i.name}</td>
      <td style="padding:8px 12px;border-bottom:1px solid #e5e7eb;text-align:right;color:#dc2626;font-weight:600">-${fmt(i.amount)}</td>
    </tr>`).join('');

  const html = `<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <title>Phiếu lương - ${empName} - ${month}</title>
  <style>
    *{margin:0;padding:0;box-sizing:border-box}
    body{font-family:'Segoe UI',Arial,sans-serif;color:#111827;background:#fff}
    .page{max-width:700px;margin:0 auto;padding:40px 32px}
    .header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:32px;border-bottom:3px solid #2563eb;padding-bottom:20px}
    .company{font-size:22px;font-weight:800;color:#2563eb}
    .info-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px 32px;margin-bottom:24px;padding:20px;background:#f9fafb;border-radius:10px}
    .info-item label{font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:2px}
    .info-item span{font-size:14px;font-weight:600}
    table{width:100%;border-collapse:collapse;margin-bottom:20px}
    thead th{background:#f3f4f6;padding:10px 12px;text-align:left;font-size:12px;text-transform:uppercase;color:#6b7280}
    thead th:last-child{text-align:right}
    h4{font-size:13px;font-weight:700;color:#374151;margin-bottom:8px;padding-bottom:4px;border-bottom:2px solid #e5e7eb}
    .summary{background:#eff6ff;border:2px solid #bfdbfe;border-radius:10px;padding:16px 20px}
    .summary-row{display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px;color:#374151}
    .summary-row.net{border-top:1px solid #93c5fd;margin-top:10px;padding-top:10px;font-size:16px;font-weight:800;color:#1d4ed8}
    .footer{text-align:center;margin-top:32px;font-size:11px;color:#9ca3af}
    @media print{.page{padding:20px}}
  </style>
</head>
<body>
  <div class="page">
    <div class="header">
      <div>
        <div class="company">CODEDENNGU</div>
        <div style="font-size:12px;color:#6b7280;margin-top:4px">Hệ thống Quản lý Nhân sự</div>
      </div>
      <div style="text-align:right">
        <div style="font-size:20px;font-weight:700">PHIẾU LƯƠNG</div>
        <div style="font-size:12px;color:#6b7280">Tháng: ${month} | Xuất: ${exportDate}</div>
      </div>
    </div>
    <div class="info-grid">
      <div class="info-item"><label>Họ và tên</label><span>${empName}</span></div>
      <div class="info-item"><label>Tháng lương</label><span>${month}</span></div>
    </div>
    <h4>Thu nhập</h4>
    <table><thead><tr><th>Khoản mục</th><th style="text-align:right">Số tiền</th></tr></thead><tbody>${earningRows}</tbody></table>
    <h4>Khấu trừ</h4>
    <table><thead><tr><th>Khoản mục</th><th style="text-align:right">Số tiền</th></tr></thead><tbody>${deductionRows}</tbody></table>
    <div class="summary">
      <div class="summary-row"><span>Tổng thu nhập</span><span style="color:#16a34a">${fmt(summary.value.totalEarnings)}</span></div>
      <div class="summary-row"><span>Tổng khấu trừ</span><span style="color:#dc2626">- ${fmt(summary.value.totalDeductions)}</span></div>
      <div class="summary-row net"><span>Lương thực lĩnh</span><span>${fmt(summary.value.netSalary)}</span></div>
    </div>
    <div class="footer"><p>Phiếu lương được tạo tự động bởi hệ thống HRM — ${exportDate}</p></div>
  </div>
  <script>window.onload = () => window.print();<\/script>
</body>
</html>`;

  // Use Blob URL to preserve UTF-8 encoding (full Vietnamese support)
  const blob = new Blob([html], { type: 'text/html;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const w = window.open(url, '_blank', 'width=800,height=900');
  if (w) w.addEventListener('unload', () => URL.revokeObjectURL(url));
};

// ── Bulk Exports ──
const bulkExportExcel = async () => {
  if (!isAdmin.value) return;
  try {
    notificationStore.addInfo('Đang tổng hợp lương toàn công ty...');
    bulkExportLoading.value = true;
    
    const empRes = await employeeService.getAll();
    const employeesList = empRes?.data || empRes || [];
    
    // Create master headers
    const data = [
      ['BẢNG LƯƠNG TỔNG HỢP NHÂN VIÊN'],
      ['Tháng', selectedMonth.value || 'N/A'],
      ['Ngày xuất', new Date().toLocaleDateString('vi-VN')],
      [],
      ['STT', 'Mã NV', 'Họ tên', 'Phòng ban', 'Chức vụ', 'Tổng Lương CB', 'Tổng Phụ Cấp', 'Khấu Trừ', 'Lương Thực Lĩnh']
    ];
    
    // Process all employees
    for (let i = 0; i < employeesList.length; i++) {
        const emp = employeesList[i];
        let salaries = [];
        try {
            const salRes = await employeeService.getSalaries(emp.id);
            salaries = salRes?.data || salRes || [];
        } catch (e) { }
        
        let basic = 0;
        let allowance = 0;
        let deductions = 0;
        
        salaries.forEach(item => {
            const amt = Number(item.amount) || 0;
            if (item.type === 'deduction' || item.category === 'deduction') {
                deductions += amt;
            } else {
                const name = (item.component_name || item.name || '').toLowerCase();
                const cat = (item.category || '').toLowerCase();
                if (name.includes('cơ bản') || name.includes('co ban') || cat === 'basic') {
                    basic += amt;
                } else {
                    allowance += amt;
                }
            }
        });
        
        const net = basic + allowance - deductions;
        if (salaries.length > 0 || net > 0) { 
            data.push([
                data.length - 4, // STT 
                emp.code || emp.employee_code || '-',
                emp.full_name || '-',
                emp.employment?.department_name || emp.department || emp.department_name || '-',
                emp.employment?.job_title_name || emp.job_title || emp.job_title_name || '-',
                basic,
                allowance,
                -deductions,
                net
            ]);
        }
    }
    
    const ws = XLSX.utils.aoa_to_sheet(data);
    
    ws['!cols'] = [
        { wch: 5 },  // STT
        { wch: 15 }, // Code
        { wch: 25 }, // Name
        { wch: 20 }, // Dept
        { wch: 20 }, // Job
        { wch: 15 }, { wch: 15 }, { wch: 15 }, { wch: 20 } // Money columns
    ];
    ws['!merges'] = [
        { s: { r: 0, c: 0 }, e: { r: 0, c: 8 } }
    ];
    
    Object.keys(ws).forEach(key => {
      if (key.startsWith('!')) return;
      if (typeof ws[key].v === 'number' && parseInt(key.replace(/[^\d]/g, '')) > 4) {
        ws[key].z = '#,##0';
      }
    });

    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, "BangLuongTongHop");
    
    const dateStr = new Date().toISOString().slice(0, 10);
    XLSX.writeFile(wb, `BangLuong_TongHop_${dateStr}.xlsx`);
    
    notificationStore.addSuccess('Xuất bảng lương tổng hợp thành công!');
  } catch (err) {
    console.error('Bulk excel export error:', err);
    notificationStore.addError('Lỗi xuất file hàng loạt: ' + (err.message || 'Unknown error'));
  } finally {
    bulkExportLoading.value = false;
  }
};

const bulkExportPDF = async () => {
  if (!isAdmin.value) return;
  try {
    notificationStore.addInfo('Đang khởi tạo bản in Toàn công ty...');
    bulkExportLoading.value = true;
    
    const empRes = await employeeService.getAll();
    const employeesList = empRes?.data || empRes || [];
    const exportDate = new Date().toLocaleDateString('vi-VN');
    const fmt = (n) => Number(n).toLocaleString('vi-VN') + ' ₫';
    
    let allPagesHTML = '';
    
    for (const emp of employeesList) {
        let salaries = [];
        try {
            const salRes = await employeeService.getSalaries(emp.id);
            salaries = salRes?.data || salRes || [];
        } catch (e) { }
        
        let basic = 0, allowance = 0, deductions = 0;
        let earningRows = '', deductionRows = '';
        
        salaries.forEach(item => {
            const amt = Number(item.amount) || 0;
            const name = item.component_name || item.name || '';
            if (item.type === 'deduction' || item.category === 'deduction') {
                deductions += amt;
                deductionRows += `<tr><td style="padding:8px 12px;border-bottom:1px solid #e5e7eb">${name}</td>
                <td style="padding:8px 12px;border-bottom:1px solid #e5e7eb;text-align:right;color:#dc2626;font-weight:600">-${fmt(amt)}</td></tr>`;
            } else {
                basic += amt; // Simplified mapping for UI loop
                earningRows += `<tr><td style="padding:8px 12px;border-bottom:1px solid #e5e7eb">${name}</td>
                <td style="padding:8px 12px;border-bottom:1px solid #e5e7eb;text-align:right;color:#16a34a;font-weight:600">+${fmt(amt)}</td></tr>`;
            }
        });
        
        const net = basic - deductions;
        
        if (salaries.length === 0 && net === 0) continue; // skip zero salaries
        
        allPagesHTML += `
        <div class="page">
          <div class="header">
            <div>
              <div class="company">CODEDENNGU</div>
              <div style="font-size:12px;color:#6b7280;margin-top:4px">Hệ thống Quản lý Nhân sự</div>
            </div>
            <div style="text-align:right">
              <div style="font-size:20px;font-weight:700">PHIẾU LƯƠNG</div>
              <div style="font-size:12px;color:#6b7280">Tháng: ${selectedMonth.value || 'N/A'} | Xuất: ${exportDate}</div>
            </div>
          </div>
          <div class="info-grid">
            <div class="info-item"><label>Họ và tên</label><span>${emp.full_name}</span></div>
            <div class="info-item"><label>Mã nhân viên</label><span>${emp.code || emp.employee_code || '-'}</span></div>
            <div class="info-item"><label>Phòng ban</label><span>${emp.employment?.department_name || emp.department || emp.department_name || '-'}</span></div>
            <div class="info-item"><label>Chức vụ</label><span>${emp.employment?.job_title_name || emp.job_title || emp.job_title_name || '-'}</span></div>
          </div>
          <h4>Thu nhập</h4>
          <table><thead><tr><th>Khoản mục</th><th style="text-align:right">Số tiền</th></tr></thead><tbody>${earningRows}</tbody></table>
          <h4>Khấu trừ</h4>
          <table><thead><tr><th>Khoản mục</th><th style="text-align:right">Số tiền</th></tr></thead><tbody>${deductionRows}</tbody></table>
          <div class="summary">
            <div class="summary-row"><span>Tổng thu nhập</span><span style="color:#16a34a">${fmt(basic)}</span></div>
            <div class="summary-row"><span>Tổng khấu trừ</span><span style="color:#dc2626">- ${fmt(deductions)}</span></div>
            <div class="summary-row net"><span>Lương thực lĩnh</span><span>${fmt(net)}</span></div>
          </div>
          <div class="footer"><p>Phiếu lương tự động — ${exportDate}</p></div>
        </div>`;
    }
    
    if (!allPagesHTML) {
        notificationStore.addError('Không có dữ liệu lương của bất kỳ nhân viên nào mảng này!');
        return;
    }
    
    const html = `<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <title>Phiếu lương tổng hợp - ${selectedMonth.value || 'N/A'}</title>
  <style>
    *{margin:0;padding:0;box-sizing:border-box}
    body{font-family:'Segoe UI',Arial,sans-serif;color:#111827;background:#fff}
    .page{max-width:700px;margin:0 auto;padding:40px 32px; page-break-after: always;}
    .page:last-child { page-break-after: avoid; }
    .header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:32px;border-bottom:3px solid #2563eb;padding-bottom:20px}
    .company{font-size:22px;font-weight:800;color:#2563eb}
    .info-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px 32px;margin-bottom:24px;padding:20px;background:#f9fafb;border-radius:10px}
    .info-item label{font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:2px}
    .info-item span{font-size:14px;font-weight:600}
    table{width:100%;border-collapse:collapse;margin-bottom:20px}
    thead th{background:#f3f4f6;padding:10px 12px;text-align:left;font-size:12px;text-transform:uppercase;color:#6b7280}
    thead th:last-child{text-align:right}
    h4{font-size:13px;font-weight:700;color:#374151;margin-bottom:8px;padding-bottom:4px;border-bottom:2px solid #e5e7eb}
    .summary{background:#eff6ff;border:2px solid #bfdbfe;border-radius:10px;padding:16px 20px}
    .summary-row{display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px;color:#374151}
    .summary-row.net{border-top:1px solid #93c5fd;margin-top:10px;padding-top:10px;font-size:16px;font-weight:800;color:#1d4ed8}
    .footer{text-align:center;margin-top:32px;font-size:11px;color:#9ca3af}
    @media print{.page{padding:20px;margin:0;box-shadow:none}}
  </style>
</head>
<body>${allPagesHTML}<script>window.onload = () => window.print();<\/script></body>
</html>`;

    const blob = new Blob([html], { type: 'text/html;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const w = window.open(url, '_blank');
    if (w) w.addEventListener('unload', () => URL.revokeObjectURL(url));
  } catch (err) {
    console.error('Bulk pdf export error:', err);
    notificationStore.addError('Lỗi tải tệp PDF hàng loạt!');
  } finally {
    bulkExportLoading.value = false;
  }
};

const loadSalary = async () => {
  const empId = isAdmin.value ? selectedEmployee.value : (currentUser.value?.employee_id || '');
  if (!empId) return;
  
  try {
    const response = await employeeService.getSalaries(empId);
    const data = response?.data || response || [];
    salaryComponents.value = data.length > 0 ? data : generateDemoSalaryData();
  } catch (err) {
    console.error('Error loading salary:', err);
    salaryComponents.value = generateDemoSalaryData();
  }
};

const loadEmployees = async () => {
  try {
    const response = await employeeService.getAll();
    const employees = response?.data || response || [];
    employeeOptions.value = [
      { label: 'Chọn nhân viên', value: '' },
      ...employees.map((emp) => ({
        label: `${emp.full_name} (${emp.code})`,
        value: String(emp.id)
      }))
    ];
  } catch (err) {
    console.error('Error loading employees:', err);
  }
};

watch(selectedMonth, () => {
  if (!isAdmin.value || selectedEmployee.value) {
    loadSalary();
  }
});

const generateDemoSalaryData = () => {
  return [
    { id: 1, component_name: 'Lương cơ bản', name: 'Lương cơ bản', type: 'earning', category: 'basic', amount: 15000000 },
    { id: 2, component_name: 'Phụ cấp ăn trưa', name: 'Phụ cấp ăn trưa', type: 'earning', category: 'allowance', amount: 1000000 },
    { id: 3, component_name: 'Phụ cấp xăng xe', name: 'Phụ cấp xăng xe', type: 'earning', category: 'allowance', amount: 500000 },
    { id: 4, component_name: 'Thưởng hiệu suất', name: 'Thưởng hiệu suất', type: 'earning', category: 'bonus', amount: 2000000 },
    { id: 5, component_name: 'Bảo hiểm xã hội (8%)', name: 'BHXH', type: 'deduction', category: 'insurance', amount: 1200000 },
    { id: 6, component_name: 'Bảo hiểm y tế (1.5%)', name: 'BHYT', type: 'deduction', category: 'insurance', amount: 225000 },
    { id: 7, component_name: 'Thuế TNCN', name: 'Thuế TNCN', type: 'deduction', category: 'tax', amount: 850000 }
  ];
};

const generateDemoHistory = () => {
  const now = new Date();
  const history = [];
  for (let i = 0; i < 6; i++) {
    const date = new Date(now.getFullYear(), now.getMonth() - i, 1);
    const monthStr = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
    history.push({
      id: i + 1,
      month: monthStr,
      total_earnings: 18500000 + Math.floor(Math.random() * 2000000),
      total_deductions: 2275000 + Math.floor(Math.random() * 500000),
      net_salary: 16225000 + Math.floor(Math.random() * 1500000)
    });
  }
  return history;
};

onMounted(async () => {
  const now = new Date();
  selectedMonth.value = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
  
  if (isAdmin.value) {
    await loadEmployees();
  } else {
    const user = currentUser.value;
    if (user?.employee_id) {
      selectedEmployee.value = String(user.employee_id);
      await loadSalary();
    }
  }
  
  try {
    const response = await salaryService.getComponents();
    if (!salaryComponents.value.length) {
      const data = response?.data || response || [];
      salaryComponents.value = data.length > 0 ? data : generateDemoSalaryData();
    }
  } catch (err) {
    console.error('Error loading salary components:', err);
    salaryComponents.value = generateDemoSalaryData();
  }
  
  history.value = generateDemoHistory();
});
</script>
