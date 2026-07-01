<template>
  <div class="w-full">
    <label v-if="label" :for="id" class="block text-sm font-medium text-foreground mb-2">
      {{ label }}
      <span v-if="required" class="text-destructive ml-1">*</span>
    </label>
    
    <select
      :id="id"
      :value="modelValue"
      @change="handleChange"
      :disabled="disabled"
      :required="required"
      :class="selectClasses"
      :data-testid="testId"
    >
      <option v-if="placeholder" value="" disabled>{{ placeholder }}</option>
      <option
        v-for="option in options"
        :key="option.value"
        :value="option.value"
      >
        {{ option.label }}
      </option>
    </select>
    
    <p v-if="error" class="text-sm text-destructive mt-1">{{ error }}</p>
    <p v-else-if="hint" class="text-sm text-muted-foreground mt-1">{{ hint }}</p>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';

interface Option {
  label: string;
  value: string | number;
}

interface Props {
  id?: string;
  label?: string;
  modelValue?: string | number;
  options: Option[];
  placeholder?: string;
  disabled?: boolean;
  required?: boolean;
  error?: string;
  hint?: string;
  testId?: string;
}

const props = withDefaults(defineProps<Props>(), {
  disabled: false,
  required: false
});

const emit = defineEmits<{
  'update:modelValue': [value: string];
}>();

const selectClasses = computed(() => {
  const base = 'w-full px-4 py-2.5 rounded-lg border bg-background text-foreground transition-colors focus:outline-none focus:ring-2 focus:ring-ring disabled:opacity-50 disabled:cursor-not-allowed appearance-none bg-[url(\'data:image/svg+xml;charset=utf-8,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' fill=\'none\' viewBox=\'0 0 20 20\'%3E%3Cpath stroke=\'%236b7280\' stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'1.5\' d=\'m6 8 4 4 4-4\'/%3E%3C/svg%3E\')] bg-[length:1.5em_1.5em] bg-[right_0.5rem_center] bg-no-repeat pr-10';
  const errorClass = props.error ? 'border-destructive' : 'border-input';
  return `${base} ${errorClass}`;
});

const handleChange = (event: Event) => {
  const target = event.target as HTMLSelectElement;
  emit('update:modelValue', target.value);
};
</script>
