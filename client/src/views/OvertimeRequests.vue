<template>
  <div class="space-y-6">
    <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
      <div>
        <h1 class="text-xl font-bold sm:text-2xl">{{ pageTitle }}</h1>
        <p class="mt-1 text-muted-foreground">{{ pageSubtitle }}</p>
      </div>
      <div class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row">
        <BaseButton v-if="!orgLens" variant="outline" class="w-full sm:w-auto" data-testid="button-create-overtime" @click="openCreateModal('request')">
          + Đăng ký tăng ca
        </BaseButton>
        <BaseButton v-if="canManageTickets && orgLens" class="w-full sm:w-auto" data-testid="button-create-overtime-ticket" @click="openCreateModal('ticket')">
          + Giao ticket OT
        </BaseButton>
      </div>
    </div>

    <div v-if="hasManagementLens" class="flex gap-1 border-b border-border">
      <button @click="viewMode = 'org'" :class="lensClass('org')">Toàn công ty</button>
      <button @click="viewMode = 'mine'" :class="lensClass('mine')">Của tôi</button>
    </div>

    <div class="grid grid-cols-2 gap-2 rounded-xl border border-border bg-muted/25 p-1 sm:inline-grid sm:min-w-[430px]">
      <button
        type="button"
        class="rounded-lg px-4 py-2 text-sm font-semibold transition-colors"
        :class="requestGroup === 'requests' ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'"
        @click="requestGroup = 'requests'"
      >
        Đơn đăng ký <span class="ml-1 text-xs">({{ scopedNormalRequests.length }})</span>
      </button>
      <button
        type="button"
        class="rounded-lg px-4 py-2 text-sm font-semibold transition-colors"
        :class="requestGroup === 'tickets' ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'"
        @click="requestGroup = 'tickets'"
      >
        Ticket được giao <span class="ml-1 text-xs">({{ scopedTickets.length }})</span>
      </button>
    </div>

    <div v-if="loading" class="py-8 text-center text-muted-foreground">Đang tải dữ liệu từ API...</div>
    <div v-else-if="error" class="rounded-lg border border-destructive/20 bg-destructive/10 p-4">
      <p class="font-medium text-destructive">Lỗi kết nối API:</p>
      <p class="mt-1 text-sm text-destructive/80">{{ error }}</p>
    </div>

    <template v-else>
      <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <BaseCard>
          <div class="text-center">
            <p class="text-sm text-muted-foreground">{{ requestGroup === 'tickets' ? 'Chờ phản hồi' : 'Chờ duyệt' }}</p>
            <p class="mt-2 text-3xl font-bold text-amber-600 dark:text-amber-400">{{ statusCounts.pending }}</p>
          </div>
        </BaseCard>
        <BaseCard>
          <div class="text-center">
            <p class="text-sm text-muted-foreground">Đã duyệt / Đã nhận</p>
            <p class="mt-2 text-3xl font-bold text-green-600 dark:text-green-400">{{ statusCounts.approved }}</p>
          </div>
        </BaseCard>
        <BaseCard>
          <div class="text-center">
            <p class="text-sm text-muted-foreground">Từ chối / Hủy</p>
            <p class="mt-2 text-3xl font-bold text-red-600 dark:text-red-400">{{ statusCounts.rejected }}</p>
          </div>
        </BaseCard>
        <BaseCard>
          <div class="text-center">
            <p class="text-sm text-muted-foreground">Giờ được thanh toán</p>
            <p class="mt-2 text-3xl font-bold text-blue-600 dark:text-blue-400">{{ formatHours(totalPayableMinutes) }}</p>
          </div>
        </BaseCard>
      </div>

      <BaseCard :title="tableTitle">
        <div v-if="displayRequests.length === 0" class="py-8 text-center text-muted-foreground">
          {{ requestGroup === 'tickets' ? 'Chưa có ticket tăng ca nào' : 'Chưa có đơn đăng ký tăng ca nào' }}
        </div>
        <BaseTable v-else :columns="columns" :data="displayRequests" data-testid="table-overtime">
          <template v-if="orgLens" #cell-employee="{ item }">
            <div>
              <p class="text-sm font-medium">{{ item.employee?.full_name || empNameById(item.employee_id) || `NV #${item.employee_id}` }}</p>
              <p class="text-xs text-muted-foreground">{{ item.employee?.employee_code || '' }}</p>
            </div>
          </template>

          <template #cell-date="{ item }">
            <span class="text-sm">{{ formatDate(item.overtime_date || item.work_date || item.date) }}</span>
          </template>

          <template #cell-interval="{ item }">
            <span class="text-sm font-medium">{{ formatTime(item.start_time) }} - {{ formatTime(item.end_time) }}</span>
          </template>

          <template #cell-approved="{ item }">
            <span class="text-sm font-medium">{{ formatHours(item.approved_minutes) }}</span>
            <span v-if="item.night_hours > 0" class="block text-[10px] text-indigo-600">{{ item.night_hours }}h đêm</span>
          </template>

          <template #cell-actual="{ item }">
            <span v-if="isReconciled(item)" class="text-sm" :title="`Tổng thời gian thực tế ngoài ca trong ngày: ${formatHours(item.actual_outside_minutes)}`">{{ formatHours(item.matched_minutes) }}</span>
            <span v-else class="text-xs text-muted-foreground">Chờ duyệt</span>
          </template>

          <template #cell-payable="{ item }">
            <span v-if="isReconciled(item)" class="text-sm font-semibold text-primary">{{ formatHours(item.payable_overtime_minutes) }}</span>
            <span v-else class="text-xs text-muted-foreground">—</span>
            <span v-if="item.converted_to_comp_off" class="mt-1 block text-[10px] text-indigo-600">Quy đổi nghỉ bù</span>
          </template>

          <template #cell-reason="{ item }">
            <span class="text-sm text-muted-foreground">{{ item.reason || '-' }}</span>
          </template>

          <template #cell-reconciliation="{ item }">
            <span :class="reconciliationBadge(item)">{{ reconciliationLabel(item) }}</span>
          </template>

          <template #cell-status="{ item }">
            <BaseBadge :variant="getStatusVariant(item.status)">{{ getStatusText(item.status) }}</BaseBadge>
          </template>

          <template #actions="{ item }">
            <div class="flex items-center gap-2">
              <button class="rounded p-1.5 text-muted-foreground hover:bg-muted" title="Xem chi tiết" @click="openDetail(item)">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268-2.943 9.542-7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                </svg>
              </button>
              <template v-if="requestGroup === 'requests' && orgLens && item.status === 'pending'">
                <button class="rounded p-1.5 text-green-600 hover:bg-green-100 dark:text-green-400 dark:hover:bg-green-900" title="Duyệt trả tiền tăng ca" :disabled="processing" @click="approveRequest(item, false)">
                  <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                </button>
                <button class="rounded border border-indigo-500/40 px-2 py-1 text-xs font-medium text-indigo-600 hover:bg-indigo-500/10 dark:text-indigo-400" title="Duyệt và đối soát sang nghỉ bù" :disabled="processing" @click="approveRequest(item, true)">Nghỉ bù</button>
              </template>
              <template v-if="requestGroup === 'tickets' && item.status === 'offered' && String(item.employee_id) === String(myEmployeeId)">
                <button class="rounded bg-emerald-600 px-2 py-1 text-xs font-semibold text-white hover:bg-emerald-700" :disabled="processing" @click="respondTicket(item, 'accept')">Nhận</button>
                <button class="rounded border border-red-300 px-2 py-1 text-xs font-semibold text-red-600 hover:bg-red-50" :disabled="processing" @click="respondTicket(item, 'decline')">Từ chối</button>
              </template>
              <button v-if="requestGroup === 'tickets' && canCancelTicket(item)" class="rounded border border-border px-2 py-1 text-xs font-semibold text-muted-foreground hover:bg-muted" :disabled="processing" @click="cancelTicket(item)">Hủy</button>
            </div>
          </template>
        </BaseTable>
      </BaseCard>
    </template>

    <BaseModal v-model="showCreateModal" :title="createKind === 'ticket' ? 'Giao ticket tăng ca' : 'Đăng ký tăng ca'" data-testid="modal-create-overtime">
      <div class="space-y-4">
        <BaseSelect
          v-if="createKind === 'ticket'"
          v-model="form.employee_id"
          label="Nhân viên nhận ticket"
          :options="employeeOptions"
          placeholder="Chọn nhân viên"
          required
        />
        <div v-else class="rounded-lg bg-muted p-3">
          <p class="text-sm text-muted-foreground">Người gửi đơn</p>
          <p class="font-medium">{{ currentUser?.full_name || currentUser?.email || 'Bạn' }}</p>
          <p class="mt-1 text-xs text-muted-foreground">Quản lý giao việc ngoài giờ bằng “Ticket tăng ca”; đơn đăng ký luôn thuộc chính người gửi.</p>
        </div>

        <BaseInput v-model="form.overtime_date" type="date" label="Ngày tăng ca" required />
        <div class="grid grid-cols-2 gap-3">
          <BaseInput v-model="form.start_time" type="time" label="Giờ bắt đầu" required />
          <BaseInput v-model="form.end_time" type="time" label="Giờ kết thúc" hint="Có thể qua nửa đêm" required />
        </div>
        <p class="text-xs text-muted-foreground">Khung giờ tối thiểu 15 phút. Tiền OT chỉ tính phần thực tế ngoài ca khớp khung này, làm tròn xuống mỗi 15 phút.</p>

        <div v-if="usage && createKind === 'request'" class="space-y-1 rounded-lg border border-border p-3 text-xs">
          <p class="font-medium text-foreground">Giới hạn tăng ca (luật VN)</p>
          <p :class="usage.daily_used >= usage.daily_max ? 'text-red-600' : 'text-muted-foreground'">Ngày {{ form.overtime_date }}: {{ usage.daily_used }}/{{ usage.daily_max }}h</p>
          <p :class="usage.monthly_used >= usage.monthly_max ? 'text-red-600' : 'text-muted-foreground'">Tháng: {{ usage.monthly_used }}/{{ usage.monthly_max }}h</p>
          <p :class="usage.yearly_used >= usage.yearly_max ? 'text-amber-600' : 'text-muted-foreground'">Năm: {{ usage.yearly_used }}/{{ usage.yearly_max }}h</p>
        </div>

        <div>
          <label class="mb-2 block text-sm font-medium text-foreground">Lý do</label>
          <textarea v-model="form.reason" class="w-full rounded-lg border border-input bg-background px-4 py-2.5 text-foreground focus:outline-none focus:ring-2 focus:ring-ring" rows="3" placeholder="Nhập nội dung tăng ca..."></textarea>
        </div>
      </div>

      <div v-if="formError" class="mt-4 rounded-lg border border-destructive/20 bg-destructive/10 p-3">
        <p class="text-sm text-destructive">{{ formError }}</p>
      </div>

      <template #footer>
        <BaseButton variant="outline" :disabled="saving" @click="closeCreateModal">Hủy</BaseButton>
        <BaseButton :disabled="saving" @click="handleCreate">{{ saving ? 'Đang gửi...' : (createKind === 'ticket' ? 'Giao ticket' : 'Gửi đăng ký') }}</BaseButton>
      </template>
    </BaseModal>

    <BaseModal v-model="showDetailModal" :title="selectedRequest?.is_ticket ? 'Chi tiết ticket tăng ca' : 'Chi tiết đơn tăng ca'" size="lg">
      <div v-if="selectedRequest" class="space-y-4">
        <div class="grid grid-cols-2 gap-3 text-sm">
          <div><p class="text-muted-foreground">Nhân viên</p><p class="font-medium">{{ selectedRequest.employee?.full_name || empNameById(selectedRequest.employee_id) || `NV #${selectedRequest.employee_id}` }}</p></div>
          <div><p class="text-muted-foreground">Loại</p><p class="font-medium">{{ selectedRequest.is_ticket ? 'Ticket quản lý giao' : 'Đơn nhân viên đăng ký' }}</p></div>
          <div><p class="text-muted-foreground">Ngày tăng ca</p><p class="font-medium">{{ formatDate(selectedRequest.work_date) }}</p></div>
          <div><p class="text-muted-foreground">Khung được duyệt</p><p class="font-medium">{{ formatTime(selectedRequest.start_time) }} - {{ formatTime(selectedRequest.end_time) }}</p></div>
          <div><p class="text-muted-foreground">Thời lượng được duyệt</p><p class="font-medium">{{ formatHours(selectedRequest.approved_minutes) }}</p></div>
          <div><p class="text-muted-foreground">Thực tế khớp khung OT</p><p class="font-medium">{{ isReconciled(selectedRequest) ? formatHours(selectedRequest.matched_minutes) : 'Chờ đối soát' }}</p></div>
          <div><p class="text-muted-foreground">Giờ được thanh toán</p><p class="font-semibold text-primary">{{ isReconciled(selectedRequest) ? formatHours(selectedRequest.payable_overtime_minutes) : '—' }}</p></div>
          <div><p class="text-muted-foreground">Kết quả đối soát</p><p><span :class="reconciliationBadge(selectedRequest)">{{ reconciliationLabel(selectedRequest) }}</span></p></div>
          <div class="col-span-2"><p class="text-muted-foreground">Lý do</p><p class="font-medium">{{ selectedRequest.reason || '-' }}</p></div>
        </div>
        <div v-if="selectedRequest.reconciliation_warnings?.length" class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800">
          <p v-for="warning in selectedRequest.reconciliation_warnings" :key="warning">{{ warning }}</p>
        </div>
        <div v-if="!selectedRequest.is_ticket" class="border-t border-border pt-2">
          <p class="mb-1 text-sm font-semibold text-foreground">Tiến trình phê duyệt</p>
          <ApprovalTimeline :steps="approvalSteps" />
        </div>
        <div v-else class="rounded-lg bg-muted/30 p-3 text-xs text-muted-foreground">
          Ticket chuyển từ “Chờ nhân viên phản hồi” sang “Đã nhận” ngay khi nhân viên chấp nhận; không cần duyệt lần hai.
        </div>
      </div>
      <template #footer><BaseButton variant="outline" @click="showDetailModal = false">Đóng</BaseButton></template>
    </BaseModal>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import ApprovalTimeline from '../components/ApprovalTimeline.vue';
import BaseBadge from '../components/BaseBadge.vue';
import BaseButton from '../components/BaseButton.vue';
import BaseCard from '../components/BaseCard.vue';
import BaseInput from '../components/BaseInput.vue';
import BaseModal from '../components/BaseModal.vue';
import BaseSelect from '../components/BaseSelect.vue';
import BaseTable from '../components/BaseTable.vue';
import { useToast } from '../composables/useToast';
import { attendanceService } from '../services/attendanceService';
import { authService } from '../services/authService';
import { employeeService } from '../services/employeeService';
import { buildApprovalSteps, statusVN, statusVariant } from '../utils/approvalSteps';

const toast = useToast();
const currentUser = computed(() => authService.getUser());
const myEmployeeId = computed(() => currentUser.value?.employee_id || currentUser.value?.id || null);
const access = computed(() => authService.getAccess());
const roleCodes = computed(() => access.value.roles.map((role) => String(role.role_code || '').toUpperCase()));
const hasManagementLens = computed(() => authService.canAccessModule('time'));
const canManageTickets = computed(() => access.value.full || roleCodes.value.some((role) => ['ADMIN', 'TENANT_ADMIN', 'HR', 'MANAGER', 'DEPT_HEAD'].includes(role)));
const canCancelAllTickets = computed(() => access.value.full || roleCodes.value.some((role) => ['ADMIN', 'TENANT_ADMIN', 'HR'].includes(role)));

const viewMode = ref(hasManagementLens.value ? 'org' : 'mine');
const orgLens = computed(() => hasManagementLens.value && viewMode.value === 'org');
const requestGroup = ref('requests');
const pageTitle = computed(() => orgLens.value ? 'Quản lý Tăng ca' : 'Tăng ca của tôi');
const pageSubtitle = computed(() => orgLens.value
  ? 'Duyệt đơn nhân viên và giao ticket OT theo đúng thời gian máy chấm công'
  : 'Đăng ký, nhận ticket và theo dõi giờ tăng ca đã đối soát');

const loading = ref(true);
const error = ref('');
const saving = ref(false);
const processing = ref(false);
const requests = ref([]);
const employees = ref([]);
const showCreateModal = ref(false);
const showDetailModal = ref(false);
const selectedRequest = ref(null);
const createKind = ref('request');
const formError = ref('');
const usage = ref(null);
const form = ref({ employee_id: '', overtime_date: '', start_time: '', end_time: '', reason: '' });

const empNameById = (id) => employees.value.find((employee) => String(employee.id) === String(id))?.full_name || null;
const employeeOptions = computed(() => employees.value.map((employee) => ({
  value: String(employee.id),
  label: `${employee.employee_code || ''} - ${employee.full_name}`.replace(/^ - /, ''),
})));

const scopeRequests = computed(() => {
  if (orgLens.value) return requests.value;
  return requests.value.filter((item) => String(item.employee_id) === String(myEmployeeId.value));
});
const scopedNormalRequests = computed(() => scopeRequests.value.filter((item) => !item.is_ticket));
const scopedTickets = computed(() => scopeRequests.value.filter((item) => item.is_ticket));
const displayRequests = computed(() => requestGroup.value === 'tickets' ? scopedTickets.value : scopedNormalRequests.value);

const allColumns = [
  { key: 'employee', label: 'Nhân viên' },
  { key: 'date', label: 'Ngày' },
  { key: 'interval', label: 'Khung OT' },
  { key: 'approved', label: 'Được duyệt' },
  { key: 'actual', label: 'Máy thực tế' },
  { key: 'payable', label: 'Thanh toán' },
  { key: 'reason', label: 'Lý do' },
  { key: 'reconciliation', label: 'Đối soát' },
  { key: 'status', label: 'Trạng thái' },
];
const columns = computed(() => orgLens.value ? allColumns : allColumns.filter((column) => column.key !== 'employee'));
const tableTitle = computed(() => requestGroup.value === 'tickets'
  ? (orgLens.value ? 'Ticket tăng ca đã giao' : 'Ticket tăng ca được giao cho tôi')
  : (orgLens.value ? 'Đơn đăng ký tăng ca' : 'Đơn tăng ca của tôi'));

const statusCounts = computed(() => ({
  pending: displayRequests.value.filter((item) => ['pending', 'offered'].includes(item.status)).length,
  approved: displayRequests.value.filter((item) => item.status === 'approved').length,
  rejected: displayRequests.value.filter((item) => ['rejected', 'declined', 'cancelled'].includes(item.status)).length,
}));
const totalPayableMinutes = computed(() => displayRequests.value.reduce((sum, item) => sum + Number(item.payable_overtime_minutes || 0), 0));

const lensClass = (mode) => [
  'border-b-2 px-4 py-2 text-sm font-medium transition-colors',
  viewMode.value === mode ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground',
];
const getStatusText = (status) => statusVN(status);
const getStatusVariant = (status) => statusVariant(status);
const formatDate = (date) => date ? new Date(date).toLocaleDateString('vi-VN') : '—';
const formatTime = (value) => value ? String(value).slice(0, 5) : '—';
const formatHours = (minutes) => {
  const value = Math.max(0, Number(minutes || 0));
  if (!value) return '0 phút';
  const hours = Math.floor(value / 60);
  const rest = Math.round(value % 60);
  if (!hours) return `${rest} phút`;
  return rest ? `${hours}h ${rest}p` : `${hours}h`;
};
const isReconciled = (item) => !!item.reconciliation_status || ['approved'].includes(item.status);
const reconciliationLabel = (item) => {
  if (!['approved'].includes(item.status)) return 'Chưa đối soát';
  if (item.reconciliation_mode === 'LEGACY_CAPPED') return 'Dữ liệu cũ · LEGACY_CAPPED';
  const status = String(item.reconciliation_status || '').toUpperCase();
  if (status === 'MATCHED') return 'Khớp đủ';
  if (status === 'PARTIAL_MATCH') return 'Khớp một phần';
  if (status === 'NO_ATTENDANCE') return 'Thiếu chấm công';
  if (status === 'NO_MATCH') return 'Không khớp';
  return 'Chờ đối soát';
};
const reconciliationBadge = (item) => {
  const label = reconciliationLabel(item);
  const base = 'inline-flex rounded-full px-2 py-0.5 text-xs font-semibold whitespace-nowrap';
  if (label === 'Khớp đủ') return `${base} bg-emerald-500/15 text-emerald-700 dark:text-emerald-300`;
  if (label === 'Khớp một phần' || label.includes('LEGACY_CAPPED')) return `${base} bg-amber-500/15 text-amber-700 dark:text-amber-300`;
  if (['Thiếu chấm công', 'Không khớp'].includes(label)) return `${base} bg-red-500/15 text-red-700 dark:text-red-300`;
  return `${base} bg-muted text-muted-foreground`;
};

const approvalSteps = computed(() => selectedRequest.value ? buildApprovalSteps(selectedRequest.value, {
  creatorName: selectedRequest.value.employee?.full_name || empNameById(selectedRequest.value.employee_id) || 'Nhân viên',
  resolveName: empNameById,
}) : []);

const fetchUsage = async () => {
  if (createKind.value !== 'request' || !myEmployeeId.value || !form.value.overtime_date) {
    usage.value = null;
    return;
  }
  try { usage.value = await attendanceService.getOvertimeUsage(myEmployeeId.value, form.value.overtime_date); }
  catch { usage.value = null; }
};
watch(() => [form.value.overtime_date, createKind.value], fetchUsage);

const resetForm = () => {
  form.value = { employee_id: '', overtime_date: '', start_time: '', end_time: '', reason: '' };
  usage.value = null;
  formError.value = '';
};
const openCreateModal = (kind) => {
  createKind.value = kind;
  resetForm();
  showCreateModal.value = true;
};
const closeCreateModal = () => {
  showCreateModal.value = false;
  resetForm();
};

const intervalMinutes = (start, end) => {
  if (!start || !end) return 0;
  const [startHour, startMinute] = start.split(':').map(Number);
  const [endHour, endMinute] = end.split(':').map(Number);
  const from = startHour * 60 + startMinute;
  let to = endHour * 60 + endMinute;
  if (to <= from) to += 1440;
  return to - from;
};
const firstError = (err, fallback) => {
  const errors = err.response?.data?.data?.errors;
  const first = errors && typeof errors === 'object' ? Object.values(errors)[0] : null;
  return (Array.isArray(first) ? first.join(' ') : first) || err.response?.data?.message || fallback;
};

const handleCreate = async () => {
  const employeeId = createKind.value === 'ticket' ? form.value.employee_id : myEmployeeId.value;
  if (!employeeId) return formError.value = createKind.value === 'ticket' ? 'Vui lòng chọn nhân viên nhận ticket.' : 'Không thể xác định tài khoản nhân viên.';
  if (!form.value.overtime_date) return formError.value = 'Vui lòng chọn ngày tăng ca.';
  if (!form.value.start_time || !form.value.end_time) return formError.value = 'Bắt buộc nhập cả giờ bắt đầu và giờ kết thúc.';
  if (intervalMinutes(form.value.start_time, form.value.end_time) < 15) return formError.value = 'Khung tăng ca phải tối thiểu 15 phút.';

  saving.value = true;
  formError.value = '';
  try {
    const payload = {
      employee_id: Number(employeeId),
      overtime_date: form.value.overtime_date,
      start_time: form.value.start_time,
      end_time: form.value.end_time,
      reason: form.value.reason,
    };
    if (createKind.value === 'ticket') await attendanceService.createOvertimeTicket(payload);
    else await attendanceService.createOvertime(payload);
    toast.success(createKind.value === 'ticket' ? 'Đã giao ticket và chờ nhân viên phản hồi.' : 'Đã gửi đơn tăng ca.');
    closeCreateModal();
    await loadRequests();
  } catch (err) {
    formError.value = firstError(err, 'Không thể tạo yêu cầu tăng ca.');
  } finally {
    saving.value = false;
  }
};

const approveRequest = async (request, compOff = false) => {
  if (processing.value) return;
  if (compOff && !window.confirm('Duyệt và quy đổi OT thực tế đã đối soát thành nghỉ bù, không trả tiền OT?')) return;
  processing.value = true;
  try {
    const result = await attendanceService.approveOvertime(request.id, compOff);
    toast.success(result?.message || 'Đã duyệt đơn tăng ca.');
    await loadRequests();
  } catch (err) {
    toast.error(firstError(err, 'Không thể duyệt đơn tăng ca.'));
  } finally {
    processing.value = false;
  }
};

const respondTicket = async (ticket, decision) => {
  if (processing.value) return;
  if (decision === 'decline' && !window.confirm('Bạn chắc chắn muốn từ chối ticket tăng ca này?')) return;
  processing.value = true;
  try {
    await attendanceService.respondOvertimeTicket(ticket.id, decision);
    toast.success(decision === 'accept' ? 'Đã nhận ticket tăng ca.' : 'Đã từ chối ticket tăng ca.');
    await loadRequests();
  } catch (err) {
    toast.error(firstError(err, 'Không thể phản hồi ticket.'));
  } finally {
    processing.value = false;
  }
};

const canCancelTicket = (ticket) => {
  if (!['offered', 'approved'].includes(ticket.status)) return false;
  return canCancelAllTickets.value || Number(ticket.meta?.created_by || 0) === Number(myEmployeeId.value);
};
const cancelTicket = async (ticket) => {
  if (processing.value || !window.confirm('Hủy ticket tăng ca này?')) return;
  processing.value = true;
  try {
    await attendanceService.cancelOvertimeTicket(ticket.id, 'Hủy từ màn hình quản lý tăng ca');
    toast.success('Đã hủy ticket tăng ca.');
    await loadRequests();
  } catch (err) {
    toast.error(firstError(err, 'Không thể hủy ticket.'));
  } finally {
    processing.value = false;
  }
};

const openDetail = (item) => {
  selectedRequest.value = item;
  showDetailModal.value = true;
};

const loadRequests = async () => {
  const params = hasManagementLens.value ? {} : { employee_id: myEmployeeId.value };
  requests.value = await attendanceService.getOvertime(params);
};

onMounted(async () => {
  loading.value = true;
  error.value = '';
  try {
    const jobs = [loadRequests()];
    if (canManageTickets.value) jobs.push(employeeService.getLookup().then((data) => { employees.value = Array.isArray(data) ? data : (data?.data || []); }));
    await Promise.all(jobs);
  } catch (err) {
    if (err.response?.status !== 403) error.value = firstError(err, 'Không thể kết nối đến API.');
  } finally {
    loading.value = false;
  }
});
</script>
