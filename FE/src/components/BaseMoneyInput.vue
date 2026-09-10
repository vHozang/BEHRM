<template>
  <div class="w-full">
    <label v-if="label" :for="id" class="mb-2 block text-sm font-medium text-foreground">
      {{ label }}
      <span v-if="required" class="ml-1 text-destructive">*</span>
    </label>
    <input
      :id="id"
      type="text"
      inputmode="numeric"
      :value="displayValue"
      :placeholder="placeholder"
      :disabled="disabled"
      :required="required"
      :data-testid="testId"
      :class="inputClasses"
      @input="handleInput"
      @blur="emit('blur')"
    />
    <p v-if="error" class="mt-1 text-sm text-destructive">{{ error }}</p>
    <p v-else-if="hint" class="mt-1 text-sm text-muted-foreground">{{ hint }}</p>
  </div>
</template>

<script setup>
import { computed, onMounted } from 'vue';
import { useMoneyPreferences } from '../composables/useMoneyPreferences';
import { formatMoneyInput, parseMoneyInput, normalizeMoneySeparator } from '../utils/money';

const props = defineProps({
  id: { type: String, default: undefined },
  label: { type: String, default: '' },
  modelValue: { type: [String, Number], default: '' },
  separator: { type: String, default: '' },
  placeholder: { type: String, default: '' },
  disabled: { type: Boolean, default: false },
  required: { type: Boolean, default: false },
  compact: { type: Boolean, default: false },
  error: { type: String, default: '' },
  hint: { type: String, default: '' },
  testId: { type: String, default: undefined },
});

const emit = defineEmits(['update:modelValue', 'blur']);
const { moneyGroupSeparator, loadMoneyPreferences } = useMoneyPreferences();
const activeSeparator = computed(() => props.separator
  ? normalizeMoneySeparator(props.separator)
  : moneyGroupSeparator.value);
const displayValue = computed(() => formatMoneyInput(props.modelValue, activeSeparator.value));
const inputClasses = computed(() => {
  const spacing = props.compact ? 'px-2 py-1 text-sm' : 'px-4 py-2.5';
  const border = props.error ? 'border-destructive' : 'border-input';
  return `w-full rounded-lg border bg-background text-foreground transition-colors focus:outline-none focus:ring-2 focus:ring-ring disabled:cursor-not-allowed disabled:opacity-50 ${spacing} ${border}`;
});

const handleInput = (event) => {
  const value = parseMoneyInput(event.target.value);
  event.target.value = formatMoneyInput(value, activeSeparator.value);
  emit('update:modelValue', value);
};

onMounted(() => { loadMoneyPreferences(); });
</script>
