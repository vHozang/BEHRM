<template>
  <div class="space-y-6">
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-6">
      <div>
        <h1 class="text-xl sm:text-2xl font-bold">{{ pageTitle }}</h1>
        <p class="text-muted-foreground mt-1">{{ pageSubtitle }}</p>
      </div>
      <BaseButton
        @click="openCreateModal"
        data-testid="button-create-overtime"
        class="w-full sm:w-auto"
      >
        + Đăng ký tăng ca
      </BaseButton>
    </div>

    <!-- Dual-lens tabs (admins only) -->
    <div v-if="isAdmin" class="flex gap-1 border-b border-border -mt-2">
      <button @click="viewMode = 'org'" :class="['px-4 py-2 text-sm font-medium border-b-2 transition-colors', viewMode === 'org' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground']">Toàn công ty</button>
      <button @click="viewMode = 'mine'" :class="['px-4 py-2 text-sm font-medium border-b-2 transition-colors', viewMode === 'mine' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground']">Của tôi</button>
    </div>

    <div v-if="loading" class="text-center py-8">
      <p class="text-muted-foreground">Đang tải dữ liệu từ API...</p>
    </div>

    <div v-else-if="error" class="bg-destructive/10 border border-destructive/20 rounded-lg p-4">
      <p class="text-destructive font-medium">Lỗi kết nối API:</p>
      <p class="text-destructive/80 text-sm mt-1">{{ error }}</p>
    </div>

    <template v-else>
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <BaseCard>
          <div class="text-center">
            <p class="text-sm text-muted-foreground">Chờ duyệt</p>
            <p class="text-3xl font-bold text-amber-600 dark:text-amber-400 mt-2">{{ statusCounts.pending }}</p>
          </div>
        </BaseCard>
        <BaseCard>
          <div class="text-center">
            <p class="text-sm text-muted-foreground">Đã duyệt</p>
            <p class="text-3xl font-bold text-green-600 dark:text-green-400 mt-2">{{ statusCounts.approved }}</p>
          </div>
        </BaseCard>
        <BaseCard>
          <div class="text-center">
            <p class="text-sm text-muted-foreground">Từ chối</p>
            <p class="text-3xl font-bold text-red-600 dark:text-red-400 mt-2">{{ statusCounts.rejected }}</p>
          </div>
        </BaseCard>
        <BaseCard>
          <div class="text-center">
            <p class="text-sm text-muted-foreground">Tổng giờ tăng ca</p>
            <p class="text-3xl font-bold text-blue-600 dark:text-blue-400 mt-2">{{ totalHours }}</p>
          </div>
        </BaseCard>
      </div>

      <BaseCard :title="orgLens ? 'Danh sách yêu cầu tăng ca' : 'Yêu cầu tăng ca của tôi'">
        <div v-if="displayRequests.length === 0" class="text-center py-8 text-muted-foreground">
          Chưa có yêu cầu tăng ca nào
        </div>
        <BaseTable
          v-else
          :columns="columns"
          :data="displayRequests"
          data-testid="table-overtime"
        >
          <template v-if="orgLens" #cell-employee="{ item }">
            <span class="text-sm font-medium">{{ item.employee?.full_name || `NV #${item.employee_id}` }}</span>
          </template>

          <template #cell-date="{ item }">
            <span class="text-sm">{{ formatDate(item.overtime_date || item.work_date || item.date) }}</span>
          </template>

          <template #cell-hours="{ item }">
            <span class="text-sm">{{ item.hours || item.overtime_hours || 0 }} giờ</span>
            <span v-if="item.night_hours > 0" class="block text-[10px] text-indigo-600">{{ item.night_hours }}h đêm</span>
          </template>

          <template #cell-classify="{ item }">
            <span v-if="item.classify_label" class="text-[11px] px-2 py-0.5 rounded-full bg-muted text-foreground whitespace-nowrap">{{ item.classify_label }}</span>
            <span v-else class="text-xs text-muted-foreground">-</span>
          </template>

          <template #cell-reason="{ item }">
            <span class="text-sm text-muted-foreground">{{ item.reason || '-' }}</span>
          </template>

          <template #cell-status="{ value }">
            <BaseBadge :variant="getStatusVariant(value)">
              {{ getStatusText(value) }}
            </BaseBadge>
          </template>

          <template #actions="{ item }">
            <div class="flex items-center gap-2">
              <button
                @click="openDetail(item)"
                class="p-1.5 rounded hover:bg-muted text-muted-foreground"
                title="Xem chi tiết & tiến trình duyệt"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                </svg>
              </button>
              <template v-if="orgLens && item.status === 'pending'">
                <button
                  @click="approveRequest(item, false)"
                  class="p-1.5 rounded hover:bg-green-100 dark:hover:bg-green-900 text-green-600 dark:text-green-400"
                  title="Duyệt (trả tiền tăng ca)"
                  :disabled="processing"
                >
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                  </svg>
                </button>
                <button
                  @click="approveRequest(item, true)"
                  class="px-2 py-1 rounded text-xs font-medium border border-indigo-500/40 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-500/10"
                  title="Duyệt và quy đổi sang nghỉ bù (không trả tiền)"
                  :disabled="processing"
                >
                  Nghỉ bù
                </button>
              </template>
            </div>
          </template>
        </BaseTable>
      </BaseCard>
    </template>

    <BaseModal
      v-model="showCreateModal"
      title="Đăng ký tăng ca"
      data-testid="modal-create-overtime"
    >
      <div class="space-y-4">
        <template v-if="orgLens">
          <BaseSelect
            v-model="form.employee_id"
            label="Nhân viên"
            :options="employeeOptions"
            required
          />
        </template>
        <template v-else>
          <div class="p-3 bg-muted rounded-lg">
            <p class="text-sm text-muted-foreground">Nhân viên</p>
            <p class="font-medium">{{ currentUser?.full_name || currentUser?.email || 'Bạn' }}</p>
          </div>
        </template>
        <BaseInput
          v-model="form.overtime_date"
          type="date"
          label="Ngày tăng ca"
          required
        />
        <div class="grid grid-cols-2 gap-3">
          <BaseInput v-model="form.start_time" type="time" label="Giờ bắt đầu (tùy chọn)" hint="Nhập giờ để tự tính ca đêm" />
          <BaseInput v-model="form.end_time" type="time" label="Giờ kết thúc (tùy chọn)" />
        </div>
        <BaseInput
          v-model="form.hours"
          type="number"
          label="Số giờ (nếu không nhập giờ bắt đầu/kết thúc)"
          placeholder="VD: 2"
        />

        <!-- Mức dùng OT theo luật + cảnh báo -->
        <div v-if="usage" class="text-xs rounded-lg border border-border p-3 space-y-1">
          <p class="font-medium text-foreground">Giới hạn tăng ca (luật VN)</p>
          <p :class="usage.daily_used >= usage.daily_max ? 'text-red-600' : 'text-muted-foreground'">Ngày {{ form.overtime_date }}: {{ usage.daily_used }}/{{ usage.daily_max }}h</p>
          <p :class="usage.monthly_used >= usage.monthly_max ? 'text-red-600' : 'text-muted-foreground'">Tháng: {{ usage.monthly_used }}/{{ usage.monthly_max }}h</p>
          <p :class="usage.yearly_used >= usage.yearly_max ? 'text-amber-600' : 'text-muted-foreground'">Năm: {{ usage.yearly_used }}/{{ usage.yearly_max }}h</p>
        </div>

        <div>
          <label class="block text-sm font-medium text-foreground mb-2">Lý do</label>
          <textarea
            v-model="form.reason"
            class="w-full px-4 py-2.5 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
            rows="3"
            placeholder="Nhập lý do tăng ca..."
          ></textarea>
        </div>
      </div>

      <div v-if="formError" class="mt-4 p-3 bg-destructive/10 border border-destructive/20 rounded-lg">
        <p class="text-destructive text-sm">{{ formError }}</p>
      </div>

      <template #footer>
        <BaseButton variant="outline" @click="closeCreateModal" :disabled="saving">Hủy</BaseButton>
        <BaseButton @click="handleCreate" :disabled="saving">
          {{ saving ? 'Đang gửi...' : 'Gửi đăng ký' }}
        </BaseButton>
      </template>
    </BaseModal>

    <!-- Chi tiết + tiến trình phê duyệt -->
    <BaseModal v-model="showDetailModal" title="Chi tiết yêu cầu tăng ca" size="lg">
      <div v-if="selectedRequest" class="space-y-4">
        <div class="grid grid-cols-2 gap-3 text-sm">
          <div><p class="text-muted-foreground">Người tạo đơn</p><p class="font-medium">{{ selectedRequest.employee?.full_name || empNameById(selectedRequest.employee_id) || ('NV #' + selectedRequest.employee_id) }}</p></div>
          <div><p class="text-muted-foreground">Ngày tăng ca</p><p class="font-medium">{{ formatDate(selectedRequest.overtime_date || selectedRequest.work_date) }}</p></div>
          <div><p class="text-muted-foreground">Số giờ</p><p class="font-medium">{{ selectedRequest.hours || selectedRequest.overtime_hours || 0 }} giờ</p></div>
          <div><p class="text-muted-foreground">Phân loại</p><p class="font-medium">{{ selectedRequest.classify_label || '-' }}</p></div>
          <div class="col-span-2"><p class="text-muted-foreground">Lý do</p><p class="font-medium">{{ selectedRequest.reason || '-' }}</p></div>
        </div>
        <div class="border-t border-border pt-2">
          <p class="text-sm font-semibold text-foreground mb-1">Tiến trình phê duyệt</p>
          <ApprovalTimeline :steps="approvalSteps" />
        </div>
      </div>
      <template #footer>
        <BaseButton variant="outline" @click="showDetailModal = false">Đóng</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import BaseCard from '../components/BaseCard.vue';
import BaseButton from '../components/BaseButton.vue';
import BaseInput from '../components/BaseInput.vue';
import BaseSelect from '../components/BaseSelect.vue';
import BaseBadge from '../components/BaseBadge.vue';
import BaseTable from '../components/BaseTable.vue';
import BaseModal from '../components/BaseModal.vue';
import ApprovalTimeline from '../components/ApprovalTimeline.vue';
import { buildApprovalSteps, statusVN, statusVariant } from '../utils/approvalSteps';
import { attendanceService } from '../services/attendanceService';
import { employeeService } from '../services/employeeService';
import { authService } from '../services/authService';
import { useToast } from '../composables/useToast';

const toast = useToast();
const isAdmin = computed(() => authService.isAdmin());
const currentUser = computed(() => authService.getUser());

// Dual-lens: admins toggle company-wide vs their own.
const viewMode = ref('org'); // 'org' | 'mine'
const myEmployeeId = computed(() => currentUser.value?.employee_id || currentUser.value?.id || null);
const orgLens = computed(() => isAdmin.value && viewMode.value === 'org');
const pageTitle = computed(() => orgLens.value ? 'Quản lý Tăng ca' : 'Đăng ký Tăng ca');
const pageSubtitle = computed(() => orgLens.value ? 'Duyệt các yêu cầu làm thêm giờ của nhân viên' : 'Đăng ký và theo dõi giờ làm thêm của bạn');

const loading = ref(true);
const error = ref('');
const saving = ref(false);
const processing = ref(false);
const formError = ref('');

const showCreateModal = ref(false);
const requests = ref([]);
const employees = ref([]);

// Chi tiết + tiến trình duyệt (hiện người tạo + người duyệt thật).
const showDetailModal = ref(false);
const selectedRequest = ref(null);
const empNameById = (id) => {
  if (!id) return null;
  const e = employees.value.find((x) => String(x.id) === String(id));
  return e ? (e.full_name || e.employee_code) : null;
};
const openDetail = (item) => { selectedRequest.value = item; showDetailModal.value = true; };
const approvalSteps = computed(() => {
  const req = selectedRequest.value;
  if (!req) return [];
  return buildApprovalSteps(req, {
    creatorName: req.employee?.full_name || empNameById(req.employee_id) || 'Nhân viên',
    resolveName: empNameById,
  });
});

const form = ref({
  employee_id: '',
  overtime_date: '',
  start_time: '',
  end_time: '',
  hours: '',
  reason: ''
});
const usage = ref(null);

const fetchUsage = async () => {
  const empId = orgLens.value ? form.value.employee_id : myEmployeeId.value;
  if (!empId || !form.value.overtime_date) { usage.value = null; return; }
  try {
    usage.value = await attendanceService.getOvertimeUsage(empId, form.value.overtime_date);
  } catch (e) { usage.value = null; }
};

watch(() => [form.value.employee_id, form.value.overtime_date], fetchUsage);

const allColumns = [
  { key: 'employee', label: 'Nhân viên' },
  { key: 'date', label: 'Ngày' },
  { key: 'hours', label: 'Số giờ' },
  { key: 'classify', label: 'Phân loại (hệ số)' },
  { key: 'reason', label: 'Lý do' },
  { key: 'status', label: 'Trạng thái' },
];
const columns = computed(() => orgLens.value ? allColumns : allColumns.filter(c => c.key !== 'employee'));

// Org lens: all requests. Otherwise: only my own.
const displayRequests = computed(() => {
  if (orgLens.value) return requests.value;
  const myId = myEmployeeId.value;
  if (!myId) return isAdmin.value ? [] : requests.value;
  return requests.value.filter(r => String(r.employee_id) === String(myId));
});

const employeeOptions = computed(() =>
  employees.value.map(e => ({ label: e.full_name, value: String(e.id) }))
);

const statusCounts = computed(() => ({
  pending: displayRequests.value.filter(r => r.status === 'pending').length,
  approved: displayRequests.value.filter(r => r.status === 'approved').length,
  rejected: displayRequests.value.filter(r => r.status === 'rejected').length
}));

const totalHours = computed(() =>
  displayRequests.value.reduce((sum, r) => sum + Number(r.hours || r.overtime_hours || 0), 0)
);

const formatDate = (date) => {
  if (!date) return '';
  return new Date(date).toLocaleDateString('vi-VN');
};

const getStatusVariant = (status) => statusVariant(status);
const getStatusText = (status) => statusVN(status);

const resetForm = () => {
  form.value = {
    employee_id: orgLens.value ? '' : (myEmployeeId.value ? String(myEmployeeId.value) : ''),
    overtime_date: '',
    start_time: '',
    end_time: '',
    hours: '',
    reason: ''
  };
  usage.value = null;
  formError.value = '';
};

const openCreateModal = () => {
  resetForm();
  showCreateModal.value = true;
};

const closeCreateModal = () => {
  showCreateModal.value = false;
  resetForm();
};

const handleCreate = async () => {
  const employeeId = orgLens.value ? form.value.employee_id : (myEmployeeId.value ? String(myEmployeeId.value) : '');

  if (!employeeId) {
    formError.value = 'Không thể xác định nhân viên. Vui lòng thử lại.';
    return;
  }
  if (!form.value.overtime_date) {
    formError.value = 'Vui lòng chọn ngày tăng ca';
    return;
  }
  const hasTimes = form.value.start_time && form.value.end_time;
  if (!hasTimes && (!form.value.hours || Number(form.value.hours) <= 0)) {
    formError.value = 'Nhập số giờ, hoặc nhập cả giờ bắt đầu và kết thúc';
    return;
  }

  try {
    saving.value = true;
    formError.value = '';

    await attendanceService.createOvertime({
      employee_id: parseInt(employeeId),
      overtime_date: form.value.overtime_date,
      start_time: form.value.start_time || undefined,
      end_time: form.value.end_time || undefined,
      hours: form.value.hours !== '' ? Number(form.value.hours) : undefined,
      reason: form.value.reason,
    });

    closeCreateModal();
    await loadRequests();
  } catch (err) {
    console.error('Error creating overtime request:', err);
    const capErrors = err.response?.data?.data?.errors?.total_hours;
    formError.value = Array.isArray(capErrors) ? capErrors.join(' ')
      : (err.response?.data?.error || err.response?.data?.message || 'Có lỗi xảy ra');
  } finally {
    saving.value = false;
  }
};

const approveRequest = async (request, compOff = false) => {
  if (processing.value || !isAdmin.value) return;
  if (compOff && !confirm('Duyệt và quy đổi giờ tăng ca này thành nghỉ bù (sẽ KHÔNG trả tiền tăng ca)?')) return;

  try {
    processing.value = true;
    const res = await attendanceService.approveOvertime(request.id, compOff);
    if (res?.message) toast.success(res.message);
    await loadRequests();
  } catch (err) {
    console.error('Error approving overtime:', err);
    const capErrors = err.response?.data?.data?.errors?.status;
    alert(Array.isArray(capErrors) ? capErrors.join(' ') : (err.response?.data?.error || err.response?.data?.message || 'Có lỗi xảy ra khi duyệt'));
  } finally {
    processing.value = false;
  }
};

const loadRequests = async () => {
  try {
    const params = {};
    if (!isAdmin.value) {
      const user = currentUser.value;
      if (user?.employee_id) {
        params.employee_id = user.employee_id;
      }
    }
    const response = await attendanceService.getOvertime(params);
    requests.value = response?.data || response || [];
  } catch (err) {
    console.error('Error loading overtime requests:', err);
    if (err.response?.status === 403) {
      requests.value = [];
    }
  }
};

onMounted(async () => {
  try {
    loading.value = true;
    error.value = '';

    const promises = [
      attendanceService.getOvertime(!isAdmin.value && currentUser.value?.employee_id ? { employee_id: currentUser.value.employee_id } : {}).catch((err) => {
        if (err.response?.status === 403) return [];
        throw err;
      })
    ];

    if (isAdmin.value) {
      promises.push(employeeService.getLookup().catch(() => []));
    }

    const results = await Promise.all(promises);

    requests.value = results[0]?.data || results[0] || [];
    if (isAdmin.value && results[1]) {
      employees.value = results[1]?.data || results[1] || [];
    }
  } catch (err) {
    console.error('Overtime API Error:', err);
    if (err.response?.status !== 403) {
      error.value = err.response?.data?.error || err.message || 'Không thể kết nối đến API';
    }
  } finally {
    loading.value = false;
  }
});
</script>
