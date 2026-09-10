<template>
  <div class="space-y-6">
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-2">
      <div>
        <h1 class="text-xl sm:text-2xl font-bold">Quản trị Platform — Tổ chức (Tenant)</h1>
        <p class="text-muted-foreground mt-1">
          Khu vực dành cho super-admin: xem và khởi tạo các tổ chức (tenant) trên hệ thống.
        </p>
      </div>
      <BaseButton @click="openCreateModal" data-testid="button-add-tenant" class="w-full sm:w-auto">
        + Tạo tổ chức mới
      </BaseButton>
    </div>

    <div v-if="error" class="bg-destructive/10 border border-destructive/20 rounded-lg p-4">
      <p class="text-destructive font-medium">Lỗi kết nối API:</p>
      <p class="text-destructive/80 text-sm mt-1">{{ error }}</p>
    </div>

    <BaseCard title="Danh sách tổ chức">
      <div v-if="loading" class="text-center py-8 text-muted-foreground">Đang tải dữ liệu...</div>
      <div v-else-if="tenants.length === 0" class="text-center py-10 text-muted-foreground">
        Chưa có tổ chức nào.
      </div>
      <BaseTable
        v-else
        :columns="columns"
        :data="tenants"
        test-id="table-tenants"
      >
        <template #cell-name="{ item }">
          <div>
            <span class="font-medium">{{ item.name || '-' }}</span>
            <span v-if="item.code" class="block text-xs text-muted-foreground">{{ item.code }}</span>
          </div>
        </template>

        <template #cell-counts="{ item }">
          <span class="text-sm text-muted-foreground">
            {{ item.legal_entities_count ?? 0 }} pháp nhân · {{ item.employees_count ?? 0 }} NV
          </span>
        </template>

        <template #cell-status="{ item }">
          <span
            :class="[
              'inline-flex items-center px-2 py-0.5 rounded-full text-xs',
              String(item.status).toUpperCase() === 'ACTIVE'
                ? 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300'
                : 'bg-muted text-muted-foreground'
            ]"
          >
            {{ item.status || '-' }}
          </span>
        </template>

        <template #actions="{ item }">
          <button
            @click="openDetail(item)"
            class="px-2 py-1 text-sm rounded hover:bg-accent text-primary"
            title="Xem chi tiết"
            data-testid="button-view-tenant"
          >
            Xem
          </button>
        </template>
      </BaseTable>
    </BaseCard>

    <!-- Provision modal -->
    <BaseModal
      v-model="showCreateModal"
      title="Tạo tổ chức mới"
      data-testid="modal-create-tenant"
    >
      <div class="space-y-4">
        <div class="text-sm text-muted-foreground">
          Hệ thống sẽ tự tạo pháp nhân mặc định, phòng ban, vai trò, loại nghỉ phép và tài khoản quản trị đầu tiên cho tổ chức.
        </div>
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-foreground mb-2">Tên tổ chức <span class="text-destructive">*</span></label>
            <input
              v-model="form.tenant_name"
              type="text"
              class="w-full px-4 py-2.5 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
              placeholder="VD: Công ty TNHH ABC"
              data-testid="input-tenant-name"
            />
          </div>
          <div>
            <label class="block text-sm font-medium text-foreground mb-2">Mã tổ chức</label>
            <input
              v-model="form.tenant_code"
              type="text"
              class="w-full px-4 py-2.5 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
              placeholder="VD: ABC (duy nhất)"
              data-testid="input-tenant-code"
            />
          </div>
        </div>

        <div class="border-t border-border pt-4">
          <p class="text-sm font-medium text-foreground mb-3">Tài khoản quản trị đầu tiên</p>
          <div class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-foreground mb-2">Họ tên <span class="text-destructive">*</span></label>
              <input
                v-model="form.admin_full_name"
                type="text"
                class="w-full px-4 py-2.5 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                data-testid="input-admin-name"
              />
            </div>
            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-foreground mb-2">Email <span class="text-destructive">*</span></label>
                <input
                  v-model="form.admin_email"
                  type="email"
                  class="w-full px-4 py-2.5 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                  data-testid="input-admin-email"
                />
              </div>
              <div>
                <label class="block text-sm font-medium text-foreground mb-2">Mật khẩu <span class="text-destructive">*</span></label>
                <input
                  v-model="form.admin_password"
                  type="text"
                  class="w-full px-4 py-2.5 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                  placeholder="Tối thiểu 6 ký tự"
                  data-testid="input-admin-password"
                />
              </div>
            </div>
          </div>
        </div>
      </div>

      <div v-if="formError" class="mt-4 p-3 bg-destructive/10 border border-destructive/20 rounded-lg">
        <p class="text-destructive text-sm">{{ formError }}</p>
      </div>

      <template #footer>
        <BaseButton variant="outline" @click="closeCreateModal" :disabled="saving">Hủy</BaseButton>
        <BaseButton @click="handleCreate" :disabled="saving" data-testid="button-submit-tenant">
          {{ saving ? 'Đang tạo...' : 'Tạo tổ chức' }}
        </BaseButton>
      </template>
    </BaseModal>

    <!-- Detail modal -->
    <BaseModal
      v-model="showDetailModal"
      :title="detail ? `Chi tiết: ${detail.tenant?.name}` : 'Chi tiết tổ chức'"
      data-testid="modal-tenant-detail"
    >
      <div v-if="detailLoading" class="text-center py-6 text-muted-foreground">Đang tải...</div>
      <div v-else-if="detail" class="space-y-4">
        <div class="grid grid-cols-3 gap-3">
          <div class="rounded-lg border border-border p-3 text-center">
            <p class="text-2xl font-bold">{{ detail.counts?.legal_entities ?? 0 }}</p>
            <p class="text-xs text-muted-foreground">Pháp nhân</p>
          </div>
          <div class="rounded-lg border border-border p-3 text-center">
            <p class="text-2xl font-bold">{{ detail.counts?.employees ?? 0 }}</p>
            <p class="text-xs text-muted-foreground">Nhân viên</p>
          </div>
          <div class="rounded-lg border border-border p-3 text-center">
            <p class="text-2xl font-bold">{{ detail.counts?.departments ?? 0 }}</p>
            <p class="text-xs text-muted-foreground">Phòng ban</p>
          </div>
        </div>
        <div>
          <p class="text-sm font-medium text-foreground mb-2">Pháp nhân / chi nhánh</p>
          <ul class="space-y-1">
            <li
              v-for="le in detail.legal_entities"
              :key="le.id"
              class="flex items-center justify-between text-sm border border-border rounded px-3 py-2"
            >
              <span>{{ le.name }} <span class="text-muted-foreground">({{ le.code || '—' }})</span></span>
              <span class="text-xs text-muted-foreground">{{ le.status }}</span>
            </li>
          </ul>
        </div>
      </div>
      <template #footer>
        <BaseButton variant="outline" @click="showDetailModal = false">Đóng</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import BaseCard from '../components/BaseCard.vue';
import BaseButton from '../components/BaseButton.vue';
import BaseTable from '../components/BaseTable.vue';
import BaseModal from '../components/BaseModal.vue';
import { platformService } from '../services/platformService';
import { useToast } from '../composables/useToast';

const toast = useToast();

const loading = ref(false);
const saving = ref(false);
const error = ref('');
const formError = ref('');

const tenants = ref([]);

const showCreateModal = ref(false);
const showDetailModal = ref(false);
const detail = ref(null);
const detailLoading = ref(false);

const form = ref({
  tenant_name: '',
  tenant_code: '',
  admin_full_name: '',
  admin_email: '',
  admin_password: ''
});

const columns = [
  { key: 'name', label: 'Tổ chức / Mã' },
  { key: 'counts', label: 'Quy mô' },
  { key: 'status', label: 'Trạng thái' }
];

const apiErrorMessage = (err, fallback) =>
  err?.response?.data?.message || err?.response?.data?.error || err?.message || fallback;

const loadTenants = async () => {
  loading.value = true;
  error.value = '';
  try {
    tenants.value = await platformService.getTenants();
  } catch (err) {
    console.error('Error loading tenants:', err);
    error.value = apiErrorMessage(err, 'Không thể tải danh sách tổ chức');
  } finally {
    loading.value = false;
  }
};

const resetForm = () => {
  form.value = { tenant_name: '', tenant_code: '', admin_full_name: '', admin_email: '', admin_password: '' };
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
  formError.value = '';
  if (!form.value.tenant_name.trim()) {
    formError.value = 'Vui lòng nhập tên tổ chức';
    return;
  }
  if (!form.value.admin_full_name.trim() || !form.value.admin_email.trim() || !form.value.admin_password) {
    formError.value = 'Vui lòng nhập đầy đủ thông tin tài khoản quản trị';
    return;
  }
  if (form.value.admin_password.length < 6) {
    formError.value = 'Mật khẩu phải có ít nhất 6 ký tự';
    return;
  }

  saving.value = true;
  try {
    const payload = {
      tenant: {
        name: form.value.tenant_name.trim(),
        code: form.value.tenant_code.trim() || null
      },
      admin: {
        full_name: form.value.admin_full_name.trim(),
        company_email: form.value.admin_email.trim(),
        password: form.value.admin_password
      }
    };
    await platformService.provisionTenant(payload);
    toast.success(`Đã tạo tổ chức "${payload.tenant.name}" và tài khoản quản trị ${payload.admin.company_email}`);
    closeCreateModal();
    await loadTenants();
  } catch (err) {
    console.error('Error provisioning tenant:', err);
    formError.value = apiErrorMessage(err, 'Có lỗi xảy ra khi tạo tổ chức');
  } finally {
    saving.value = false;
  }
};

const openDetail = async (item) => {
  detail.value = null;
  detailLoading.value = true;
  showDetailModal.value = true;
  try {
    detail.value = await platformService.getTenant(item.id);
  } catch (err) {
    console.error('Error loading tenant detail:', err);
    toast.error(apiErrorMessage(err, 'Không thể tải chi tiết tổ chức'));
    showDetailModal.value = false;
  } finally {
    detailLoading.value = false;
  }
};

onMounted(loadTenants);
</script>
