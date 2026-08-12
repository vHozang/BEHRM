<template>
  <div class="space-y-6">
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-2">
      <div>
        <h1 class="text-xl sm:text-2xl font-bold">Đơn điều chỉnh công</h1>
        <p class="text-muted-foreground mt-1">Tạo và phê duyệt yêu cầu điều chỉnh chấm công của nhân viên</p>
      </div>
      <BaseButton
        @click="openCreateModal"
        data-testid="button-create-adjustment"
        class="w-full sm:w-auto"
      >
        + Tạo đơn
      </BaseButton>
    </div>

    <div v-if="error" class="bg-destructive/10 border border-destructive/20 rounded-lg p-4">
      <p class="text-destructive font-medium">Lỗi kết nối API:</p>
      <p class="text-destructive/80 text-sm mt-1">{{ error }}</p>
    </div>

    <BaseCard>
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 items-end">
        <div>
          <label class="block text-sm font-medium text-foreground mb-2">Trạng thái</label>
          <select
            v-model="filters.status"
            class="w-full px-4 py-2.5 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
            data-testid="select-filter-status"
          >
            <option v-for="opt in statusFilterOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-foreground mb-2">Ngày công</label>
          <input
            v-model="filters.work_date"
            type="date"
            class="w-full px-4 py-2.5 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
            data-testid="input-filter-date"
          />
        </div>
        <div class="flex gap-2">
          <BaseButton variant="outline" @click="loadAdjustments" :disabled="loading">
            {{ loading ? 'Đang tải...' : 'Áp dụng' }}
          </BaseButton>
          <BaseButton variant="ghost" @click="resetFilters" :disabled="loading">Xóa lọc</BaseButton>
        </div>
      </div>
    </BaseCard>

    <BaseCard title="Danh sách đơn điều chỉnh">
      <div v-if="loading" class="text-center py-8 text-muted-foreground">Đang tải dữ liệu...</div>
      <div v-else-if="adjustments.length === 0" class="text-center py-8 text-muted-foreground">
        Chưa có đơn điều chỉnh công nào
      </div>
      <BaseTable
        v-else
        :columns="columns"
        :data="adjustments"
        test-id="table-adjustments"
      >
        <template #cell-employee="{ item }">
          <span class="font-medium">{{ employeeName(item) }}</span>
        </template>

        <template #cell-work_date="{ item }">
          <span class="text-sm">{{ formatDate(item.work_date) }}</span>
        </template>

        <template #cell-times="{ item }">
          <div class="text-sm">
            <p>Vào: {{ formatTime(item.requested_check_in_time) }}</p>
            <p>Ra: {{ formatTime(item.requested_check_out_time) }}</p>
            <p v-if="item.requested_status" class="text-xs text-muted-foreground">
              Trạng thái: {{ item.requested_status }}
            </p>
          </div>
        </template>

        <template #cell-reason="{ item }">
          <div class="text-sm">
            <p class="text-muted-foreground">{{ item.reason || '-' }}</p>
            <a v-if="item.evidence" :href="item.evidence" target="_blank" class="text-xs text-primary underline">📎 Bằng chứng</a>
            <p v-if="item.decision_comment" class="text-xs text-muted-foreground italic mt-0.5">QL: {{ item.decision_comment }}</p>
          </div>
        </template>

        <template #cell-status="{ value }">
          <StatusPill :status="String(value || '').toLowerCase()" :label="statusLabel(value)" />
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
            <template v-if="isPending(item)">
              <button
                @click="approve(item)"
                class="p-1.5 rounded hover:bg-green-100 dark:hover:bg-green-900 text-green-600 dark:text-green-400 disabled:opacity-50"
                title="Duyệt"
                :disabled="processing"
                data-testid="button-approve"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
              </button>
              <button
                @click="openRejectModal(item)"
                class="p-1.5 rounded hover:bg-red-100 dark:hover:bg-red-900 text-red-600 dark:text-red-400 disabled:opacity-50"
                title="Từ chối"
                :disabled="processing"
                data-testid="button-reject"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
              <button
                @click="cancel(item)"
                class="p-1.5 rounded hover:bg-orange-100 dark:hover:bg-orange-900 text-orange-600 dark:text-orange-400 disabled:opacity-50"
                title="Hủy đơn"
                :disabled="processing"
                data-testid="button-cancel"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                </svg>
              </button>
            </template>
            <span v-else class="text-xs text-muted-foreground">—</span>
          </div>
        </template>
      </BaseTable>
    </BaseCard>

    <!-- Create modal -->
    <BaseModal
      v-model="showCreateModal"
      title="Tạo đơn điều chỉnh công"
      data-testid="modal-create-adjustment"
    >
      <div class="space-y-4">
        <div>
          <RemoteEmployeeSelect
            v-model="form.employee_id"
            label="Nhân viên"
            :initial-label="selectedEmployeeLabel"
            @select="rememberEmployee"
            data-testid="select-employee"
          />
          <p v-if="form.employee_id && selectedEmployeeLabel" class="text-xs text-primary mt-1">✓ Đã chọn: {{ selectedEmployeeLabel }}</p>
        </div>

        <div>
          <label class="block text-sm font-medium text-foreground mb-2">Ngày công <span class="text-destructive">*</span></label>
          <input
            v-model="form.work_date"
            type="date"
            class="w-full px-4 py-2.5 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
            data-testid="input-work-date"
          />
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-foreground mb-2">Giờ vào đề xuất</label>
            <input
              v-model="form.requested_check_in_time"
              type="time"
              class="w-full px-4 py-2.5 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
              data-testid="input-check-in"
            />
          </div>
          <div>
            <label class="block text-sm font-medium text-foreground mb-2">Giờ ra đề xuất</label>
            <input
              v-model="form.requested_check_out_time"
              type="time"
              class="w-full px-4 py-2.5 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
              data-testid="input-check-out"
            />
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium text-foreground mb-2">Lý do <span class="text-destructive">*</span></label>
          <textarea
            v-model="form.reason"
            rows="3"
            class="w-full px-4 py-2.5 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
            placeholder="Nhập lý do điều chỉnh công..."
            data-testid="input-reason"
          ></textarea>
        </div>

        <div>
          <label class="block text-sm font-medium text-foreground mb-2">Bằng chứng (link ảnh/tài liệu)</label>
          <input
            v-model="form.evidence"
            type="text"
            class="w-full px-4 py-2.5 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
            placeholder="Dán link ảnh chấm công/email xác nhận của quản lý (nếu có)"
          />
          <p class="text-xs text-muted-foreground mt-1">Đơn vẫn cần quản lý/HR duyệt — bước duyệt là xác nhận chính thức.</p>
        </div>
      </div>

      <div v-if="formError" class="mt-4 p-3 bg-destructive/10 border border-destructive/20 rounded-lg">
        <p class="text-destructive text-sm">{{ formError }}</p>
      </div>

      <template #footer>
        <BaseButton variant="outline" @click="closeCreateModal" :disabled="saving">Hủy</BaseButton>
        <BaseButton @click="handleCreate" :disabled="saving" data-testid="button-submit-adjustment">
          {{ saving ? 'Đang tạo...' : 'Tạo đơn' }}
        </BaseButton>
      </template>
    </BaseModal>

    <!-- Reject modal -->
    <BaseModal
      v-model="showRejectModal"
      title="Từ chối đơn điều chỉnh"
      size="sm"
      data-testid="modal-reject-adjustment"
    >
      <div class="space-y-4">
        <p class="text-sm text-muted-foreground">
          Bạn đang từ chối đơn của
          <span class="font-medium text-foreground">{{ rejectTarget ? employeeName(rejectTarget) : '' }}</span>.
          Có thể nhập lý do từ chối (không bắt buộc).
        </p>
        <div>
          <label class="block text-sm font-medium text-foreground mb-2">Ghi chú từ chối</label>
          <textarea
            v-model="rejectComment"
            rows="3"
            class="w-full px-4 py-2.5 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
            placeholder="Lý do từ chối..."
            data-testid="input-reject-comment"
          ></textarea>
        </div>
      </div>
      <template #footer>
        <BaseButton variant="outline" @click="showRejectModal = false" :disabled="processing">Đóng</BaseButton>
        <BaseButton variant="destructive" @click="confirmReject" :disabled="processing" data-testid="button-confirm-reject">
          {{ processing ? 'Đang xử lý...' : 'Từ chối' }}
        </BaseButton>
      </template>
    </BaseModal>

    <!-- Chi tiết + tiến trình phê duyệt -->
    <BaseModal v-model="showDetailModal" title="Chi tiết đơn điều chỉnh công" size="lg">
      <div v-if="selectedRequest" class="space-y-4">
        <div class="grid grid-cols-2 gap-3 text-sm">
          <div><p class="text-muted-foreground">Người tạo đơn</p><p class="font-medium">{{ selectedRequest.employee?.full_name || empNameById(selectedRequest.employee_id) || ('NV #' + selectedRequest.employee_id) }}</p></div>
          <div><p class="text-muted-foreground">Ngày công</p><p class="font-medium">{{ formatDate(selectedRequest.work_date) }}</p></div>
          <div><p class="text-muted-foreground">Giờ vào → ra (đề nghị)</p><p class="font-medium">{{ (selectedRequest.requested_check_in_time || '—') }} → {{ (selectedRequest.requested_check_out_time || '—') }}</p></div>
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
import { ref, computed, onMounted, watch, nextTick } from 'vue';
import { useRoute } from 'vue-router';
import BaseCard from '../components/BaseCard.vue';
import BaseButton from '../components/BaseButton.vue';
import BaseTable from '../components/BaseTable.vue';
import BaseModal from '../components/BaseModal.vue';
import StatusPill from '../components/StatusPill.vue';
import ApprovalTimeline from '../components/ApprovalTimeline.vue';
import RemoteEmployeeSelect from '../components/RemoteEmployeeSelect.vue';
import { buildApprovalSteps, statusVN } from '../utils/approvalSteps';
import { regularizationService } from '../services/regularizationService';
import { employeeService } from '../services/employeeService';
import { useToast } from '../composables/useToast';

const toast = useToast();
const route = useRoute();

const loading = ref(false);
const saving = ref(false);
const processing = ref(false);
const error = ref('');
const formError = ref('');

const adjustments = ref([]);
const employees = ref([]);

// Chi tiết + tiến trình duyệt (người tạo + người duyệt thật).
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

const showCreateModal = ref(false);
const showRejectModal = ref(false);
const rejectTarget = ref(null);
const rejectComment = ref('');

const filters = ref({ status: '', work_date: '' });

const form = ref({
  employee_id: '',
  work_date: '',
  requested_check_in_time: '',
  requested_check_out_time: '',
  reason: '',
  evidence: ''
});

const columns = [
  { key: 'employee', label: 'Nhân viên' },
  { key: 'work_date', label: 'Ngày công' },
  { key: 'times', label: 'Giờ vào/ra đề xuất' },
  { key: 'reason', label: 'Lý do' },
  { key: 'status', label: 'Trạng thái' }
];

const statusFilterOptions = [
  { label: 'Tất cả', value: '' },
  { label: 'Chờ duyệt', value: 'PENDING' },
  { label: 'Đã duyệt', value: 'APPROVED' },
  { label: 'Từ chối', value: 'REJECTED' },
  { label: 'Đã hủy', value: 'CANCELLED' }
];

const employeeOptions = computed(() =>
  employees.value.map((e) => ({
    label: e.full_name || `NV #${e.id}`,
    value: String(e.id)
  }))
);

const selectedEmployeeLabel = computed(() => {
  const opt = employeeOptions.value.find((o) => o.value === String(form.value.employee_id));
  return opt ? opt.label : '';
});

const rememberEmployee = (employee) => {
  if (!employee) return;
  const index = employees.value.findIndex(item => String(item.id) === String(employee.id));
  if (index >= 0) employees.value[index] = employee;
  else employees.value.push(employee);
};

const STATUS_LABELS = {
  PENDING: 'Chờ duyệt',
  APPROVED: 'Đã duyệt',
  REJECTED: 'Từ chối',
  CANCELLED: 'Đã hủy'
};

const statusLabel = (status) => statusVN(status);

const isPending = (item) => String(item?.status || '').toUpperCase() === 'PENDING';

const employeeName = (item) =>
  item?.employee?.full_name || item?.full_name || `NV #${item?.employee_id ?? '?'}`;

const formatDate = (date) => {
  if (!date) return '-';
  const d = new Date(date);
  return Number.isNaN(d.getTime()) ? date : d.toLocaleDateString('vi-VN');
};

const formatTime = (time) => {
  if (!time) return '—';
  // Accept "HH:mm", "HH:mm:ss" or ISO datetime; show HH:mm.
  const m = String(time).match(/(\d{1,2}):(\d{2})/);
  return m ? `${m[1].padStart(2, '0')}:${m[2]}` : String(time);
};

const apiErrorMessage = (err, fallback) =>
  err?.response?.data?.message || err?.response?.data?.error || err?.message || fallback;

const resetForm = () => {
  form.value = {
    employee_id: '',
    work_date: '',
    requested_check_in_time: '',
    requested_check_out_time: '',
    reason: '',
    evidence: ''
  };
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

const resetFilters = () => {
  filters.value = { status: '', work_date: '' };
  loadAdjustments();
};

const extractItems = (response) => {
  // axiosClient unwraps {items,pagination} envelopes to a plain array on response.data.
  // Be defensive: also accept raw {items} / {data:{items}} / array shapes.
  if (Array.isArray(response)) return response;
  if (Array.isArray(response?.items)) return response.items;
  if (Array.isArray(response?.data)) return response.data;
  if (Array.isArray(response?.data?.items)) return response.data.items;
  return [];
};

const loadAdjustments = async () => {
  loading.value = true;
  error.value = '';
  try {
    const params = {};
    if (filters.value.status) params.status = filters.value.status;
    if (filters.value.work_date) params.work_date = filters.value.work_date;
    const response = await regularizationService.getAll(params);
    adjustments.value = extractItems(response);
  } catch (err) {
    console.error('Error loading attendance adjustments:', err);
    error.value = apiErrorMessage(err, 'Không thể tải danh sách đơn điều chỉnh');
  } finally {
    loading.value = false;
  }
};

const loadPrefilledEmployee = async (employeeId) => {
  if (!employeeId || employees.value.some(item => String(item.id) === String(employeeId))) return;
  try {
    rememberEmployee(await employeeService.getById(employeeId));
  } catch {
    // Keep the form usable even if the referenced profile is no longer visible.
  }
};

const handleCreate = async () => {
  formError.value = '';
  if (!form.value.employee_id) {
    formError.value = 'Vui lòng chọn nhân viên';
    return;
  }
  if (!form.value.work_date) {
    formError.value = 'Vui lòng chọn ngày công';
    return;
  }
  if (!form.value.reason || !form.value.reason.trim()) {
    formError.value = 'Vui lòng nhập lý do';
    return;
  }

  saving.value = true;
  try {
    const payload = {
      employee_id: parseInt(form.value.employee_id, 10),
      work_date: form.value.work_date,
      reason: form.value.reason.trim()
    };
    if (form.value.requested_check_in_time) {
      payload.requested_check_in_time = form.value.requested_check_in_time;
    }
    if (form.value.requested_check_out_time) {
      payload.requested_check_out_time = form.value.requested_check_out_time;
    }
    if (form.value.evidence && form.value.evidence.trim()) {
      payload.evidence = form.value.evidence.trim();
    }
    await regularizationService.create(payload);
    toast.success('Đã tạo đơn điều chỉnh công');
    closeCreateModal();
    await loadAdjustments();
  } catch (err) {
    console.error('Error creating adjustment:', err);
    formError.value = apiErrorMessage(err, 'Có lỗi xảy ra khi tạo đơn');
  } finally {
    saving.value = false;
  }
};

const approve = async (item) => {
  if (processing.value) return;
  processing.value = true;
  try {
    const res = await regularizationService.approve(item.id);
    toast.success(res?.message || 'Đã duyệt đơn điều chỉnh');
    await loadAdjustments();
  } catch (err) {
    console.error('Error approving adjustment:', err);
    toast.error(apiErrorMessage(err, 'Có lỗi xảy ra khi duyệt'));
  } finally {
    processing.value = false;
  }
};

const openRejectModal = (item) => {
  rejectTarget.value = item;
  rejectComment.value = '';
  showRejectModal.value = true;
};

const confirmReject = async () => {
  if (processing.value || !rejectTarget.value) return;
  processing.value = true;
  try {
    const payload = rejectComment.value.trim()
      ? { decision_comment: rejectComment.value.trim() }
      : {};
    await regularizationService.reject(rejectTarget.value.id, payload);
    toast.success('Đã từ chối đơn điều chỉnh');
    showRejectModal.value = false;
    rejectTarget.value = null;
    await loadAdjustments();
  } catch (err) {
    console.error('Error rejecting adjustment:', err);
    toast.error(apiErrorMessage(err, 'Có lỗi xảy ra khi từ chối'));
  } finally {
    processing.value = false;
  }
};

const cancel = async (item) => {
  if (processing.value) return;
  if (!confirm('Bạn có chắc chắn muốn hủy đơn điều chỉnh này?')) return;
  processing.value = true;
  try {
    await regularizationService.cancel(item.id);
    toast.success('Đã hủy đơn điều chỉnh');
    await loadAdjustments();
  } catch (err) {
    console.error('Error cancelling adjustment:', err);
    toast.error(apiErrorMessage(err, 'Có lỗi xảy ra khi hủy đơn'));
  } finally {
    processing.value = false;
  }
};

// Mở form tạo đơn đã điền sẵn từ query (?employee_id=&work_date=) — vd bấm từ
// ngày VẮNG trên Bảng công / trang Chấm công.
const applyQueryPrefill = async (q) => {
  q = q || {};
  if (!q.employee_id && !q.work_date) return;
  if (q.employee_id) await loadPrefilledEmployee(q.employee_id);
  resetForm();
  if (q.work_date) form.value.work_date = String(q.work_date);
  if (q.employee_id) form.value.employee_id = String(q.employee_id);
  await nextTick();
  showCreateModal.value = true;
};

// Chạy ngay khi vào trang (immediate) + khi điều hướng lại với query mới.
watch(() => route.query, (q) => applyQueryPrefill(q), { immediate: true });

onMounted(() => {
  loadAdjustments();
});
</script>
