import { readonly, ref } from 'vue';
import { settingsService } from '../services/settingsService';
import { normalizeMoneySeparator } from '../utils/money';

const STORAGE_KEY = 'hrm_money_group_separator';
const storedSeparator = typeof window !== 'undefined' ? window.localStorage.getItem(STORAGE_KEY) : null;
const moneyGroupSeparator = ref(normalizeMoneySeparator(storedSeparator));
let loaded = false;
let pending = null;

export const setMoneyGroupSeparator = (value) => {
  moneyGroupSeparator.value = normalizeMoneySeparator(value);
  if (typeof window !== 'undefined') {
    window.localStorage.setItem(STORAGE_KEY, moneyGroupSeparator.value);
  }
};

export const loadMoneyPreferences = async (force = false) => {
  if (loaded && !force) return moneyGroupSeparator.value;
  if (pending && !force) return pending;

  pending = settingsService.getUiPreferences()
    .then((preferences) => {
      setMoneyGroupSeparator(preferences?.money_group_separator);
      loaded = true;
      return moneyGroupSeparator.value;
    })
    .catch(() => moneyGroupSeparator.value)
    .finally(() => { pending = null; });

  return pending;
};

export const useMoneyPreferences = () => ({
  moneyGroupSeparator: readonly(moneyGroupSeparator),
  loadMoneyPreferences,
});
