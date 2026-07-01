<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-3xl font-bold text-foreground">Quản lý Phòng ban</h1>
        <p class="text-muted-foreground mt-1">Cấu trúc tổ chức và phân cấp phòng ban</p>
      </div>
      <BaseButton
        @click="openCreateModal"
        data-testid="button-create-department"
      >
        + Thêm phòng ban
      </BaseButton>
    </div>

    <div v-if="!loading && !error" class="grid grid-cols-2 sm:grid-cols-3 gap-3">
      <div class="rounded-lg border border-border p-3">
        <p class="text-2xl font-bold text-foreground">{{ orgSummary.totalDepts }}</p>
        <p class="text-xs text-muted-foreground">Phòng ban</p>
      </div>
      <div class="rounded-lg border border-border p-3">
        <p class="text-2xl font-bold text-foreground">{{ orgSummary.totalEmployees }}</p>
        <p class="text-xs text-muted-foreground">Nhân viên đã phân phòng</p>
      </div>
      <div class="rounded-lg border border-border p-3 col-span-2 sm:col-span-1">
        <p class="text-2xl font-bold text-primary">{{ formatCurrency(orgSummary.totalSalary) }}</p>
        <p class="text-xs text-muted-foreground">Quỹ lương tháng (toàn công ty)</p>
      </div>
    </div>

    <div v-if="loading" class="text-center py-8">
      <p class="text-muted-foreground">Đang tải dữ liệu từ API...</p>
    </div>

    <div v-else-if="error" class="bg-destructive/10 border border-destructive/20 rounded-lg p-4">
      <p class="text-destructive font-medium">Lỗi kết nối API:</p>
      <p class="text-destructive/80 text-sm mt-1">{{ error }}</p>
    </div>

    <div v-else class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <BaseCard title="Cấu trúc phòng ban" class="lg:col-span-1">
        <div class="flex items-center justify-between gap-2 mb-3 flex-wrap">
          <label class="flex items-center gap-1.5 text-xs text-muted-foreground cursor-pointer">
            <input type="checkbox" v-model="showOnlyOverBudget" class="h-3.5 w-3.5 rounded border-input text-primary" />
            Chỉ phòng vượt ngân sách
          </label>
          <button @click="exportCostReport" class="text-xs text-primary hover:underline">⬇ Xuất Excel</button>
        </div>

        <div v-if="departments.length === 0" class="text-center py-8 text-muted-foreground">
          Chưa có phòng ban nào
        </div>
        <div v-else class="space-y-1">
          <div
            v-for="row in filteredOrderedDepartments"
            :key="row.dept.id"
            @click="selectDepartment(row.dept)"
            :class="['py-2 pr-3 rounded-lg cursor-pointer transition-colors hover:bg-muted',
                     (!viewingUnassigned && selectedDept?.id === row.dept.id) ? 'bg-primary/10 border-l-4 border-primary' : '']"
            :style="{ paddingLeft: (12 + row.level * 18) + 'px' }"
            :data-testid="`dept-item-${row.dept.id}`"
          >
            <div class="flex items-center gap-2">
              <span v-if="row.level > 0" class="text-muted-foreground text-xs">└</span>
              <IconBuilding class="w-4 h-4 flex-shrink-0" />
              <div class="flex-1 min-w-0">
                <p class="font-medium text-sm truncate">{{ row.dept.name }}</p>
                <p class="text-xs text-muted-foreground truncate">{{ row.dept.code }} · {{ getDeptEmployeeCount(row.dept.id) }} NV · {{ formatCurrency(deptCostOf(row.dept.id)) }}</p>
              </div>
              <span v-if="isOverBudget(row.dept)" class="w-2.5 h-2.5 rounded-full bg-red-500 flex-shrink-0" title="Vượt ngân sách"></span>
              <BaseBadge size="sm" :variant="row.dept.is_active !== false ? 'success' : 'default'">
                {{ row.dept.is_active !== false ? 'Hoạt động' : 'Tạm dừng' }}
              </BaseBadge>
            </div>
          </div>

          <!-- Mục ảo: nhân viên chưa phân phòng -->
          <div
            v-if="unassignedEmployees.length"
            @click="selectUnassigned()"
            :class="['py-2 px-3 rounded-lg cursor-pointer transition-colors hover:bg-muted border border-dashed border-amber-300',
                     viewingUnassigned ? 'bg-amber-50 border-l-4 border-amber-500' : '']"
          >
            <div class="flex items-center gap-2">
              <svg class="w-4 h-4 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5.07 19h13.86a2 2 0 001.74-3L13.74 4a2 2 0 00-3.48 0L3.33 16a2 2 0 001.74 3z" /></svg>
              <div class="flex-1">
                <p class="font-medium text-sm text-amber-700">Chưa phân phòng</p>
                <p class="text-xs text-muted-foreground">{{ unassignedEmployees.length }} nhân viên cần phân bổ</p>
              </div>
            </div>
          </div>
        </div>
      </BaseCard>

      <BaseCard title="Chi tiết phòng ban" class="lg:col-span-2">
        <!-- Nhân viên chưa phân phòng -->
        <div v-if="viewingUnassigned" class="space-y-4">
          <div>
            <h3 class="text-lg font-bold text-foreground">Nhân viên chưa phân phòng</h3>
            <p class="text-sm text-muted-foreground">Chọn phòng ban để phân bổ từng người (NV mới hoặc vừa bị gỡ khỏi phòng).</p>
          </div>
          <div v-if="unassignedEmployees.length === 0" class="text-center py-8 text-muted-foreground">Tất cả nhân viên đã được phân phòng 🎉</div>
          <div v-else class="space-y-2">
            <div v-for="emp in unassignedEmployees" :key="emp.id" class="flex items-center gap-3 p-3 border border-border rounded-lg">
              <div class="w-8 h-8 rounded-full bg-primary text-primary-foreground flex items-center justify-center text-sm font-semibold">{{ getInitials(emp.full_name) }}</div>
              <div class="flex-1 min-w-0">
                <p class="text-sm font-medium truncate">{{ emp.full_name }}</p>
                <p class="text-xs text-muted-foreground">{{ emp.employee_code || emp.code }} · {{ emp.job_title_name || emp.job_title || 'Chưa có chức danh' }}</p>
              </div>
              <select v-model="assignTargets[emp.id]" class="px-2 py-1.5 rounded-md border border-input bg-background text-xs focus:outline-none focus:ring-2 focus:ring-ring">
                <option value="">-- Chọn phòng --</option>
                <option v-for="d in departments" :key="d.id" :value="String(d.id)">{{ d.name }}</option>
              </select>
              <BaseButton size="sm" @click="assignUnassigned(emp.id)" :disabled="primaryBusy || !assignTargets[emp.id]">Gán</BaseButton>
            </div>
          </div>
        </div>

        <div v-else-if="selectedDept" class="space-y-6">
          <div class="flex justify-between items-start">
            <div class="grid grid-cols-2 gap-4 flex-1">
              <div>
                <p class="text-sm text-muted-foreground">Mã phòng ban</p>
                <p class="font-medium">{{ selectedDept.code }}</p>
              </div>
              <div>
                <p class="text-sm text-muted-foreground">Tên phòng ban</p>
                <p class="font-medium">{{ selectedDept.name }}</p>
              </div>
              <div>
                <p class="text-sm text-muted-foreground">Trạng thái</p>
                <BaseBadge :variant="selectedDept.is_active !== false ? 'success' : 'default'">
                  {{ selectedDept.is_active !== false ? 'Hoạt động' : 'Tạm dừng' }}
                </BaseBadge>
              </div>
              <div>
                <p class="text-sm text-muted-foreground">Phòng ban cha</p>
                <p class="font-medium">{{ getParentName(selectedDept.parent_id) }}</p>
              </div>
              <div>
                <p class="text-sm text-muted-foreground">Trưởng phòng / Quản lý</p>
                <p class="font-medium">{{ managerName(selectedDept.manager_id) }}</p>
              </div>
              <div>
                <p class="text-sm text-muted-foreground">Tổng nhân viên (chính)</p>
                <p class="font-medium text-primary">{{ getDeptEmployeeCount(selectedDept.id) }} người</p>
              </div>
              <div>
                <p class="text-sm text-muted-foreground flex items-center gap-1">
                  Cost center
                  <button v-if="!editingFinance" @click="startEditFinance" class="text-primary hover:underline text-xs" title="Sửa nhanh">✎</button>
                </p>
                <p v-if="!editingFinance" class="font-medium">{{ selectedDept.cost_center || '—' }}</p>
                <input v-else v-model="financeForm.cost_center" class="w-full px-2 py-1 rounded border border-input bg-background text-sm focus:outline-none focus:ring-2 focus:ring-ring" placeholder="VD: CC-IT" />
              </div>
              <div>
                <p class="text-sm text-muted-foreground">Ngân sách (tháng)</p>
                <p v-if="!editingFinance" class="font-medium">{{ selectedDept.budget ? formatCurrency(selectedDept.budget) : '—' }}</p>
                <div v-else class="flex items-center gap-1">
                  <input v-model.number="financeForm.budget" type="number" class="w-full px-2 py-1 rounded border border-input bg-background text-sm focus:outline-none focus:ring-2 focus:ring-ring" />
                  <button @click="saveFinance" :disabled="financeBusy" class="text-emerald-600 hover:underline text-xs whitespace-nowrap">Lưu</button>
                  <button @click="editingFinance = false" class="text-muted-foreground hover:underline text-xs">Hủy</button>
                </div>
              </div>
              <div>
                <p class="text-sm text-muted-foreground">Quỹ lương tháng (gross)</p>
                <p class="font-medium">{{ formatCurrency(deptCost) }}</p>
                <p v-if="budgetUsedPct !== null" class="text-xs mt-0.5"
                   :class="budgetUsedPct > 100 ? 'text-red-600' : (budgetUsedPct >= 90 ? 'text-amber-600' : 'text-emerald-600')">
                  {{ budgetUsedPct }}% ngân sách{{ budgetUsedPct > 100 ? ' — vượt ngân sách!' : '' }}
                </p>
              </div>
            </div>
            <div class="flex gap-2">
              <BaseButton variant="outline" size="sm" @click="openEditModal(selectedDept)">
                Sửa
              </BaseButton>
              <BaseButton 
                :variant="selectedDept.is_active !== false ? 'outline' : 'default'" 
                size="sm" 
                @click="toggleDepartmentStatus(selectedDept)"
                :disabled="togglingStatus"
              >
                {{ selectedDept.is_active !== false ? 'Tạm dừng' : 'Kích hoạt' }}
              </BaseButton>
            </div>
          </div>

          <div class="border-t border-border pt-4">
            <div class="flex items-center justify-between mb-4 gap-2 flex-wrap">
              <h3 class="font-semibold">Nhân viên chính thức (nhóm theo chức danh)</h3>
              <div class="flex gap-2">
                <select v-model="addPrimaryId" class="px-3 py-2 rounded-lg border border-input bg-background text-foreground text-sm focus:outline-none focus:ring-2 focus:ring-ring">
                  <option value="">-- Thêm / điều chuyển NV vào phòng này --</option>
                  <option v-for="o in primaryPickerOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                </select>
                <BaseButton size="sm" @click="assignPrimaryMember" :disabled="primaryBusy || !addPrimaryId">Thêm vào phòng</BaseButton>
              </div>
            </div>
            <p class="text-xs text-muted-foreground -mt-2 mb-3">Đây là phòng ban CHÍNH của nhân viên (mỗi người 1 phòng chính). Thêm người từ phòng khác = điều chuyển sang đây, tự ghi lịch sử công tác.</p>

            <div v-if="loadingEmployees" class="text-center py-4 text-muted-foreground">
              Đang tải...
            </div>
            
            <div v-else-if="groupedEmployees.length === 0" class="text-center py-8 text-muted-foreground">
              Chưa có nhân viên trong phòng ban này
            </div>
            
            <div v-else class="space-y-4">
              <div v-for="group in groupedEmployees" :key="group.jobTitleId" class="border border-border rounded-lg overflow-hidden">
                <div 
                  class="bg-muted/50 px-4 py-2 flex items-center justify-between cursor-pointer"
                  @click="toggleJobTitleGroup(group.jobTitleId)"
                >
                  <div class="flex items-center gap-2">
                    <svg 
                      class="w-4 h-4 transition-transform" 
                      :class="expandedJobTitles.includes(group.jobTitleId) ? 'rotate-90' : ''"
                      fill="none" stroke="currentColor" viewBox="0 0 24 24"
                    >
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                    </svg>
                    <span class="font-medium text-sm">{{ group.jobTitle }}</span>
                  </div>
                  <BaseBadge size="sm" variant="outline">
                    {{ group.employees.length }} người
                  </BaseBadge>
                </div>
                
                <div v-if="expandedJobTitles.includes(group.jobTitleId)" class="divide-y divide-border">
                  <div
                    v-for="emp in group.employees"
                    :key="emp.id"
                    class="flex items-center gap-3 p-3 hover:bg-muted/30"
                  >
                    <div class="w-8 h-8 rounded-full bg-primary text-primary-foreground flex items-center justify-center text-sm font-semibold">
                      {{ getInitials(emp.full_name) }}
                    </div>
                    <div class="flex-1">
                      <p class="text-sm font-medium">{{ emp.full_name }}</p>
                      <p class="text-xs text-muted-foreground">{{ emp.employee_code || emp.code }}</p>
                    </div>
                    <BaseBadge size="sm" :variant="getStatusVariant(emp.employment_status)">
                      {{ getStatusLabel(emp.employment_status) }}
                    </BaseBadge>
                    <button
                      @click="unassignPrimaryMember(emp.id)"
                      :disabled="primaryBusy"
                      title="Gỡ khỏi phòng ban"
                      class="p-1 rounded hover:bg-red-100 dark:hover:bg-red-900 text-red-600 dark:text-red-400 disabled:opacity-50"
                    >
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Kiêm nhiệm (matrix) — nhân viên phòng khác cũng tham gia phòng này -->
          <div class="border-t border-border pt-4">
            <div class="flex items-center justify-between mb-3">
              <h3 class="font-semibold">Kiêm nhiệm (thuộc phòng ban khác)</h3>
            </div>
            <div class="flex gap-2 mb-3">
              <select v-model="addMemberId" class="flex-1 px-3 py-2 rounded-lg border border-input bg-background text-foreground text-sm focus:outline-none focus:ring-2 focus:ring-ring">
                <option value="">-- Chọn nhân viên gán kiêm nhiệm --</option>
                <option v-for="o in memberPickerOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
              </select>
              <BaseButton size="sm" @click="addSecondaryMember" :disabled="memberBusy || !addMemberId">Gán</BaseButton>
            </div>
            <div v-if="secondaryMembers.length === 0" class="text-sm text-muted-foreground">Chưa có nhân viên kiêm nhiệm.</div>
            <div v-else class="space-y-2">
              <div v-for="m in secondaryMembers" :key="m.id" class="flex items-center justify-between p-2 border border-border rounded-lg">
                <span class="text-sm">{{ m.full_name }} <span class="text-muted-foreground">({{ m.employee_code }})</span><span v-if="m.role_in_dept" class="text-xs text-muted-foreground"> · {{ m.role_in_dept }}</span></span>
                <button @click="removeSecondaryMember(m.id)" :disabled="memberBusy" class="p-1 rounded hover:bg-red-100 dark:hover:bg-red-900 text-red-600 dark:text-red-400" title="Gỡ kiêm nhiệm">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
              </div>
            </div>
          </div>
        </div>
        <div v-else class="text-center py-12 text-muted-foreground">
          Chọn một phòng ban để xem chi tiết
        </div>
      </BaseCard>
    </div>

    <BaseModal
      v-model="showModal"
      :title="isEditing ? 'Chỉnh sửa phòng ban' : 'Thêm phòng ban mới'"
      data-testid="modal-department"
    >
      <div class="space-y-4">
        <BaseInput 
          v-model="form.code" 
          label="Mã phòng ban" 
          required 
          disabled
          placeholder="VD: DEP01"
        />
        <BaseInput 
          v-model="form.name" 
          label="Tên phòng ban" 
          required 
          placeholder="VD: Phòng Công nghệ thông tin"
        />
        <BaseSelect
          v-model="form.parent_id"
          label="Phòng ban cha"
          :options="parentOptions"
          placeholder="Không có"
        />
        <BaseSelect
          v-model="form.manager_id"
          label="Trưởng phòng / Quản lý"
          :options="managerOptions"
          placeholder="Chưa gán"
        />
        <div class="grid grid-cols-2 gap-4">
          <BaseInput v-model="form.cost_center" label="Cost center (mã chi phí)" placeholder="VD: CC-IT" />
          <BaseInput v-model.number="form.budget" type="number" label="Ngân sách (VNĐ)" />
        </div>
        <BaseSelect
          v-model="form.is_active"
          label="Trạng thái"
          :options="statusOptions"
        />
      </div>

      <div v-if="formError" class="mt-4 p-3 bg-destructive/10 border border-destructive/20 rounded-lg">
        <p class="text-destructive text-sm">{{ formError }}</p>
      </div>

      <template #footer>
        <BaseButton variant="outline" @click="closeModal" :disabled="saving">Hủy</BaseButton>
        <BaseButton @click="handleSubmit" :disabled="saving">
          {{ saving ? 'Đang lưu...' : (isEditing ? 'Cập nhật' : 'Tạo mới') }}
        </BaseButton>
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
import BaseModal from '../components/BaseModal.vue';
import IconBuilding from '../components/IconBuilding.vue';
import { departmentService } from '../services/departmentService';
import { employeeService } from '../services/employeeService';
import { useNotificationStore } from '../stores/notificationStore';

const notificationStore = useNotificationStore();

const loading = ref(true);
const error = ref('');
const saving = ref(false);
const togglingStatus = ref(false);
const formError = ref('');

const showModal = ref(false);
const isEditing = ref(false);
const editingId = ref(null);

const departments = ref([]);
const allEmployees = ref([]);
const selectedDept = ref(null);
const loadingEmployees = ref(false);
const expandedJobTitles = ref([]);

// Matrix members (kiêm nhiệm) for the selected department.
const secondaryMembers = ref([]);
const addMemberId = ref('');
const memberBusy = ref(false);

// Primary membership (phòng ban chính) — thêm / điều chuyển nhân viên.
const addPrimaryId = ref('');
const primaryBusy = ref(false);

// Chi phí nhân sự theo phòng (sĩ số + quỹ lương tháng).
const costByDept = ref({});

// Cây phân cấp / lọc / chưa phân phòng / sửa inline.
const showOnlyOverBudget = ref(false);
const viewingUnassigned = ref(false);
const assignTargets = ref({});
const editingFinance = ref(false);
const financeBusy = ref(false);
const financeForm = ref({ cost_center: '', budget: '' });

const form = ref({
  code: '',
  name: '',
  parent_id: '',
  manager_id: '',
  cost_center: '',
  budget: '',
  is_active: 'true'
});

const managerOptions = computed(() => [
  { label: 'Chưa gán', value: '' },
  ...allEmployees.value.map((e) => ({ label: `${e.full_name}${e.employee_code ? ` (${e.employee_code})` : ''}`, value: String(e.id) }))
]);

// Employees eligible to be assigned as PRIMARY here: those not already in this dept.
const primaryPickerOptions = computed(() => {
  const deptId = selectedDept.value?.id;
  return allEmployees.value
    .filter((e) => Number(e.department_id) !== Number(deptId))
    .map((e) => {
      const cur = departments.value.find((d) => Number(d.id) === Number(e.department_id));
      const note = e.department_id ? ` — đang ở ${cur ? cur.name : 'phòng khác'}` : ' — chưa có phòng';
      return { label: `${e.full_name}${e.employee_code ? ` (${e.employee_code})` : ''}${note}`, value: String(e.id) };
    });
});

// Employees eligible to be added as secondary: not already primary in this dept, not already secondary.
const memberPickerOptions = computed(() => {
  const deptId = selectedDept.value?.id;
  const secIds = new Set(secondaryMembers.value.map((m) => String(m.id)));
  return allEmployees.value
    .filter((e) => Number(e.department_id) !== Number(deptId) && !secIds.has(String(e.id)))
    .map((e) => ({ label: `${e.full_name}${e.employee_code ? ` (${e.employee_code})` : ''}`, value: String(e.id) }));
});

const managerName = (id) => {
  if (!id) return 'Chưa gán';
  const e = allEmployees.value.find((x) => String(x.id) === String(id));
  return e ? e.full_name : `NV #${id}`;
};
const formatCurrency = (v) => new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND', maximumFractionDigits: 0 }).format(v || 0);

const loadCostSummary = async () => {
  try { costByDept.value = await departmentService.getCostSummary(); } catch (e) { costByDept.value = {}; }
};

const orgSummary = computed(() => ({
  totalDepts: departments.value.length,
  totalEmployees: allEmployees.value.filter((e) => e.department_id).length,
  totalSalary: Object.values(costByDept.value).reduce((s, c) => s + (c.total_salary || 0), 0),
}));

const deptCost = computed(() => costByDept.value[Number(selectedDept.value?.id)]?.total_salary || 0);
const budgetUsedPct = computed(() => {
  const b = Number(selectedDept.value?.budget || 0);
  if (!b) return null;
  return Math.round((deptCost.value / b) * 100);
});

const deptCostOf = (id) => costByDept.value[Number(id)]?.total_salary || 0;
const isOverBudget = (dept) => {
  const b = Number(dept?.budget || 0);
  return b > 0 && deptCostOf(dept.id) > b;
};

// Danh sách phòng ban theo thứ tự CÂY (cha trước, con thụt cấp).
const orderedDepartments = computed(() => {
  const byParent = {};
  departments.value.forEach((d) => {
    const p = d.parent_id ? Number(d.parent_id) : 0;
    (byParent[p] = byParent[p] || []).push(d);
  });
  const out = [];
  const seen = new Set();
  const walk = (parentId, level) => {
    (byParent[parentId] || []).forEach((d) => {
      if (seen.has(d.id)) return;
      seen.add(d.id);
      out.push({ dept: d, level });
      walk(Number(d.id), level + 1);
    });
  };
  walk(0, 0);
  // Phòng có cha không tồn tại trong danh sách → coi như gốc.
  departments.value.forEach((d) => { if (!seen.has(d.id)) out.push({ dept: d, level: 0 }); });
  return out;
});

const filteredOrderedDepartments = computed(() =>
  showOnlyOverBudget.value
    ? orderedDepartments.value.filter((r) => isOverBudget(r.dept))
    : orderedDepartments.value
);

// Nhân viên chưa có phòng ban chính (NV mới / vừa bị gỡ).
const unassignedEmployees = computed(() =>
  allEmployees.value.filter((e) =>
    !e.department_id && e.employment_status !== 'terminated' && !/System Administrator/i.test(e.full_name || '')
  )
);

const statusOptions = [
  { label: 'Hoạt động', value: 'true' },
  { label: 'Tạm dừng', value: 'false' }
];

const parentOptions = computed(() => {
  const options = [{ label: 'Không có', value: '' }];
  departments.value.forEach(d => {
    if (!isEditing.value || d.id !== editingId.value) {
      options.push({ label: d.name, value: String(d.id) });
    }
  });
  return options;
});

const groupedEmployees = computed(() => {
  if (!selectedDept.value?.employees?.length) return [];
  
  const groups = {};
  selectedDept.value.employees.forEach(emp => {
    const jobTitleId = emp.job_title_id || 0;
    const jobTitle = emp.job_title || emp.job_title_name || 'Chưa phân loại';
    
    if (!groups[jobTitleId]) {
      groups[jobTitleId] = {
        jobTitleId,
        jobTitle,
        employees: []
      };
    }
    groups[jobTitleId].employees.push(emp);
  });
  
  return Object.values(groups).sort((a, b) => a.jobTitle.localeCompare(b.jobTitle));
});

const getInitials = (name) => {
  if (!name) return '';
  return name.split(' ').map(w => w[0]).join('').toUpperCase().slice(0, 2);
};

const getParentName = (parentId) => {
  if (!parentId) return 'Không có';
  const parent = departments.value.find(d => d.id === parentId);
  return parent ? parent.name : 'Không có';
};

const getDeptEmployeeCount = (deptId) => {
  return allEmployees.value.filter(emp => {
    const empDeptId = Number(emp.department_id || 0);
    return empDeptId === Number(deptId);
  }).length;
};

const getStatusVariant = (status) => {
  if (status === 'active' || status === true) return 'success';
  if (status === 'probation' || status === 'on_leave') return 'warning';
  if (status === 'terminated' || status === 'resigned') return 'destructive';
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

const toggleJobTitleGroup = (jobTitleId) => {
  const index = expandedJobTitles.value.indexOf(jobTitleId);
  if (index === -1) {
    expandedJobTitles.value.push(jobTitleId);
  } else {
    expandedJobTitles.value.splice(index, 1);
  }
};

const generateDepartmentCode = () => {
  const existingCodes = departments.value
    .map(d => d.code || '')
    .filter(code => /^DEP\d+$/i.test(code))
    .map(code => parseInt(code.replace(/^DEP/i, ''), 10));
  
  const maxNum = existingCodes.length > 0 ? Math.max(...existingCodes) : 0;
  const nextNum = maxNum + 1;
  return `DEP${String(nextNum).padStart(2, '0')}`;
};

const resetForm = () => {
  form.value = {
    code: '',
    name: '',
    parent_id: '',
    manager_id: '',
    cost_center: '',
    budget: '',
    is_active: 'true'
  };
  formError.value = '';
};

const openCreateModal = () => {
  resetForm();
  isEditing.value = false;
  editingId.value = null;
  form.value.code = generateDepartmentCode();
  showModal.value = true;
};

const openEditModal = (dept) => {
  resetForm();
  isEditing.value = true;
  editingId.value = dept.id;
  
  form.value = {
    code: dept.code || '',
    name: dept.name || '',
    parent_id: dept.parent_id ? String(dept.parent_id) : '',
    manager_id: dept.manager_id ? String(dept.manager_id) : '',
    cost_center: dept.cost_center || '',
    budget: dept.budget ?? '',
    is_active: dept.is_active !== false ? 'true' : 'false'
  };

  showModal.value = true;
};

const closeModal = () => {
  showModal.value = false;
  resetForm();
};

// ── Chưa phân phòng ──
const selectUnassigned = () => {
  viewingUnassigned.value = true;
  selectedDept.value = null;
  assignTargets.value = {};
};

const assignUnassigned = async (employeeId) => {
  const deptId = assignTargets.value[employeeId];
  if (!deptId) return;
  primaryBusy.value = true;
  try {
    await departmentService.assignEmployee(parseInt(deptId, 10), employeeId);
    notificationStore.addSuccess('Đã phân bổ nhân viên vào phòng ban');
    allEmployees.value = await employeeService.getAll();
    await loadCostSummary();
  } catch (err) {
    notificationStore.addError(err.response?.data?.data?.errors?.employee_id?.[0] || err.response?.data?.message || 'Không thể phân bổ');
  } finally {
    primaryBusy.value = false;
  }
};

// ── Sửa nhanh cost center / ngân sách (inline) ──
const startEditFinance = () => {
  financeForm.value = {
    cost_center: selectedDept.value?.cost_center || '',
    budget: selectedDept.value?.budget ?? '',
  };
  editingFinance.value = true;
};

const saveFinance = async () => {
  if (!selectedDept.value) return;
  financeBusy.value = true;
  try {
    await departmentService.update(selectedDept.value.id, {
      cost_center: financeForm.value.cost_center || null,
      budget: financeForm.value.budget === '' || financeForm.value.budget === null ? null : Number(financeForm.value.budget),
    });
    notificationStore.addSuccess('Đã cập nhật cost center / ngân sách');
    editingFinance.value = false;
    await loadDepartments();
    const fresh = departments.value.find((d) => d.id === selectedDept.value.id);
    if (fresh) selectedDept.value = { ...selectedDept.value, cost_center: fresh.cost_center, budget: fresh.budget };
  } catch (err) {
    notificationStore.addError(err.response?.data?.message || 'Không thể lưu');
  } finally {
    financeBusy.value = false;
  }
};

// ── Xuất Excel báo cáo chi phí theo phòng ── (lazy-load xlsx khi cần)
const exportCostReport = async () => {
  const mod = await import('xlsx');
  const XLSX = mod.utils ? mod : (mod.default || mod);
  const rows = orderedDepartments.value.map((r) => {
    const d = r.dept;
    const cost = deptCostOf(d.id);
    const budget = Number(d.budget || 0);
    return {
      'Phòng ban': `${'  '.repeat(r.level)}${d.name}`,
      'Mã': d.code || '',
      'Cost center': d.cost_center || '',
      'Phòng cha': getParentName(d.parent_id),
      'Trưởng phòng': managerName(d.manager_id),
      'Sĩ số': getDeptEmployeeCount(d.id),
      'Quỹ lương tháng': cost,
      'Ngân sách': budget,
      '% ngân sách': budget > 0 ? Math.round((cost / budget) * 100) + '%' : '—',
      'Trạng thái NS': budget > 0 ? (cost > budget ? 'VƯỢT NGÂN SÁCH' : 'Trong ngân sách') : '—',
    };
  });
  const ws = XLSX.utils.json_to_sheet(rows);
  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, 'Chi phí phòng ban');
  XLSX.writeFile(wb, `bao-cao-chi-phi-phong-ban-${new Date().toISOString().slice(0, 10)}.xlsx`);
  notificationStore.addSuccess('Đã xuất báo cáo Excel');
};

const selectDepartment = async (dept) => {
  viewingUnassigned.value = false;
  editingFinance.value = false;
  selectedDept.value = { ...dept, employees: [] };
  loadingEmployees.value = true;
  expandedJobTitles.value = [];
  
  try {
    const targetDeptId = Number(dept.id);
    
    const deptEmployees = allEmployees.value.filter(emp => {
      const empDeptId = Number(emp.department_id || emp.employment?.department_id || 0);
      return empDeptId === targetDeptId && empDeptId !== 0;
    });
    
    selectedDept.value = { 
      ...dept, 
      employees: deptEmployees.map(emp => ({
        ...emp,
        job_title: emp.job_title || emp.job_title_name || emp.employment?.job_title?.name || ''
      }))
    };
    
    if (groupedEmployees.value.length > 0) {
      expandedJobTitles.value = [groupedEmployees.value[0].jobTitleId];
    }

    await loadSecondaryMembers(dept.id);
  } catch (err) {
    console.error('Error loading department employees:', err);
  } finally {
    loadingEmployees.value = false;
  }
};

const loadSecondaryMembers = async (deptId) => {
  addMemberId.value = '';
  try {
    const m = await departmentService.getMembers(deptId);
    secondaryMembers.value = Array.isArray(m?.secondary) ? m.secondary : [];
  } catch (err) {
    secondaryMembers.value = [];
  }
};

const addSecondaryMember = async () => {
  if (!addMemberId.value || !selectedDept.value) return;
  memberBusy.value = true;
  try {
    await departmentService.addMember(selectedDept.value.id, parseInt(addMemberId.value, 10));
    notificationStore.addSuccess('Đã gán nhân viên kiêm nhiệm');
    await loadSecondaryMembers(selectedDept.value.id);
  } catch (err) {
    notificationStore.addError(err.response?.data?.data?.errors?.employee_id?.[0] || err.response?.data?.message || 'Không thể gán nhân viên');
  } finally {
    memberBusy.value = false;
  }
};

const assignPrimaryMember = async () => {
  if (!addPrimaryId.value || !selectedDept.value) return;
  primaryBusy.value = true;
  try {
    await departmentService.assignEmployee(selectedDept.value.id, parseInt(addPrimaryId.value, 10));
    notificationStore.addSuccess('Đã thêm/điều chuyển nhân viên vào phòng ban');
    addPrimaryId.value = '';
    // Tải lại danh sách nhân viên để cập nhật phòng ban chính + sĩ số, rồi refresh chi tiết.
    allEmployees.value = await employeeService.getAll();
    await loadCostSummary();
    await selectDepartment(selectedDept.value);
  } catch (err) {
    notificationStore.addError(err.response?.data?.data?.errors?.employee_id?.[0] || err.response?.data?.message || 'Không thể thêm nhân viên');
  } finally {
    primaryBusy.value = false;
  }
};

const unassignPrimaryMember = async (employeeId) => {
  if (!selectedDept.value) return;
  if (!confirm('Gỡ nhân viên này khỏi phòng ban? Họ sẽ về trạng thái "chưa phân phòng".')) return;
  primaryBusy.value = true;
  try {
    await departmentService.unassignEmployee(selectedDept.value.id, employeeId);
    notificationStore.addSuccess('Đã gỡ nhân viên khỏi phòng ban');
    allEmployees.value = await employeeService.getAll();
    await loadCostSummary();
    await selectDepartment(selectedDept.value);
  } catch (err) {
    notificationStore.addError(err.response?.data?.data?.errors?.employee_id?.[0] || err.response?.data?.message || 'Không thể gỡ');
  } finally {
    primaryBusy.value = false;
  }
};

const removeSecondaryMember = async (employeeId) => {
  if (!selectedDept.value) return;
  memberBusy.value = true;
  try {
    await departmentService.removeMember(selectedDept.value.id, employeeId);
    notificationStore.addSuccess('Đã gỡ kiêm nhiệm');
    await loadSecondaryMembers(selectedDept.value.id);
  } catch (err) {
    notificationStore.addError(err.response?.data?.message || 'Không thể gỡ');
  } finally {
    memberBusy.value = false;
  }
};

const toggleDepartmentStatus = async (dept) => {
  try {
    togglingStatus.value = true;
    const newStatus = (dept.is_active === false || dept.is_active === 0) ? 1 : 0;
    const newStatusBool = newStatus === 1;
    
    await departmentService.update(dept.id, {
      is_active: newStatus
    });
    
    // Immediately update local state for instant UI feedback
    const deptIndex = departments.value.findIndex(d => d.id === dept.id);
    if (deptIndex !== -1) {
      const updatedDepts = [...departments.value];
      updatedDepts[deptIndex] = { 
        ...updatedDepts[deptIndex], 
        is_active: newStatusBool 
      };
      departments.value = updatedDepts;
    }
    
    // Update selectedDept if it's the one being toggled
    if (selectedDept.value?.id === dept.id) {
      selectedDept.value = { 
        ...selectedDept.value, 
        is_active: newStatusBool 
      };
    }
    
    notificationStore.addSuccess(`Đã ${newStatusBool ? 'kích hoạt' : 'tạm dừng'} phòng ban "${dept.name}"`);
    
    // Reload from server to ensure consistency
    await loadDepartments();
    
    // Re-select the department to refresh its details
    if (selectedDept.value?.id === dept.id) {
      const updatedDept = departments.value.find(d => d.id === dept.id);
      if (updatedDept) {
        selectedDept.value = { 
          ...selectedDept.value, 
          ...updatedDept,
          is_active: updatedDept.is_active === 1 || updatedDept.is_active === true || updatedDept.is_active === '1'
        };
      }
    }
  } catch (err) {
    console.error('Error toggling department status:', err);
    const errorMsg = err.response?.data?.error || 'Có lỗi xảy ra khi cập nhật trạng thái';
    notificationStore.addError(`Lỗi: ${errorMsg}`);
  } finally {
    togglingStatus.value = false;
  }
};

const handleSubmit = async () => {
  if (!form.value.code?.trim()) {
    formError.value = 'Vui lòng nhập mã phòng ban';
    return;
  }
  
  if (!form.value.name?.trim()) {
    formError.value = 'Vui lòng nhập tên phòng ban';
    return;
  }

  try {
    saving.value = true;
    formError.value = '';
    
    const payload = {
      code: form.value.code,
      name: form.value.name,
      parent_id: form.value.parent_id ? parseInt(form.value.parent_id) : null,
      manager_id: form.value.manager_id ? parseInt(form.value.manager_id) : null,
      cost_center: form.value.cost_center || null,
      budget: form.value.budget === '' || form.value.budget === null ? null : Number(form.value.budget),
      is_active: form.value.is_active === 'true' ? 1 : 0
    };

    if (isEditing.value) {
      await departmentService.update(editingId.value, payload);
      notificationStore.addSuccess(`Đã cập nhật phòng ban "${form.value.name}"`);
    } else {
      await departmentService.create(payload);
      notificationStore.addSuccess(`Đã thêm phòng ban "${form.value.name}"`);
    }
    
    closeModal();
    await loadDepartments();
    
    if (isEditing.value && selectedDept.value?.id === editingId.value) {
      const updatedDept = departments.value.find(d => d.id === editingId.value);
      if (updatedDept) {
        selectedDept.value = { 
          ...selectedDept.value, 
          ...updatedDept,
          is_active: updatedDept.is_active === 1 || updatedDept.is_active === true || updatedDept.is_active === '1'
        };
      }
    }
  } catch (err) {
    console.error('Error saving department:', err);
    const errorMsg = err.response?.data?.error || err.response?.data?.message || 'Có lỗi xảy ra khi lưu';
    formError.value = errorMsg;
    notificationStore.addError(`Lỗi: ${errorMsg}`);
  } finally {
    saving.value = false;
  }
};

const loadDepartments = async () => {
  try {
    const response = await departmentService.getAll();
    let rawDepts = response?.data || response || [];
    if (!Array.isArray(rawDepts)) {
      rawDepts = rawDepts.items || rawDepts.data || [];
    }
    if (!Array.isArray(rawDepts)) rawDepts = [];
    
    departments.value = rawDepts.map(d => ({
      ...d,
      is_active: d.is_active === 1 || d.is_active === true || d.is_active === '1'
    }));
  } catch (err) {
    console.error('Error loading departments:', err);
  }
};

onMounted(async () => {
  try {
    loading.value = true;
    error.value = '';
    
    const [deptsRes, empsRes] = await Promise.all([
      departmentService.getAll(),
      employeeService.getAll()
    ]);
    
    let rawDepts = deptsRes?.data || deptsRes || [];
    if (!Array.isArray(rawDepts)) {
      rawDepts = rawDepts.items || rawDepts.data || [];
    }
    if (!Array.isArray(rawDepts)) rawDepts = [];
    
    departments.value = rawDepts.map(d => ({
      ...d,
      is_active: d.is_active === 1 || d.is_active === true || d.is_active === '1'
    }));
    allEmployees.value = empsRes?.data || empsRes || [];
    await loadCostSummary();
  } catch (err) {
    console.error('Departments API Error:', err);
    error.value = err.response?.data?.error || err.message || 'Không thể kết nối đến API';
  } finally {
    loading.value = false;
  }
});
</script>
