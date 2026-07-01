<template>
  <div class="w-full">
    <label v-if="label" :for="id" class="block text-sm font-medium text-foreground mb-2">
      {{ label }}
      <span v-if="required" class="text-destructive ml-1">*</span>
    </label>
    
    <input
      :id="id"
      :type="type"
      :value="modelValue"
      @input="handleInput"
      @blur="emit('blur')"
      :placeholder="placeholder"
      :disabled="disabled"
      :required="required"
      :class="inputClasses"
      :data-testid="testId"
    />
    
    <p v-if="error" class="text-sm text-destructive mt-1">{{ error }}</p>
    <p v-else-if="hint" class="text-sm text-muted-foreground mt-1">{{ hint }}</p>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';

interface Props {
  id?: string;
  type?: string;
  label?: string;
  modelValue?: string | number;
  placeholder?: string;
  disabled?: boolean;
  required?: boolean;
  error?: string;
  hint?: string;
  testId?: string;
}

const props = withDefaults(defineProps<Props>(), {
  type: 'text',
  disabled: false,
  required: false
});

const emit = defineEmits<{
  'update:modelValue': [value: string];
  blur: [];
}>();

const inputClasses = computed(() => {
  const base = 'w-full px-4 py-2.5 rounded-lg border bg-background text-foreground transition-colors focus:outline-none focus:ring-2 focus:ring-ring disabled:opacity-50 disabled:cursor-not-allowed';
  const errorClass = props.error ? 'border-destructive' : 'border-input';
  return `${base} ${errorClass}`;
});

const handleInput = (event: Event) => {
  const target = event.target as HTMLInputElement;
  emit('update:modelValue', target.value);
};
</script>
