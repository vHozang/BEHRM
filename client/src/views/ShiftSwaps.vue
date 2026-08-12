<template>
  <div class="space-y-6">
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-6">
      <div>
        <h1 class="text-xl sm:text-2xl font-bold">{{ pageTitle }}</h1>
        <p class="text-muted-foreground mt-1">{{ pageSubtitle }}</p>
      </div>
      <BaseButton
        @click="openCreateModal"
        data-testid="button-create-swap"
        class="w-full sm:w-auto"
      >
        + Tạo yêu cầu đổi ca
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
            <p class="text-sm text-muted-foreground">Tổng yêu cầu</p>
            <p class="text-3xl font-bold text-blue-600 dark:text-blue-400 mt-2">{{ displayRequests.length }}</p>
          </div>
        </BaseCard>
      </div>

      <BaseCard :title="orgLens ? 'Danh sách yêu cầu đổi ca' : 'Yêu cầu đổi ca của tôi'">
        <div v-if="displayRequests.length === 0" class="text-center py-8 text-muted-foreground">
          Chưa có yêu cầu đổi ca nào
        </div>
        <BaseTable
          v-else
          :columns="columns"
          :data="displayRequests"
          data-testid="table-swaps"
        >
          <template v-if="orgLens" #cell-requester="{ item }">
            <span class="text-sm font-medium">{{ item.requester?.full_name || item.employee?.full_name || `NV #${item.requester_id || item.employee_id}` }}</span>
          </template>

          <template #cell-target="{ item }">
            <span class="text-sm">{{ item.target?.full_name || item.target_employee?.full_name || (item.target_employee_id ? `NV #${item.target_employee_id}` : '-') }}</span>
          </template>

          <template #cell-dates="{ item }">
            <div class="text-sm">
              <p>{{ formatDate(item.from_date || item.original_date) }}<span v-if="item.to_date || item.swap_date"> &rarr; {{ formatDate(item.to_date || item.swap_date) }}</span></p>
            </div>
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
              <button
                v-if="orgLens && item.status === 'pending'"
                @click="approveRequest(item)"
                class="p-1.5 rounded hover:bg-green-100 dark:hover:bg-green-900 text-green-600 dark:text-green-400"
                title="Duyệt"
                :disabled="processing"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
              </button>
            </div>
          </template>
        </BaseTable>
      </BaseCard>
    </template>

    <BaseModal
      v-model="showCreateModal"
      title="Tạo yêu cầu đổi ca"
      data-testid="modal-create-swap"
    >
      <div class="space-y-4">
        <template v-if="orgLens">
          <RemoteEmployeeSelect
            v-model="form.requester_id"
            label="Nhân viên yêu cầu"
            :initial-label="employeeLabel(form.requester_id)"
            @select="rememberEmployee"
          />
        </template>
        <template v-else>
          <div class="p-3 bg-muted rounded-lg">
            <p class="text-sm text-muted-foreground">Nhân viên yêu cầu</p>
            <p class="font-medium">{{ currentUser?.full_name || currentUser?.email || 'Bạn' }}</p>
          </div>
        </template>
        <RemoteEmployeeSelect
          v-model="form.target_employee_id"
          label="Đổi ca với nhân viên"
          :initial-label="employeeLabel(form.target_employee_id)"
          @select="rememberEmployee"
        />
        <div class="grid grid-cols-2 gap-4">
          <BaseInput
            v-model="form.from_date"
            type="date"
            label="Ngày của tôi"
            required
          />
          <BaseInput
            v-model="form.to_date"
            type="date"
            label="Ngày muốn đổi"
            required
          />
        </div>
        <div>
          <label class="block text-sm font-medium text-foreground mb-2">Lý do</label>
          <textarea
            v-model="form.reason"
            class="w-full px-4 py-2.5 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
            rows="3"
            placeholder="Nhập lý do đổi ca..."
          ></textarea>
        </div>
      </div>

      <div v-if="formError" class="mt-4 p-3 bg-destructive/10 border border-destructive/20 rounded-lg">
        <p class="text-destructive text-sm">{{ formError }}</p>
      </div>

      <template #footer>
        <BaseButton variant="outline" @click="closeCreateModal" :disabled="saving">Hủy</BaseButton>
        <BaseButton @click="handleCreate" :disabled="saving">
          {{ saving ? 'Đang gửi...' : 'Gửi yêu cầu' }}
        </BaseButton>
      </template>
    </BaseModal>

    <!-- Chi tiết + tiến trình phê duyệt -->
    <BaseModal v-model="showDetailModal" title="Chi tiết yêu cầu đổi ca" size="lg">
      <div v-if="selectedRequest" class="space-y-4">
        <div class="grid grid-cols-2 gap-3 text-sm">
          <div><p class="text-muted-foreground">Người yêu cầu</p><p class="font-medium">{{ selectedRequest.requester?.full_name || empNameById(selectedRequest.requester_id) || ('NV #' + selectedRequest.requester_id) }}</p></div>
          <div><p class="text-muted-foreground">Đổi ca với</p><p class="font-medium">{{ selectedRequest.target?.full_name || empNameById(selectedRequest.target_employee_id) || ('NV #' + selectedRequest.target_employee_id) }}</p></div>
          <div><p class="text-muted-foreground">Ngày đổi</p><p class="font-medium">{{ formatDate(selectedRequest.swap_date) }}</p></div>
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
import { ref, computed, onMounted } from 'vue';
import BaseCard from '../components/BaseCard.vue';
import BaseButton from '../components/BaseButton.vue';
import BaseInput from '../components/BaseInput.vue';
import BaseSelect from '../components/BaseSelect.vue';
import BaseBadge from '../components/BaseBadge.vue';
import BaseTable from '../components/BaseTable.vue';
import ApprovalTimeline from '../components/ApprovalTimeline.vue';
import { buildApprovalSteps, statusVN, statusVariant } from '../utils/approvalSteps';
import BaseModal from '../components/BaseModal.vue';
import RemoteEmployeeSelect from '../components/RemoteEmployeeSelect.vue';
import { workScheduleService } from '../services/workScheduleService';
import { authService } from '../services/authService';

const isAdmin = computed(() => authService.isAdmin());
const currentUser = computed(() => authService.getUser());

// Dual-lens: admins toggle company-wide vs their own.
const viewMode = ref('org'); // 'org' | 'mine'
const myEmployeeId = computed(() => currentUser.value?.employee_id || currentUser.value?.id || null);
const orgLens = computed(() => isAdmin.value && viewMode.value === 'org');
const pageTitle = computed(() => orgLens.value ? 'Quản lý Đổi ca' : 'Yêu cầu Đổi ca');
const pageSubtitle = computed(() => orgLens.value ? 'Duyệt các yêu cầu hoán đổi ca làm việc' : 'Gửi và theo dõi yêu cầu đổi ca của bạn');

const loading = ref(true);
const error = ref('');
const saving = ref(false);
const processing = ref(false);
const formError = ref('');

const showCreateModal = ref(false);
const requests = ref([]);
const employees = ref([]);

// Chi tiết + tiến trình duyệt (người yêu cầu + người duyệt thật).
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
    creatorName: req.requester?.full_name || empNameById(req.requester_id) || 'Nhân viên',
    resolveName: empNameById,
    approverId: req.approver_id,
  });
});

const form = ref({
  requester_id: '',
  target_employee_id: '',
  from_date: '',
  to_date: '',
  reason: ''
});

const allColumns = [
  { key: 'requester', label: 'Người yêu cầu' },
  { key: 'target', label: 'Đổi với' },
  { key: 'dates', label: 'Ngày đổi' },
  { key: 'reason', label: 'Lý do' },
  { key: 'status', label: 'Trạng thái' },
];
const columns = computed(() => orgLens.value ? allColumns : allColumns.filter(c => c.key !== 'requester'));

// Org lens: all swaps. Otherwise: swaps I'm involved in (requester or target).
const displayRequests = computed(() => {
  if (orgLens.value) return requests.value;
  const myId = myEmployeeId.value;
  if (!myId) return isAdmin.value ? [] : requests.value;
  return requests.value.filter(r =>
    String(r.requester_id ?? r.employee_id) === String(myId) ||
    String(r.target_employee_id ?? '') === String(myId)
  );
});

const employeeLabel = (id) => {
  const employee = employees.value.find(item => String(item.id) === String(id));
  return employee ? `${employee.employee_code || ''} · ${employee.full_name}`.replace(/^ · /, '') : '';
};
const rememberEmployee = (employee) => {
  if (!employee) return;
  const index = employees.value.findIndex(item => String(item.id) === String(employee.id));
  if (index >= 0) employees.value[index] = employee;
  else employees.value.push(employee);
};

const statusCounts = computed(() => ({
  pending: displayRequests.value.filter(r => r.status === 'pending').length,
  approved: displayRequests.value.filter(r => r.status === 'approved').length,
  rejected: displayRequests.value.filter(r => r.status === 'rejected').length
}));

const formatDate = (date) => {
  if (!date) return '';
  return new Date(date).toLocaleDateString('vi-VN');
};

const getStatusVariant = (status) => statusVariant(status);
const getStatusText = (status) => statusVN(status);

const resetForm = () => {
  form.value = {
    requester_id: orgLens.value ? '' : (myEmployeeId.value ? String(myEmployeeId.value) : ''),
    target_employee_id: '',
    from_date: '',
    to_date: '',
    reason: ''
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

const handleCreate = async () => {
  const requesterId = orgLens.value ? form.value.requester_id : (myEmployeeId.value ? String(myEmployeeId.value) : '');

  if (!requesterId) {
    formError.value = 'Không thể xác định nhân viên. Vui lòng thử lại.';
    return;
  }
  if (!form.value.from_date || !form.value.to_date) {
    formError.value = 'Vui lòng chọn ngày của bạn và ngày muốn đổi';
    return;
  }

  try {
    saving.value = true;
    formError.value = '';

    const payload = {
      requester_id: parseInt(requesterId),
      from_date: form.value.from_date,
      to_date: form.value.to_date,
      reason: form.value.reason,
      status: 'pending'
    };
    if (form.value.target_employee_id) {
      payload.target_employee_id = parseInt(form.value.target_employee_id);
    }

    await workScheduleService.requestSwap(payload);

    closeCreateModal();
    await loadRequests();
  } catch (err) {
    console.error('Error creating shift swap:', err);
    formError.value = err.response?.data?.error || err.response?.data?.message || 'Có lỗi xảy ra';
  } finally {
    saving.value = false;
  }
};

const approveRequest = async (request) => {
  if (processing.value || !isAdmin.value) return;

  try {
    processing.value = true;
    await workScheduleService.approveSwap(request.id);
    await loadRequests();
  } catch (err) {
    console.error('Error approving shift swap:', err);
    alert(err.response?.data?.error || err.response?.data?.message || 'Có lỗi xảy ra khi duyệt');
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
        params.requester_id = user.employee_id;
      }
    }
    const response = await workScheduleService.getSwaps(params);
    requests.value = response?.data || response || [];
  } catch (err) {
    console.error('Error loading shift swaps:', err);
    if (err.response?.status === 403) {
      requests.value = [];
    }
  }
};

onMounted(async () => {
  try {
    loading.value = true;
    error.value = '';

    const result = await workScheduleService.getSwaps(!isAdmin.value && currentUser.value?.employee_id ? { requester_id: currentUser.value.employee_id } : {}).catch((err) => {
      if (err.response?.status === 403) return [];
      throw err;
    });
    requests.value = result?.data || result || [];
  } catch (err) {
    console.error('Shift swap API Error:', err);
    if (err.response?.status !== 403) {
      error.value = err.response?.data?.error || err.message || 'Không thể kết nối đến API';
    }
  } finally {
    loading.value = false;
  }
});
</script>
