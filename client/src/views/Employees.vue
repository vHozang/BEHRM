<template>
  <div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
      <div>
        <h1 class="text-2xl sm:text-3xl font-bold text-foreground">Quản lý Nhân viên</h1>
        <p class="text-sm sm:text-base text-muted-foreground mt-1">Danh sách và phân loại nhân viên</p>
      </div>
      <BaseButton
        @click="openCreateModal"
        class="w-full sm:w-auto"
        data-testid="button-create-employee"
      >
        + Thêm nhân viên
      </BaseButton>
    </div>

    <div v-if="loading" class="space-y-4">
      <BaseCard><BaseSkeleton type="cards" :rows="4" /></BaseCard>
      <BaseCard><BaseSkeleton type="table" :rows="8" :cols="6" /></BaseCard>
    </div>

    <div v-else-if="error" class="bg-destructive/10 border border-destructive/20 rounded-lg p-4">
      <p class="text-destructive font-medium">Lỗi kết nối API:</p>
      <p class="text-destructive/80 text-sm mt-1">{{ error }}</p>
    </div>

    <template v-else>
      <BaseCard>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
          <BaseInput
            v-model="filters.search"
            placeholder="Tìm kiếm theo tên, mã NV..."
            data-testid="input-search-employee"
          />
          <BaseSelect
            v-model="filters.department"
            :options="[{ label: 'Tất cả phòng ban', value: '' }, ...departmentFilterOptions]"
            data-testid="select-department-filter"
          />
          <BaseSelect
            v-model="filters.status"
            :options="statusFilterOptions"
            data-testid="select-status-filter"
          />
          <BaseButton
            variant="outline"
            @click="applyFilters"
            data-testid="button-apply-filters"
          >
            Áp dụng
          </BaseButton>
        </div>
      </BaseCard>

      <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4 mb-4">
        <BaseCard class="p-4">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-primary/10 flex items-center justify-center">
              <IconUser class="w-5 h-5 text-primary" />
            </div>
            <div>
              <p class="text-2xl font-bold">{{ totalEmployees }}</p>
              <p class="text-sm text-muted-foreground">Tổng nhân viên</p>
            </div>
          </div>
        </BaseCard>
        <BaseCard class="p-4">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-green-100 flex items-center justify-center">
              <IconUser class="w-5 h-5 text-green-600" />
            </div>
            <div>
              <p class="text-2xl font-bold text-green-600">{{ activeEmployees }}</p>
              <p class="text-sm text-muted-foreground">Đang làm việc</p>
            </div>
          </div>
        </BaseCard>
        <BaseCard class="p-4">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-yellow-100 flex items-center justify-center">
              <IconUser class="w-5 h-5 text-yellow-600" />
            </div>
            <div>
              <p class="text-2xl font-bold text-yellow-600">{{ probationEmployees }}</p>
              <p class="text-sm text-muted-foreground">Thử việc</p>
            </div>
          </div>
        </BaseCard>
        <BaseCard class="p-4">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-gray-100 flex items-center justify-center">
              <IconUser class="w-5 h-5 text-gray-600" />
            </div>
            <div>
              <p class="text-2xl font-bold text-gray-600">{{ inactiveEmployees }}</p>
              <p class="text-sm text-muted-foreground">Nghỉ việc</p>
            </div>
          </div>
        </BaseCard>
      </div>

      <BaseCard>
        <div v-if="filteredEmployees.length === 0" class="text-center py-8 text-muted-foreground">
          Chưa có nhân viên nào
        </div>
        <BaseTable
          v-else
          :columns="columns"
          :data="filteredEmployees"
          data-testid="table-employees"
        >
          <template #cell-full_name="{ item }">
            <div class="flex items-center gap-3">
              <div class="w-8 h-8 rounded-full bg-primary text-primary-foreground flex items-center justify-center font-semibold text-sm">
                {{ getInitials(item.full_name) }}
              </div>
              <div>
                <p class="font-medium">{{ item.full_name }}</p>
                <p class="text-xs text-muted-foreground">{{ item.employee_code }}</p>
              </div>
            </div>
          </template>

          <template #cell-email="{ item }">
            <span class="text-sm">{{ item.personal_email || item.email || '-' }}</span>
          </template>

          <template #cell-department="{ item }">
            <span class="text-sm">{{ item.department || item.department_name || '-' }}</span>
          </template>

          <template #cell-job_title="{ item }">
            <span class="text-sm">{{ item.job_title || item.job_title_name || '-' }}</span>
          </template>

          <template #cell-status="{ item }">
            <BaseBadge
              :variant="getStatusVariant(item.employment_status)"
              data-testid="badge-employee-status"
            >
              {{ getStatusLabel(item.employment_status) }}
            </BaseBadge>
          </template>

          <template #actions="{ item }">
            <div class="flex items-center gap-2">
              <button
                @click="viewEmployee(item)"
                class="p-1 rounded hover:bg-muted"
                title="Xem chi tiết"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                </svg>
              </button>
              <button
                @click="openEditModal(item)"
                class="p-1 rounded hover:bg-muted"
                title="Chỉnh sửa"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                </svg>
              </button>
              <button
                @click="openStatusModal(item)"
                class="p-1 rounded hover:bg-muted"
                :class="item.employment_status === 'active' ? 'text-yellow-600' : 'text-green-600'"
                :title="item.employment_status === 'active' ? 'Đổi sang nghỉ việc' : 'Đổi sang đang làm việc'"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                </svg>
              </button>
            </div>
          </template>
        </BaseTable>
      </BaseCard>
    </template>

    <BaseModal
      v-model="showModal"
      :title="isEditing ? 'Chỉnh sửa nhân viên' : 'Thêm nhân viên mới'"
      size="lg"
      data-testid="modal-employee"
    >
      <!-- Stepper Header -->
      <div class="mb-8 mt-2 flex items-center justify-between relative px-4">
        <div class="absolute left-10 right-10 top-1/2 -translate-y-1/2 h-1 bg-border z-0 rounded-full"></div>
        <div class="absolute left-10 top-1/2 -translate-y-1/2 h-1 bg-primary z-0 transition-all duration-300 rounded-full" :style="{ width: ((currentStep - 1) / 2) * 85 + '%' }"></div>
        
        <div v-for="step in 3" :key="step" class="relative z-10 flex flex-col items-center">
          <div 
            class="w-10 h-10 rounded-full flex items-center justify-center font-bold text-sm transition-colors duration-300 border-4"
            :class="currentStep >= step ? 'bg-primary text-primary-foreground border-primary' : 'bg-background text-muted-foreground border-border'"
          >
            {{ step }}
          </div>
          <span class="text-xs font-semibold mt-2 absolute -bottom-5 w-24 text-center" :class="currentStep >= step ? 'text-primary' : 'text-muted-foreground'">
            {{ step === 1 ? 'Cá nhân' : (step === 2 ? 'Công việc' : 'Tài khoản') }}
          </span>
        </div>
      </div>

      <div class="mt-6">
        <!-- Step 1: Cá nhân -->
        <div v-show="currentStep === 1" class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <BaseInput v-model="form.employee_code" label="Mã nhân viên" required disabled />
          <BaseInput v-model="form.full_name" label="Họ và tên" required />
          <BaseInput v-model="form.work_email" label="Email công ty" type="email" />
          <BaseInput v-model="form.personal_email" label="Email cá nhân" type="email" />
          <BaseInput v-model="form.phone" label="Số điện thoại" />
          <BaseInput v-model="form.date_of_birth" label="Ngày sinh" type="date" />
          <BaseSelect v-model="form.gender" label="Giới tính" :options="genderOptions" />
          <div class="md:col-span-2">
            <BaseInput v-model="form.address" label="Địa chỉ" />
          </div>
        </div>

        <!-- Step 2: Công việc -->
        <div v-show="currentStep === 2" class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <BaseSelect v-model="form.department_id" label="Phòng ban" :options="departmentOptions" />
          <BaseSelect v-model="form.job_title_id" label="Chức danh" :options="jobTitleOptions" />
          <BaseInput v-model="form.hire_date" label="Ngày vào làm" type="date" />
          <BaseSelect v-model="form.employment_status" label="Trạng thái làm việc" :options="employmentStatusOptions" />
          <BaseSelect v-model="form.employment_type" label="Loại hình làm việc" :options="employmentTypeOptions" />
        </div>

        <!-- Step 3: Tài khoản ngân hàng -->
        <div v-show="currentStep === 3" class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <BaseInput v-model="form.bank_name" label="Ngân hàng" />
          <BaseInput v-model="form.bank_account" label="Số tài khoản" />
        </div>
      </div>

      <div v-if="formError" class="mt-4 p-3 bg-destructive/10 border border-destructive/20 rounded-lg">
        <p class="text-destructive text-sm">{{ formError }}</p>
      </div>

      <template #footer>
        <div class="flex flex-col-reverse sm:flex-row justify-between w-full gap-3 sm:gap-0">
          <BaseButton variant="outline" @click="closeModal" :disabled="saving" class="w-full sm:w-auto">Hủy bỏ</BaseButton>
          <div class="flex flex-col sm:flex-row gap-2 w-full sm:w-auto">
            <BaseButton variant="outline" @click="currentStep--" v-if="currentStep > 1" :disabled="saving" class="w-full sm:w-auto">Quay lại</BaseButton>
            <BaseButton @click="handleNextStep" v-if="currentStep < 3" class="w-full sm:w-auto">Tiếp tục</BaseButton>
            <BaseButton @click="handleSubmit" v-if="currentStep === 3" :disabled="saving" class="w-full sm:w-auto">
              {{ saving ? 'Đang lưu...' : (isEditing ? 'Cập nhật' : 'Tạo mới') }}
            </BaseButton>
          </div>
        </div>
      </template>
    </BaseModal>

    <BaseModal
      v-model="showStatusModal"
      title="Thay đổi trạng thái làm việc"
      size="sm"
    >
      <div class="space-y-4">
        <p class="text-muted-foreground">
          Nhân viên: <strong>{{ statusTarget?.full_name }}</strong>
        </p>
        <p class="text-muted-foreground">
          Trạng thái hiện tại: 
          <BaseBadge :variant="getStatusVariant(statusTarget?.employment_status)">
            {{ getStatusLabel(statusTarget?.employment_status) }}
          </BaseBadge>
        </p>
        <BaseSelect 
          v-model="newStatus" 
          label="Trạng thái mới" 
          :options="employmentStatusOptions" 
        />
        <BaseInput 
          v-if="newStatus === 'inactive' || newStatus === 'resigned' || newStatus === 'terminated'"
          v-model="terminationDate" 
          label="Ngày nghỉ việc" 
          type="date" 
        />
        <BaseInput 
          v-if="newStatus === 'inactive' || newStatus === 'resigned' || newStatus === 'terminated'"
          v-model="terminationReason" 
          label="Lý do nghỉ việc" 
        />
      </div>

      <template #footer>
        <BaseButton variant="outline" @click="showStatusModal = false" :disabled="updatingStatus">Hủy</BaseButton>
        <BaseButton @click="handleStatusChange" :disabled="updatingStatus">
          {{ updatingStatus ? 'Đang cập nhật...' : 'Cập nhật trạng thái' }}
        </BaseButton>
      </template>
    </BaseModal>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import BaseCard from '../components/BaseCard.vue';
import BaseButton from '../components/BaseButton.vue';
import BaseSkeleton from '../components/BaseSkeleton.vue';
import BaseInput from '../components/BaseInput.vue';
import BaseSelect from '../components/BaseSelect.vue';
import BaseBadge from '../components/BaseBadge.vue';
import BaseTable from '../components/BaseTable.vue';
import BaseModal from '../components/BaseModal.vue';
import IconUser from '../components/IconUser.vue';
import { employeeService } from '../services/employeeService';
import { departmentService } from '../services/departmentService';
import { jobTitleService } from '../services/jobTitleService';
import { useNotificationStore } from '../stores/notificationStore';

const router = useRouter();
const notificationStore = useNotificationStore();

const loading = ref(true);
const error = ref('');
const saving = ref(false);
const updatingStatus = ref(false);
const formError = ref('');

const showModal = ref(false);
const showStatusModal = ref(false);
const isEditing = ref(false);
const editingId = ref(null);
const statusTarget = ref(null);
const newStatus = ref('');
const terminationDate = ref('');
const terminationReason = ref('');
const currentStep = ref(1);

const handleNextStep = () => {
  if (currentStep.value === 1 && !form.value.full_name?.trim()) {
    formError.value = 'Vui lòng nhập họ và tên';
    return;
  }
  formError.value = '';
  currentStep.value++;
};

const employees = ref([]);
const departmentOptions = ref([]);
const jobTitleOptions = ref([]);

const filters = ref({
  search: '',
  department: '',
  status: ''
});

const form = ref({
  employee_code: '',
  full_name: '',
  work_email: '',
  personal_email: '',
  phone: '',
  date_of_birth: '',
  gender: '',
  address: '',
  department_id: '',
  job_title_id: '',
  hire_date: '',
  employment_status: 'active',
  employment_type: 'full_time',
  bank_name: '',
  bank_account: ''
});

const columns = [
  { key: 'full_name', label: 'Nhân viên' },
  { key: 'email', label: 'Email' },
  { key: 'department', label: 'Phòng ban' },
  { key: 'job_title', label: 'Chức danh' },
  { key: 'status', label: 'Trạng thái' },
];

const statusFilterOptions = [
  { label: 'Tất cả trạng thái', value: '' },
  { label: 'Đang làm việc', value: 'active' },
  { label: 'Thử việc', value: 'probation' },
  { label: 'Nghỉ việc', value: 'inactive' },
  { label: 'Đã nghỉ', value: 'resigned' },
];

const employmentStatusOptions = [
  { label: 'Đang làm việc', value: 'active' },
  { label: 'Thử việc', value: 'probation' },
  { label: 'Nghỉ việc', value: 'inactive' },
  { label: 'Đã nghỉ', value: 'resigned' },
  { label: 'Chấm dứt HĐ', value: 'terminated' },
];

const employmentTypeOptions = [
  { label: 'Toàn thời gian', value: 'full_time' },
  { label: 'Bán thời gian', value: 'part_time' },
  { label: 'Hợp đồng', value: 'contract' },
  { label: 'Thực tập', value: 'intern' },
];

const genderOptions = [
  { label: 'Nam', value: 'M' },
  { label: 'Nữ', value: 'F' },
  { label: 'Khác', value: 'O' },
];

const normalizeEmploymentType = (value) => {
  const mapping = {
    'fulltime': 'full_time',
    'full-time': 'full_time',
    'Full-time': 'full_time',
    'parttime': 'part_time',
    'part-time': 'part_time',
    'Part-time': 'part_time',
  };
  return mapping[value] || value || 'full_time';
};

const departmentFilterOptions = computed(() => [
  ...departmentOptions.value
]);

const totalEmployees = computed(() => employees.value.length);
const activeEmployees = computed(() => 
  employees.value.filter(e => e.employment_status === 'active' || e.is_active === true).length
);
const probationEmployees = computed(() => 
  employees.value.filter(e => e.employment_status === 'probation').length
);
const inactiveEmployees = computed(() => 
  employees.value.filter(e => 
    e.employment_status === 'inactive' || 
    e.employment_status === 'resigned' || 
    e.employment_status === 'terminated' ||
    e.is_active === false
  ).length
);

const filteredEmployees = computed(() => {
  let result = [...employees.value];
  
  if (filters.value.search) {
    const search = filters.value.search.toLowerCase();
    result = result.filter(emp => 
      emp.full_name?.toLowerCase().includes(search) ||
      emp.employee_code?.toLowerCase().includes(search) ||
      emp.email?.toLowerCase().includes(search)
    );
  }
  
  if (filters.value.department) {
    result = result.filter(emp => String(emp.department_id) === filters.value.department);
  }
  
  if (filters.value.status) {
    result = result.filter(emp => emp.employment_status === filters.value.status);
  }
  
  return result;
});

const getInitials = (name) => {
  if (!name) return '';
  return name
    .split(' ')
    .map(word => word[0])
    .join('')
    .toUpperCase()
    .slice(0, 2);
};

const getStatusVariant = (status) => {
  if (status === 'active' || status === true) return 'success';
  if (status === 'probation' || status === 'on_leave') return 'warning';
  if (status === 'terminated' || status === 'resigned') return 'destructive';
  if (status === 'inactive') return 'default';
  return 'default';
};

const getStatusLabel = (status) => {
  const labels = {
    'active': 'Đang làm việc',
    'probation': 'Thử việc',
    'on_leave': 'Đang nghỉ phép',
    'inactive': 'Nghỉ việc',
    'terminated': 'Đã nghỉ việc',
    'resigned': 'Đã nghỉ việc'
  };
  return labels[status] || (status ? 'Đang làm việc' : 'Nghỉ việc');
};

const resetForm = () => {
  form.value = {
    employee_code: '',
    full_name: '',
    work_email: '',
    personal_email: '',
    phone: '',
    date_of_birth: '',
    gender: '',
    address: '',
    department_id: '',
    job_title_id: '',
    hire_date: '',
    employment_status: 'active',
    employment_type: 'full_time',
    bank_name: '',
    bank_account: ''
  };
  formError.value = '';
};

const generateEmployeeCode = () => {
  const existingCodes = employees.value
    .map(e => e.employee_code || e.code || '')
    .filter(code => /^EMP\d+$/i.test(code))
    .map(code => parseInt(code.replace(/^EMP/i, ''), 10));
  
  const maxNum = existingCodes.length > 0 ? Math.max(...existingCodes) : 0;
  const nextNum = maxNum + 1;
  return `EMP${String(nextNum).padStart(4, '0')}`;
};

const openCreateModal = () => {
  resetForm();
  currentStep.value = 1;
  isEditing.value = false;
  editingId.value = null;
  form.value.employee_code = generateEmployeeCode();
  showModal.value = true;
};

const openEditModal = (employee) => {
  resetForm();
  currentStep.value = 1;
  isEditing.value = true;
  editingId.value = employee.id;
  
  // Try to find department_id by matching name if ID not provided
  let deptId = employee.department_id ? String(employee.department_id) : '';
  if (!deptId && (employee.department || employee.department_name)) {
    const deptName = employee.department || employee.department_name;
    const matchedDept = departmentOptions.value.find(d => d.label === deptName);
    if (matchedDept) deptId = matchedDept.value;
  }
  
  // Try to find job_title_id by matching name if ID not provided
  let jobId = employee.job_title_id ? String(employee.job_title_id) : '';
  if (!jobId && (employee.job_title || employee.job_title_name)) {
    const jobName = employee.job_title || employee.job_title_name;
    const matchedJob = jobTitleOptions.value.find(j => j.label === jobName);
    if (matchedJob) jobId = matchedJob.value;
  }
  
  form.value = {
    employee_code: employee.employee_code || employee.code || '',
    full_name: employee.full_name || '',
    work_email: employee.work_email || employee.email || '',
    personal_email: employee.personal_email || '',
    phone: employee.phone || employee.personal_phone || '',
    date_of_birth: employee.date_of_birth || employee.dob || '',
    gender: employee.gender || '',
    address: employee.address || '',
    department_id: deptId,
    job_title_id: jobId,
    hire_date: employee.hire_date || employee.start_date || '',
    employment_status: employee.employment_status || 'active',
    employment_type: normalizeEmploymentType(employee.employment_type),
    bank_name: employee.bank_name || '',
    bank_account: employee.bank_account || ''
  };
  
  showModal.value = true;
};

const openStatusModal = (employee) => {
  // Find the current employee from the list to ensure we have the latest data
  const currentEmployee = employees.value.find(e => e.id === employee.id) || employee;
  statusTarget.value = { ...currentEmployee };
  newStatus.value = currentEmployee.employment_status || 'active';
  terminationDate.value = '';
  terminationReason.value = '';
  showStatusModal.value = true;
};

const closeModal = () => {
  showModal.value = false;
  resetForm();
  currentStep.value = 1;
};

const handleSubmit = async () => {
  if (!form.value.full_name?.trim()) {
    formError.value = 'Vui lòng nhập họ và tên';
    return;
  }
  
  if (!form.value.employee_code?.trim()) {
    formError.value = 'Vui lòng nhập mã nhân viên';
    return;
  }

  try {
    saving.value = true;
    formError.value = '';
    
    const deptIdParsed = form.value.department_id ? parseInt(form.value.department_id, 10) : null;
    const jobIdParsed = form.value.job_title_id ? parseInt(form.value.job_title_id, 10) : null;
    
    const payload = {
      code: form.value.employee_code,
      full_name: form.value.full_name,
      gender: form.value.gender || null,
      dob: form.value.date_of_birth || null,
      personal_email: form.value.personal_email || form.value.work_email || null,
      personal_phone: form.value.phone || null,
      address: form.value.address || null,
      bank_name: form.value.bank_name || null,
      bank_account: form.value.bank_account || null,
      employment: {
        department_id: isNaN(deptIdParsed) ? null : deptIdParsed,
        job_title_id: isNaN(jobIdParsed) ? null : jobIdParsed,
        start_date: form.value.hire_date || new Date().toISOString().split('T')[0],
        employment_status: form.value.employment_status || 'active',
        employment_type: normalizeEmploymentType(form.value.employment_type)
      }
    };

    console.log('=== EMPLOYEE PAYLOAD ===', JSON.stringify(payload, null, 2));

    if (isEditing.value) {
      await employeeService.update(editingId.value, payload);
      notificationStore.addSuccess(`Đã cập nhật nhân viên "${form.value.full_name}"`);
    } else {
      await employeeService.create(payload);
      notificationStore.addSuccess(`Đã thêm nhân viên "${form.value.full_name}"`);
    }
    
    closeModal();
    await loadEmployees();
  } catch (err) {
    console.error('Error saving employee:', err);
    const errorMsg = err.response?.data?.error || err.response?.data?.message || 'Có lỗi xảy ra khi lưu';
    formError.value = errorMsg;
    notificationStore.addError(`Lỗi: ${errorMsg}`);
  } finally {
    saving.value = false;
  }
};

const handleStatusChange = async () => {
  if (!statusTarget.value || !newStatus.value) return;
  
  try {
    updatingStatus.value = true;
    
    // Find current department_id and job_title_id by name matching
    let deptId = statusTarget.value.department_id;
    if (!deptId && (statusTarget.value.department || statusTarget.value.department_name)) {
      const deptName = statusTarget.value.department || statusTarget.value.department_name;
      const matchedDept = departmentOptions.value.find(d => d.label === deptName);
      if (matchedDept) deptId = parseInt(matchedDept.value);
    }
    
    let jobId = statusTarget.value.job_title_id;
    if (!jobId && (statusTarget.value.job_title || statusTarget.value.job_title_name)) {
      const jobName = statusTarget.value.job_title || statusTarget.value.job_title_name;
      const matchedJob = jobTitleOptions.value.find(j => j.label === jobName);
      if (matchedJob) jobId = parseInt(matchedJob.value);
    }
    
    // Always use today's date for new employment history so it becomes the latest record
    const employment = {
      employment_status: newStatus.value,
      department_id: deptId || null,
      job_title_id: jobId || null,
      start_date: new Date().toISOString().split('T')[0]
    };
    
    if (terminationDate.value) {
      employment.end_date = terminationDate.value;
    }
    
    console.log('=== STATUS CHANGE PAYLOAD ===', JSON.stringify({ employment }, null, 2));
    await employeeService.update(statusTarget.value.id, { employment });
    
    // Update local state immediately for reactivity
    const targetId = statusTarget.value.id;
    const updatedStatus = newStatus.value;
    const index = employees.value.findIndex(e => e.id === targetId);
    if (index !== -1) {
      // Create a new array to force Vue reactivity
      const updatedEmployees = [...employees.value];
      updatedEmployees[index] = { 
        ...updatedEmployees[index], 
        employment_status: updatedStatus 
      };
      employees.value = updatedEmployees;
    }
    
    showStatusModal.value = false;
    statusTarget.value = null;
    
    // Reload from server to ensure consistency
    await loadEmployees();
  } catch (err) {
    console.error('Error updating status:', err);
    alert(err.response?.data?.error || 'Có lỗi xảy ra khi cập nhật trạng thái');
  } finally {
    updatingStatus.value = false;
  }
};

const viewEmployee = (employee) => {
  router.push(`/employees/${employee.id}`);
};

const applyFilters = () => {
};

const loadEmployees = async () => {
  try {
    const response = await employeeService.getAll();
    let emps = response?.data || response || [];
    if (!Array.isArray(emps)) emps = emps.items || emps.data || [];
    if (!Array.isArray(emps)) emps = [];
    
    // Convert backend data format to match frontend component expectations
    employees.value = emps.map(emp => ({ ...emp }));
  } catch (err) {
    console.error('Error loading employees:', err);
  }
};

onMounted(async () => {
  try {
    loading.value = true;
    error.value = '';
    
    const [employeesRes, departmentsRes, jobTitlesRes] = await Promise.all([
      employeeService.getAll(),
      departmentService.getAll(),
      jobTitleService.getAll()
    ]);
    
    employees.value = employeesRes?.data || employeesRes || [];
    
    const depts = departmentsRes?.data || departmentsRes || [];
    departmentOptions.value = depts.map(d => ({
      label: d.name,
      value: String(d.id)
    }));
    
    const titles = jobTitlesRes?.data || jobTitlesRes || [];
    jobTitleOptions.value = titles.map(t => ({
      label: t.name,
      value: String(t.id)
    }));
    
  } catch (err) {
    console.error('Employees API Error:', err);
    error.value = err.response?.data?.error || err.message || 'Không thể kết nối đến API';
  } finally {
    loading.value = false;
  }
});
</script>
