<template>
  <BaseCard>
    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <h3 class="font-semibold text-foreground">{{ title }}</h3>
        <p v-if="description" class="mt-1 text-xs text-muted-foreground">{{ description }}</p>
      </div>
      <div class="flex items-center gap-2">
        <BaseButton variant="outline" :disabled="loading" @click="load">{{ loading ? 'Đang tải...' : 'Tải lại' }}</BaseButton>
        <BaseButton v-if="!readOnly" @click="openCreate">+ Thêm</BaseButton>
      </div>
    </div>

    <div v-if="error" class="mb-4 rounded-lg border border-destructive/30 bg-destructive/10 p-3 text-sm text-destructive">
      {{ error }}
    </div>
    <div v-if="loading && !items.length" class="py-10 text-center text-sm text-muted-foreground">Đang tải dữ liệu...</div>
    <div v-else-if="!items.length" class="rounded-xl border border-dashed border-border py-10 text-center text-sm text-muted-foreground">
      Chưa có dữ liệu.
    </div>
    <div v-else class="overflow-x-auto rounded-xl border border-border">
      <table class="w-full min-w-[680px] text-sm">
        <thead class="bg-muted/40 text-left text-xs uppercase text-muted-foreground">
          <tr>
            <th v-for="column in columns" :key="column.key" class="px-3 py-2.5">{{ column.label }}</th>
            <th v-if="!readOnly" class="px-3 py-2.5 text-right">Thao tác</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in items" :key="item.id" class="border-t border-border/70 align-top">
            <td v-for="column in columns" :key="column.key" class="px-3 py-3">
              <button v-if="column.downloadPath && valueAt(item, column.key)" class="text-xs font-semibold text-primary hover:underline" @click="downloadFile(item, column)">{{ column.downloadLabel || 'Tải tệp' }}</button>
              <span v-else :class="column.mono ? 'font-mono text-xs' : ''">{{ displayValue(item, column) }}</span>
            </td>
            <td v-if="!readOnly" class="px-3 py-3 text-right whitespace-nowrap">
              <button class="text-xs font-semibold text-primary hover:underline" @click="openEdit(item)">Sửa</button>
              <button v-if="allowDelete" class="ml-3 text-xs font-semibold text-destructive hover:underline" @click="remove(item)">Xóa</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="pagination.last_page > 1" class="mt-4 flex items-center justify-between text-sm">
      <span class="text-muted-foreground">Trang {{ pagination.current_page }}/{{ pagination.last_page }} · {{ pagination.total }} bản ghi</span>
      <div class="flex gap-2">
        <BaseButton variant="outline" :disabled="pagination.current_page <= 1 || loading" @click="goPage(pagination.current_page - 1)">Trước</BaseButton>
        <BaseButton variant="outline" :disabled="pagination.current_page >= pagination.last_page || loading" @click="goPage(pagination.current_page + 1)">Sau</BaseButton>
      </div>
    </div>

    <BaseModal v-model="modalOpen" :title="editingId ? `Sửa ${title}` : `Thêm ${title}`" size="md">
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <label v-for="field in fields" :key="field.key" :class="field.full ? 'sm:col-span-2' : ''" class="block text-sm font-medium">
          <input v-if="field.type === 'hidden'" v-model="form[field.key]" type="hidden" />
          <template v-else>
          <span>{{ field.label }} <span v-if="field.required" class="text-destructive">*</span></span>
          <RemoteEmployeeSelect
            v-if="field.type === 'employee'"
            v-model="form[field.key]"
            :initial-label="form[field.initialLabelKey || 'employee_label'] || ''"
            :legal-entity-id="field.legalEntityId || ''"
          />
          <ResourceSelect
            v-else-if="field.type === 'resource'"
            v-model="form[field.key]"
            :resource="field.resource"
            :label-key="field.labelKey"
            :code-key="field.codeKey || ''"
            :placeholder="field.placeholder || '-- Chọn --'"
            :params="field.params || {}"
          />
          <input
            v-else-if="field.type === 'file'"
            type="file"
            :accept="field.accept || '.pdf,.jpg,.jpeg,.png'"
            class="mt-1 block w-full rounded-lg border border-input bg-background px-3 py-2 font-normal"
            @change="form[field.key] = $event.target.files?.[0] || null"
          />
          <textarea
            v-else-if="field.type === 'textarea'"
            v-model="form[field.key]"
            :rows="field.rows || 3"
            :placeholder="field.placeholder || ''"
            class="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2 font-normal"
          />
          <select
            v-else-if="field.type === 'select'"
            v-model="form[field.key]"
            class="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2 font-normal"
          >
            <option value="">-- Chọn --</option>
            <option v-for="option in field.options || []" :key="String(option.value)" :value="String(option.value)">{{ option.label }}</option>
          </select>
          <span v-else-if="field.type === 'checkbox'" class="mt-2 flex items-center gap-2 rounded-lg border border-input px-3 py-2 font-normal">
            <input v-model="form[field.key]" type="checkbox" class="h-4 w-4 rounded" />
            {{ field.checkboxLabel || 'Có' }}
          </span>
          <input
            v-else
            v-model="form[field.key]"
            :type="field.type || 'text'"
            :min="field.min"
            :max="field.max"
            :step="field.step"
            :placeholder="field.placeholder || ''"
            class="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2 font-normal"
          />
          <span v-if="field.help" class="mt-1 block text-xs font-normal text-muted-foreground">{{ field.help }}</span>
          </template>
        </label>
      </div>
      <div v-if="formError" class="mt-4 rounded-lg border border-destructive/30 bg-destructive/10 p-3 text-sm text-destructive">{{ formError }}</div>
      <template #footer>
        <BaseButton variant="outline" :disabled="saving" @click="modalOpen = false">Hủy</BaseButton>
        <BaseButton :disabled="saving" @click="save">{{ saving ? 'Đang lưu...' : 'Lưu' }}</BaseButton>
      </template>
    </BaseModal>
  </BaseCard>
</template>

<script setup>
import { onMounted, reactive, ref, watch } from 'vue';
import axiosClient from '../services/axiosClient';
import BaseButton from './BaseButton.vue';
import BaseCard from './BaseCard.vue';
import BaseModal from './BaseModal.vue';
import RemoteEmployeeSelect from './RemoteEmployeeSelect.vue';
import ResourceSelect from './ResourceSelect.vue';
import { useToast } from '../composables/useToast';

const props = defineProps({
  resource: { type: String, required: true },
  title: { type: String, required: true },
  description: { type: String, default: '' },
  columns: { type: Array, default: () => [] },
  fields: { type: Array, default: () => [] },
  defaults: { type: Object, default: () => ({}) },
  params: { type: Object, default: () => ({}) },
  readOnly: { type: Boolean, default: false },
  allowDelete: { type: Boolean, default: true },
  perPage: { type: Number, default: 25 },
});

const emit = defineEmits(['changed']);
const toast = useToast();
const items = ref([]);
const loading = ref(false);
const saving = ref(false);
const error = ref('');
const formError = ref('');
const modalOpen = ref(false);
const editingId = ref(null);
const form = reactive({});
const pagination = reactive({ current_page: 1, last_page: 1, total: 0 });

const normalizeList = (response) => {
  const list = Array.isArray(response.data) ? response.data : (response.pageData?.items || response.data?.items || []);
  const page = response.pagination || response.pageData?.pagination || {};
  return { list, page };
};

const load = async () => {
  loading.value = true;
  error.value = '';
  try {
    const response = await axiosClient.get(`/${props.resource}`, {
      params: { ...props.params, page: pagination.current_page, per_page: props.perPage },
    });
    const { list, page } = normalizeList(response);
    items.value = list;
    pagination.current_page = Number(page.current_page || pagination.current_page || 1);
    pagination.last_page = Number(page.last_page || 1);
    pagination.total = Number(page.total || list.length);
  } catch (err) {
    error.value = err.response?.data?.message || `Không tải được ${props.title.toLowerCase()}`;
  } finally {
    loading.value = false;
  }
};

const resetForm = (source = {}) => {
  for (const key of Object.keys(form)) delete form[key];
  Object.assign(form, props.defaults, source);
  for (const field of props.fields) {
    if (form[field.key] === undefined || form[field.key] === null) {
      form[field.key] = field.type === 'checkbox' ? false : '';
    } else if (field.type === 'select') {
      form[field.key] = String(form[field.key]);
    } else if (field.cast === 'json-array' && Array.isArray(form[field.key])) {
      form[field.key] = form[field.key].join('\n');
    }
  }
  formError.value = '';
};

const openCreate = () => {
  editingId.value = null;
  resetForm();
  modalOpen.value = true;
};

const openEdit = async (item) => {
  editingId.value = item.id;
  formError.value = '';
  try {
    const response = await axiosClient.get(`/${props.resource}/${item.id}`);
    resetForm(response.data || item);
  } catch {
    resetForm(item);
  }
  modalOpen.value = true;
};

const payload = () => {
  const data = {};
  for (const field of props.fields) {
    if (field.type === 'file') continue;
    let value = form[field.key];
    if (field.cast === 'number') value = value === '' ? null : Number(value);
    if (field.cast === 'json-array') {
      value = String(value || '').split(/[\n,]/).map((item) => item.trim()).filter(Boolean);
    }
    if (field.type === 'checkbox') value = Boolean(value);
    data[field.key] = value === '' && field.nullable ? null : value;
  }
  return data;
};

const save = async () => {
  for (const field of props.fields) {
    if (field.required && (form[field.key] === '' || form[field.key] === null || form[field.key] === undefined)) {
      formError.value = `Vui lòng nhập ${field.label.toLowerCase()}`;
      return;
    }
  }
  saving.value = true;
  formError.value = '';
  try {
    const response = editingId.value
      ? await axiosClient.patch(`/${props.resource}/${editingId.value}`, payload())
      : await axiosClient.post(`/${props.resource}`, payload());
    const recordId = editingId.value || response.data?.id;
    for (const field of props.fields.filter((candidate) => candidate.type === 'file' && form[candidate.key])) {
      const uploadPath = String(field.uploadPath || '').replace('{id}', String(recordId));
      if (!uploadPath || !recordId) continue;
      const body = new FormData();
      body.append('file', form[field.key]);
      // endpoint-audit: POST /employee-record-files/{resource}/{id}/{slot}
      await axiosClient.post(uploadPath, body);
    }
    toast.success(editingId.value ? 'Đã cập nhật dữ liệu' : 'Đã thêm dữ liệu');
    modalOpen.value = false;
    await load();
    emit('changed');
  } catch (err) {
    const errors = err.response?.data?.data?.errors;
    formError.value = errors
      ? Object.values(errors).flat().join(' ')
      : (err.response?.data?.message || 'Không thể lưu dữ liệu');
  } finally {
    saving.value = false;
  }
};

const remove = async (item) => {
  if (!window.confirm(`Xóa bản ghi "${displayValue(item, props.columns[1] || props.columns[0])}"?`)) return;
  try {
    await axiosClient.delete(`/${props.resource}/${item.id}`);
    toast.success('Đã xóa dữ liệu');
    if (items.value.length === 1 && pagination.current_page > 1) pagination.current_page--;
    await load();
    emit('changed');
  } catch (err) {
    const violations = err.response?.data?.data?.violations;
    toast.error(Array.isArray(violations) && violations.length
      ? violations[0]
      : (err.response?.data?.message || 'Không thể xóa dữ liệu'));
  }
};

const displayValue = (item, column = {}) => {
  if (!item || !column) return '';
  const value = String(column.key || '').split('.').reduce((current, key) => current?.[key], item);
  if (typeof column.format === 'function') return column.format(value, item);
  const field = props.fields.find((candidate) => candidate.key === column.key);
  if (field?.type === 'select') {
    return field.options?.find((option) => String(option.value) === String(value))?.label ?? value ?? '—';
  }
  if (typeof value === 'boolean') return value ? 'Có' : 'Không';
  return value === null || value === undefined || value === '' ? '—' : value;
};

const valueAt = (item, key) => String(key || '').split('.').reduce((current, part) => current?.[part], item);

const downloadFile = async (item, column) => {
  try {
    const path = String(column.downloadPath).replace('{id}', String(item.id));
    // endpoint-audit: GET /employee-record-files/{resource}/{id}/{slot}
    const response = await axiosClient.get(path, { responseType: 'blob' });
    const url = URL.createObjectURL(response.data);
    const link = document.createElement('a');
    link.href = url;
    link.download = column.filename ? column.filename(item) : `${props.resource}-${item.id}`;
    link.click();
    URL.revokeObjectURL(url);
  } catch (err) {
    toast.error(err.response?.data?.message || 'Không tải được tệp riêng tư');
  }
};

const goPage = (page) => {
  pagination.current_page = page;
  load();
};

watch(() => props.params, () => { pagination.current_page = 1; load(); }, { deep: true });
onMounted(load);
defineExpose({ reload: load });
</script>
