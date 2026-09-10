<template>
  <select :value="modelValue == null ? '' : String(modelValue)" class="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2 font-normal" :disabled="loading" @change="$emit('update:modelValue', $event.target.value)">
    <option value="">{{ loading ? 'Đang tải...' : placeholder }}</option>
    <option v-for="item in items" :key="item.id" :value="String(item.id)">{{ label(item) }}</option>
  </select>
</template>

<script setup>
import { onMounted, ref, watch } from 'vue';
import axiosClient from '../services/axiosClient';

const props = defineProps({
  modelValue: { type: [String, Number], default: '' },
  resource: { type: String, required: true },
  labelKey: { type: String, required: true },
  codeKey: { type: String, default: '' },
  placeholder: { type: String, default: '-- Chọn --' },
  params: { type: Object, default: () => ({}) },
});
defineEmits(['update:modelValue']);
const items = ref([]);
const loading = ref(false);
const label = (item) => props.codeKey && item[props.codeKey] ? `${item[props.codeKey]} · ${item[props.labelKey]}` : (item[props.labelKey] || `#${item.id}`);
const load = async () => {
  loading.value = true;
  try {
    const response = await axiosClient.get(`/${props.resource}`, { params: { per_page: 100, ...props.params } });
    items.value = Array.isArray(response.data) ? response.data : (response.pageData?.items || response.data?.items || []);
  } finally { loading.value = false; }
};
watch(() => props.params, load, { deep: true });
onMounted(load);
</script>
