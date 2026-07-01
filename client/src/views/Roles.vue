<template>
  <div class="space-y-6">
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-2">
      <div>
        <h1 class="text-xl sm:text-2xl font-bold">Vai trò & Phân quyền</h1>
        <p class="text-muted-foreground mt-1">
          Mỗi vai trò mở quyền vào một số khu vực (module). Gán vai trò cho nhân viên để giới hạn họ chỉ thấy phần mình phụ trách.
        </p>
      </div>
      <BaseButton @click="openCreate" data-testid="button-add-role">+ Thêm vai trò</BaseButton>
    </div>

    <div v-if="error" class="bg-destructive/10 border border-destructive/20 rounded-lg p-4">
      <p class="text-destructive font-medium">Lỗi kết nối API:</p>
      <p class="text-destructive/80 text-sm mt-1">{{ error }}</p>
    </div>

    <BaseCard title="Danh sách vai trò">
      <div v-if="loading" class="text-center py-8 text-muted-foreground">Đang tải dữ liệu...</div>
      <BaseTable
        v-else
        :columns="[
          { key: 'role_name', label: 'Vai trò' },
          { key: 'access', label: 'Phạm vi truy cập' },
          { key: 'members', label: 'Thành viên' }
        ]"
        :data="roles"
      >
        <template #cell-role_name="{ item }">
          <div class="font-medium">{{ item.role_name }}</div>
          <div class="text-xs text-muted-foreground">{{ item.role_code }}</div>
        </template>
        <template #cell-access="{ item }">
          <span v-if="isAdminRole(item)" class="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-primary/10 text-primary">Toàn quyền</span>
          <div v-else class="flex flex-wrap gap-1">
            <span v-for="m in moduleList(item)" :key="m" class="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-muted text-muted-foreground">{{ MODULES[m] || m }}</span>
            <span v-if="moduleList(item).length === 0" class="text-xs text-muted-foreground">Chỉ Portal nhân viên</span>
          </div>
        </template>
        <template #cell-members="{ item }">
          <span class="text-sm text-muted-foreground">{{ memberCounts[item.id] ?? '—' }} người</span>
        </template>
        <template #actions="{ item }">
          <div class="flex gap-1">
            <button @click="openAccess(item)" class="px-2.5 py-1.5 text-xs font-medium rounded-md bg-primary/10 text-primary hover:bg-primary/20">Phân quyền</button>
            <button @click="openMembers(item)" class="px-2.5 py-1.5 text-xs font-medium rounded-md bg-accent text-foreground hover:bg-accent/70">Thành viên</button>
            <button @click="removeRole(item)" class="px-2.5 py-1.5 text-xs font-medium rounded-md bg-destructive/10 text-destructive hover:bg-destructive/20">Xóa</button>
          </div>
        </template>
      </BaseTable>
    </BaseCard>

    <!-- Create role modal -->
    <BaseModal v-model="showCreate" title="Thêm vai trò" size="sm">
      <div class="space-y-4">
        <BaseInput v-model="createForm.role_name" label="Tên vai trò" />
        <BaseInput v-model="createForm.role_code" label="Mã vai trò (VD: ACCOUNTANT)" />
      </div>
      <template #footer>
        <BaseButton variant="outline" @click="showCreate = false" :disabled="saving">Hủy</BaseButton>
        <BaseButton @click="createRole" :disabled="saving">{{ saving ? 'Đang lưu...' : 'Tạo' }}</BaseButton>
      </template>
    </BaseModal>

    <!-- Access (modules) modal -->
    <BaseModal v-model="showAccess" :title="`Phân quyền: ${current?.role_name || ''}`" size="md">
      <div class="space-y-4">
        <label class="flex items-center gap-2 p-3 border border-border rounded-lg">
          <input type="checkbox" v-model="accessForm.is_admin" class="h-4 w-4 rounded border-input text-primary focus:ring-ring" />
          <span class="text-sm font-medium text-foreground">Toàn quyền quản trị (thấy & làm mọi khu vực)</span>
        </label>
        <div :class="accessForm.is_admin ? 'opacity-40 pointer-events-none' : ''">
          <p class="text-sm font-medium text-foreground mb-2">Hoặc chọn các khu vực được phép:</p>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
            <label v-for="(label, key) in MODULES" :key="key" class="flex items-center gap-2 p-2 border border-border rounded-lg">
              <input type="checkbox" :value="key" v-model="accessForm.modules" class="h-4 w-4 rounded border-input text-primary focus:ring-ring" />
              <span class="text-sm text-foreground">{{ label }}</span>
            </label>
          </div>
          <p class="text-xs text-muted-foreground mt-2">Không chọn khu vực nào = vai trò chỉ dùng cho Portal nhân viên (và duyệt đơn nếu được gán bước duyệt).</p>
        </div>
      </div>
      <template #footer>
        <BaseButton variant="outline" @click="showAccess = false" :disabled="saving">Hủy</BaseButton>
        <BaseButton @click="saveAccess" :disabled="saving">{{ saving ? 'Đang lưu...' : 'Lưu' }}</BaseButton>
      </template>
    </BaseModal>

    <!-- Members modal -->
    <BaseModal v-model="showMembers" :title="`Thành viên: ${current?.role_name || ''}`" size="md">
      <div class="space-y-4">
        <div class="flex gap-2">
          <select v-model="addEmployeeId" class="flex-1 px-3 py-2 rounded-lg border border-input bg-background text-foreground text-sm focus:outline-none focus:ring-2 focus:ring-ring">
            <option value="">-- Chọn nhân viên để gán --</option>
            <option v-for="e in assignableEmployees" :key="e.id" :value="e.id">{{ e.full_name }}{{ e.employee_code ? ` (${e.employee_code})` : '' }}</option>
          </select>
          <BaseButton @click="addMember" :disabled="memberBusy || !addEmployeeId">Gán</BaseButton>
        </div>
        <div v-if="membersLoading" class="text-center py-4 text-muted-foreground">Đang tải...</div>
        <div v-else-if="members.length === 0" class="text-center py-4 text-muted-foreground">Chưa có nhân viên nào giữ vai trò này.</div>
        <div v-else class="space-y-2">
          <div v-for="m in members" :key="m.id" class="flex justify-between items-center p-2 border border-border rounded-lg">
            <span class="text-sm">{{ employeeName(m.employee_id) }}</span>
            <button @click="removeMember(m)" :disabled="memberBusy" class="p-1 rounded hover:bg-red-100 dark:hover:bg-red-900 text-red-600 dark:text-red-400" title="Gỡ">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
            </button>
          </div>
        </div>
      </div>
      <template #footer>
        <BaseButton variant="outline" @click="showMembers = false">Đóng</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import BaseCard from '../components/BaseCard.vue';
import BaseButton from '../components/BaseButton.vue';
import BaseTable from '../components/BaseTable.vue';
import BaseModal from '../components/BaseModal.vue';
import BaseInput from '../components/BaseInput.vue';
import { roleService } from '../services/roleService';
import { employeeService } from '../services/employeeService';
import { useToast } from '../composables/useToast';

const toast = useToast();

// Must match the backend module catalog (App\Support\AccessControl::MODULES).
const MODULES = {
  hr: 'Nhân sự & Hợp đồng',
  time: 'Công & Lịch',
  payroll: 'Lương & Báo cáo',
  recruitment: 'Tuyển dụng',
  communications: 'Truyền thông',
  settings: 'Cấu hình'
};

const loading = ref(false);
const saving = ref(false);
const error = ref('');
const roles = ref([]);
const employees = ref([]);
const memberCounts = ref({});

const showCreate = ref(false);
const showAccess = ref(false);
const showMembers = ref(false);
const current = ref(null);

const createForm = ref({ role_name: '', role_code: '' });
const accessForm = ref({ is_admin: false, modules: [] });

const members = ref([]);
const membersLoading = ref(false);
const memberBusy = ref(false);
const addEmployeeId = ref('');

const asArray = (d) => Array.isArray(d) ? d : (d?.items || d?.data || []);
const metaOf = (role) => {
  let m = role?.meta;
  if (typeof m === 'string') { try { m = JSON.parse(m); } catch { m = {}; } }
  return (m && typeof m === 'object') ? m : {};
};
const isAdminRole = (role) => metaOf(role).is_admin === true || String(role?.role_code).toUpperCase() === 'ADMIN';
const moduleList = (role) => {
  const m = metaOf(role).modules;
  return Array.isArray(m) ? m.filter((x) => MODULES[x]) : [];
};

const employeeName = (id) => {
  const e = employees.value.find((x) => String(x.id) === String(id));
  return e ? `${e.full_name}${e.employee_code ? ` (${e.employee_code})` : ''}` : `NV #${id}`;
};

const assignableEmployees = computed(() => {
  const held = new Set(members.value.map((m) => String(m.employee_id)));
  return employees.value.filter((e) => !held.has(String(e.id)));
});

const apiErr = (err, fb) => err?.response?.data?.message || err?.response?.data?.error || err?.message || fb;

const loadRoles = async () => {
  loading.value = true;
  error.value = '';
  try {
    roles.value = asArray(await roleService.getAll());
    const counts = {};
    await Promise.all(roles.value.map(async (r) => {
      try { counts[r.id] = (await roleService.getMembers(r.id)).length; } catch { counts[r.id] = 0; }
    }));
    memberCounts.value = counts;
  } catch (err) {
    error.value = apiErr(err, 'Không thể tải danh sách vai trò');
  } finally {
    loading.value = false;
  }
};

const loadEmployees = async () => {
  try { employees.value = asArray(await employeeService.getAll()); } catch { /* ignore */ }
};

const openCreate = () => { createForm.value = { role_name: '', role_code: '' }; showCreate.value = true; };
const createRole = async () => {
  if (!createForm.value.role_name.trim() || !createForm.value.role_code.trim()) { toast.error('Nhập tên và mã vai trò'); return; }
  saving.value = true;
  try {
    await roleService.create({ role_name: createForm.value.role_name.trim(), role_code: createForm.value.role_code.trim().toUpperCase(), meta: { modules: [] } });
    toast.success('Đã tạo vai trò');
    showCreate.value = false;
    await loadRoles();
  } catch (err) { toast.error(apiErr(err, 'Lỗi khi tạo vai trò')); } finally { saving.value = false; }
};

const openAccess = (role) => {
  current.value = role;
  accessForm.value = { is_admin: isAdminRole(role), modules: [...moduleList(role)] };
  showAccess.value = true;
};
const saveAccess = async () => {
  saving.value = true;
  try {
    const meta = accessForm.value.is_admin ? { is_admin: true } : { modules: accessForm.value.modules };
    await roleService.update(current.value.id, { meta });
    toast.success('Đã cập nhật phân quyền');
    showAccess.value = false;
    await loadRoles();
  } catch (err) { toast.error(apiErr(err, 'Lỗi khi lưu phân quyền')); } finally { saving.value = false; }
};

const openMembers = async (role) => {
  current.value = role;
  addEmployeeId.value = '';
  members.value = [];
  showMembers.value = true;
  membersLoading.value = true;
  try { members.value = await roleService.getMembers(role.id); } catch (err) { toast.error(apiErr(err, 'Lỗi tải thành viên')); } finally { membersLoading.value = false; }
};
const addMember = async () => {
  if (!addEmployeeId.value) return;
  memberBusy.value = true;
  try {
    await roleService.addMember(parseInt(addEmployeeId.value, 10), current.value.id);
    toast.success('Đã gán vai trò');
    addEmployeeId.value = '';
    members.value = await roleService.getMembers(current.value.id);
    memberCounts.value[current.value.id] = members.value.length;
  } catch (err) { toast.error(apiErr(err, 'Lỗi khi gán vai trò')); } finally { memberBusy.value = false; }
};
const removeMember = async (m) => {
  if (!confirm(`Gỡ vai trò khỏi ${employeeName(m.employee_id)}?`)) return;
  memberBusy.value = true;
  try {
    await roleService.removeMember(m.id);
    toast.success('Đã gỡ vai trò');
    members.value = await roleService.getMembers(current.value.id);
    memberCounts.value[current.value.id] = members.value.length;
  } catch (err) { toast.error(apiErr(err, 'Lỗi khi gỡ vai trò')); } finally { memberBusy.value = false; }
};

const removeRole = async (role) => {
  if (!confirm(`Xóa vai trò "${role.role_name}"?`)) return;
  try {
    await roleService.delete(role.id);
    toast.success('Đã xóa vai trò');
    await loadRoles();
  } catch (err) {
    const v = err?.response?.data?.data?.violations;
    toast.error(Array.isArray(v) && v.length ? v[0] : apiErr(err, 'Lỗi khi xóa vai trò'));
  }
};

onMounted(() => { loadRoles(); loadEmployees(); });
</script>
