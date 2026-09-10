<template>
  <div class="space-y-6">
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
      <div>
        <h1 class="text-2xl font-bold text-foreground">Đơn đổi thông tin nhân sự</h1>
        <p class="text-muted-foreground mt-1">Duyệt các đề nghị thay đổi thông tin cá nhân (CCCD, MST, ngân hàng, BHXH...)</p>
      </div>
      <div class="flex items-center gap-2 shrink-0">
        <BaseSelect v-model="statusFilter" :options="statusOptions" class="w-56 min-w-[14rem]" />
        <BaseButton variant="outline" size="sm" @click="loadData" :disabled="loading" title="Làm mới">
          <svg class="w-4 h-4" :class="{ 'animate-spin': loading }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
          </svg>
        </BaseButton>
      </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
      <BaseCard><div class="text-center"><p class="text-sm text-muted-foreground">Chờ duyệt</p><p class="text-3xl font-bold text-amber-600 mt-2">{{ counts.PENDING }}</p></div></BaseCard>
      <BaseCard><div class="text-center"><p class="text-sm text-muted-foreground">Đã duyệt</p><p class="text-3xl font-bold text-green-600 mt-2">{{ counts.APPROVED }}</p></div></BaseCard>
      <BaseCard><div class="text-center"><p class="text-sm text-muted-foreground">Từ chối</p><p class="text-3xl font-bold text-red-600 mt-2">{{ counts.REJECTED }}</p></div></BaseCard>
      <BaseCard><div class="text-center"><p class="text-sm text-muted-foreground">Tổng đơn</p><p class="text-3xl font-bold text-blue-600 mt-2">{{ requests.length }}</p></div></BaseCard>
    </div>

    <BaseCard title="Danh sách đơn">
      <div v-if="loading" class="text-center py-8 text-muted-foreground">Đang tải...</div>
      <div v-else-if="filtered.length === 0" class="text-center py-8 text-muted-foreground">Không có đơn nào</div>
      <BaseTable v-else :columns="columns" :data="filtered">
        <template #cell-employee="{ item }">
          <span class="text-sm font-medium">{{ item.employee?.full_name || ('NV #' + item.employee_id) }}</span>
        </template>
        <template #cell-changes="{ item }">
          <div class="flex flex-wrap gap-1.5">
            <span v-for="(c, key) in item.changes" :key="key" class="text-xs px-2 py-0.5 rounded-full bg-muted">
              {{ c.label || key }}: <span class="line-through text-muted-foreground">{{ c.old || '∅' }}</span> → <span class="font-medium">{{ c.new }}</span>
            </span>
          </div>
          <p v-if="item.reason" class="text-xs text-muted-foreground mt-1">Lý do: {{ item.reason }}</p>
        </template>
        <template #cell-status="{ value }">
          <BaseBadge :variant="statusVariant(value)">{{ statusText(value) }}</BaseBadge>
        </template>
        <template #actions="{ item }">
          <div class="flex items-center gap-2">
            <button @click="openDetail(item)" class="p-1.5 rounded hover:bg-muted text-muted-foreground" title="Xem chi tiết & tiến trình duyệt">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
              </svg>
            </button>
            <template v-if="item.status === 'PENDING'">
              <button @click="approve(item)" :disabled="processing" class="p-1.5 rounded hover:bg-green-100 text-green-600" title="Duyệt">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
              </button>
              <button @click="openReject(item)" :disabled="processing" class="p-1.5 rounded hover:bg-red-100 text-red-600" title="Từ chối">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
              </button>
            </template>
            <span v-else class="text-xs text-muted-foreground">{{ item.approver?.full_name ? 'bởi ' + item.approver.full_name : '—' }}</span>
          </div>
        </template>
      </BaseTable>
    </BaseCard>

    <!-- Reject modal -->
    <BaseModal v-model="showReject" title="Từ chối đơn" size="sm">
      <div v-if="rejectTarget" class="space-y-3">
        <p class="text-sm text-muted-foreground">Đơn của <span class="font-medium text-foreground">{{ rejectTarget.employee?.full_name }}</span></p>
        <div>
          <label class="block text-sm font-medium mb-1">Lý do từ chối</label>
          <textarea v-model="rejectComment" rows="3" class="w-full px-3 py-2 rounded-lg border border-input bg-background focus:outline-none focus:ring-2 focus:ring-ring"></textarea>
        </div>
      </div>
      <template #footer>
        <BaseButton variant="outline" @click="showReject = false" :disabled="processing">Hủy</BaseButton>
        <BaseButton variant="destructive" @click="confirmReject" :disabled="processing">Từ chối</BaseButton>
      </template>
    </BaseModal>

    <!-- Chi tiết + tiến trình phê duyệt -->
    <BaseModal v-model="showDetail" title="Chi tiết đơn đổi thông tin" size="lg">
      <div v-if="selectedRequest" class="space-y-4">
        <div class="grid grid-cols-2 gap-3 text-sm">
          <div><p class="text-muted-foreground">Người tạo đơn</p><p class="font-medium">{{ selectedRequest.employee?.full_name || ('NV #' + selectedRequest.employee_id) }}</p></div>
          <div v-if="selectedRequest.reason" class="col-span-2"><p class="text-muted-foreground">Lý do</p><p class="font-medium">{{ selectedRequest.reason }}</p></div>
        </div>
        <div class="border-t border-border pt-2">
          <p class="text-sm font-semibold text-foreground mb-1">Tiến trình phê duyệt</p>
          <ApprovalTimeline :steps="approvalSteps" />
        </div>
      </div>
      <template #footer>
        <BaseButton variant="outline" @click="showDetail = false">Đóng</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import BaseCard from '../components/BaseCard.vue';
import BaseButton from '../components/BaseButton.vue';
import BaseBadge from '../components/BaseBadge.vue';
import BaseTable from '../components/BaseTable.vue';
import BaseModal from '../components/BaseModal.vue';
import BaseSelect from '../components/BaseSelect.vue';
import ApprovalTimeline from '../components/ApprovalTimeline.vue';
import { buildApprovalSteps } from '../utils/approvalSteps';
import { profileChangeService } from '../services/profileChangeService';
import { useNotificationStore } from '../stores/notificationStore';

const notify = useNotificationStore();

const loading = ref(true);
const processing = ref(false);
const requests = ref([]);
const statusFilter = ref('');

const showReject = ref(false);
const rejectTarget = ref(null);
const rejectComment = ref('');

// Chi tiết + tiến trình duyệt (người tạo + người duyệt thật từ object lồng).
const showDetail = ref(false);
const selectedRequest = ref(null);
const openDetail = async (item) => {
  selectedRequest.value = item;
  showDetail.value = true;
  try {
    selectedRequest.value = await profileChangeService.get(item.id);
  } catch (err) {
    notify.error(err.response?.data?.message || 'Không tải được chi tiết yêu cầu');
  }
};
const approvalSteps = computed(() => {
  const req = selectedRequest.value;
  if (!req) return [];
  return buildApprovalSteps(req, {
    creatorName: req.employee?.full_name || ('NV #' + req.employee_id),
    resolveName: (id) => (req.approver && String(req.approver_id) === String(id) ? req.approver.full_name : null),
    approverId: req.approver_id,
  });
});

const columns = [
  { key: 'employee', label: 'Nhân viên' },
  { key: 'changes', label: 'Nội dung thay đổi' },
  { key: 'status', label: 'Trạng thái' },
];

const statusOptions = [
  { label: 'Tất cả trạng thái', value: '' },
  { label: 'Chờ duyệt', value: 'PENDING' },
  { label: 'Đã duyệt', value: 'APPROVED' },
  { label: 'Từ chối', value: 'REJECTED' },
  { label: 'Đã hủy', value: 'CANCELLED' },
];

const statusText = (s) => ({ PENDING: 'Chờ duyệt', APPROVED: 'Đã duyệt', REJECTED: 'Từ chối', CANCELLED: 'Đã hủy' }[s] || s);
const statusVariant = (s) => ({ PENDING: 'warning', APPROVED: 'success', REJECTED: 'destructive', CANCELLED: 'default' }[s] || 'default');

const filtered = computed(() => statusFilter.value ? requests.value.filter(r => r.status === statusFilter.value) : requests.value);

const counts = computed(() => ({
  PENDING: requests.value.filter(r => r.status === 'PENDING').length,
  APPROVED: requests.value.filter(r => r.status === 'APPROVED').length,
  REJECTED: requests.value.filter(r => r.status === 'REJECTED').length,
}));

const loadData = async () => {
  loading.value = true;
  try {
    requests.value = await profileChangeService.getAll({ per_page: 100 });
  } catch (e) {
    console.error('Error loading profile change requests:', e);
    requests.value = [];
  } finally {
    loading.value = false;
  }
};

const approve = async (item) => {
  if (processing.value) return;
  processing.value = true;
  try {
    await profileChangeService.approve(item.id);
    notify.addSuccess('Đã duyệt và cập nhật hồ sơ nhân viên');
    await loadData();
  } catch (err) {
    notify.addError(err.response?.data?.data?.errors?.approver_id?.[0] || err.response?.data?.message || 'Không thể duyệt');
  } finally {
    processing.value = false;
  }
};

const openReject = (item) => {
  rejectTarget.value = item;
  rejectComment.value = '';
  showReject.value = true;
};

const confirmReject = async () => {
  if (!rejectTarget.value || processing.value) return;
  processing.value = true;
  try {
    await profileChangeService.reject(rejectTarget.value.id, rejectComment.value);
    notify.addSuccess('Đã từ chối đơn');
    showReject.value = false;
    await loadData();
  } catch (err) {
    notify.addError(err.response?.data?.message || 'Không thể từ chối');
  } finally {
    processing.value = false;
  }
};

onMounted(loadData);
</script>
