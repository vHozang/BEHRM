<template>
  <div class="space-y-6">
    <BaseCard>
      <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div><h3 class="font-bold text-lg">Mẫu báo cáo an toàn</h3><p class="text-xs text-muted-foreground">Chỉ dùng loại báo cáo, cột, filter và biểu đồ trong allowlist; không nhận SQL từ trình duyệt.</p></div>
        <BaseButton v-if="canManage" @click="openCreate">+ Tạo mẫu</BaseButton>
      </div>
      <div v-if="loading" class="py-8 text-center text-muted-foreground">Đang tải mẫu báo cáo...</div>
      <div v-else-if="!templates.length" class="rounded-xl border border-dashed py-8 text-center text-muted-foreground">Chưa có mẫu an toàn.</div>
      <div v-else class="overflow-x-auto rounded-xl border border-border">
        <table class="w-full min-w-[760px] text-sm">
          <thead class="bg-muted/40 text-left text-xs uppercase text-muted-foreground"><tr><th class="p-3">Mẫu</th><th class="p-3">Loại</th><th class="p-3">Cột</th><th class="p-3">Trạng thái</th><th class="p-3 text-right">Thao tác</th></tr></thead>
          <tbody><tr v-for="item in templates" :key="item.id" class="border-t border-border"><td class="p-3"><p class="font-semibold">{{ item.template_name }}</p><p class="font-mono text-xs text-muted-foreground">{{ item.template_code }}</p></td><td class="p-3">{{ reportLabel(item.report_type) }}</td><td class="p-3">{{ (item.columns || []).length }}</td><td class="p-3"><BaseBadge :variant="item.status === 'ACTIVE' ? 'success' : 'secondary'">{{ item.status }}</BaseBadge></td><td class="p-3 text-right whitespace-nowrap"><button :disabled="item.status !== 'ACTIVE'" class="text-xs font-semibold text-primary disabled:opacity-40" @click="run(item)">Chạy</button><button v-if="canManage" class="ml-3 text-xs font-semibold text-primary" @click="openEdit(item)">{{ item.legacy_disabled ? 'Chuyển đổi' : 'Sửa' }}</button><button v-if="canManage" class="ml-3 text-xs font-semibold text-destructive" @click="remove(item)">Xóa</button></td></tr></tbody>
        </table>
      </div>
    </BaseCard>

    <BaseCard>
      <div class="mb-4 flex items-center justify-between"><div><h3 class="font-bold text-lg">Lịch sử chạy</h3><p class="text-xs text-muted-foreground">File kết quả nằm trong private storage và được kiểm tra quyền khi tải.</p></div><BaseButton variant="outline" @click="loadHistory">Tải lại</BaseButton></div>
      <div v-if="!histories.length" class="rounded-xl border border-dashed py-8 text-center text-muted-foreground">Chưa có lịch sử chạy báo cáo.</div>
      <div v-else class="overflow-x-auto rounded-xl border border-border"><table class="w-full min-w-[680px] text-sm"><thead class="bg-muted/40 text-left text-xs uppercase text-muted-foreground"><tr><th class="p-3">Mẫu / loại</th><th class="p-3">Thời điểm</th><th class="p-3">Trạng thái</th><th class="p-3 text-right">Kết quả</th></tr></thead><tbody><tr v-for="item in histories" :key="item.id" class="border-t"><td class="p-3">{{ item.template_name || historyType(item) }}</td><td class="p-3">{{ dateTime(item.executed_at || item.created_at) }}</td><td class="p-3">{{ item.status }}</td><td class="p-3 text-right"><button v-if="item.file_url" class="text-xs font-semibold text-primary" @click="download(item)">Tải CSV</button></td></tr></tbody></table></div>
    </BaseCard>

    <BaseModal v-model="modalOpen" :title="editingId ? 'Cập nhật mẫu báo cáo' : 'Tạo mẫu báo cáo'" size="lg">
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <BaseInput v-model="form.template_code" label="Mã mẫu" required />
        <BaseInput v-model="form.template_name" label="Tên mẫu" required />
        <label class="block text-sm font-medium sm:col-span-2">Loại báo cáo <span class="text-destructive">*</span><select v-model="form.report_type" class="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2 font-normal" @change="resetDefinition"><option value="">-- Chọn --</option><option v-for="item in catalog.reports || []" :key="item.type" :value="item.type">{{ item.label }}</option></select></label>
        <div v-if="definition" class="sm:col-span-2"><p class="mb-2 text-sm font-medium">Cột được phép</p><div class="grid grid-cols-1 gap-2 rounded-lg border p-3 sm:grid-cols-2"><label v-for="column in definition.columns" :key="column" class="flex items-center gap-2 text-sm"><input v-model="form.columns" type="checkbox" :value="column" class="h-4 w-4 rounded" />{{ column }}</label></div></div>
        <template v-if="definition?.filters?.includes('period_id')"><label class="block text-sm font-medium sm:col-span-2">Kỳ lương mặc định<ResourceSelect v-model="form.filters.period_id" resource="salary-periods" label-key="period_name" code-key="period_code" /></label></template>
        <BaseInput v-if="definition?.filters?.includes('from')" v-model="form.filters.from" type="date" label="Từ ngày mặc định" />
        <BaseInput v-if="definition?.filters?.includes('to')" v-model="form.filters.to" type="date" label="Đến ngày mặc định" />
        <label class="block text-sm font-medium">Kiểu biểu đồ<select v-model="form.chart.type" class="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2 font-normal"><option v-for="type in catalog.charts || []" :key="type" :value="type">{{ type }}</option></select></label>
        <label class="block text-sm font-medium">Trục X<select v-model="form.chart.x" class="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2 font-normal"><option value="">-- Không chọn --</option><option v-for="column in form.columns" :key="column" :value="column">{{ column }}</option></select></label>
        <label class="block text-sm font-medium">Trục Y<select v-model="form.chart.y" class="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2 font-normal"><option value="">-- Không chọn --</option><option v-for="column in form.columns" :key="column" :value="column">{{ column }}</option></select></label>
        <label class="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm"><input v-model="form.is_public" type="checkbox" class="h-4 w-4 rounded" />Cho phép dùng chung trong công ty</label>
      </div>
      <p v-if="formError" class="mt-4 rounded-lg border border-destructive/30 bg-destructive/10 p-3 text-sm text-destructive">{{ formError }}</p>
      <template #footer><BaseButton variant="outline" :disabled="saving" @click="modalOpen = false">Hủy</BaseButton><BaseButton :disabled="saving" @click="save">{{ saving ? 'Đang lưu...' : 'Lưu mẫu' }}</BaseButton></template>
    </BaseModal>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import BaseBadge from './BaseBadge.vue';
import BaseButton from './BaseButton.vue';
import BaseCard from './BaseCard.vue';
import BaseInput from './BaseInput.vue';
import BaseModal from './BaseModal.vue';
import ResourceSelect from './ResourceSelect.vue';
import { authService } from '../services/authService';
import { reportService } from '../services/reportService';
import { useToast } from '../composables/useToast';

const toast = useToast();
const canManage = computed(() => authService.hasCapability('reports.templates.manage'));
const loading = ref(false); const saving = ref(false); const modalOpen = ref(false); const editingId = ref(null); const formError = ref('');
const catalog = ref({ reports: [], charts: [] }); const templates = ref([]); const histories = ref([]);
const emptyForm = () => ({ template_code: '', template_name: '', report_type: '', columns: [], filters: {}, chart: { type: 'TABLE', x: '', y: '' }, is_public: false, status: 'ACTIVE' });
const form = reactive(emptyForm());
const definition = computed(() => (catalog.value.reports || []).find((item) => item.type === form.report_type));
const reportLabel = (type) => (catalog.value.reports || []).find((item) => item.type === type)?.label || type;
const dateTime = (value) => value ? new Date(value).toLocaleString('vi-VN') : '—';
const historyType = (item) => { try { return reportLabel(JSON.parse(item.parameters || '{}').type); } catch { return 'Báo cáo hệ thống'; } };
const resetForm = (source = {}) => { Object.assign(form, emptyForm(), source); form.columns = Array.isArray(source.columns) ? [...source.columns] : []; form.filters = { ...(source.filters || {}) }; form.chart = { type: 'TABLE', x: '', y: '', ...(source.chart || {}) }; };
const resetDefinition = () => { form.columns = [...(definition.value?.columns || [])]; form.filters = {}; form.chart = { type: 'TABLE', x: '', y: '' }; };
const loadTemplates = async () => { const result = await reportService.getTemplates({ per_page: 100 }); templates.value = result.items; };
const loadHistory = async () => { const result = await reportService.getHistory({ per_page: 100 }); histories.value = result.items; };
const load = async () => { loading.value = true; try { catalog.value = await reportService.getCatalog(); await Promise.all([loadTemplates(), loadHistory()]); } catch (err) { toast.error(err.response?.data?.message || 'Không tải được trình dựng báo cáo'); } finally { loading.value = false; } };
const openCreate = () => { editingId.value = null; resetForm(); formError.value = ''; modalOpen.value = true; };
const openEdit = async (item) => {
  editingId.value = item.id;
  formError.value = '';
  try {
    resetForm(await reportService.getTemplate(item.id));
  } catch {
    resetForm(item);
  }
  // Legacy SQL templates are disabled by migration. Saving them through the
  // safe builder explicitly converts them to an allowlisted active template.
  if (form.status === 'LEGACY_DISABLED') form.status = 'ACTIVE';
  modalOpen.value = true;
};
const save = async () => { if (!form.template_code.trim() || !form.template_name.trim() || !form.report_type || !form.columns.length) { formError.value = 'Nhập mã, tên, loại và chọn ít nhất một cột'; return; } saving.value = true; formError.value = ''; try { const payload = { ...form, template_code: form.template_code.trim().toUpperCase(), template_name: form.template_name.trim(), filters: Object.fromEntries(Object.entries(form.filters).filter(([, value]) => value !== '' && value !== null)), chart: { ...form.chart } }; if (payload.filters.period_id) payload.filters.period_id = Number(payload.filters.period_id); editingId.value ? await reportService.updateTemplate(editingId.value, payload) : await reportService.createTemplate(payload); modalOpen.value = false; toast.success('Đã lưu mẫu báo cáo'); await loadTemplates(); } catch (err) { const errors = err.response?.data?.errors || err.response?.data?.data?.errors; formError.value = errors ? Object.values(errors).flat().join(' ') : (err.response?.data?.message || 'Không thể lưu mẫu'); } finally { saving.value = false; } };
const remove = async (item) => { if (!confirm(`Xóa hoặc lưu trữ mẫu "${item.template_name}"?`)) return; try { await reportService.deleteTemplate(item.id); toast.success('Đã xử lý mẫu báo cáo'); await loadTemplates(); } catch (err) { toast.error(err.response?.data?.message || 'Không thể xóa mẫu'); } };
const run = async (item) => { try { const result = await reportService.generateTemplate(item.id); const rows = Array.isArray(result?.rows) ? result.rows : (result?.rows?.rows || []); toast.success(`Đã tạo báo cáo ${rows.length} dòng`); await loadHistory(); } catch (err) { toast.error(err.response?.data?.message || 'Không thể chạy mẫu báo cáo'); } };
const download = async (item) => { try { const blob = await reportService.downloadHistory(item.id); const url = URL.createObjectURL(blob); const link = document.createElement('a'); link.href = url; link.download = `bao-cao-${item.id}.csv`; link.click(); URL.revokeObjectURL(url); } catch (err) { toast.error(err.response?.data?.message || 'Không tải được file báo cáo'); } };
onMounted(load);
</script>
