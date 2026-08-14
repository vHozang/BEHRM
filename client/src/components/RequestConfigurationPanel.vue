<template>
  <div class="space-y-5">
    <div class="flex gap-2 overflow-x-auto rounded-xl border border-border bg-card p-2">
      <button
        v-for="tab in tabs"
        :key="tab.value"
        class="whitespace-nowrap rounded-lg px-3 py-2 text-sm font-semibold"
        :class="activeTab === tab.value ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-muted'"
        @click="activeTab = tab.value"
      >{{ tab.label }}</button>
    </div>

    <BaseCard v-if="activeTab === 'types'">
      <div class="mb-4 flex items-center justify-between gap-3">
        <div>
          <h3 class="font-semibold">Loại yêu cầu</h3>
          <p class="text-xs text-muted-foreground">Mỗi loại dùng một luồng phê duyệt chuẩn.</p>
        </div>
        <BaseButton @click="openType()">+ Thêm loại</BaseButton>
      </div>
      <div v-if="loading" class="py-8 text-center text-sm text-muted-foreground">Đang tải...</div>
      <div v-else class="overflow-x-auto rounded-xl border border-border">
        <table class="w-full min-w-[720px] text-sm">
          <thead class="bg-muted/40 text-left text-xs uppercase text-muted-foreground">
            <tr><th class="px-3 py-2.5">Mã</th><th class="px-3 py-2.5">Tên</th><th class="px-3 py-2.5">Nhóm</th><th class="px-3 py-2.5">Luồng</th><th class="px-3 py-2.5">Trạng thái</th><th class="px-3 py-2.5"></th></tr>
          </thead>
          <tbody>
            <tr v-for="item in requestTypes" :key="item.id" class="border-t border-border/70">
              <td class="px-3 py-3 font-mono text-xs">{{ item.request_type_code }}</td>
              <td class="px-3 py-3 font-medium">{{ item.request_type_name }}</td>
              <td class="px-3 py-3">{{ item.category || '—' }}</td>
              <td class="px-3 py-3">{{ item.approval_flow?.flow_name || 'Chưa gán' }}</td>
              <td class="px-3 py-3">{{ statusLabel(item.status) }}</td>
              <td class="px-3 py-3 text-right whitespace-nowrap">
                <button class="text-xs font-semibold text-primary hover:underline" @click="openType(item)">Sửa</button>
                <button class="ml-3 text-xs font-semibold text-destructive hover:underline" @click="removeType(item)">Xóa</button>
              </td>
            </tr>
            <tr v-if="!requestTypes.length"><td colspan="6" class="px-3 py-8 text-center text-muted-foreground">Chưa có loại yêu cầu.</td></tr>
          </tbody>
        </table>
      </div>
    </BaseCard>

    <BaseCard v-else>
      <div class="mb-4 flex items-center justify-between gap-3">
        <div>
          <h3 class="font-semibold">Luồng phê duyệt</h3>
          <p class="text-xs text-muted-foreground">Thứ tự bước được lưu trong một transaction; mỗi bước chọn vai trò hoặc người cụ thể.</p>
        </div>
        <BaseButton @click="openFlow()">+ Thêm luồng</BaseButton>
      </div>
      <div v-if="loading" class="py-8 text-center text-sm text-muted-foreground">Đang tải...</div>
      <div v-else class="space-y-3">
        <div v-for="flow in flows" :key="flow.id" class="rounded-xl border border-border p-4">
          <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
              <p class="font-semibold">{{ flow.flow_name }}</p>
              <p class="text-xs text-muted-foreground">{{ flow.request_type?.request_type_name || 'Chưa gán loại yêu cầu' }} · {{ statusLabel(flow.status) }}</p>
            </div>
            <div class="whitespace-nowrap">
              <button class="text-xs font-semibold text-primary hover:underline" @click="openFlow(flow)">Sửa</button>
              <button class="ml-3 text-xs font-semibold text-destructive hover:underline" @click="removeFlow(flow)">Xóa</button>
            </div>
          </div>
          <ol class="mt-3 flex flex-wrap gap-2">
            <li v-for="step in flow.steps || []" :key="step.id" class="rounded-lg bg-muted px-3 py-2 text-xs">
              <strong>Bước {{ step.step_order }}:</strong> {{ step.approver_name || step.role_name || step.role_code || 'Chưa cấu hình' }}
            </li>
          </ol>
        </div>
        <div v-if="!flows.length" class="rounded-xl border border-dashed border-border py-8 text-center text-sm text-muted-foreground">Chưa có luồng phê duyệt.</div>
      </div>
    </BaseCard>

    <BaseModal v-model="typeModal" :title="typeForm.id ? 'Sửa loại yêu cầu' : 'Thêm loại yêu cầu'">
      <div class="space-y-4">
        <BaseInput v-model="typeForm.request_type_code" label="Mã loại" required />
        <BaseInput v-model="typeForm.request_type_name" label="Tên loại" required />
        <BaseInput v-model="typeForm.category" label="Nhóm" />
        <label class="block text-sm font-medium">Luồng phê duyệt
          <select v-model="typeForm.approval_flow_id" class="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2 font-normal">
            <option value="">Chưa gán</option>
            <option v-for="flow in availableFlows(typeForm.id)" :key="flow.id" :value="String(flow.id)">{{ flow.flow_name }}</option>
          </select>
        </label>
        <label class="block text-sm font-medium">Trạng thái
          <select v-model="typeForm.status" class="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2 font-normal">
            <option value="ACTIVE">Hoạt động</option><option value="INACTIVE">Ngừng dùng</option>
          </select>
        </label>
        <p v-if="formError" class="rounded-lg bg-destructive/10 p-3 text-sm text-destructive">{{ formError }}</p>
      </div>
      <template #footer><BaseButton variant="outline" @click="typeModal = false">Hủy</BaseButton><BaseButton :disabled="saving" @click="saveType">{{ saving ? 'Đang lưu...' : 'Lưu' }}</BaseButton></template>
    </BaseModal>

    <BaseModal v-model="flowModal" :title="flowForm.id ? 'Sửa luồng phê duyệt' : 'Thêm luồng phê duyệt'" size="lg">
      <div class="space-y-4">
        <BaseInput v-model="flowForm.flow_name" label="Tên luồng" required />
        <BaseInput v-model="flowForm.description" label="Mô tả" />
        <label class="block text-sm font-medium">Loại yêu cầu
          <select v-model="flowForm.request_type_id" class="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2 font-normal">
            <option value="">Chưa gán</option>
            <option v-for="item in availableTypes(flowForm.id)" :key="item.id" :value="String(item.id)">{{ item.request_type_name }}</option>
          </select>
        </label>
        <label class="block text-sm font-medium">Trạng thái
          <select v-model="flowForm.status" class="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2 font-normal"><option value="ACTIVE">Hoạt động</option><option value="INACTIVE">Ngừng dùng</option></select>
        </label>

        <div>
          <div class="mb-2 flex items-center justify-between"><p class="text-sm font-semibold">Các bước duyệt</p><BaseButton variant="outline" @click="addStep">+ Bước</BaseButton></div>
          <div v-for="(step, index) in flowForm.steps" :key="index" class="mb-2 grid gap-2 rounded-xl border border-border p-3 sm:grid-cols-[80px_1fr_1fr_auto]">
            <div class="pt-2 text-sm font-semibold">Bước {{ index + 1 }}</div>
            <select v-model="step.approver_role_id" class="rounded-lg border border-input bg-background px-3 py-2 text-sm" @change="step.approver_user_id = ''">
              <option value="">Vai trò duyệt</option><option v-for="role in roles" :key="role.id" :value="String(role.id)">{{ role.role_name }}</option>
            </select>
            <select v-model="step.approver_user_id" class="rounded-lg border border-input bg-background px-3 py-2 text-sm" @change="step.approver_role_id = ''">
              <option value="">Hoặc người cụ thể</option><option v-for="employee in employees" :key="employee.id" :value="String(employee.id)">{{ employee.employee_code }} · {{ employee.full_name }}</option>
            </select>
            <button class="px-2 text-sm text-destructive" :disabled="flowForm.steps.length === 1" @click="removeStep(index)">Xóa</button>
          </div>
        </div>
        <p v-if="formError" class="rounded-lg bg-destructive/10 p-3 text-sm text-destructive">{{ formError }}</p>
      </div>
      <template #footer><BaseButton variant="outline" @click="flowModal = false">Hủy</BaseButton><BaseButton :disabled="saving" @click="saveFlow">{{ saving ? 'Đang lưu...' : 'Lưu' }}</BaseButton></template>
    </BaseModal>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import BaseButton from './BaseButton.vue';
import BaseCard from './BaseCard.vue';
import BaseInput from './BaseInput.vue';
import BaseModal from './BaseModal.vue';
import { requestService } from '../services/requestService';
import { roleService } from '../services/roleService';
import { employeeService } from '../services/employeeService';
import { useToast } from '../composables/useToast';

const toast = useToast();
const tabs = [{ value: 'types', label: 'Loại đơn' }, { value: 'flows', label: 'Luồng duyệt' }];
const activeTab = ref('types');
const loading = ref(false);
const saving = ref(false);
const formError = ref('');
const requestTypes = ref([]);
const flows = ref([]);
const roles = ref([]);
const employees = ref([]);
const typeModal = ref(false);
const flowModal = ref(false);
const typeForm = reactive({ id: null, request_type_code: '', request_type_name: '', category: '', approval_flow_id: '', status: 'ACTIVE' });
const flowForm = reactive({ id: null, flow_name: '', description: '', request_type_id: '', status: 'ACTIVE', steps: [] });

const list = (data) => Array.isArray(data) ? data : (data?.items || data?.data || []);
const statusLabel = (status) => String(status || 'ACTIVE').toUpperCase() === 'ACTIVE' ? 'Hoạt động' : 'Ngừng dùng';
const apiError = (error) => error.response?.data?.message || Object.values(error.response?.data?.data?.errors || {}).flat().join(' ') || 'Không thể lưu dữ liệu';

const load = async () => {
  loading.value = true;
  try {
    const [typesData, flowsData, rolesData, employeesData] = await Promise.all([
      requestService.getTypes(), requestService.getFlows(), roleService.getAll(), employeeService.getLookup(),
    ]);
    requestTypes.value = list(typesData);
    flows.value = list(flowsData);
    roles.value = list(rolesData);
    employees.value = list(employeesData);
  } finally { loading.value = false; }
};

const availableFlows = (typeId) => flows.value.filter((flow) => !flow.request_type || String(flow.request_type.id) === String(typeId));
const availableTypes = (flowId) => requestTypes.value.filter((type) => !type.approval_flow_id || String(type.approval_flow_id) === String(flowId));
const openType = (item = null) => {
  Object.assign(typeForm, { id: item?.id || null, request_type_code: item?.request_type_code || '', request_type_name: item?.request_type_name || '', category: item?.category || '', approval_flow_id: item?.approval_flow_id ? String(item.approval_flow_id) : '', status: item?.status || 'ACTIVE' });
  formError.value = ''; typeModal.value = true;
};
const saveType = async () => {
  if (!typeForm.request_type_code || !typeForm.request_type_name) { formError.value = 'Nhập mã và tên loại yêu cầu'; return; }
  saving.value = true; formError.value = '';
  const payload = { request_type_code: typeForm.request_type_code, request_type_name: typeForm.request_type_name, category: typeForm.category || null, approval_flow_id: typeForm.approval_flow_id ? Number(typeForm.approval_flow_id) : null, status: typeForm.status };
  try { typeForm.id ? await requestService.updateType(typeForm.id, payload) : await requestService.createType(payload); typeModal.value = false; toast.success('Đã lưu loại yêu cầu'); await load(); }
  catch (error) { formError.value = apiError(error); } finally { saving.value = false; }
};
const removeType = async (item) => { if (!confirm(`Xóa loại "${item.request_type_name}"?`)) return; try { await requestService.deleteType(item.id); await load(); } catch (error) { toast.error(apiError(error)); } };

const openFlow = (item = null) => {
  Object.assign(flowForm, { id: item?.id || null, flow_name: item?.flow_name || '', description: item?.description || '', request_type_id: item?.request_type?.id ? String(item.request_type.id) : (item?.request_type_id ? String(item.request_type_id) : ''), status: item?.status || 'ACTIVE', steps: (item?.steps?.length ? item.steps : [{}]).map((step) => ({ approver_role_id: step.approver_role_id ? String(step.approver_role_id) : '', approver_user_id: step.approver_user_id ? String(step.approver_user_id) : '' })) });
  formError.value = ''; flowModal.value = true;
};
const addStep = () => flowForm.steps.push({ approver_role_id: '', approver_user_id: '' });
const removeStep = (index) => { if (flowForm.steps.length > 1) flowForm.steps.splice(index, 1); };
const saveFlow = async () => {
  if (!flowForm.flow_name || flowForm.steps.some((step) => !step.approver_role_id && !step.approver_user_id)) { formError.value = 'Nhập tên luồng và người/vai trò duyệt cho từng bước'; return; }
  saving.value = true; formError.value = '';
  const payload = { flow_name: flowForm.flow_name, description: flowForm.description || null, request_type_id: flowForm.request_type_id ? Number(flowForm.request_type_id) : null, status: flowForm.status, steps: flowForm.steps.map((step) => ({ approver_role_id: step.approver_role_id ? Number(step.approver_role_id) : null, approver_user_id: step.approver_user_id ? Number(step.approver_user_id) : null })) };
  try { flowForm.id ? await requestService.updateFlow(flowForm.id, payload) : await requestService.createFlow(payload); flowModal.value = false; toast.success('Đã lưu luồng phê duyệt'); await load(); }
  catch (error) { formError.value = apiError(error); } finally { saving.value = false; }
};
const removeFlow = async (item) => { if (!confirm(`Xóa luồng "${item.flow_name}"?`)) return; try { await requestService.deleteFlow(item.id); await load(); } catch (error) { toast.error(apiError(error)); } };

onMounted(load);
</script>
