<template>
  <div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
      <div>
        <h1 class="text-2xl sm:text-3xl font-bold text-foreground">Quyết định nhân sự</h1>
        <p class="text-sm sm:text-base text-muted-foreground mt-1">
          Điều chỉnh lương, bổ nhiệm, điều chuyển và chấm dứt hợp đồng — đề xuất phải được duyệt mới có hiệu lực
        </p>
      </div>
      <BaseButton class="w-full sm:w-auto" data-testid="button-create-decision" @click="openCreate">
        + Tạo đề xuất
      </BaseButton>
    </div>

    <!-- Vì sao phải qua duyệt: nhắc căn cứ pháp lý ngay trên màn hình -->
    <div class="p-3 rounded-xl bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-900/40 text-xs text-amber-800 dark:text-amber-300">
      Thay đổi mức lương hoặc công việc phải có phụ lục hợp đồng (Điều 33 Bộ luật Lao động). Người đề xuất
      không thể tự duyệt quyết định của mình; mọi thay đổi đều được lưu vết người đề xuất, người duyệt và thời điểm.
    </div>

    <BaseCard>
      <div class="flex flex-wrap gap-3 items-end mb-4">
        <div>
          <label class="block text-xs font-semibold text-muted-foreground uppercase tracking-wider mb-1.5">Loại quyết định</label>
          <select v-model="filterType" class="px-3 py-2 rounded-lg border border-input bg-background text-foreground text-sm">
            <option value="">Tất cả</option>
            <option v-for="(label, key) in TYPE_LABEL" :key="key" :value="key">{{ label }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-semibold text-muted-foreground uppercase tracking-wider mb-1.5">Trạng thái</label>
          <select v-model="filterStatus" class="px-3 py-2 rounded-lg border border-input bg-background text-foreground text-sm">
            <option value="">Tất cả</option>
            <option value="CHỜ_DUYỆT">Chờ duyệt</option>
            <option value="ĐÃ_DUYỆT">Đã duyệt</option>
            <option value="TỪ_CHỐI">Từ chối</option>
          </select>
        </div>
        <BaseButton variant="outline" @click="load">Áp dụng</BaseButton>
      </div>

      <BaseSkeleton v-if="loading" type="table" :rows="6" :cols="6" />
      <div v-else-if="!rows.length" class="text-center py-12 border border-dashed rounded-2xl text-muted-foreground">
        Chưa có quyết định nhân sự nào
      </div>
      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-muted/50">
            <tr>
              <th class="text-left px-4 py-3 font-medium">Nhân viên</th>
              <th class="text-left px-4 py-3 font-medium">Loại quyết định</th>
              <th class="text-left px-4 py-3 font-medium">Thay đổi</th>
              <th class="text-left px-4 py-3 font-medium">Hiệu lực</th>
              <th class="text-left px-4 py-3 font-medium">Trạng thái</th>
              <th class="text-right px-4 py-3 font-medium">Thao tác</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="d in rows" :key="d.id" class="border-t border-border align-top">
              <td class="px-4 py-3">
                <p class="font-medium">{{ d.full_name }}</p>
                <p class="text-xs text-muted-foreground">{{ d.employee_code }}</p>
              </td>
              <td class="px-4 py-3">
                {{ d.type_label }}
                <p class="text-xs text-muted-foreground max-w-[240px]">{{ d.reason }}</p>
              </td>
              <td class="px-4 py-3 whitespace-nowrap">
                <span class="text-muted-foreground line-through">{{ displayValue(d, d.old_value) }}</span>
                <span class="mx-1">→</span>
                <span class="font-semibold text-primary">{{ displayValue(d, d.new_value) }}</span>
              </td>
              <td class="px-4 py-3 whitespace-nowrap">{{ formatDate(d.effective_date) }}</td>
              <td class="px-4 py-3">
                <BaseBadge :variant="statusVariant(d.status)">{{ statusText(d.status) }}</BaseBadge>
                <p v-if="d.status === 'ĐÃ_DUYỆT' && metaOf(d).leave_payout_amount" class="text-xs text-green-600 mt-1">
                  Trả {{ metaOf(d).leave_payout_days }} ngày phép: {{ formatMoney(metaOf(d).leave_payout_amount) }}
                </p>
              </td>
              <td class="px-4 py-3 text-right whitespace-nowrap">
                <template v-if="d.can_approve">
                  <button
                    class="px-2.5 py-1 rounded-lg text-xs font-medium bg-green-50 text-green-700 hover:bg-green-100 dark:bg-green-950/30 dark:text-green-400"
                    :disabled="processing" @click="approve(d)"
                  >Duyệt</button>
                  <button
                    class="ml-1 px-2.5 py-1 rounded-lg text-xs font-medium bg-red-50 text-red-700 hover:bg-red-100 dark:bg-red-950/30 dark:text-red-400"
                    :disabled="processing" @click="reject(d)"
                  >Từ chối</button>
                </template>
                <span v-else-if="d.status === 'CHỜ_DUYỆT'" class="text-xs text-muted-foreground">Chờ admin duyệt</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </BaseCard>

    <!-- Modal tạo đề xuất -->
    <BaseModal v-model="showCreate" title="Tạo đề xuất quyết định nhân sự">
      <div class="space-y-4">
        <div>
          <label class="block text-sm font-medium mb-1">Phòng ban</label>
          <select
            v-model="selectedDepartmentId"
            class="w-full px-3 py-2 rounded-lg border border-input bg-background text-sm"
            @change="handleDepartmentChange"
          >
            <option value="">— Tất cả phòng ban —</option>
            <option v-for="department in departments" :key="department.id" :value="String(department.id)">
              {{ department.department_name || department.name }}
            </option>
          </select>
        </div>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <label class="block text-sm font-medium mb-1">Mã nhân viên <span class="text-destructive">*</span></label>
            <input
              :value="employeeCode"
              list="personnel-decision-employee-codes"
              autocomplete="off"
              class="w-full px-3 py-2.5 rounded-lg border border-input bg-background text-sm uppercase focus:outline-none focus:ring-2 focus:ring-ring"
              placeholder="Nhập mã, ví dụ CN00672"
              data-testid="input-employee-code"
              @input="handleEmployeeCodeInput"
              @blur="validateEmployeeCode"
            />
            <datalist id="personnel-decision-employee-codes">
              <option v-for="employee in filteredEmployees" :key="employee.id" :value="employee.employee_code">
                {{ employee.full_name }}
              </option>
            </datalist>
            <p v-if="employeeLookupError" class="mt-1 text-xs text-destructive">{{ employeeLookupError }}</p>
            <p v-else class="mt-1 text-xs text-muted-foreground">Có thể chọn phòng ban trước để thu hẹp danh sách mã.</p>
          </div>
          <BaseInput
            :model-value="selectedEmployee?.full_name || ''"
            label="Tên nhân viên"
            placeholder="Tự động hiển thị theo mã nhân viên"
            disabled
          />
        </div>
        <div>
          <label class="block text-sm font-medium mb-1">Loại quyết định <span class="text-destructive">*</span></label>
          <select v-model="form.change_type" class="w-full px-3 py-2 rounded-lg border border-input bg-background text-sm">
            <option v-for="(label, key) in TYPE_LABEL" :key="key" :value="key">{{ label }}</option>
          </select>
        </div>

        <div v-if="form.change_type === 'SALARY'">
          <BaseMoneyInput v-model="form.new_value" label="Mức lương mới (VNĐ)" required data-testid="input-new-salary" />
          <p v-if="currentSalary !== null" class="text-xs text-muted-foreground mt-1">
            Mức hiện tại: {{ formatMoney(currentSalary) }}
          </p>
        </div>
        <div v-else-if="form.change_type === 'POSITION'">
          <label class="block text-sm font-medium mb-1">Chức danh mới <span class="text-destructive">*</span></label>
          <select v-model="form.new_value" class="w-full px-3 py-2 rounded-lg border border-input bg-background text-sm">
            <option value="">— Chọn chức danh —</option>
            <option v-for="p in positions" :key="p.id" :value="p.id">{{ p.position_name || p.name }}</option>
          </select>
        </div>
        <div v-else-if="form.change_type === 'DEPARTMENT'">
          <label class="block text-sm font-medium mb-1">Bộ phận mới <span class="text-destructive">*</span></label>
          <select v-model="form.new_value" class="w-full px-3 py-2 rounded-lg border border-input bg-background text-sm">
            <option value="">— Chọn bộ phận —</option>
            <option v-for="dp in departments" :key="dp.id" :value="dp.id">{{ dp.department_name || dp.name }}</option>
          </select>
        </div>
        <div v-else class="p-3 rounded-lg bg-red-50 dark:bg-red-950/20 text-xs text-red-700 dark:text-red-400">
          Khi duyệt, hệ thống sẽ: chốt ngày chấm dứt, kết thúc hợp đồng lao động và tự tính tiền những ngày
          phép chưa nghỉ để trả vào kỳ lương đang mở (Điều 113.3 Bộ luật Lao động).
        </div>

        <BaseInput v-model="form.effective_date" type="date" label="Ngày hiệu lực" required />
        <div>
          <label class="block text-sm font-medium mb-1">Lý do / căn cứ <span class="text-destructive">*</span></label>
          <textarea
            v-model="form.reason" rows="3"
            class="w-full px-3 py-2 rounded-lg border border-input bg-background text-sm"
            placeholder="Ví dụ: Tăng lương theo kết quả đánh giá 6 tháng đầu năm 2026"
          ></textarea>
        </div>
        <p v-if="formError" class="text-sm text-destructive">{{ formError }}</p>
      </div>
      <template #footer>
        <BaseButton variant="outline" @click="showCreate = false">Hủy</BaseButton>
        <BaseButton :disabled="saving" @click="submit">{{ saving ? 'Đang lưu…' : 'Gửi đề xuất' }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>

<script setup>
import { computed, ref, watch, onMounted } from 'vue';
import BaseCard from '../components/BaseCard.vue';
import BaseButton from '../components/BaseButton.vue';
import BaseInput from '../components/BaseInput.vue';
import BaseMoneyInput from '../components/BaseMoneyInput.vue';
import BaseBadge from '../components/BaseBadge.vue';
import BaseModal from '../components/BaseModal.vue';
import BaseSkeleton from '../components/BaseSkeleton.vue';
import { personnelDecisionService } from '../services/personnelDecisionService';
import { employeeService } from '../services/employeeService';
import { departmentService } from '../services/departmentService';
import { jobTitleService } from '../services/jobTitleService';
import { useNotificationStore } from '../stores/notificationStore';
import { filterEmployeesByDepartment, findEmployeeByCode, normalizeEmployeeCode } from '../utils/employeeLookup';

const notificationStore = useNotificationStore();

const TYPE_LABEL = {
  SALARY: 'Điều chỉnh lương',
  POSITION: 'Bổ nhiệm / thay đổi chức danh',
  DEPARTMENT: 'Điều chuyển bộ phận',
  TERMINATION: 'Chấm dứt hợp đồng lao động',
};

const loading = ref(true);
const saving = ref(false);
const processing = ref(false);
const rows = ref([]);
const employees = ref([]);
const departments = ref([]);
const positions = ref([]);
const filterType = ref('');
const filterStatus = ref('');
const showCreate = ref(false);
const formError = ref('');
const form = ref({ employee_id: '', change_type: 'SALARY', new_value: '', effective_date: '', reason: '' });
const selectedDepartmentId = ref('');
const employeeCode = ref('');
const employeeLookupError = ref('');
const selectedEmployee = computed(() => employees.value.find((employee) => String(employee.id) === String(form.value.employee_id)) || null);
const filteredEmployees = computed(() => filterEmployeesByDepartment(employees.value, selectedDepartmentId.value));

const selectEmployeeByCode = () => {
  const employee = findEmployeeByCode(employees.value, employeeCode.value);
  if (!employee) {
    form.value.employee_id = '';
    return false;
  }

  form.value.employee_id = employee.id;
  selectedDepartmentId.value = employee.department_id ? String(employee.department_id) : '';
  employeeLookupError.value = '';
  return true;
};

const handleEmployeeCodeInput = (event) => {
  employeeCode.value = normalizeEmployeeCode(event.target.value);
  event.target.value = employeeCode.value;
  employeeLookupError.value = '';
  selectEmployeeByCode();
};

const validateEmployeeCode = () => {
  if (!employeeCode.value.trim()) {
    employeeLookupError.value = '';
    return;
  }
  if (!selectEmployeeByCode()) employeeLookupError.value = 'Không tìm thấy mã nhân viên này.';
};

const handleDepartmentChange = () => {
  employeeLookupError.value = '';
  const employee = selectedEmployee.value;
  if (employee && selectedDepartmentId.value && String(employee.department_id || '') !== selectedDepartmentId.value) {
    employeeCode.value = '';
    form.value.employee_id = '';
  }
};

// /employees/lookup KHÔNG trả base_salary (chỉ id/mã/tên/phòng ban) → phải lấy hồ sơ
// đầy đủ khi chọn người, nếu không gợi ý "mức hiện tại" luôn hiện 0đ.
const currentSalary = ref(null);
watch(() => form.value.employee_id, async (id) => {
  currentSalary.value = null;
  if (!id) return;
  try {
    const res = await employeeService.getById(id);
    currentSalary.value = Number((res?.data || res)?.base_salary) || 0;
  } catch { /* gợi ý thôi, lỗi thì bỏ qua */ }
});

const metaOf = (d) => {
  if (!d?.meta) return {};
  if (typeof d.meta === 'string') { try { return JSON.parse(d.meta); } catch { return {}; } }
  return d.meta;
};

const formatMoney = (v) => new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND', maximumFractionDigits: 0 }).format(Number(v) || 0);
const formatDate = (d) => (d ? new Date(d).toLocaleDateString('vi-VN') : '—');

// old/new_value lưu dạng text: lương hiện tiền, chức danh/bộ phận hiện TÊN thay vì id.
const displayValue = (d, raw) => {
  if (raw === null || raw === undefined || raw === '') return '—';
  if (d.change_type === 'SALARY') return formatMoney(raw);
  if (d.change_type === 'POSITION') return positions.value.find(p => String(p.id) === String(raw))?.position_name || `#${raw}`;
  if (d.change_type === 'DEPARTMENT') return departments.value.find(x => String(x.id) === String(raw))?.department_name || `#${raw}`;
  return raw === 'TERMINATED' ? 'Đã chấm dứt' : raw;
};

const statusText = (s) => ({ 'CHỜ_DUYỆT': 'Chờ duyệt', 'ĐÃ_DUYỆT': 'Đã duyệt', 'TỪ_CHỐI': 'Từ chối' }[s] || s);
const statusVariant = (s) => ({ 'CHỜ_DUYỆT': 'warning', 'ĐÃ_DUYỆT': 'success', 'TỪ_CHỐI': 'destructive' }[s] || 'default');

const load = async () => {
  try {
    loading.value = true;
    const params = { per_page: 100 };
    if (filterType.value) params.change_type = filterType.value;
    if (filterStatus.value) params.status = filterStatus.value;
    const res = await personnelDecisionService.getAll(params);
    rows.value = Array.isArray(res) ? res : (res?.items || res?.data || []);
  } catch (err) {
    notificationStore.addError(err.response?.data?.message || 'Không tải được danh sách quyết định');
  } finally {
    loading.value = false;
  }
};

const openCreate = () => {
  form.value = { employee_id: '', change_type: 'SALARY', new_value: '', effective_date: new Date().toISOString().slice(0, 10), reason: '' };
  selectedDepartmentId.value = '';
  employeeCode.value = '';
  employeeLookupError.value = '';
  formError.value = '';
  showCreate.value = true;
};

const submit = async () => {
  formError.value = '';
  if (!form.value.employee_id) { formError.value = employeeCode.value ? 'Mã nhân viên không hợp lệ' : 'Vui lòng nhập mã nhân viên'; return; }
  if (!form.value.reason || form.value.reason.trim().length < 3) { formError.value = 'Vui lòng ghi lý do / căn cứ của quyết định'; return; }
  if (form.value.change_type !== 'TERMINATION' && !form.value.new_value) { formError.value = 'Vui lòng nhập giá trị mới'; return; }
  try {
    saving.value = true;
    await personnelDecisionService.create({
      employee_id: Number(form.value.employee_id),
      change_type: form.value.change_type,
      new_value: form.value.change_type === 'TERMINATION' ? null : form.value.new_value,
      effective_date: form.value.effective_date,
      reason: form.value.reason,
    });
    showCreate.value = false;
    notificationStore.addSuccess('Đã gửi đề xuất — chờ duyệt');
    await load();
  } catch (err) {
    const fe = err.response?.data?.data?.errors;
    formError.value = (fe && Object.values(fe)[0]?.[0]) || err.response?.data?.message || 'Không gửi được đề xuất';
  } finally {
    saving.value = false;
  }
};

const approve = async (d) => {
  if (processing.value) return;
  if (!window.confirm(`Duyệt và ÁP DỤNG "${d.type_label}" cho ${d.full_name}? Thay đổi sẽ có hiệu lực ngay.`)) return;
  try {
    processing.value = true;
    await personnelDecisionService.approve(d.id);
    notificationStore.addSuccess('Đã duyệt và áp dụng quyết định');
    await load();
  } catch (err) {
    const fe = err.response?.data?.data?.errors;
    notificationStore.addError((fe && Object.values(fe)[0]?.[0]) || err.response?.data?.message || 'Không duyệt được');
  } finally {
    processing.value = false;
  }
};

const reject = async (d) => {
  if (processing.value) return;
  const comment = window.prompt('Lý do từ chối:');
  if (comment === null) return;
  try {
    processing.value = true;
    await personnelDecisionService.reject(d.id, comment);
    notificationStore.addSuccess('Đã từ chối quyết định');
    await load();
  } catch (err) {
    notificationStore.addError(err.response?.data?.message || 'Không từ chối được');
  } finally {
    processing.value = false;
  }
};

onMounted(async () => {
  const [emp, dep, pos] = await Promise.all([
    employeeService.getLookup().catch(() => []),
    departmentService.getAll().catch(() => []),
    jobTitleService.getAll().catch(() => []),
  ]);
  employees.value = Array.isArray(emp) ? emp : (emp?.data || []);
  departments.value = Array.isArray(dep) ? dep : (dep?.data || []);
  positions.value = Array.isArray(pos) ? pos : (pos?.data || []);
  await load();
});
</script>
