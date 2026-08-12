<template>
  <div ref="root" class="relative w-full">
    <label v-if="label" class="mb-2 block text-sm font-medium text-foreground">{{ label }}</label>
    <input
      v-model="search"
      type="search"
      :placeholder="placeholder"
      class="w-full rounded-lg border border-input bg-background px-4 py-2.5 text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
      @focus="open = true; runSearch()"
      @input="queueSearch"
      @keydown.esc="open = false"
    />
    <button v-if="modelValue" type="button" class="absolute right-3 top-[2.65rem] text-xs text-muted-foreground" @click="clear">Xóa</button>
    <div v-if="open" class="absolute z-50 mt-1 max-h-64 w-full overflow-auto rounded-lg border border-border bg-card shadow-xl">
      <p v-if="loading" class="px-4 py-3 text-sm text-muted-foreground">Đang tìm nhân viên…</p>
      <button
        v-for="employee in results"
        :key="employee.id"
        type="button"
        class="block w-full px-4 py-2.5 text-left hover:bg-muted"
        @mousedown.prevent="choose(employee)"
      >
        <span class="block text-sm font-medium">{{ employee.full_name }}</span>
        <span class="block text-xs text-muted-foreground">{{ employee.employee_code }}</span>
      </button>
      <p v-if="!loading && !results.length" class="px-4 py-3 text-sm text-muted-foreground">Không tìm thấy nhân viên</p>
    </div>
  </div>
</template>

<script setup>
import { ref, watch, onMounted, onUnmounted } from 'vue';
import { employeeService } from '../services/employeeService';

const props = defineProps({
  modelValue: { type: [String, Number], default: '' },
  label: { type: String, default: '' },
  placeholder: { type: String, default: 'Nhập mã hoặc tên nhân viên' },
  departmentId: { type: [String, Number], default: '' },
  legalEntityId: { type: [String, Number], default: '' },
  initialLabel: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue', 'select']);
const search = ref(props.initialLabel);
const results = ref([]);
const loading = ref(false);
const open = ref(false);
const root = ref(null);
let debounceTimer = null;
let controller = null;
const cache = new Map();
const CACHE_TTL_MS = 60_000;

watch(() => props.initialLabel, (value) => { search.value = value || ''; });
watch(() => props.modelValue, (value) => {
  if (!value && !open.value) search.value = '';
});

const runSearch = async () => {
  const term = search.value.trim();
  if (term.length === 1) {
    results.value = [];
    return;
  }
  const key = `${term}|${props.departmentId}|${props.legalEntityId}`;
  const cached = cache.get(key);
  if (cached && cached.expiresAt > Date.now()) {
    results.value = cached.items;
    return;
  }
  if (cached) cache.delete(key);
  controller?.abort();
  controller = new AbortController();
  loading.value = true;
  try {
    results.value = await employeeService.searchLookup(term, {
      department_id: props.departmentId || undefined,
      legal_entity_id: props.legalEntityId || undefined,
    }, controller.signal);
    cache.set(key, { items: results.value, expiresAt: Date.now() + CACHE_TTL_MS });
  } catch (error) {
    if (error.code !== 'ERR_CANCELED') results.value = [];
  } finally {
    loading.value = false;
  }
};

const queueSearch = () => {
  open.value = true;
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(runSearch, 300);
};

const choose = (employee) => {
  emit('update:modelValue', String(employee.id));
  emit('select', employee);
  search.value = `${employee.employee_code} · ${employee.full_name}`;
  open.value = false;
};

const clear = () => {
  emit('update:modelValue', '');
  emit('select', null);
  search.value = '';
  results.value = [];
};

const closeOnOutsideClick = (event) => {
  if (!root.value?.contains(event.target)) open.value = false;
};

onMounted(() => document.addEventListener('mousedown', closeOnOutsideClick));

onUnmounted(() => {
  document.removeEventListener('mousedown', closeOnOutsideClick);
  clearTimeout(debounceTimer);
  controller?.abort();
});
</script>
