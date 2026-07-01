import { ref, computed } from 'vue';

/**
 * useTheme — light/dark theme composable.
 *
 * Matches the existing app convention:
 *   - toggles the `.dark` class on <html> (documentElement)
 *   - persists to localStorage under the 'theme' key ('dark' | 'light')
 *
 * State is module-level (shared singleton) so every component that calls
 * useTheme() observes the same reactive value and stays in sync.
 *
 * API:
 *   const { isDark, theme, toggleTheme, setTheme, initTheme } = useTheme();
 *   - isDark:      Ref<boolean>
 *   - theme:       ComputedRef<'dark' | 'light'>
 *   - toggleTheme(): void
 *   - setTheme('dark' | 'light'): void
 *   - initTheme(): void   // read persisted/system preference and apply (call once at app start)
 */

const STORAGE_KEY = 'theme';
const isDark = ref(false);

function apply(dark) {
  const root = document.documentElement;
  if (dark) {
    root.classList.add('dark');
  } else {
    root.classList.remove('dark');
  }
}

function setTheme(value) {
  const dark = value === 'dark';
  isDark.value = dark;
  apply(dark);
  try {
    localStorage.setItem(STORAGE_KEY, dark ? 'dark' : 'light');
  } catch (e) {
    /* ignore storage errors (private mode, etc.) */
  }
}

function toggleTheme() {
  setTheme(isDark.value ? 'light' : 'dark');
}

function initTheme() {
  let saved = null;
  try {
    saved = localStorage.getItem(STORAGE_KEY);
  } catch (e) {
    /* ignore */
  }

  let dark;
  if (saved === 'dark' || saved === 'light') {
    dark = saved === 'dark';
  } else {
    // Fall back to OS preference, then to the class already on <html>.
    const prefersDark = typeof window !== 'undefined'
      && window.matchMedia
      && window.matchMedia('(prefers-color-scheme: dark)').matches;
    dark = prefersDark || document.documentElement.classList.contains('dark');
  }

  isDark.value = dark;
  apply(dark);
}

export function useTheme() {
  return {
    isDark,
    theme: computed(() => (isDark.value ? 'dark' : 'light')),
    toggleTheme,
    setTheme,
    initTheme,
  };
}

export default useTheme;
