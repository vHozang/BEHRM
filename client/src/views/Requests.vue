<template>
  <div class="space-y-6">
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-6">
      <div>
        <h1 class="text-xl sm:text-2xl font-bold">{{ isAdmin ? 'Quản lý Yêu cầu phê duyệt' : 'Yêu cầu phê duyệt của tôi' }}</h1>
        <p class="text-muted-foreground mt-1">{{ isAdmin ? 'Xử lý các yêu cầu phê duyệt nhiều bước' : 'Tạo và theo dõi yêu cầu phê duyệt' }}</p>
      </div>
      <BaseButton
        @click="openCreateModal"
        data-testid="button-create-request"
        class="w-full sm:w-auto"
      >
        + Tạo yêu cầu
      </BaseButton>
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
            <p class="text-3xl font-bold text-blue-600 dark:text-blue-400 mt-2">{{ requests.length }}</p>
          </div>
        </BaseCard>
      </div>

      <BaseCard :title="isAdmin ? 'Danh sách yêu cầu' : 'Yêu cầu của tôi'">
        <div v-if="filteredRequests.length === 0" class="text-center py-8 text-muted-foreground">
          Chưa có yêu cầu phê duyệt nào
        </div>
        <BaseTable
          v-else
          :columns="columns"
          :data="filteredRequests"
          data-testid="table-requests"
        >
          <template #cell-title="{ item }">
            <div class="text-sm">
              <p class="font-medium">{{ item.title || item.subject || `Yêu cầu #${item.id}` }}</p>
              <p class="text-xs text-muted-foreground">{{ getTypeText(item.type || item.request_type) }}</p>
            </div>
          </template>

          <template #cell-requester="{ item }">
            <span class="text-sm">{{ item.requester?.full_name || item.employee?.full_name || `NV #${item.employee_id || item.requester_id || '-'}` }}</span>
          </template>

          <template #cell-step="{ item }">
            <span class="text-sm text-muted-foreground">{{ getStepText(item) }}</span>
          </template>

          <template #cell-status="{ value }">
            <BaseBadge :variant="getStatusVariant(value)">
              {{ getStatusText(value) }}
            </BaseBadge>
          </template>

          <template #actions="{ item }">
            <div class="flex items-center gap-2">
              <template v-if="isAdmin && item.status === 'pending'">
                <button
                  @click="approveRequest(item)"
                  class="p-1.5 rounded hover:bg-green-100 dark:hover:bg-green-900 text-green-600 dark:text-green-400"
                  title="Duyệt"
                  :disabled="processing"
                >
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                  </svg>
                </button>
                <button
                  @click="rejectRequest(item)"
                  class="p-1.5 rounded hover:bg-red-100 dark:hover:bg-red-900 text-red-600 dark:text-red-400"
                  title="Từ chối"
                  :disabled="processing"
                >
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                  </svg>
                </button>
              </template>
              <button
                v-if="canCancel(item)"
                @click="cancelRequest(item)"
                class="p-1.5 rounded hover:bg-orange-100 dark:hover:bg-orange-900 text-orange-600 dark:text-orange-400"
                title="Hủy yêu cầu"
                :disabled="processing"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                </svg>
              </button>
              <button
                @click="viewDetail(item)"
                class="p-1.5 rounded hover:bg-muted"
                title="Xem chi tiết"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                </svg>
              </button>
            </div>
          </template>
        </BaseTable>
      </BaseCard>
    </template>

    <BaseModal
      v-model="showCreateModal"
      title="Tạo yêu cầu phê duyệt"
      data-testid="modal-create-request"
    >
      <div class="space-y-4">
        <BaseSelect
          v-model="form.type"
          label="Loại yêu cầu"
          :options="typeOptions"
          required
        />
        <BaseInput
          v-model="form.title"
          label="Tiêu đề"
          placeholder="Nhập tiêu đề yêu cầu..."
          required
        />
        <div>
          <label class="block text-sm font-medium text-foreground mb-2">Nội dung</label>
          <textarea
            v-model="form.description"
            class="w-full px-4 py-2.5 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
            rows="4"
            placeholder="Mô tả chi tiết yêu cầu..."
          ></textarea>
        </div>
      </div>

      <div v-if="formError" class="mt-4 p-3 bg-destructive/10 border border-destructive/20 rounded-lg">
        <p class="text-destructive text-sm">{{ formError }}</p>
      </div>

      <template #footer>
        <BaseButton variant="outline" @click="closeCreateModal" :disabled="saving">Hủy</BaseButton>
        <BaseButton @click="handleCreate" :disabled="saving">
          {{ saving ? 'Đang tạo...' : 'Tạo yêu cầu' }}
        </BaseButton>
      </template>
    </BaseModal>

    <BaseModal
      v-model="showDetailModal"
      title="Chi tiết yêu cầu phê duyệt"
    >
      <div v-if="selectedRequest" class="space-y-4">
        <div class="grid grid-cols-2 gap-4">
          <div>
            <p class="text-sm text-muted-foreground">Tiêu đề</p>
            <p class="font-medium">{{ selectedRequest.title || selectedRequest.subject || `Yêu cầu #${selectedRequest.id}` }}</p>
          </div>
          <div>
            <p class="text-sm text-muted-foreground">Loại yêu cầu</p>
            <p class="font-medium">{{ getTypeText(selectedRequest.type || selectedRequest.request_type) }}</p>
          </div>
          <div>
            <p class="text-sm text-muted-foreground">Người yêu cầu</p>
            <p class="font-medium">{{ selectedRequest.requester?.full_name || selectedRequest.employee?.full_name || `NV #${selectedRequest.employee_id || selectedRequest.requester_id || '-'}` }}</p>
          </div>
          <div>
            <p class="text-sm text-muted-foreground">Trạng thái</p>
            <BaseBadge :variant="getStatusVariant(selectedRequest.status)">
              {{ getStatusText(selectedRequest.status) }}
            </BaseBadge>
          </div>
        </div>
        <div>
          <p class="text-sm text-muted-foreground">Nội dung</p>
          <p class="font-medium mb-4">{{ selectedRequest.description || selectedRequest.content || 'Không có' }}</p>
        </div>
        <div class="border-t border-border pt-4">
          <p class="text-sm font-semibold text-foreground mb-3">Quy trình phê duyệt</p>
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
import BaseModal from '../components/BaseModal.vue';
import ApprovalTimeline from '../components/ApprovalTimeline.vue';
import { requestService } from '../services/requestService';
import { authService } from '../services/authService';

const isAdmin = computed(() => authService.isAdmin());
const currentUser = computed(() => authService.getUser());

const loading = ref(true);
const error = ref('');
const saving = ref(false);
const processing = ref(false);
const formError = ref('');

const showCreateModal = ref(false);
const showDetailModal = ref(false);
const selectedRequest = ref(null);

const requests = ref([]);

const form = ref({
  type: '',
  title: '',
  description: ''
});

const columns = [
  { key: 'title', label: 'Yêu cầu' },
  { key: 'requester', label: 'Người yêu cầu' },
  { key: 'step', label: 'Tiến trình' },
  { key: 'status', label: 'Trạng thái' },
];

const typeOptions = [
  { label: 'Chọn loại yêu cầu', value: '' },
  { label: 'Yêu cầu chung', value: 'general' },
  { label: 'Yêu cầu nghỉ phép', value: 'leave' },
  { label: 'Yêu cầu tăng ca', value: 'overtime' },
  { label: 'Yêu cầu đổi ca', value: 'shift_swap' },
  { label: 'Yêu cầu mua sắm', value: 'purchase' },
];

const typeTexts = {
  general: 'Yêu cầu chung',
  leave: 'Yêu cầu nghỉ phép',
  overtime: 'Yêu cầu tăng ca',
  shift_swap: 'Yêu cầu đổi ca',
  purchase: 'Yêu cầu mua sắm'
};

const getTypeText = (type) => typeTexts[type] || type || 'Yêu cầu chung';

const statusCounts = computed(() => ({
  pending: requests.value.filter(r => r.status === 'pending').length,
  approved: requests.value.filter(r => r.status === 'approved').length,
  rejected: requests.value.filter(r => r.status === 'rejected').length
}));

const filteredRequests = computed(() => requests.value);

const getStepText = (item) => {
  const current = item.current_step ?? item.step ?? null;
  const total = item.total_steps ?? item.steps_count ?? null;
  if (current != null && total != null) {
    return `Bước ${current}/${total}`;
  }
  if (current != null) {
    return `Bước ${current}`;
  }
  return getStatusText(item.status);
};

const getStatusVariant = (status) => {
  const variants = {
    draft: 'default',
    pending: 'warning',
    approved: 'success',
    rejected: 'error',
    cancelled: 'default'
  };
  return variants[status] || 'default';
};

const getStatusText = (status) => {
  const texts = {
    draft: 'Nháp',
    pending: 'Chờ duyệt',
    approved: 'Đã duyệt',
    rejected: 'Từ chối',
    cancelled: 'Đã hủy'
  };
  return texts[status] || status;
};

const approvalSteps = computed(() => {
  if (!selectedRequest.value) return [];
  const req = selectedRequest.value;

  // Use real approval history when available
  const history = req.approvals || req.approval_steps || req.steps;
  if (Array.isArray(history) && history.length > 0) {
    return history.map((s, i) => ({
      step_name: s.step_name || s.name || `Bước ${i + 1}`,
      approver_name: s.approver_name || s.approver?.full_name || s.reviewer?.full_name || '',
      status: mapStepStatus(s.status),
      action_date: s.action_date || s.updated_at || s.created_at || null,
      comment: s.comment || s.note || null
    }));
  }

  // Fallback synthetic timeline based on the overall status
  return [
    {
      step_name: 'Khởi tạo yêu cầu',
      approver_name: req.requester?.full_name || req.employee?.full_name || 'Người yêu cầu',
      status: 'ĐÃ_DUYỆT',
      action_date: req.created_at || null,
      comment: 'Gửi yêu cầu thành công'
    },
    {
      step_name: 'Phê duyệt',
      approver_name: 'Người duyệt',
      status: req.status === 'approved' ? 'ĐÃ_DUYỆT' : req.status === 'rejected' ? 'TỪ_CHỐI' : 'CHỜ_DUYỆT',
      action_date: req.status !== 'pending' ? req.updated_at : null,
      comment: req.status === 'rejected' ? 'Từ chối yêu cầu' : req.status === 'approved' ? 'Đã duyệt' : 'Đang chờ phê duyệt'
    },
    {
      step_name: 'Hoàn tất',
      approver_name: 'Hệ thống',
      status: req.status === 'approved' ? 'HOÀN_THÀNH' : req.status === 'rejected' ? 'TỪ_CHỐI' : 'CHỜ_DUYỆT',
      action_date: req.status === 'approved' ? req.updated_at : null,
      comment: req.status === 'approved' ? 'Yêu cầu đã được xử lý' : null
    }
  ];
});

const mapStepStatus = (status) => {
  const map = {
    approved: 'ĐÃ_DUYỆT',
    completed: 'HOÀN_THÀNH',
    rejected: 'TỪ_CHỐI',
    pending: 'CHỜ_DUYỆT',
    processing: 'ĐANG_XỬ_LÝ'
  };
  return map[status] || 'CHỜ_DUYỆT';
};

const resetForm = () => {
  form.value = { type: '', title: '', description: '' };
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

const viewDetail = async (request) => {
  selectedRequest.value = request;
  showDetailModal.value = true;
  try {
    const response = await requestService.get(request.id);
    const detail = response?.data || response;
    if (detail) selectedRequest.value = detail;
  } catch (err) {
    console.error('Error loading request detail:', err);
  }
};

const handleCreate = async () => {
  if (!form.value.type) {
    formError.value = 'Vui lòng chọn loại yêu cầu';
    return;
  }
  if (!form.value.title) {
    formError.value = 'Vui lòng nhập tiêu đề';
    return;
  }

  try {
    saving.value = true;
    formError.value = '';

    const payload = {
      type: form.value.type,
      title: form.value.title,
      description: form.value.description,
      status: 'pending'
    };
    const user = currentUser.value;
    if (user?.employee_id) {
      payload.employee_id = user.employee_id;
    }

    await requestService.create(payload);

    closeCreateModal();
    await loadRequests();
  } catch (err) {
    console.error('Error creating request:', err);
    formError.value = err.response?.data?.error || err.response?.data?.message || 'Có lỗi xảy ra';
  } finally {
    saving.value = false;
  }
};

const approveRequest = async (request) => {
  if (processing.value || !isAdmin.value) return;

  try {
    processing.value = true;
    await requestService.approve(request.id, {});
    await loadRequests();
  } catch (err) {
    console.error('Error approving request:', err);
    alert(err.response?.data?.error || err.response?.data?.message || 'Có lỗi xảy ra khi duyệt');
  } finally {
    processing.value = false;
  }
};

const rejectRequest = async (request) => {
  if (processing.value || !isAdmin.value) return;

  try {
    processing.value = true;
    await requestService.reject(request.id, {});
    await loadRequests();
  } catch (err) {
    console.error('Error rejecting request:', err);
    alert(err.response?.data?.error || err.response?.data?.message || 'Có lỗi xảy ra khi từ chối');
  } finally {
    processing.value = false;
  }
};

const canCancel = (request) => {
  if (!['pending', 'draft'].includes(request.status)) return false;
  if (isAdmin.value) return true;
  const user = currentUser.value;
  if (!user?.employee_id) return false;
  return String(request.employee_id || request.requester_id) === String(user.employee_id);
};

const cancelRequest = async (request) => {
  if (processing.value || !canCancel(request)) return;
  if (!confirm('Bạn có chắc chắn muốn hủy yêu cầu này?')) return;

  try {
    processing.value = true;
    await requestService.cancel(request.id);
    await loadRequests();
  } catch (err) {
    console.error('Error cancelling request:', err);
    alert(err.response?.data?.error || err.response?.data?.message || 'Có lỗi xảy ra khi hủy yêu cầu');
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
    const response = await requestService.getAll(params);
    requests.value = response?.data || response || [];
  } catch (err) {
    console.error('Error loading requests:', err);
    if (err.response?.status === 403) {
      requests.value = [];
    }
  }
};

onMounted(async () => {
  try {
    loading.value = true;
    error.value = '';
    await loadRequests();
  } catch (err) {
    console.error('Requests API Error:', err);
    if (err.response?.status !== 403) {
      error.value = err.response?.data?.error || err.message || 'Không thể kết nối đến API';
    }
  } finally {
    loading.value = false;
  }
});
</script>
