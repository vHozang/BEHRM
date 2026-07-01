<template>
  <div class="space-y-6">
    <div class="mb-2">
      <h1 class="text-xl sm:text-2xl font-bold">Nhật ký hệ thống</h1>
      <p class="text-muted-foreground mt-1">
        Lịch sử thay đổi dữ liệu (tạo / sửa / xóa) — ai làm, lúc nào, thay đổi gì.
      </p>
    </div>

    <div v-if="error" class="bg-destructive/10 border border-destructive/20 rounded-lg p-4">
      <p class="text-destructive font-medium">Lỗi kết nối API:</p>
      <p class="text-destructive/80 text-sm mt-1">{{ error }}</p>
    </div>

    <BaseCard>
      <div class="grid grid-cols-1 sm:grid-cols-4 gap-4 items-end">
        <div>
          <label class="block text-sm font-medium text-foreground mb-2">Hành động</label>
          <select
            v-model="filters.action"
            class="w-full px-4 py-2.5 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
            data-testid="select-action"
          >
            <option v-for="opt in actionOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-foreground mb-2">Bảng dữ liệu</label>
          <input
            v-model="filters.table_name"
            type="text"
            class="w-full px-4 py-2.5 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
            placeholder="VD: holidays, dependents"
            data-testid="input-table"
            @keyup.enter="applyFilters"
          />
        </div>
        <div class="sm:col-span-2 flex gap-2">
          <BaseButton variant="outline" @click="applyFilters" :disabled="loading">
            {{ loading ? 'Đang tải...' : 'Áp dụng' }}
          </BaseButton>
          <BaseButton variant="ghost" @click="resetFilters" :disabled="loading">Xóa lọc</BaseButton>
        </div>
      </div>
    </BaseCard>

    <BaseCard title="Nhật ký thay đổi">
      <div v-if="loading" class="text-center py-8 text-muted-foreground">Đang tải dữ liệu...</div>
      <div v-else-if="logs.length === 0" class="text-center py-10 text-muted-foreground">
        Chưa có bản ghi nhật ký nào.
      </div>
      <div v-else class="space-y-2">
        <div
          v-for="log in logs"
          :key="log.id"
          class="border border-border rounded-lg overflow-hidden"
          data-testid="audit-row"
        >
          <button
            class="w-full flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3 text-left hover:bg-accent/50"
            @click="toggle(log.id)"
          >
            <span :class="['inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium', actionClass(log.action)]">
              {{ actionLabel(log.action) }}
            </span>
            <span class="font-medium text-sm">{{ log.table_name }}<span class="text-muted-foreground">#{{ log.record_id ?? '?' }}</span></span>
            <span class="text-sm text-muted-foreground">· {{ log.actor_name || 'Hệ thống' }}</span>
            <span class="text-xs text-muted-foreground ml-auto">{{ formatDateTime(log.created_at) }}</span>
            <svg
              v-if="hasChanges(log)"
              class="w-4 h-4 text-muted-foreground transition-transform"
              :class="{ 'rotate-180': expanded[log.id] }"
              fill="none" stroke="currentColor" viewBox="0 0 24 24"
            >
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
            </svg>
          </button>

          <div v-if="expanded[log.id] && hasChanges(log)" class="border-t border-border bg-muted/30 px-4 py-3">
            <table class="w-full text-sm">
              <thead>
                <tr class="text-xs text-muted-foreground">
                  <th class="text-left font-medium pb-1 pr-4">Trường</th>
                  <th class="text-left font-medium pb-1 pr-4">Trước</th>
                  <th class="text-left font-medium pb-1">Sau</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="field in changedFields(log)" :key="field" class="align-top border-t border-border/50">
                  <td class="py-1 pr-4 font-medium">{{ field }}</td>
                  <td class="py-1 pr-4 text-red-600 dark:text-red-400 break-all">{{ displayValue(log.changes?.before?.[field]) }}</td>
                  <td class="py-1 text-green-600 dark:text-green-400 break-all">{{ displayValue(log.changes?.after?.[field]) }}</td>
                </tr>
              </tbody>
            </table>
            <p v-if="log.ip_address" class="text-xs text-muted-foreground mt-2">IP: {{ log.ip_address }}</p>
          </div>
        </div>
      </div>

      <div v-if="pagination && pagination.last_page > 1" class="flex items-center justify-between mt-4 pt-4 border-t border-border">
        <span class="text-sm text-muted-foreground">
          Trang {{ pagination.current_page }} / {{ pagination.last_page }} · {{ pagination.total }} bản ghi
        </span>
        <div class="flex gap-2">
          <BaseButton variant="outline" :disabled="loading || page <= 1" @click="goTo(page - 1)">Trước</BaseButton>
          <BaseButton variant="outline" :disabled="loading || page >= pagination.last_page" @click="goTo(page + 1)">Sau</BaseButton>
        </div>
      </div>
    </BaseCard>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import BaseCard from '../components/BaseCard.vue';
import BaseButton from '../components/BaseButton.vue';
import { auditLogService } from '../services/auditLogService';

const loading = ref(false);
const error = ref('');
const logs = ref([]);
const pagination = ref(null);
const page = ref(1);
const expanded = reactive({});

const filters = ref({ action: '', table_name: '' });

const actionOptions = [
  { label: 'Tất cả', value: '' },
  { label: 'Tạo mới', value: 'create' },
  { label: 'Cập nhật', value: 'update' },
  { label: 'Xóa', value: 'delete' }
];

const ACTION_LABELS = { create: 'Tạo mới', update: 'Cập nhật', delete: 'Xóa' };
const actionLabel = (a) => ACTION_LABELS[String(a || '').toLowerCase()] || a || '-';
const actionClass = (a) => {
  switch (String(a || '').toLowerCase()) {
    case 'create': return 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300';
    case 'update': return 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300';
    case 'delete': return 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300';
    default: return 'bg-muted text-muted-foreground';
  }
};

const hasChanges = (log) => {
  const c = log?.changes;
  return !!c && (c.before || c.after);
};

const changedFields = (log) => {
  const c = log?.changes || {};
  const keys = new Set([...Object.keys(c.before || {}), ...Object.keys(c.after || {})]);
  return Array.from(keys);
};

const displayValue = (v) => {
  if (v === null || v === undefined || v === '') return '∅';
  if (typeof v === 'object') return JSON.stringify(v);
  return String(v);
};

const formatDateTime = (dt) => {
  if (!dt) return '-';
  const d = new Date(dt);
  return Number.isNaN(d.getTime()) ? dt : d.toLocaleString('vi-VN');
};

const toggle = (id) => {
  expanded[id] = !expanded[id];
};

const load = async () => {
  loading.value = true;
  error.value = '';
  try {
    const params = { page: page.value };
    if (filters.value.action) params.action = filters.value.action;
    if (filters.value.table_name.trim()) params.table_name = filters.value.table_name.trim();
    const { items, pagination: pg } = await auditLogService.getAll(params);
    logs.value = items;
    pagination.value = pg;
  } catch (err) {
    console.error('Error loading audit logs:', err);
    error.value = err?.response?.data?.message || err?.message || 'Không thể tải nhật ký';
  } finally {
    loading.value = false;
  }
};

const applyFilters = () => {
  page.value = 1;
  load();
};

const resetFilters = () => {
  filters.value = { action: '', table_name: '' };
  page.value = 1;
  load();
};

const goTo = (p) => {
  page.value = p;
  load();
};

onMounted(load);
</script>
