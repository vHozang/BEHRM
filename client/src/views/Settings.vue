<template>
  <div class="space-y-6">
    <div class="mb-2">
      <h1 class="text-xl sm:text-2xl font-bold">Cấu hình nghiệp vụ</h1>
      <p class="text-muted-foreground mt-1">
        Tham số luật/chính sách áp dụng cho công ty bạn. Để trống = dùng mặc định theo luật VN. Thay đổi áp dụng ngay cho tính lương, thuế, phép, hợp đồng.
      </p>
    </div>

    <div v-if="error" class="bg-destructive/10 border border-destructive/20 rounded-lg p-4">
      <p class="text-destructive font-medium">Lỗi:</p>
      <p class="text-destructive/80 text-sm mt-1">{{ error }}</p>
    </div>

    <div v-if="loading" class="text-center py-8 text-muted-foreground">Đang tải cấu hình...</div>

    <BaseCard v-for="group in groups" :key="group.key" :title="group.label">
      <div class="space-y-4">
        <div v-for="item in group.items" :key="item.key" class="grid grid-cols-1 sm:grid-cols-3 gap-3 items-start">
          <label class="text-sm font-medium text-foreground sm:pt-2">{{ item.label }}</label>
          <div class="sm:col-span-2">
            <!-- number -->
            <input
              v-if="item.type === 'int' || item.type === 'float'"
              v-model="values[item.key]"
              type="number"
              :step="item.type === 'float' ? '0.01' : '1'"
              class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground text-sm focus:outline-none focus:ring-2 focus:ring-ring"
            />
            <!-- bool -->
            <label v-else-if="item.type === 'bool'" class="inline-flex items-center gap-2">
              <input type="checkbox" v-model="values[item.key]" class="h-4 w-4 rounded border-input text-primary focus:ring-ring" />
              <span class="text-sm text-muted-foreground">Bật</span>
            </label>
            <!-- modules (feature toggles) -->
            <div v-else-if="item.type === 'modules'" class="grid grid-cols-1 sm:grid-cols-2 gap-2">
              <label v-for="opt in (item.options || [])" :key="opt.key" class="flex items-center gap-2 p-2 border border-border rounded-lg">
                <input type="checkbox" :value="opt.key" v-model="values[item.key]" class="h-4 w-4 rounded border-input text-primary focus:ring-ring" />
                <span class="text-sm text-foreground">{{ opt.label }}</span>
              </label>
            </div>
            <!-- list (one per line) -->
            <textarea
              v-else-if="item.type === 'list'"
              v-model="values[item.key]"
              rows="8"
              class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground text-sm font-mono focus:outline-none focus:ring-2 focus:ring-ring"
              placeholder="Mỗi dòng một mục"
            ></textarea>
            <!-- json (advanced) -->
            <textarea
              v-else-if="item.type === 'json'"
              v-model="values[item.key]"
              rows="6"
              class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground text-xs font-mono focus:outline-none focus:ring-2 focus:ring-ring"
            ></textarea>
            <input
              v-else
              v-model="values[item.key]"
              type="text"
              class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground text-sm focus:outline-none focus:ring-2 focus:ring-ring"
            />
            <!-- Tiện ích geofence: lấy toạ độ văn phòng từ vị trí hiện tại -->
            <button
              v-if="item.key === 'attendance.geofence_lat'"
              type="button"
              @click="useCurrentLocation"
              :disabled="locating"
              class="mt-2 inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg border border-border text-foreground hover:bg-muted disabled:opacity-50"
            >
              📍 {{ locating ? 'Đang lấy vị trí…' : 'Dùng vị trí hiện tại làm toạ độ văn phòng' }}
            </button>
            <p v-if="item.key === 'attendance.enforce_mode'" class="text-xs text-muted-foreground mt-1">
              <code>off</code> = tắt · <code>flag</code> = đánh dấu để xem xét · <code>block</code> = chặn check-in ngoài phạm vi.
            </p>
          </div>
        </div>
      </div>
    </BaseCard>

    <div v-if="!loading" class="flex justify-end gap-2">
      <BaseButton variant="outline" @click="load" :disabled="saving">Khôi phục</BaseButton>
      <BaseButton @click="saveAll" :disabled="saving" data-testid="button-save-settings">
        {{ saving ? 'Đang lưu...' : 'Lưu cấu hình' }}
      </BaseButton>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import BaseCard from '../components/BaseCard.vue';
import BaseButton from '../components/BaseButton.vue';
import { settingsService } from '../services/settingsService';
import { useToast } from '../composables/useToast';

const toast = useToast();
const loading = ref(false);
const saving = ref(false);
const locating = ref(false);
const error = ref('');

// Lấy toạ độ hiện tại điền vào geofence văn phòng (đứng tại VP rồi bấm).
const useCurrentLocation = () => {
  if (!('geolocation' in navigator)) {
    toast.error('Trình duyệt không hỗ trợ định vị');
    return;
  }
  locating.value = true;
  navigator.geolocation.getCurrentPosition(
    (pos) => {
      values['attendance.geofence_lat'] = pos.coords.latitude.toFixed(6);
      values['attendance.geofence_lng'] = pos.coords.longitude.toFixed(6);
      locating.value = false;
      toast.success('Đã điền toạ độ — nhớ bấm "Lưu cấu hình"');
    },
    () => {
      locating.value = false;
      toast.error('Không lấy được vị trí (bị từ chối hoặc hết thời gian)');
    },
    { enableHighAccuracy: true, timeout: 8000 }
  );
};
const groups = ref([]);
const values = reactive({});
const typeByKey = {};

const load = async () => {
  loading.value = true;
  error.value = '';
  try {
    groups.value = await settingsService.getCatalog();
    groups.value.forEach((g) => g.items.forEach((it) => {
      typeByKey[it.key] = it.type;
      // Present values per type for editing.
      if (it.type === 'modules') {
        values[it.key] = Array.isArray(it.value) ? [...it.value] : [];
      } else if (it.type === 'list') {
        values[it.key] = Array.isArray(it.value) ? it.value.join('\n') : '';
      } else if (it.type === 'json') {
        values[it.key] = it.value != null ? JSON.stringify(it.value, null, 2) : '';
      } else if (it.type === 'bool') {
        values[it.key] = it.value === true || it.value === 'true' || it.value === 1;
      } else {
        values[it.key] = it.value ?? '';
      }
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
      let v = values[key];
      if (type === 'modules') {
        v = Array.isArray(v) ? v : [];
      } else if (type === 'list') {
        v = String(v).split('\n').map((s) => s.trim()).filter(Boolean);
      } else if (type === 'json') {
        if (String(v).trim() === '') return; // skip empty advanced field
        try { v = JSON.parse(v); } catch { jsonError = key; return; }
      } else if (type === 'bool') {
        v = !!v;
      } else if (type === 'int' || type === 'float') {
        if (v === '' || v === null) return; // skip empties (keep default)
        v = type === 'int' ? parseInt(v, 10) : parseFloat(v);
      }
      items.push({ key, value: v });
    });

    if (jsonError) {
      toast.error(`JSON không hợp lệ ở mục: ${jsonError}`);
      saving.value = false;
      return;
    }

    await settingsService.save(items);
    toast.success('Đã lưu cấu hình');
    await load();
  } catch (err) {
    toast.error(err?.response?.data?.message || 'Có lỗi khi lưu cấu hình');
  } finally {
    saving.value = false;
  }
};

onMounted(load);
</script>
