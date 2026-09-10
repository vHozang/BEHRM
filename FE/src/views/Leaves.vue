<template>
  <div class="space-y-6">
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-6">
      <div>
        <h1 class="text-xl sm:text-2xl font-bold">{{ pageTitle }}</h1>
        <p class="text-muted-foreground mt-1">{{ pageSubtitle }}</p>
      </div>
      <div class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row">
        <BaseButton v-if="activeSection === 'requests' && orgLens && canRunAccrual" variant="outline" data-testid="button-run-leave-accrual" @click="openAccrualModal">Đối soát phép năm</BaseButton>
        <BaseButton v-if="activeSection === 'requests'" data-testid="button-create-leave" @click="openCreateModal">+ Tạo đơn xin nghỉ</BaseButton>
      </div>
    </div>

    <div class="flex gap-2 overflow-x-auto rounded-xl border border-border bg-card p-2">
      <button v-for="tab in sectionTabs" :key="tab.value" class="whitespace-nowrap rounded-lg px-3 py-2 text-sm font-semibold" :class="activeSection === tab.value ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-muted'" @click="activeSection = tab.value">{{ tab.label }}</button>
    </div>

    <template v-if="activeSection === 'requests'">

    <!-- Dual-lens tabs (admins only): toàn công ty vs của tôi -->
    <div v-if="isAdmin" class="flex gap-1 border-b border-border -mt-2">
      <button
        @click="viewMode = 'org'"
        :class="['px-4 py-2 text-sm font-medium border-b-2 transition-colors', viewMode === 'org' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground']"
        data-testid="tab-leave-org"
      >
        Toàn công ty
      </button>
      <button
        @click="viewMode = 'mine'"
        :class="['px-4 py-2 text-sm font-medium border-b-2 transition-colors', viewMode === 'mine' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground']"
        data-testid="tab-leave-mine"
      >
        Của tôi
      </button>
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
            <p class="text-sm text-muted-foreground">Tổng đơn</p>
            <p class="text-3xl font-bold text-blue-600 dark:text-blue-400 mt-2">{{ displayRequests.length }}</p>
          </div>
        </BaseCard>
      </div>

      <!-- Số dư phép (lens "Của tôi" / nhân viên) -->
      <BaseCard v-if="!orgLens && balances.length" :title="`Số dư phép ${new Date().getFullYear()}`">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
          <div
            v-for="b in currentYearBalances"
            :key="b.leave_type_id"
            class="rounded-lg border border-border p-3"
          >
            <p class="text-sm text-muted-foreground truncate" :title="b.leave_type_name">{{ b.leave_type_name }}</p>
            <p class="text-2xl font-bold text-foreground mt-1">{{ formatNum(b.remaining) }}</p>
            <p class="text-xs text-muted-foreground">còn lại / {{ formatNum(b.entitlement) }} ngày · đã dùng {{ formatNum(b.used) }}</p>
          </div>
        </div>
      </BaseCard>

      <BaseCard>
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
          <BaseSelect
            v-model="filters.status"
            label="Trạng thái"
            :options="statusOptions"
            data-testid="select-status"
          />
          <BaseSelect
            v-model="filters.leaveType"
            label="Loại nghỉ"
            :options="leaveTypeOptions"
            data-testid="select-leave-type"
          />
          <BaseInput
            v-model="filters.startDate"
            type="date"
            label="Từ ngày"
            data-testid="input-start-date"
          />
          <div class="flex items-end">
            <BaseButton
              variant="outline"
              @click="applyFilters"
              data-testid="button-apply-filters"
            >
              Áp dụng
            </BaseButton>
          </div>
        </div>
      </BaseCard>

      <BaseCard :title="orgLens ? 'Danh sách yêu cầu' : 'Đơn nghỉ phép của tôi'">
        <div v-if="filteredRequests.length === 0" class="text-center py-8 text-muted-foreground">
          Chưa có yêu cầu nghỉ phép nào
        </div>
        <BaseTable
          v-else
          :columns="displayColumns"
          :data="filteredRequests"
          data-testid="table-leaves"
        >
          <template v-if="orgLens" #cell-employee="{ item }">
            <div class="flex items-center gap-2">
              <div class="w-8 h-8 rounded-full bg-primary text-primary-foreground flex items-center justify-center text-sm font-semibold">
                {{ getInitials(item.employee?.full_name || '') }}
              </div>
              <span class="font-medium">{{ item.employee?.full_name || `NV #${item.employee_id}` }}</span>
            </div>
          </template>

          <template #cell-leave_type="{ item }">
            <span class="text-sm">{{ item.leave_type || getLeaveTypeName(item.leave_type_id) }}</span>
          </template>

          <template #cell-dates="{ item }">
            <div class="text-sm">
              <p>{{ formatDate(item.start_date) }} - {{ formatDate(item.end_date) }}</p>
              <p class="text-xs text-muted-foreground">{{ item.total_days || item.days_count || 0 }} ngày</p>
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
              <!-- can_approve do backend tính theo CẤP duyệt hiện tại (đa cấp: Quản lý→HR…). -->
              <template v-if="item.can_approve">
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
                v-if="canEdit(item)"
                @click="openEditModal(item)"
                class="p-1.5 rounded hover:bg-blue-100 dark:hover:bg-blue-900 text-blue-600 dark:text-blue-400"
                title="Sửa đơn"
                :disabled="processing"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                </svg>
              </button>
              <button
                v-if="canCancel(item)"
                @click="cancelRequest(item)"
                class="p-1.5 rounded hover:bg-orange-100 dark:hover:bg-orange-900 text-orange-600 dark:text-orange-400"
                title="Hủy đơn"
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
      :title="editingId ? 'Sửa đơn xin nghỉ' : 'Tạo đơn xin nghỉ'"
      data-testid="modal-create-leave"
    >
      <div class="space-y-4">
        <template v-if="orgLens && !editingId">
          <RemoteEmployeeSelect
            v-model="form.employee_id"
            label="Nhân viên"
            :initial-label="selectedEmployeeLabel"
            @select="rememberEmployee"
          />
        </template>
        <template v-else>
          <div class="p-3 bg-muted rounded-lg">
            <p class="text-sm text-muted-foreground">Nhân viên</p>
            <p class="font-medium">{{ editingEmployeeName || currentUser?.full_name || currentUser?.email || 'Bạn' }}</p>
          </div>
        </template>
        <BaseSelect
          v-model="form.leave_type_id"
          label="Loại nghỉ"
          :options="leaveTypeOptions.filter(o => o.value)"
          required
        />

        <!-- Thông tin định mức theo loại nghỉ (luật VN) -->
        <div v-if="selectedType" class="rounded-lg border border-border bg-muted/40 p-3 text-sm space-y-2">
          <div class="flex flex-wrap items-center gap-2">
            <span
              :class="['px-2 py-0.5 rounded text-xs font-medium', selectedType.paid
                ? 'bg-green-500/15 text-green-700 dark:text-green-400'
                : 'bg-gray-500/15 text-gray-600 dark:text-gray-300']"
            >{{ selectedType.paid ? 'Có lương' : 'Không lương' }}</span>
            <span class="px-2 py-0.5 rounded text-xs font-medium bg-blue-500/15 text-blue-700 dark:text-blue-400">
              {{ accrualLabel(selectedType.accrual) }}
            </span>
            <span v-if="selectedType.statutory_ref" class="text-xs text-muted-foreground">{{ selectedType.statutory_ref }}</span>
          </div>
          <p v-if="selectedType.accrual === 'per_event' && selectedType.max_days_per_event" class="text-muted-foreground">
            Tối đa <span class="font-semibold text-foreground">{{ selectedType.max_days_per_event }}</span> ngày/lần theo luật (không trừ phép năm).
          </p>
          <template v-if="selectedType.requires_balance">
            <p v-if="selectedBalance" class="text-muted-foreground">
              Số dư còn lại:
              <span class="font-semibold text-foreground">{{ formatNum(selectedBalance.remaining) }}</span>
              / {{ formatNum(selectedBalance.entitlement) }} ngày (năm {{ selectedBalance.year }})
            </p>
            <p v-else class="text-amber-600 dark:text-amber-400">
              Chưa có số dư phép loại này cho năm áp dụng — vui lòng chạy cấp phép năm.
            </p>
          </template>
        </div>

        <BaseSelect
          v-model="form.duration_type"
          label="Thời lượng"
          :options="durationOptions"
        />
        <!-- Nửa ngày: chọn buổi -->
        <BaseSelect
          v-if="form.duration_type === 'half_day'"
          v-model="form.half_session"
          label="Buổi"
          :options="[{label:'Buổi sáng', value:'morning'}, {label:'Buổi chiều', value:'afternoon'}]"
        />
        <!-- Theo giờ: số giờ -->
        <BaseInput
          v-if="form.duration_type === 'hourly'"
          v-model="form.hours"
          type="number"
          label="Số giờ nghỉ"
          placeholder="VD: 2"
        />

        <div v-if="form.duration_type === 'full_day'" class="grid grid-cols-2 gap-4">
          <BaseInput v-model="form.start_date" type="date" label="Từ ngày" required />
          <BaseInput v-model="form.end_date" type="date" label="Đến ngày" required />
        </div>
        <div v-else>
          <BaseInput v-model="form.start_date" type="date" label="Ngày nghỉ" required />
          <p class="text-xs text-muted-foreground mt-1">{{ form.duration_type === 'half_day' ? 'Nghỉ nửa ngày = 0.5 công' : 'Nghỉ theo giờ — quy đổi theo giờ chuẩn/ngày' }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-foreground mb-2">Lý do</label>
          <textarea
            v-model="form.reason"
            class="w-full px-4 py-2.5 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
            rows="4"
            placeholder="Nhập lý do xin nghỉ..."
          ></textarea>
        </div>
      </div>

      <div v-if="formError" class="mt-4 p-3 bg-destructive/10 border border-destructive/20 rounded-lg">
        <p class="text-destructive text-sm">{{ formError }}</p>
      </div>

      <template #footer>
        <BaseButton variant="outline" @click="closeCreateModal" :disabled="saving">Hủy</BaseButton>
        <BaseButton @click="handleCreate" :disabled="saving">
          {{ saving ? 'Đang lưu...' : (editingId ? 'Lưu thay đổi' : 'Tạo đơn') }}
        </BaseButton>
      </template>
    </BaseModal>

    <BaseModal v-model="showAccrualModal" title="Đối soát phép năm toàn công ty">
      <div class="space-y-4">
        <div class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
          Thao tác này tính lại hạn mức và số dư phép cho toàn bộ nhân viên trong công ty. Dữ liệu của năm đã chọn sẽ được cập nhật.
        </div>
        <BaseInput v-model.number="accrualYear" type="number" label="Năm đối soát" min="2000" max="2100" />
        <label class="flex items-start gap-2 rounded-lg border border-border p-3 text-sm">
          <input v-model="accrualConfirmed" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-input" />
          <span>Tôi xác nhận đối soát phép năm cho toàn công ty.</span>
        </label>
        <div v-if="accrualResult" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
          <p class="font-semibold">Đối soát năm {{ accrualResult.year }} hoàn tất</p>
          <p class="mt-1">{{ accrualResult.employees || 0 }} nhân viên · {{ accrualResult.balances_upserted || 0 }} số dư được cập nhật · {{ accrualResult.transactions_written || 0 }} giao dịch ghi nhận.</p>
        </div>
      </div>
      <template #footer>
        <BaseButton variant="outline" :disabled="accrualLoading" @click="showAccrualModal = false">Đóng</BaseButton>
        <BaseButton :disabled="!accrualConfirmed || accrualLoading" @click="runAccrual">{{ accrualLoading ? 'Đang đối soát...' : 'Xác nhận đối soát' }}</BaseButton>
      </template>
    </BaseModal>

    <BaseModal
      v-model="showDetailModal"
      title="Chi tiết đơn nghỉ phép"
    >
      <div v-if="selectedRequest" class="space-y-4">
        <div class="grid grid-cols-2 gap-4">
          <div>
            <p class="text-sm text-muted-foreground">Nhân viên</p>
            <p class="font-medium">{{ selectedRequest.full_name || selectedRequest.employee?.full_name || empNameById(selectedRequest.employee_id) || `NV #${selectedRequest.employee_id}` }}</p>
          </div>
          <div>
            <p class="text-sm text-muted-foreground">Loại nghỉ</p>
            <p class="font-medium">{{ selectedRequest.leave_type || getLeaveTypeName(selectedRequest.leave_type_id) }}</p>
            <p v-if="selectedRequest.statutory_ref" class="text-xs text-muted-foreground mt-0.5">
              {{ selectedRequest.paid === false ? 'Không lương' : 'Có lương' }} · {{ selectedRequest.statutory_ref }}
            </p>
          </div>
          <div>
            <p class="text-sm text-muted-foreground">Từ ngày</p>
            <p class="font-medium">{{ formatDate(selectedRequest.start_date) }}</p>
          </div>
          <div>
            <p class="text-sm text-muted-foreground">Đến ngày</p>
            <p class="font-medium">{{ formatDate(selectedRequest.end_date) }}</p>
          </div>
          <div>
            <p class="text-sm text-muted-foreground">Số ngày</p>
            <p class="font-medium">{{ selectedRequest.total_days || selectedRequest.days_count || 0 }} ngày</p>
          </div>
          <div>
            <p class="text-sm text-muted-foreground">Trạng thái</p>
            <BaseBadge :variant="getStatusVariant(selectedRequest.status)">
              {{ getStatusText(selectedRequest.status) }}
            </BaseBadge>
          </div>
        </div>
        <div>
          <p class="text-sm text-muted-foreground">Lý do</p>
          <p class="font-medium mb-4">{{ selectedRequest.reason || 'Không có' }}</p>
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
    </template>

    <LeaveOperationsPanel v-else />
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import BaseCard from '../components/BaseCard.vue';
import BaseButton from '../components/BaseButton.vue';
import BaseInput from '../components/BaseInput.vue';
import BaseSelect from '../components/BaseSelect.vue';
import BaseBadge from '../components/BaseBadge.vue';
import BaseTable from '../components/BaseTable.vue';
import BaseModal from '../components/BaseModal.vue';
import ApprovalTimeline from '../components/ApprovalTimeline.vue';
import RemoteEmployeeSelect from '../components/RemoteEmployeeSelect.vue';
import LeaveOperationsPanel from '../components/LeaveOperationsPanel.vue';
import { buildApprovalSteps, statusVN, statusVariant } from '../utils/approvalSteps';
import { leaveService } from '../services/leaveService';
import { authService } from '../services/authService';

const isAdmin = computed(() => authService.isAdmin());
const currentUser = computed(() => authService.getUser());
const canRunAccrual = computed(() => {
  const access = authService.getAccess();
  return access.full || access.modules.includes('hr');
});
const activeSection = ref('requests');
const sectionTabs = [{ value: 'requests', label: 'Đơn nghỉ' }, { value: 'operations', label: 'Tạm ứng & sổ phép' }];

// Dual-lens: admins toggle between the company-wide view and their own ("My Space").
const viewMode = ref('org'); // 'org' = toàn công ty | 'mine' = của tôi
const myEmployeeId = computed(() => currentUser.value?.employee_id || currentUser.value?.id || null);
// Company-wide (admin approving others) only in the 'org' lens.
const orgLens = computed(() => isAdmin.value && viewMode.value === 'org');
const pageTitle = computed(() => orgLens.value ? 'Quản lý Nghỉ phép' : 'Đơn nghỉ phép của tôi');
const pageSubtitle = computed(() => orgLens.value ? 'Duyệt và quản lý đơn nghỉ phép của nhân viên' : 'Xem và tạo đơn xin nghỉ phép của bạn');

const loading = ref(true);
const error = ref('');
const saving = ref(false);
const processing = ref(false);
const formError = ref('');

const roleLabels = { MANAGER: 'Quản lý trực tiếp', DEPT_HEAD: 'Trưởng phòng', HR: 'Nhân sự (HR)', DIRECTOR: 'Ban giám đốc' };

// Tên NV theo id (để hiện NGƯỜI DUYỆT thật trong timeline).
const empNameById = (id) => {
  if (!id) return null;
  const e = employees.value.find((x) => String(x.id) === String(id));
  return e ? (e.full_name || e.employee_code) : null;
};

const approvalSteps = computed(() => {
  if (!selectedRequest.value) return [];
  const req = selectedRequest.value;
  return buildApprovalSteps(req, {
    creatorName: req.full_name || empNameById(req.employee_id) || 'Nhân viên',
    resolveName: empNameById,
  });
});

const showCreateModal = ref(false);
const editingId = ref(null);
const editingEmployeeId = ref(null);
const editingEmployeeName = ref('');
const showDetailModal = ref(false);
const showAccrualModal = ref(false);
const accrualLoading = ref(false);
const accrualYear = ref(new Date().getFullYear());
const accrualConfirmed = ref(false);
const accrualResult = ref(null);
const selectedRequest = ref(null);

const openAccrualModal = () => {
  accrualYear.value = new Date().getFullYear();
  accrualConfirmed.value = false;
  accrualResult.value = null;
  showAccrualModal.value = true;
};

const runAccrual = async () => {
  if (!accrualConfirmed.value || accrualLoading.value) return;
  const year = Number(accrualYear.value);
  if (!Number.isInteger(year) || year < 2000 || year > 2100) {
    alert('Năm đối soát phải từ 2000 đến 2100');
    return;
  }
  accrualLoading.value = true;
  try {
    accrualResult.value = await leaveService.runAccrual(year);
    await loadRequests();
    if (myEmployeeId.value) await loadBalances(myEmployeeId.value);
  } catch (err) {
    alert(err?.response?.data?.message || 'Không thể đối soát phép năm');
  } finally {
    accrualLoading.value = false;
  }
};

const requests = ref([]);
const leaveTypes = ref([]);
const employees = ref([]);
const balances = ref([]);

// Chuẩn hoá leave_types: API trả leave_type_name + cấu hình trong meta
// (axios đã flatten meta ra top-level). Gộp về { id, code, name, paid, accrual,
// requires_balance, max_days_per_event, statutory_ref }.
const mapType = (t) => {
  let meta = t.meta;
  if (typeof meta === 'string') { try { meta = JSON.parse(meta); } catch { meta = {}; } }
  meta = meta || {};
  const category = String(t.category || '').toUpperCase();
  return {
    id: t.id,
    code: t.leave_type_code || t.code || '',
    name: t.leave_type_name || t.name || `Loại #${t.id}`,
    category,
    paid: t.paid ?? meta.paid ?? (category !== 'UNPAID'),
    accrual: t.accrual ?? meta.accrual ?? 'none',
    requires_balance: t.requires_balance ?? meta.requires_balance ?? false,
    max_days_per_event: t.max_days_per_event ?? meta.max_days_per_event ?? null,
    statutory_ref: t.statutory_ref ?? meta.statutory_ref ?? null,
  };
};

const filters = ref({
  status: '',
  leaveType: '',
  startDate: ''
});

const form = ref({
  employee_id: '',
  leave_type_id: '',
  start_date: '',
  end_date: '',
  reason: '',
  duration_type: 'full_day',
  half_session: 'morning',
  hours: ''
});

const durationOptions = [
  { label: 'Cả ngày', value: 'full_day' },
  { label: 'Nửa ngày', value: 'half_day' },
  { label: 'Theo giờ', value: 'hourly' }
];

const adminColumns = [
  { key: 'employee', label: 'Nhân viên' },
  { key: 'leave_type', label: 'Loại nghỉ' },
  { key: 'dates', label: 'Thời gian' },
  { key: 'reason', label: 'Lý do' },
  { key: 'status', label: 'Trạng thái' },
];

const employeeColumns = [
  { key: 'leave_type', label: 'Loại nghỉ' },
  { key: 'dates', label: 'Thời gian' },
  { key: 'reason', label: 'Lý do' },
  { key: 'status', label: 'Trạng thái' },
];

const displayColumns = computed(() => orgLens.value ? adminColumns : employeeColumns);

const displayRequests = computed(() => {
  // Org lens (admin): all company requests. Otherwise: only my own.
  if (orgLens.value) {
    return requests.value;
  }
  const myId = myEmployeeId.value;
  if (!myId) return isAdmin.value ? [] : requests.value;
  return requests.value.filter(r => String(r.employee_id) === String(myId));
});

const statusOptions = [
  { label: 'Tất cả', value: '' },
  { label: 'Chờ duyệt', value: 'pending' },
  { label: 'Đã duyệt', value: 'approved' },
  { label: 'Từ chối', value: 'rejected' },
];

const defaultLeaveTypes = [
  { id: 1, name: 'Nghỉ phép năm' },
  { id: 2, name: 'Nghỉ ốm' },
  { id: 3, name: 'Nghỉ không lương' },
  { id: 4, name: 'Nghỉ thai sản' },
  { id: 5, name: 'Nghỉ việc riêng' }
];

const leaveTypeOptions = computed(() => {
  const options = [{ label: 'Tất cả', value: '' }];
  const types = leaveTypes.value.length > 0 ? leaveTypes.value : defaultLeaveTypes;
  types.forEach(t => {
    options.push({ label: t.name, value: String(t.id) });
  });
  return options;
});

const selectedEmployeeLabel = computed(() => {
  const employee = employees.value.find(e => String(e.id) === String(form.value.employee_id));
  return employee ? `${employee.employee_code || ''} · ${employee.full_name}`.replace(/^ · /, '') : '';
});

const rememberEmployee = (employee) => {
  if (!employee) return;
  const index = employees.value.findIndex(item => String(item.id) === String(employee.id));
  if (index >= 0) employees.value[index] = employee;
  else employees.value.push(employee);
};

const statusCounts = computed(() => {
  const data = displayRequests.value;
  return {
    pending: data.filter(r => r.status === 'pending').length,
    approved: data.filter(r => r.status === 'approved').length,
    rejected: data.filter(r => r.status === 'rejected').length
  };
});

const filteredRequests = computed(() => {
  let result = [...displayRequests.value];
  
  if (filters.value.status) {
    result = result.filter(r => r.status === filters.value.status);
  }
  
  if (filters.value.leaveType) {
    result = result.filter(r => String(r.leave_type_id) === filters.value.leaveType);
  }
  
  if (filters.value.startDate) {
    result = result.filter(r => r.start_date >= filters.value.startDate);
  }
  
  return result;
});

const getInitials = (name) => {
  if (!name) return '';
  return name.split(' ').map(w => w[0]).join('').toUpperCase().slice(0, 2);
};

const formatDate = (date) => {
  if (!date) return '';
  return new Date(date).toLocaleDateString('vi-VN');
};

const getStatusVariant = (status) => (String(status).toLowerCase() === 'draft' ? 'default' : statusVariant(status));
const getStatusText = (status) => (String(status).toLowerCase() === 'draft' ? 'Nháp' : statusVN(status));

const getLeaveTypeName = (id) => {
  const type = leaveTypes.value.find(t => String(t.id) === String(id));
  return type ? type.name : `Loại #${id}`;
};

const accrualLabel = (accrual) => ({
  annual: 'Phép năm (có hạn mức)',
  per_event: 'Theo sự kiện',
  none: 'Không hạn mức',
}[accrual] || accrual || '');

// Loại nghỉ đang chọn trong form tạo đơn.
const selectedType = computed(() =>
  leaveTypes.value.find(t => String(t.id) === String(form.value.leave_type_id)) || null
);

// Số dư phép năm tương ứng loại đang chọn (theo năm của ngày bắt đầu).
const selectedBalance = computed(() => {
  const t = selectedType.value;
  if (!t) return null;
  const yr = (form.value.start_date ? new Date(form.value.start_date) : new Date()).getFullYear();
  return balances.value.find(b => Number(b.leave_type_id) === Number(t.id) && Number(b.year) === yr) || null;
});

const currentYearBalances = computed(() => {
  const yr = new Date().getFullYear();
  return balances.value.filter(b => Number(b.year) === yr);
});

const loadBalances = async (empId) => {
  if (!empId) { balances.value = []; return; }
  try {
    const res = await leaveService.getBalances(empId);
    balances.value = res?.data || res || [];
  } catch {
    balances.value = [];
  }
};

const formatNum = (n) => {
  const v = Number(n || 0);
  return Number.isInteger(v) ? String(v) : v.toFixed(1);
};

const resetForm = () => {
  form.value = {
    employee_id: orgLens.value ? '' : (myEmployeeId.value ? String(myEmployeeId.value) : ''),
    leave_type_id: '',
    start_date: '',
    end_date: '',
    reason: '',
    duration_type: 'full_day',
    half_session: 'morning',
    hours: ''
  };
  formError.value = '';
};

const openCreateModal = () => {
  editingId.value = null;
  editingEmployeeId.value = null;
  editingEmployeeName.value = '';
  resetForm();
  showCreateModal.value = true;
  const empId = orgLens.value ? form.value.employee_id : myEmployeeId.value;
  if (empId) loadBalances(empId);
};

// Trong lens "Toàn công ty": đổi nhân viên trong form → nạp số dư phép của họ.
watch(() => form.value.employee_id, (id) => {
  if (showCreateModal.value && orgLens.value && id) {
    loadBalances(id);
  }
});

const closeCreateModal = () => {
  showCreateModal.value = false;
  editingId.value = null;
  editingEmployeeId.value = null;
  editingEmployeeName.value = '';
  resetForm();
};

const canEdit = (request) => {
  if (request?.can_edit === true) return true;
  return request?.status === 'pending'
    && String(request.employee_id) === String(myEmployeeId.value);
};

const openEditModal = (request) => {
  if (!canEdit(request)) return;
  editingId.value = request.id;
  editingEmployeeId.value = request.employee_id;
  editingEmployeeName.value = request.employee?.full_name || empNameById(request.employee_id) || currentUser.value?.full_name || '';
  form.value = {
    employee_id: String(request.employee_id || ''),
    leave_type_id: String(request.leave_type_id || ''),
    start_date: String(request.start_date || '').slice(0, 10),
    end_date: String(request.end_date || '').slice(0, 10),
    reason: request.reason || '',
    duration_type: request.duration_type || 'full_day',
    half_session: request.half_session || 'morning',
    hours: request.hours || ''
  };
  formError.value = '';
  showCreateModal.value = true;
  loadBalances(request.employee_id);
};

const viewDetail = async (request) => {
  selectedRequest.value = request;
  showDetailModal.value = true;
  try {
    selectedRequest.value = await leaveService.getRequest(request.id);
  } catch {
    // Keep the lightweight row visible if the detail endpoint is unavailable.
  }
};

const handleCreate = async () => {
  const employeeId = editingId.value
    ? String(editingEmployeeId.value || '')
    : (orgLens.value ? form.value.employee_id : (myEmployeeId.value ? String(myEmployeeId.value) : ''));

  if (!employeeId) {
    formError.value = 'Không thể xác định nhân viên. Vui lòng thử lại.';
    return;
  }
  if (!form.value.leave_type_id) {
    formError.value = 'Vui lòng chọn loại nghỉ';
    return;
  }
  const partial = form.value.duration_type !== 'full_day';
  if (!form.value.start_date) {
    formError.value = 'Vui lòng chọn ngày nghỉ';
    return;
  }
  if (!partial && !form.value.end_date) {
    formError.value = 'Vui lòng chọn ngày bắt đầu và kết thúc';
    return;
  }
  if (form.value.duration_type === 'hourly' && !(parseFloat(form.value.hours) > 0)) {
    formError.value = 'Vui lòng nhập số giờ nghỉ';
    return;
  }

  try {
    saving.value = true;
    formError.value = '';

    // Nghỉ nửa ngày / theo giờ chỉ trong 1 ngày → end = start.
    const endDateVal = partial ? form.value.start_date : form.value.end_date;
    const startDate = new Date(form.value.start_date);
    const endDate = new Date(endDateVal);
    const diffTime = Math.abs(endDate - startDate);
    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;

    const payload = {
      leave_type_id: parseInt(form.value.leave_type_id),
      start_date: form.value.start_date,
      end_date: endDateVal,
      total_days: diffDays,
      days_count: diffDays,
      reason: form.value.reason,
      duration_type: form.value.duration_type,
      half_session: form.value.half_session,
      hours: form.value.duration_type === 'hourly' ? parseFloat(form.value.hours) : undefined,
      status: 'pending'
    };
    if (editingId.value) {
      await leaveService.updateRequest(editingId.value, payload);
    } else {
      await leaveService.createRequest({ employee_id: parseInt(employeeId), ...payload });
    }
    
    closeCreateModal();
    await loadRequests();
  } catch (err) {
    console.error('Error creating leave request:', err);
    // BE trả lỗi field tại data.data.errors.{field}[0] (vd vượt trần ngày/lần).
    const fieldErrors = err.response?.data?.data?.errors;
    const firstField = fieldErrors && typeof fieldErrors === 'object' ? Object.values(fieldErrors)[0] : null;
    formError.value = (Array.isArray(firstField) ? firstField[0] : firstField)
      || err.response?.data?.error || err.response?.data?.message || 'Có lỗi xảy ra';
  } finally {
    saving.value = false;
  }
};

const approveRequest = async (request) => {
  if (processing.value || !request.can_approve) return;

  try {
    processing.value = true;
    const user = JSON.parse(localStorage.getItem('user') || '{}');
    await leaveService.approveRequest(request.id, user.id);
    await loadRequests();
  } catch (err) {
    console.error('Error approving request:', err);
    alert(err.response?.data?.error || 'Có lỗi xảy ra khi duyệt');
  } finally {
    processing.value = false;
  }
};

const rejectRequest = async (request) => {
  if (processing.value || !request.can_approve) return;

  try {
    processing.value = true;
    const user = JSON.parse(localStorage.getItem('user') || '{}');
    await leaveService.rejectRequest(request.id, user.id);
    await loadRequests();
  } catch (err) {
    console.error('Error rejecting request:', err);
    alert(err.response?.data?.error || 'Có lỗi xảy ra khi từ chối');
  } finally {
    processing.value = false;
  }
};

const canCancel = (request) => {
  if (!['pending', 'approved'].includes(request.status)) return false;
  const user = currentUser.value;
  if (!user?.employee_id) return false;
  return String(request.employee_id) === String(user.employee_id);
};

const cancelRequest = async (request) => {
  if (processing.value || !canCancel(request)) return;
  if (!confirm('Bạn có chắc chắn muốn hủy đơn nghỉ phép này?')) return;

  try {
    processing.value = true;
    await leaveService.cancel(request.id);
    await loadRequests();
  } catch (err) {
    console.error('Error cancelling request:', err);
    alert(err.response?.data?.error || err.response?.data?.message || 'Có lỗi xảy ra khi hủy đơn');
  } finally {
    processing.value = false;
  }
};

const applyFilters = () => {
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
    const response = await leaveService.getRequests(params);
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
    
    const promises = [
      leaveService.getRequests(!isAdmin.value && currentUser.value?.employee_id ? { employee_id: currentUser.value.employee_id } : {}).catch((err) => {
        if (err.response?.status === 403) {
          return [];
        }
        throw err;
      }),
      leaveService.getTypes().catch(() => [])
    ];
    
    const results = await Promise.all(promises);
    
    requests.value = results[0]?.data || results[0] || [];
    leaveTypes.value = (results[1]?.data || results[1] || []).map(mapType);

    // Số dư phép của chính mình (cho thẻ tổng quan ở lens "Của tôi").
    if (myEmployeeId.value) {
      await loadBalances(myEmployeeId.value);
    }

  } catch (err) {
    console.error('Leaves API Error:', err);
    if (err.response?.status !== 403) {
      error.value = err.response?.data?.error || err.message || 'Không thể kết nối đến API';
    }
  } finally {
    loading.value = false;
  }
});
</script>
