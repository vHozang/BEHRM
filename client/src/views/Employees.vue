<template>
  <div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
      <div>
        <h1 class="text-2xl sm:text-3xl font-bold text-foreground">Quản lý Nhân viên</h1>
        <p class="text-sm sm:text-base text-muted-foreground mt-1">Danh sách và phân loại nhân viên</p>
      </div>
      <div class="flex gap-2 w-full sm:w-auto">
        <BaseButton variant="outline" class="flex-1 sm:flex-none" data-testid="button-import-employees" @click="showImportModal = true">
          ⬆ Import Excel/CSV
        </BaseButton>
        <BaseButton
          @click="openCreateModal"
          class="flex-1 sm:flex-none"
          data-testid="button-create-employee"
        >
          + Thêm nhân viên
        </BaseButton>
      </div>
    </div>

    <!-- Import CSV modal: tải mẫu → HR điền bằng Excel → lưu CSV UTF-8 → upload → preview → import -->
    <BaseModal v-model="showImportModal" title="Import nhân viên từ Excel/CSV">
      <div class="space-y-4">
        <div class="grid grid-cols-2 rounded-lg bg-muted p-1">
          <button
            v-for="mode in importModes"
            :key="mode.id"
            type="button"
            class="rounded-md px-3 py-2 text-sm font-medium"
            :class="importMode === mode.id ? 'bg-background shadow-sm' : 'text-muted-foreground'"
            @click="changeImportMode(mode.id)"
          >
            {{ mode.label }}
          </button>
        </div>
        <div class="text-sm text-muted-foreground space-y-1">
          <p>1. Tải file mẫu, mở bằng Excel và điền danh sách nhân viên.</p>
          <p>2. Lưu dạng <b>CSV UTF-8</b> (File → Save As → CSV UTF-8) rồi tải lên đây.</p>
          <p v-if="importMode === 'official'">Chỉ <b>Họ tên</b> là bắt buộc; mã NV/email trùng sẽ tự bỏ qua.</p>
          <p v-else><b>Họ tên và email công ty</b> là bắt buộc. Hệ thống báo lỗi riêng theo từng dòng và vẫn nhập các dòng hợp lệ.</p>
        </div>
        <div class="flex gap-2">
          <BaseButton variant="outline" size="sm" @click="downloadImportTemplate">⬇ Tải file mẫu</BaseButton>
          <label class="inline-flex items-center px-3 py-1.5 rounded-lg border border-input text-sm font-medium cursor-pointer hover:bg-muted">
            Chọn file CSV
            <input type="file" accept=".csv,text/csv" class="hidden" @change="onImportFile" />
          </label>
        </div>

        <div v-if="importRows.length" class="space-y-2">
          <p class="text-sm font-medium">Xem trước {{ Math.min(importRows.length, 5) }}/{{ importRows.length }} dòng:</p>
          <div class="rounded-lg border overflow-x-auto max-h-48">
            <table class="w-full text-xs">
              <thead class="bg-muted/50"><tr><th v-for="h in importHeaderLabels" :key="h" class="px-2 py-1.5 text-left whitespace-nowrap">{{ h }}</th></tr></thead>
              <tbody>
                <tr v-for="(r, i) in importRows.slice(0, 5)" :key="i" class="border-t border-border">
                  <td v-for="k in importKeys" :key="k" class="px-2 py-1 whitespace-nowrap">{{ r[k] }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div v-if="importResult && importMode === 'official'" class="text-sm space-y-1">
          <p class="font-medium" :class="importResult.skipped ? 'text-amber-600' : 'text-green-600'">
            ✔ Đã tạo {{ importResult.created }} nhân viên<span v-if="importResult.skipped"> · bỏ qua {{ importResult.skipped }} dòng</span>
          </p>
          <ul v-if="importResult.skipped" class="text-xs text-muted-foreground max-h-28 overflow-y-auto list-disc pl-4">
            <li v-for="r in importResult.results.filter(x => x.status === 'skipped')" :key="r.line">Dòng {{ r.line }}: {{ r.reason }}</li>
          </ul>
        </div>
        <div v-else-if="importResult" class="space-y-2 text-sm">
          <p class="font-medium text-green-600">Đã tạo {{ importResult.imported || 0 }} nhân viên thử việc.</p>
          <div v-if="importResult.errors?.length" class="rounded-lg border border-amber-200 bg-amber-50 p-3">
            <p class="font-medium text-amber-800">{{ importResult.errors.length }} dòng có lỗi:</p>
            <ul class="mt-1 max-h-32 list-disc overflow-y-auto pl-4 text-xs text-amber-800"><li v-for="message in importResult.errors" :key="message">{{ message }}</li></ul>
          </div>
        </div>
      </div>
      <template #footer>
        <BaseButton variant="outline" @click="closeImportModal">Đóng</BaseButton>
        <BaseButton :disabled="!importRows.length || importing" @click="runImport">
          {{ importing ? 'Đang import…' : `Import ${importRows.length} nhân viên` }}
        </BaseButton>
      </template>
    </BaseModal>

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
        <div v-if="pagination && pagination.last_page > 1" class="flex items-center justify-between mt-4 pt-4 border-t border-border">
          <span class="text-sm text-muted-foreground">
            Trang {{ pagination.current_page }} / {{ pagination.last_page }} · {{ pagination.total }} nhân viên
          </span>
          <div class="flex gap-2">
            <BaseButton variant="outline" :disabled="loading || page <= 1" @click="goTo(page - 1)">Trước</BaseButton>
            <BaseButton variant="outline" :disabled="loading || page >= pagination.last_page" @click="goTo(page + 1)">Sau</BaseButton>
          </div>
        </div>
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
            {{ step === 1 ? 'Cá nhân' : (step === 2 ? 'Công việc' : 'Hồ sơ') }}
          </span>
        </div>
      </div>

      <div class="mt-6">
        <!-- Step 1: Cá nhân -->
        <div v-show="currentStep === 1" class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <BaseInput v-model="form.employee_code" label="Mã nhân viên" required disabled />
          <BaseInput v-model="form.full_name" label="Họ và tên" required />
          <BaseInput v-model="form.work_email" label="Email công ty" type="email" required />
          <BaseInput v-model="form.personal_email" label="Email cá nhân" type="email" />
          <BaseInput v-model="form.phone" label="Số điện thoại" />
          <BaseInput v-model="form.date_of_birth" label="Ngày sinh" type="date" />
          <BaseSelect v-model="form.gender" label="Giới tính" :options="genderOptions" />
          <BaseInput v-model="form.ethnicity" label="Dân tộc" />
          <BaseInput v-model="form.religion" label="Tôn giáo" />
          <BaseSelect v-model="form.marital_status" label="Tình trạng hôn nhân" :options="maritalStatusOptions" />
          <BaseInput v-model="form.nationality_name" label="Quốc tịch" />
          <BaseInput v-model="form.hometown" label="Quê quán" />
          <BaseInput v-model="form.education_level" label="Trình độ học vấn" />
          <div class="md:col-span-2">
            <BaseInput v-model="form.address" label="Địa chỉ" />
          </div>
          <div class="md:col-span-2">
            <BaseInput v-model="form.permanent_address" label="Địa chỉ thường trú" />
          </div>
        </div>

        <!-- Step 2: Công việc -->
        <div v-show="currentStep === 2" class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <BaseSelect v-model="form.department_id" label="Phòng ban" :options="departmentOptions" />
          <BaseSelect v-model="form.job_title_id" label="Chức danh" :options="jobTitleOptions" />
          <BaseSelect v-model="form.manager_id" label="Quản lý trực tiếp" :options="managerOptions" />
          <BaseInput v-model="form.hire_date" label="Ngày vào làm" type="date" />
          <BaseInput v-model="form.probation_end_date" label="Ngày hết thử việc" type="date" />
          <BaseSelect v-model="form.employment_status" label="Trạng thái làm việc" :options="employmentStatusOptions" />
          <BaseSelect v-model="form.employment_type" label="Loại hình làm việc" :options="employmentTypeOptions" />
        </div>

        <!-- Step 3: Hồ sơ & liên hệ -->
        <div v-show="currentStep === 3" class="space-y-5">
          <div>
            <p class="text-sm font-semibold mb-3">Giấy tờ tùy thân & thuế/BHXH</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <BaseInput v-model="form.id_number" label="CMND/CCCD" />
              <BaseInput v-model="form.id_issue_date" label="Ngày cấp" type="date" />
              <BaseInput v-model="form.id_issue_place" label="Nơi cấp" />
              <BaseInput v-model="form.tax_number" label="Mã số thuế cá nhân" />
              <BaseInput v-model="form.insurance_number" label="Số sổ BHXH" />
            </div>
          </div>

          <div>
            <p class="text-sm font-semibold mb-3">Tài khoản ngân hàng</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <BaseInput v-model="form.bank_name" label="Ngân hàng" />
              <BaseInput v-model="form.bank_account" label="Số tài khoản" />
            </div>
          </div>

          <div>
            <p class="text-sm font-semibold mb-3">Liên hệ khẩn cấp</p>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
              <BaseInput v-model="form.emergency_contact_name" label="Họ tên" />
              <BaseInput v-model="form.emergency_contact_relationship" label="Mối quan hệ" />
              <BaseInput v-model="form.emergency_contact_phone" label="Số điện thoại" />
            </div>
          </div>
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
import { downloadCsv, parseCsv } from '../utils/csv';

// ── Import nhân viên từ CSV (parse phía client → POST JSON, không cần lib Excel) ──
const showImportModal = ref(false);
const importRows = ref([]);
const importing = ref(false);
const importResult = ref(null);
const importMode = ref('official');
const importModes = [
  { id: 'official', label: 'Nhân viên chính thức' },
  { id: 'probation', label: 'Nhân viên thử việc' }
];
// Cột khớp payload backend /employees/import; header tiếng Việt cho HR.
const officialImportKeys = ['employee_code', 'full_name', 'gender', 'date_of_birth', 'phone_number', 'company_email', 'department', 'position', 'hire_date', 'base_salary', 'id_number', 'tax_number', 'insurance_number', 'bank_name', 'bank_account', 'address'];
const officialImportLabels = ['Mã NV (trống = tự sinh)', 'Họ tên *', 'Giới tính (Nam/Nữ)', 'Ngày sinh (dd/mm/yyyy)', 'Số điện thoại', 'Email công ty', 'Phòng ban', 'Chức danh', 'Ngày vào làm (dd/mm/yyyy)', 'Lương cơ bản', 'CCCD', 'Mã số thuế', 'Số sổ BHXH', 'Ngân hàng', 'Số tài khoản', 'Địa chỉ'];
const probationImportKeys = ['employee_code', 'full_name', 'company_email'];
const probationImportLabels = ['Mã NV (không bắt buộc)', 'Họ tên *', 'Email công ty *'];
const importKeys = computed(() => importMode.value === 'probation' ? probationImportKeys : officialImportKeys);
const importHeaderLabels = computed(() => importMode.value === 'probation' ? probationImportLabels : officialImportLabels);

const changeImportMode = (mode) => {
  importMode.value = mode;
  importRows.value = [];
  importResult.value = null;
};

const downloadImportTemplate = () => {
  const example = importMode.value === 'probation'
    ? ['TV0001', 'Nguyễn Văn Thử Việc', 'thuviec.nguyen@devtapcode.io.vn']
    : ['', 'Nguyễn Văn Mẫu', 'Nam', '15/03/1995', '0901234567', 'mau.nguyen@congty.vn', 'Phân xưởng A', 'Công nhân', '01/08/2026', '6500000', '012345678901', '', '', 'Vietcombank', '0123456789', 'Số 1, đường ABC, Hà Nội'];
  downloadCsv([importHeaderLabels.value, example], importMode.value === 'probation' ? 'mau-import-thu-viec' : 'mau-import-nhan-vien');
};

const onImportFile = (e) => {
  const file = e.target.files?.[0];
  if (!file) return;
  importResult.value = null;
  const reader = new FileReader();
  reader.onload = () => {
    const grid = parseCsv(reader.result);
    if (grid.length < 2) { importRows.value = []; notificationStore.addError('File không có dòng dữ liệu nào'); return; }
    // Bỏ header (dòng 1), map theo THỨ TỰ cột của file mẫu.
    importRows.value = grid.slice(1).map(cells => {
      const row = {};
      importKeys.value.forEach((k, idx) => { row[k] = (cells[idx] ?? '').trim(); });
      return row;
    }).filter(r => Object.values(r).some(v => v !== ''));
  };
  reader.readAsText(file, 'utf-8');
  e.target.value = '';
};

const runImport = async () => {
  if (!importRows.value.length || importing.value) return;
  try {
    importing.value = true;
    importResult.value = importMode.value === 'probation'
      ? await employeeService.importProbation(importRows.value)
      : await employeeService.import(importRows.value);
    await loadEmployees();
    const created = importMode.value === 'probation' ? importResult.value?.imported : importResult.value?.created;
    if (created) notificationStore.addSuccess(`Đã import ${created} nhân viên`);
  } catch (err) {
    notificationStore.addError(err.response?.data?.message || 'Import thất bại');
  } finally {
    importing.value = false;
  }
};

const closeImportModal = () => {
  showImportModal.value = false;
  importRows.value = [];
  importResult.value = null;
  importMode.value = 'official';
};

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
  if (currentStep.value === 1 && !form.value.work_email?.trim()) {
    formError.value = 'Vui lòng nhập email công ty';
    return;
  }
  formError.value = '';
  currentStep.value++;
};

const employees = ref([]);
const employeeLookup = ref([]);
const pagination = ref(null);
const page = ref(1);
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
  ethnicity: '',
  religion: '',
  marital_status: '',
  nationality_name: '',
  hometown: '',
  education_level: '',
  address: '',
  permanent_address: '',
  department_id: '',
  job_title_id: '',
  manager_id: '',
  hire_date: '',
  probation_end_date: '',
  employment_status: 'active',
  employment_type: 'full_time',
  id_number: '',
  id_issue_date: '',
  id_issue_place: '',
  tax_number: '',
  insurance_number: '',
  bank_name: '',
  bank_account: '',
  emergency_contact_name: '',
  emergency_contact_relationship: '',
  emergency_contact_phone: ''
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

const maritalStatusOptions = [
  { label: '--', value: '' },
  { label: 'Độc thân', value: 'SINGLE' },
  { label: 'Đã kết hôn', value: 'MARRIED' },
  { label: 'Khác', value: 'OTHER' },
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

const asArray = (value) => {
  if (Array.isArray(value)) return value;
  if (Array.isArray(value?.items)) return value.items;
  if (Array.isArray(value?.data)) return value.data;
  return [];
};

const departmentFilterOptions = computed(() => [
  ...departmentOptions.value
]);

const managerOptions = computed(() => [
  { label: '-- Không có --', value: '' },
  ...employeeLookup.value
    .filter(e => !editingId.value || String(e.id) !== String(editingId.value))
    .map(e => ({
      label: `${e.full_name}${e.employee_code ? ` (${e.employee_code})` : ''}`,
      value: String(e.id)
    }))
]);

const totalEmployees = computed(() => employeeLookup.value.length);
const activeEmployees = computed(() => 
  employeeLookup.value.filter(e => e.employment_status === 'active' || e.is_active === true).length
);
const probationEmployees = computed(() => 
  employeeLookup.value.filter(e => e.employment_status === 'probation').length
);
const inactiveEmployees = computed(() => 
  employeeLookup.value.filter(e =>
    e.employment_status === 'inactive' || 
    e.employment_status === 'resigned' || 
    e.employment_status === 'terminated' ||
    e.is_active === false
  ).length
);

const filteredEmployees = computed(() => employees.value);

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
    ethnicity: '',
    religion: '',
    marital_status: '',
    nationality_name: '',
    hometown: '',
    education_level: '',
    address: '',
    permanent_address: '',
    department_id: '',
    job_title_id: '',
    manager_id: '',
    hire_date: '',
    probation_end_date: '',
    employment_status: 'active',
    employment_type: 'full_time',
    id_number: '',
    id_issue_date: '',
    id_issue_place: '',
    tax_number: '',
    insurance_number: '',
    bank_name: '',
    bank_account: '',
    emergency_contact_name: '',
    emergency_contact_relationship: '',
    emergency_contact_phone: ''
  };
  formError.value = '';
};

const generateEmployeeCode = () => {
  const existingCodes = employeeLookup.value
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
    ethnicity: employee.ethnicity || '',
    religion: employee.religion || '',
    marital_status: (employee.marital_status || '').toUpperCase(),
    nationality_name: employee.nationality_name || '',
    hometown: employee.hometown || '',
    education_level: employee.education_level || '',
    address: employee.address || '',
    permanent_address: employee.permanent_address || '',
    department_id: deptId,
    job_title_id: jobId,
    manager_id: employee.manager_id ? String(employee.manager_id) : '',
    hire_date: employee.hire_date || employee.start_date || '',
    probation_end_date: (employee.probation_end_date || '').substring(0, 10),
    employment_status: employee.employment_status || 'active',
    employment_type: normalizeEmploymentType(employee.employment_type),
    id_number: employee.id_number || '',
    id_issue_date: (employee.id_issue_date || '').substring(0, 10),
    id_issue_place: employee.id_issue_place || '',
    tax_number: employee.tax_number || '',
    insurance_number: employee.insurance_number || '',
    bank_name: employee.bank_name || '',
    bank_account: employee.bank_account || '',
    emergency_contact_name: employee.emergency_contact_name || '',
    emergency_contact_relationship: employee.emergency_contact_relationship || '',
    emergency_contact_phone: employee.emergency_contact_phone || ''
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

  if (!form.value.work_email?.trim()) {
    formError.value = 'Vui lòng nhập email công ty';
    return;
  }

  try {
    saving.value = true;
    formError.value = '';
    
    const deptIdParsed = form.value.department_id ? parseInt(form.value.department_id, 10) : null;
    const jobIdParsed = form.value.job_title_id ? parseInt(form.value.job_title_id, 10) : null;
    const managerIdParsed = form.value.manager_id ? parseInt(form.value.manager_id, 10) : null;
    
    const payload = {
      code: form.value.employee_code,
      full_name: form.value.full_name,
      work_email: form.value.work_email || null,
      gender: form.value.gender || null,
      dob: form.value.date_of_birth || null,
      personal_email: form.value.personal_email || null,
      personal_phone: form.value.phone || null,
      address: form.value.address || null,
      ethnicity: form.value.ethnicity || null,
      religion: form.value.religion || null,
      marital_status: form.value.marital_status || null,
      nationality_name: form.value.nationality_name || null,
      hometown: form.value.hometown || null,
      education_level: form.value.education_level || null,
      permanent_address: form.value.permanent_address || null,
      probation_end_date: form.value.probation_end_date || null,
      id_number: form.value.id_number || null,
      id_issue_date: form.value.id_issue_date || null,
      id_issue_place: form.value.id_issue_place || null,
      tax_number: form.value.tax_number || null,
      insurance_number: form.value.insurance_number || null,
      bank_name: form.value.bank_name || null,
      bank_account: form.value.bank_account || null,
      emergency_contact_name: form.value.emergency_contact_name || null,
      emergency_contact_relationship: form.value.emergency_contact_relationship || null,
      emergency_contact_phone: form.value.emergency_contact_phone || null,
      employment: {
        department_id: isNaN(deptIdParsed) ? null : deptIdParsed,
        job_title_id: isNaN(jobIdParsed) ? null : jobIdParsed,
        manager_id: isNaN(managerIdParsed) ? null : managerIdParsed,
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
    await Promise.all([loadEmployees(), refreshLookup()]);
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
    await Promise.all([loadEmployees(), refreshLookup()]);
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
  page.value = 1;
  loadEmployees();
};

const goTo = (target) => {
  page.value = target;
  loadEmployees();
};

const loadEmployees = async () => {
  try {
    const statusMap = { active: 'ACTIVE', probation: 'PROBATION', inactive: 'INACTIVE', resigned: 'TERMINATED' };
    const params = { page: page.value };
    if (filters.value.search.trim()) params.search = filters.value.search.trim();
    if (filters.value.department) params.department_id = filters.value.department;
    if (filters.value.status) params.status = statusMap[filters.value.status] || filters.value.status.toUpperCase();
    const result = await employeeService.getPage(params);
    employees.value = result.items;
    pagination.value = result.pagination;
  } catch (err) {
    console.error('Error loading employees:', err);
  }
};

const refreshLookup = async () => {
  employeeLookup.value = await employeeService.getLookup();
};

onMounted(async () => {
  try {
    loading.value = true;
    error.value = '';
    
    const [employeesRes, lookupRes, departmentsRes, jobTitlesRes] = await Promise.all([
      employeeService.getPage({ page: 1 }),
      employeeService.getLookup(),
      departmentService.getAll(),
      jobTitleService.getAll()
    ]);
    
    employees.value = employeesRes.items;
    pagination.value = employeesRes.pagination;
    employeeLookup.value = lookupRes;
    
    const depts = asArray(departmentsRes);
    departmentOptions.value = depts.map(d => ({
      label: d.name || d.department_name || d.department_code || `Phòng ban #${d.id}`,
      value: String(d.id)
    }));
    
    const titles = asArray(jobTitlesRes);
    jobTitleOptions.value = titles
      .filter(t => t?.id)
      .map(t => ({
        label: t.name || t.position_name || t.job_title_name || t.title || t.position_code || `Chức danh #${t.id}`,
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
