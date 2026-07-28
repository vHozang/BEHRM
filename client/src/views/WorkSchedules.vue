<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-3xl font-bold text-foreground">{{ isAdmin ? 'Quản lý Lên lịch làm việc' : 'Lịch làm việc của tôi' }}</h1>
        <p class="text-muted-foreground mt-1">{{ isAdmin ? 'Quản lý lịch làm việc của nhân viên' : 'Xem lịch làm việc cá nhân' }}</p>
      </div>
      <div class="flex gap-2">
        <BaseButton v-if="!isAdmin" variant="outline" @click="showShiftChangeModal = true">
          Xin đổi ca
        </BaseButton>
        <BaseButton v-if="isAdmin" @click="showCreateModal = true">+ Lên lịch</BaseButton>
      </div>
    </div>

    <BaseCard>
      <div class="flex flex-wrap gap-3 mb-4 items-end">
        <template v-if="isAdmin">
          <div class="flex-1 min-w-[200px]">
            <BaseSelect
              v-model="filters.employee"
              :options="employeeOptions"
              placeholder="Chọn nhân viên"
            />
          </div>
        </template>
        <div class="flex-1 min-w-[150px]">
          <BaseInput
            v-model="filters.startDate"
            type="date"
            label="Từ ngày"
          />
        </div>
        <div class="flex-1 min-w-[150px]">
          <BaseInput
            v-model="filters.endDate"
            type="date"
            label="Đến ngày"
          />
        </div>
        <BaseButton @click="applyFilters" size="sm" class="px-6 h-[42px]">Tìm kiếm</BaseButton>
      </div>

      <BaseTable
        :columns="displayColumns"
        :data="displaySchedules"
      >
        <template v-if="isAdmin" #cell-employee_id="{ item }">
          <span>{{ getEmployeeName(item.employee_id) }}</span>
        </template>
        <template #cell-work_date="{ value }">
          <span>{{ formatDate(value) }}</span>
        </template>
        <template #cell-shift_id="{ item }">
          <span>{{ getShiftName(item.shift_id) }}</span>
        </template>
        <template #cell-status="{ item }">
          <BaseBadge :variant="getStatusVariant(item.status)">
            {{ getStatusText(item.status) }}
          </BaseBadge>
        </template>
      </BaseTable>
    </BaseCard>

    <!-- Yêu cầu đổi ca của tôi (nhân viên) -->
    <BaseCard v-if="!isAdmin">
      <h3 class="font-semibold mb-3">Yêu cầu đổi ca của tôi</h3>
      <div v-if="mySwaps.length === 0" class="text-sm text-muted-foreground">Chưa có yêu cầu đổi ca nào.</div>
      <div v-else class="space-y-2">
        <div v-for="sw in mySwaps" :key="sw.id" class="flex flex-wrap items-center justify-between gap-3 border border-border rounded-lg p-3">
          <div class="min-w-0 text-sm">
            <p class="font-medium text-foreground">Ngày {{ formatDate(sw.swap_date) }} · đổi với {{ getEmployeeName(sw.target_employee_id) }}</p>
            <p v-if="sw.reason" class="text-xs text-muted-foreground">Lý do: {{ sw.reason }}</p>
          </div>
          <BaseBadge :variant="swapStatusVariant(sw.approval_status)">{{ swapStatusText(sw.approval_status) }}</BaseBadge>
        </div>
      </div>
    </BaseCard>

    <BaseModal v-model="showCreateModal" title="Lên lịch làm việc">
      <div class="space-y-4">
        <BaseSelect
          v-model="form.employee_id"
          :options="employeeOptions"
          label="Nhân viên"
          required
        />
        <BaseInput v-model="form.work_date" type="date" label="Ngày làm" required />
        <BaseSelect
          v-model="form.shift_id"
          :options="shiftOptions"
          label="Ca"
          required
        />
        <BaseButton @click="saveItem" class="w-full">Lưu</BaseButton>
      </div>
    </BaseModal>

    <BaseModal v-model="showShiftChangeModal" title="Xin đổi ca với đồng nghiệp">
      <div class="space-y-4">
        <div class="p-3 bg-muted rounded-lg">
          <p class="text-sm text-muted-foreground">Người yêu cầu</p>
          <p class="font-medium">{{ currentUser?.full_name || currentUser?.email || 'Bạn' }}</p>
        </div>
        <BaseInput
          v-model="shiftChangeForm.swap_date"
          type="date"
          label="Ngày muốn đổi ca"
          required
        />
        <BaseSelect
          v-model="shiftChangeForm.target_employee_id"
          :options="colleagueOptions"
          label="Đổi ca với đồng nghiệp"
          placeholder="Chọn đồng nghiệp"
          required
        />
        <p class="text-xs text-muted-foreground -mt-2">
          Hệ thống sẽ hoán đổi ca của hai người trong đúng ngày đã chọn sau khi quản lý duyệt (ca cố định các ngày khác giữ nguyên).
        </p>
        <div>
          <label class="block text-sm font-medium text-foreground mb-2">Lý do</label>
          <textarea
            v-model="shiftChangeForm.reason"
            class="w-full px-4 py-2.5 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
            rows="3"
            placeholder="Nhập lý do xin đổi ca..."
          ></textarea>
        </div>
        <div v-if="shiftChangeError" class="p-3 bg-destructive/10 border border-destructive/20 rounded-lg">
          <p class="text-destructive text-sm">{{ shiftChangeError }}</p>
        </div>
        <div v-if="shiftChangeSuccess" class="p-3 bg-green-100 dark:bg-green-900/30 border border-green-200 dark:border-green-800 rounded-lg">
          <p class="text-green-600 dark:text-green-400 text-sm">{{ shiftChangeSuccess }}</p>
        </div>
      </div>
      <template #footer>
        <BaseButton variant="outline" @click="showShiftChangeModal = false">Hủy</BaseButton>
        <BaseButton @click="submitShiftChangeRequest" :disabled="shiftChangeLoading">
          {{ shiftChangeLoading ? 'Đang gửi...' : 'Gửi yêu cầu' }}
        </BaseButton>
      </template>
    </BaseModal>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import BaseButton from '../components/BaseButton.vue';
import BaseCard from '../components/BaseCard.vue';
import BaseTable from '../components/BaseTable.vue';
import BaseModal from '../components/BaseModal.vue';
import BaseInput from '../components/BaseInput.vue';
import BaseSelect from '../components/BaseSelect.vue';
import BaseBadge from '../components/BaseBadge.vue';
import { workScheduleService } from '../services/workScheduleService';
import { employeeService } from '../services/employeeService';
import { workShiftService } from '../services/workShiftService';
import { authService } from '../services/authService';

const isAdmin = computed(() => authService.isAdmin());
const currentUser = computed(() => authService.getUser());

const workSchedules = ref([]);
const employeeOptions = ref([]);
const employeeMap = ref({});
const shiftOptions = ref([]);
const shiftMap = ref({});
const showCreateModal = ref(false);
const showShiftChangeModal = ref(false);

const shiftChangeForm = ref({
  swap_date: '',
  target_employee_id: '',
  reason: ''
});
const shiftChangeLoading = ref(false);
const shiftChangeError = ref('');
const shiftChangeSuccess = ref('');

// Đồng nghiệp để chọn đổi ca (trừ chính mình) + danh sách yêu cầu đổi ca của tôi.
const colleagueOptions = ref([]);
const mySwaps = ref([]);

const swapStatusText = (s) => ({
  PENDING: 'Chờ duyệt', 'CHỜ_DUYỆT': 'Chờ duyệt', APPROVED: 'Đã duyệt', REJECTED: 'Từ chối',
  CANCELLED: 'Đã hủy', 'ĐÃ_HỦY': 'Đã hủy',
}[s] || s || 'Chờ duyệt');
const swapStatusVariant = (s) => ({
  PENDING: 'warning', 'CHỜ_DUYỆT': 'warning', APPROVED: 'success', REJECTED: 'destructive',
  CANCELLED: 'default', 'ĐÃ_HỦY': 'default',
}[s] || 'warning');

const filters = ref({
  employee: '',
  startDate: '',
  endDate: ''
});

const form = ref({
  employee_id: '',
  work_date: '',
  shift_id: ''
});

// work_date thực chất là effective_date của PHÂN CA (service map lại) — nhãn phải
// là "Áp dụng từ", để "Ngày làm" NV đọc thành "chỉ làm đúng 1 ngày 1/1/2024".
const adminColumns = [
  { key: 'employee_id', label: 'Nhân viên' },
  { key: 'work_date', label: 'Áp dụng từ' },
  { key: 'shift_id', label: 'Ca' },
  { key: 'status', label: 'Trạng thái' }
];

const employeeColumns = [
  { key: 'work_date', label: 'Áp dụng từ' },
  { key: 'shift_id', label: 'Ca' },
  { key: 'status', label: 'Trạng thái' }
];

const displayColumns = computed(() => isAdmin.value ? adminColumns : employeeColumns);

const displaySchedules = computed(() => {
  if (isAdmin.value) {
    return workSchedules.value;
  }
  const user = currentUser.value;
  if (!user?.employee_id) return workSchedules.value;
  return workSchedules.value.filter(s => String(s.employee_id) === String(user.employee_id));
});

const formatDate = (date) => {
  if (!date) return '-';
  return new Date(date).toLocaleDateString('vi-VN');
};

const getEmployeeName = (id) => {
  return employeeMap.value[id] || `NV #${id}`;
};

const getShiftName = (id) => {
  return shiftMap.value[id] || `Ca #${id}`;
};

const getStatusText = (status) => {
  const statuses = {
    scheduled: 'Đã lên lịch',
    present: 'Có mặt',
    absent: 'Vắng mặt',
    late: 'Muộn',
    half_day: 'Nửa ngày',
    ACTIVE: 'Hiệu lực',
    'HIỆU_LỰC': 'Hiệu lực',
    INACTIVE: 'Hết hiệu lực',
  };
  return statuses[status] || status;
};

const getStatusVariant = (status) => {
  const variants = {
    scheduled: 'default',
    present: 'success',
    absent: 'destructive',
    late: 'warning',
    half_day: 'secondary'
  };
  return variants[status] || 'default';
};

const loadData = async () => {
  try {
    const params = {};
    
    if (filters.value.employee) {
      params.employee_id = filters.value.employee;
    }
    if (filters.value.startDate) {
      params.from = filters.value.startDate;
    }
    if (filters.value.endDate) {
      params.to = filters.value.endDate;
    }
    
    if (!isAdmin.value) {
      const user = currentUser.value;
      if (user?.employee_id) {
        params.employee_id = user.employee_id;
      }
    }
    
    const response = await workScheduleService.getAll(params);
    workSchedules.value = response?.data || response || [];
  } catch (err) {
    console.error('Error loading work schedules:', err);
    if (err.response?.status === 403) {
      workSchedules.value = [];
    }
  }
};

const loadEmployees = async () => {
  try {
    const response = await employeeService.getLookup();
    const employees = response?.data || response || [];
    employeeOptions.value = employees.map((emp) => ({
      value: emp.id,
      label: emp.full_name
    }));
    employees.forEach(emp => {
      employeeMap.value[emp.id] = emp.full_name;
    });
  } catch (err) {
    console.error('Error loading employees:', err);
    employeeOptions.value = [];
  }
};

const defaultShifts = [
  { id: 1, name: 'Ca sáng (6:00 - 14:00)' },
  { id: 2, name: 'Ca chiều (14:00 - 22:00)' },
  { id: 3, name: 'Ca đêm (22:00 - 6:00)' },
  { id: 4, name: 'Ca hành chính (8:00 - 17:00)' }
];

const loadShifts = async () => {
  try {
    const response = await workShiftService.getAll();
    const shifts = response?.data || response || [];
    if (shifts.length > 0) {
      shiftOptions.value = shifts.map((shift) => ({
        value: shift.id,
        label: shift.name
      }));
      shifts.forEach(shift => {
        shiftMap.value[shift.id] = shift.name;
      });
    } else {
      shiftOptions.value = defaultShifts.map(s => ({ value: s.id, label: s.name }));
      defaultShifts.forEach(s => { shiftMap.value[s.id] = s.name; });
    }
  } catch (err) {
    console.error('Error loading shifts:', err);
    shiftOptions.value = defaultShifts.map(s => ({ value: s.id, label: s.name }));
    defaultShifts.forEach(s => { shiftMap.value[s.id] = s.name; });
  }
};

const saveItem = async () => {
  try {
    if (form.value.id) {
      await workScheduleService.update(form.value.id, form.value);
    } else {
      await workScheduleService.create(form.value);
    }
    showCreateModal.value = false;
    form.value = { employee_id: '', work_date: '', shift_id: '' };
    await loadData();
  } catch (err) {
    console.error('Error saving work schedule:', err);
  }
};

const loadColleagues = async () => {
  try {
    const response = await employeeService.getLookup();
    const employees = response?.data || response || [];
    const myId = currentUser.value?.employee_id;
    colleagueOptions.value = employees
      .filter((e) => String(e.id) !== String(myId))
      .map((e) => ({ value: e.id, label: e.full_name }));
    employees.forEach((e) => { employeeMap.value[e.id] = e.full_name; });
  } catch (err) {
    console.error('Error loading colleagues:', err);
  }
};

const loadMySwaps = async () => {
  const myId = currentUser.value?.employee_id;
  if (!myId) return;
  try {
    const res = await workScheduleService.getSwaps({ requester_id: myId });
    mySwaps.value = res?.data || res || [];
  } catch (err) {
    console.error('Error loading my swaps:', err);
    mySwaps.value = [];
  }
};

const submitShiftChangeRequest = async () => {
  shiftChangeError.value = '';
  shiftChangeSuccess.value = '';

  const myId = currentUser.value?.employee_id;
  if (!myId) { shiftChangeError.value = 'Tài khoản chưa liên kết hồ sơ nhân viên'; return; }
  if (!shiftChangeForm.value.swap_date) { shiftChangeError.value = 'Vui lòng chọn ngày muốn đổi ca'; return; }
  if (!shiftChangeForm.value.target_employee_id) { shiftChangeError.value = 'Vui lòng chọn đồng nghiệp muốn đổi ca'; return; }
  if (String(shiftChangeForm.value.target_employee_id) === String(myId)) { shiftChangeError.value = 'Không thể đổi ca với chính mình'; return; }

  try {
    shiftChangeLoading.value = true;
    await workScheduleService.requestSwap({
      requester_id: myId,
      target_employee_id: parseInt(shiftChangeForm.value.target_employee_id),
      swap_date: shiftChangeForm.value.swap_date,
      reason: shiftChangeForm.value.reason || '',
    });
    shiftChangeSuccess.value = 'Yêu cầu đổi ca đã được gửi! Vui lòng chờ quản lý phê duyệt.';
    await loadMySwaps();
    setTimeout(() => {
      shiftChangeForm.value = { swap_date: '', target_employee_id: '', reason: '' };
      shiftChangeSuccess.value = '';
      showShiftChangeModal.value = false;
    }, 1500);
  } catch (err) {
    console.error('Error submitting shift swap request:', err);
    shiftChangeError.value = err.response?.data?.data?.errors
      ? Object.values(err.response.data.data.errors)[0]?.[0]
      : (err.response?.data?.message || 'Có lỗi xảy ra khi gửi yêu cầu');
  } finally {
    shiftChangeLoading.value = false;
  }
};

const applyFilters = async () => {
  await loadData();
};

onMounted(async () => {
  const promises = [loadData(), loadShifts()];
  if (isAdmin.value) {
    promises.push(loadEmployees());
  } else {
    promises.push(loadColleagues(), loadMySwaps());
  }
  await Promise.all(promises);
});
</script>
