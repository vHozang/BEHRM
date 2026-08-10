<template>
  <div class="space-y-6">
    <div>
      <h1 class="text-xl font-bold sm:text-2xl">Cấu hình hệ thống</h1>
      <p class="mt-1 text-muted-foreground">Quản lý chính sách nghiệp vụ, kết nối AI Resume và mẫu thông báo toàn công ty.</p>
    </div>

    <div class="flex gap-1 overflow-x-auto rounded-xl border border-border bg-muted/30 p-1">
      <button
        v-for="tab in tabs"
        :key="tab.id"
        type="button"
        class="whitespace-nowrap rounded-lg px-4 py-2 text-sm font-medium transition-colors"
        :class="activeTab === tab.id ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'"
        @click="activeTab = tab.id"
      >
        {{ tab.label }}
      </button>
    </div>

    <template v-if="activeTab === 'business'">
      <div v-if="error" class="rounded-lg border border-destructive/20 bg-destructive/10 p-4 text-sm text-destructive">{{ error }}</div>
      <div v-if="loading" class="py-8 text-center text-muted-foreground">Đang tải cấu hình...</div>
      <template v-else>
        <BaseCard v-for="group in groups" :key="group.key" :title="group.label">
          <div class="space-y-4">
            <div v-for="item in group.items" :key="item.key" class="grid grid-cols-1 items-start gap-3 sm:grid-cols-3">
              <label class="text-sm font-medium text-foreground sm:pt-2">{{ item.label }}</label>
              <div class="sm:col-span-2">
                <select
                  v-if="item.type === 'select'"
                  v-model="values[item.key]"
                  class="form-control"
                >
                  <option v-for="option in (item.options || [])" :key="option.value" :value="option.value">
                    {{ option.label }}
                  </option>
                </select>
                <BaseMoneyInput
                  v-else-if="isMoneySetting(item)"
                  v-model="values[item.key]"
                  :separator="values['display.money_group_separator']"
                />
                <input
                  v-else-if="item.type === 'int' || item.type === 'float'"
                  v-model="values[item.key]"
                  type="number"
                  :step="item.type === 'float' ? '0.01' : '1'"
                  :min="item.min"
                  :max="item.max"
                  class="form-control"
                />
                <label v-else-if="item.type === 'bool'" class="inline-flex items-center gap-2">
                  <input v-model="values[item.key]" type="checkbox" class="h-4 w-4 rounded border-input text-primary" />
                  <span class="text-sm text-muted-foreground">Bật</span>
                </label>
                <div v-else-if="item.type === 'modules'" class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                  <label v-for="opt in (item.options || [])" :key="opt.key" class="flex items-center gap-2 rounded-lg border border-border p-2">
                    <input v-model="values[item.key]" type="checkbox" :value="opt.key" class="h-4 w-4 rounded border-input text-primary" />
                    <span class="text-sm">{{ opt.label }}</span>
                  </label>
                </div>
                <textarea v-else-if="item.type === 'list'" v-model="values[item.key]" rows="8" class="form-control font-mono" placeholder="Mỗi dòng một mục"></textarea>
                <textarea v-else-if="item.type === 'json'" v-model="values[item.key]" rows="6" class="form-control font-mono text-xs"></textarea>
                <input v-else v-model="values[item.key]" type="text" class="form-control" />
                <button
                  v-if="item.key === 'attendance.geofence_lat'"
                  type="button"
                  :disabled="locating"
                  class="mt-2 rounded-lg border border-border px-3 py-1.5 text-xs font-medium hover:bg-muted disabled:opacity-50"
                  @click="useCurrentLocation"
                >
                  {{ locating ? 'Đang lấy vị trí...' : 'Dùng vị trí hiện tại làm tọa độ văn phòng' }}
                </button>
                <p v-if="item.key === 'attendance.enforce_mode'" class="mt-1 text-xs text-muted-foreground">
                  <code>off</code> = tắt · <code>flag</code> = đánh dấu · <code>block</code> = chặn check-in ngoài phạm vi.
                </p>
                <p v-if="item.key === 'attendance.device_upload_delay_minutes'" class="mt-1 text-xs text-muted-foreground">
                  Mặc định 15 phút. “Đồng bộ ngay” sẽ bỏ qua thời gian chờ.
                </p>
              </div>
            </div>
          </div>
        </BaseCard>

        <div class="flex justify-end gap-2">
          <BaseButton variant="outline" :disabled="saving" @click="load">Khôi phục</BaseButton>
          <BaseButton :disabled="saving" data-testid="button-save-settings" @click="saveAll">
            {{ saving ? 'Đang lưu...' : 'Lưu cấu hình' }}
          </BaseButton>
        </div>
      </template>
    </template>

    <template v-else-if="activeTab === 'integrations'">
      <BaseCard title="AI Resume / AutoRecruit">
        <div class="space-y-4">
          <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <div class="flex items-center gap-2">
                <span class="h-2.5 w-2.5 rounded-full" :class="integrationHealth?.available ? 'bg-emerald-500' : 'bg-amber-500'"></span>
                <p class="font-semibold">{{ integrationHealth?.available ? 'Dịch vụ đang hoạt động' : 'Chưa kết nối được AI Resume' }}</p>
              </div>
              <p class="mt-1 text-sm text-muted-foreground">Endpoint đang chọn: <code>{{ integrationHealth?.selected_url || 'Chưa có' }}</code></p>
            </div>
            <BaseButton variant="outline" :disabled="integrationLoading" @click="loadIntegrationHealth">
              {{ integrationLoading ? 'Đang kiểm tra...' : 'Kiểm tra lại' }}
            </BaseButton>
          </div>

          <div v-if="integrationError" class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800">
            {{ integrationError }}. Các trang khác vẫn hoạt động bình thường.
          </div>
          <div v-if="integrationLoading && !integrationHealth" class="py-6 text-center text-sm text-muted-foreground">Đang kiểm tra Mac và Windows...</div>
          <div v-else class="grid gap-3 md:grid-cols-2">
            <div
              v-for="check in integrationChecks"
              :key="check.url"
              class="rounded-xl border p-4"
              :class="check.healthy ? 'border-emerald-300 bg-emerald-50/50' : 'border-border bg-muted/20'"
            >
              <div class="flex items-start justify-between gap-3">
                <div>
                  <p class="font-semibold">{{ integrationLabel(check.url) }}</p>
                  <code class="mt-1 block break-all text-xs text-muted-foreground">{{ check.url }}</code>
                </div>
                <span class="rounded-full px-2 py-1 text-xs font-semibold" :class="check.healthy ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700'">
                  {{ check.healthy ? 'Online' : 'Offline' }}
                </span>
              </div>
              <div class="mt-3 grid grid-cols-2 gap-2 text-sm">
                <p><span class="text-muted-foreground">Latency:</span> {{ check.latency_ms ?? '—' }} ms</p>
                <p><span class="text-muted-foreground">HTTP:</span> {{ check.status ?? 'Timeout' }}</p>
              </div>
              <p v-if="check.error" class="mt-2 text-xs text-red-600">{{ check.error }}</p>
            </div>
          </div>
        </div>
      </BaseCard>
    </template>

    <template v-else>
      <BaseCard title="Mẫu thông báo toàn công ty">
        <div class="space-y-4">
          <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-sm text-muted-foreground">Chỉnh sửa nhiều mẫu rồi lưu một lần. Không áp dụng cho tùy chọn cá nhân.</p>
            <BaseButton variant="outline" @click="addNotificationTemplate">+ Thêm mẫu</BaseButton>
          </div>
          <div v-if="notificationsLoading" class="py-8 text-center text-muted-foreground">Đang tải mẫu thông báo...</div>
          <div v-else-if="!notificationTemplates.length" class="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">Chưa có mẫu thông báo.</div>
          <div v-else class="space-y-4">
            <div v-for="(item, index) in notificationTemplates" :key="item.local_key" class="rounded-xl border border-border p-4">
              <div class="grid gap-4 lg:grid-cols-2">
                <label class="field-label">Loại thông báo <span class="text-destructive">*</span><input v-model.trim="item.notification_type" class="form-control mt-1 font-normal" placeholder="candidate_applied" /></label>
                <label class="field-label">Người nhận<input v-model="item.recipients" class="form-control mt-1 font-normal" placeholder="HR, MANAGER hoặc email" /></label>
                <label class="field-label lg:col-span-2">Tiêu đề<input v-model="item.template_subject" class="form-control mt-1 font-normal" /></label>
                <label class="field-label lg:col-span-2">Nội dung<textarea v-model="item.template_body" rows="5" class="form-control mt-1 font-normal"></textarea></label>
              </div>
              <div class="mt-3 flex items-center justify-between gap-3">
                <label class="inline-flex items-center gap-2 text-sm"><input v-model="item.status" type="checkbox" class="h-4 w-4 rounded border-input text-primary" />{{ item.status ? 'Đang bật' : 'Đang tắt' }}</label>
                <button type="button" class="text-sm font-medium text-destructive hover:underline" @click="removeNotificationTemplate(index)">Xóa mẫu</button>
              </div>
            </div>
          </div>
          <div class="flex justify-end gap-2">
            <BaseButton variant="outline" :disabled="notificationsSaving" @click="loadNotificationTemplates">Khôi phục</BaseButton>
            <BaseButton :disabled="notificationsSaving" @click="saveNotificationTemplates">{{ notificationsSaving ? 'Đang lưu...' : 'Lưu mẫu thông báo' }}</BaseButton>
          </div>
        </div>
      </BaseCard>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import BaseButton from '../components/BaseButton.vue';
import BaseCard from '../components/BaseCard.vue';
import BaseMoneyInput from '../components/BaseMoneyInput.vue';
import { useToast } from '../composables/useToast';
import { setMoneyGroupSeparator } from '../composables/useMoneyPreferences';
import { settingsService } from '../services/settingsService';
import { integrationEndpointLabel, notificationStatusEnabled } from '../utils/managementUi';

const toast = useToast();
const tabs = [
  { id: 'business', label: 'Nghiệp vụ' },
  { id: 'integrations', label: 'Tích hợp' },
  { id: 'notifications', label: 'Thông báo & Email' }
];
const activeTab = ref('business');
const loading = ref(false);
const saving = ref(false);
const locating = ref(false);
const error = ref('');
const groups = ref([]);
const values = reactive({});
const typeByKey = {};
const moneySettingKeys = new Set([
  'payroll.personal_deduction',
  'payroll.dependent_deduction',
  'payroll.base_salary',
  'payroll.region_min_wage',
  'payroll.monthly_budget'
]);
const isMoneySetting = (item) => item.type === 'int' && moneySettingKeys.has(item.key);

const integrationLoading = ref(false);
const integrationHealth = ref(null);
const integrationError = ref('');
const integrationChecks = computed(() => integrationHealth.value?.checks || []);

const notificationsLoading = ref(false);
const notificationsSaving = ref(false);
const notificationTemplates = ref([]);
const deletedNotificationIds = ref([]);
let notificationSequence = 0;

const useCurrentLocation = () => {
  if (!('geolocation' in navigator)) return toast.error('Trình duyệt không hỗ trợ định vị');
  locating.value = true;
  navigator.geolocation.getCurrentPosition(
    (pos) => {
      values['attendance.geofence_lat'] = pos.coords.latitude.toFixed(6);
      values['attendance.geofence_lng'] = pos.coords.longitude.toFixed(6);
      locating.value = false;
      toast.success('Đã điền tọa độ — nhớ lưu cấu hình');
    },
    () => { locating.value = false; toast.error('Không lấy được vị trí'); },
    { enableHighAccuracy: true, timeout: 8000 }
  );
};

const load = async () => {
  loading.value = true;
  error.value = '';
  try {
    groups.value = await settingsService.getCatalog();
    groups.value.forEach((group) => group.items.forEach((item) => {
      typeByKey[item.key] = item.type;
      if (item.type === 'modules') values[item.key] = Array.isArray(item.value) ? [...item.value] : [];
      else if (item.type === 'list') values[item.key] = Array.isArray(item.value) ? item.value.join('\n') : '';
      else if (item.type === 'json') values[item.key] = item.value != null ? JSON.stringify(item.value, null, 2) : '';
      else if (item.type === 'bool') values[item.key] = item.value === true || item.value === 'true' || item.value === 1;
      else values[item.key] = item.value ?? '';
    }));
  } catch (err) {
    error.value = err?.response?.data?.message || 'Không thể tải cấu hình';
  } finally {
    loading.value = false;
  }
};

const saveAll = async () => {
  saving.value = true;
  try {
    const items = [];
    let jsonError = '';
    Object.keys(values).forEach((key) => {
      const type = typeByKey[key];
      let value = values[key];
      if (type === 'modules') value = Array.isArray(value) ? value : [];
      else if (type === 'list') value = String(value).split('\n').map((line) => line.trim()).filter(Boolean);
      else if (type === 'json') {
        if (String(value).trim() === '') return;
        try { value = JSON.parse(value); } catch { jsonError = key; return; }
      } else if (type === 'bool') value = !!value;
      else if (type === 'int' || type === 'float') {
        if (value === '' || value === null) return;
        value = type === 'int' ? parseInt(value, 10) : parseFloat(value);
      }
      items.push({ key, value });
    });
    if (jsonError) return toast.error(`JSON không hợp lệ ở mục: ${jsonError}`);
    await settingsService.save(items);
    setMoneyGroupSeparator(values['display.money_group_separator']);
    toast.success('Đã lưu cấu hình');
    await load();
  } catch (err) {
    toast.error(err?.response?.data?.message || 'Có lỗi khi lưu cấu hình');
  } finally {
    saving.value = false;
  }
};

const integrationLabel = integrationEndpointLabel;

const loadIntegrationHealth = async () => {
  integrationLoading.value = true;
  integrationError.value = '';
  try {
    integrationHealth.value = await settingsService.getAutoRecruitHealth();
  } catch (err) {
    integrationHealth.value = err?.response?.data?.data || { available: false, selected_url: null, checks: [] };
    integrationError.value = err?.response?.data?.message || 'AI Resume không phản hồi hoặc hết thời gian chờ';
  } finally {
    integrationLoading.value = false;
  }
};

const normalizeNotificationTemplate = (item = {}) => ({
  id: item.id || null,
  local_key: item.id ? `notification-${item.id}` : `new-notification-${++notificationSequence}`,
  notification_type: item.notification_type || '',
  recipients: item.recipients || '',
  template_subject: item.template_subject || '',
  template_body: item.template_body || '',
  status: notificationStatusEnabled(item.status)
});

const loadNotificationTemplates = async () => {
  notificationsLoading.value = true;
  try {
    notificationTemplates.value = (await settingsService.getNotificationTemplates()).map(normalizeNotificationTemplate);
    deletedNotificationIds.value = [];
  } catch (err) {
    toast.error(err?.response?.data?.message || 'Không thể tải mẫu thông báo');
  } finally {
    notificationsLoading.value = false;
  }
};

const addNotificationTemplate = () => notificationTemplates.value.push(normalizeNotificationTemplate({ status: true }));
const removeNotificationTemplate = (index) => {
  const [removed] = notificationTemplates.value.splice(index, 1);
  if (removed?.id) deletedNotificationIds.value.push(removed.id);
};

const saveNotificationTemplates = async () => {
  if (notificationTemplates.value.some((item) => !item.notification_type.trim())) return toast.error('Loại thông báo không được để trống');
  notificationsSaving.value = true;
  try {
    const payload = notificationTemplates.value.map(({ local_key, ...item }) => item);
    notificationTemplates.value = (await settingsService.saveNotificationTemplates(payload, deletedNotificationIds.value)).map(normalizeNotificationTemplate);
    deletedNotificationIds.value = [];
    toast.success('Đã lưu mẫu thông báo');
  } catch (err) {
    toast.error(err?.response?.data?.message || 'Không thể lưu mẫu thông báo');
  } finally {
    notificationsSaving.value = false;
  }
};

watch(activeTab, (tab) => {
  if (tab === 'integrations' && !integrationHealth.value && !integrationLoading.value) loadIntegrationHealth();
  if (tab === 'notifications' && !notificationTemplates.value.length && !notificationsLoading.value) loadNotificationTemplates();
});

onMounted(load);
</script>

<style scoped>
.form-control {
  width: 100%;
  border-radius: 0.5rem;
  border: 1px solid hsl(var(--input));
  background: hsl(var(--background));
  padding: 0.5rem 0.75rem;
  color: hsl(var(--foreground));
  font-size: 0.875rem;
}
.form-control:focus { outline: 2px solid hsl(var(--ring)); outline-offset: 1px; }
.field-label { font-size: 0.875rem; font-weight: 500; }
</style>
