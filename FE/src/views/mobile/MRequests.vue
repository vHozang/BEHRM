<template>
  <div class="px-4 pt-8">
    <h1 class="mb-4 text-lg font-bold">Đơn từ của tôi</h1>

    <div class="mb-4 flex gap-2 overflow-x-auto">
      <button v-for="item in filters" :key="item.value" class="whitespace-nowrap rounded-full border px-3.5 py-1.5 text-xs font-semibold" :class="filter === item.value ? 'border-primary bg-primary text-primary-foreground' : 'border-border bg-card text-muted-foreground'" @click="filter = item.value">{{ item.label }}</button>
    </div>

    <div v-if="loading" class="rounded-2xl border border-border bg-card p-6 text-center text-sm text-muted-foreground">Đang tải...</div>
    <div v-else-if="!filtered.length" class="rounded-2xl border border-border bg-card p-6 text-center text-sm text-muted-foreground">Không có đơn nào</div>
    <button v-for="item in filtered" :key="item.id" class="mb-2.5 block w-full rounded-2xl border border-border bg-card p-4 text-left" @click="openDetail(item)">
      <div class="flex items-start justify-between gap-2">
        <div><p class="text-sm font-bold">{{ item.title }}</p><p class="mt-0.5 text-xs text-muted-foreground">{{ typeName(item.request_type_id) }} · {{ dmy(item.created_at) }}</p></div>
        <span class="shrink-0 rounded-full px-2.5 py-1 text-[11px] font-bold" :class="statusChip(item.status)">{{ statusLabel(item.status) }}</span>
      </div>
      <p v-if="item.description" class="mt-2 line-clamp-2 text-xs text-muted-foreground">{{ item.description }}</p>
      <button v-if="['pending', 'draft'].includes(item.status)" class="mt-2.5 text-xs font-semibold text-red-600" @click.stop="cancel(item)">{{ item.status === 'draft' ? 'Xóa bản nháp' : 'Hủy đơn' }}</button>
    </button>

    <button class="fixed bottom-24 right-4 z-40 flex h-14 w-14 items-center justify-center rounded-full bg-primary text-primary-foreground shadow-xl" @click="openForm">
      <svg class="h-7 w-7" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>
    </button>

    <div v-if="formOpen" class="fixed inset-0 z-50 bg-black/50" @click.self="formOpen = false">
      <div class="absolute inset-x-0 bottom-0 max-h-[85vh] overflow-y-auto rounded-t-3xl bg-card p-5" style="padding-bottom: calc(env(safe-area-inset-bottom) + 20px)">
        <div class="mx-auto mb-4 h-1 w-10 rounded-full bg-muted-foreground/30"></div>
        <h2 class="mb-4 font-bold">{{ editingId ? 'Sửa đơn' : 'Tạo đơn mới' }}</h2>
        <label class="mb-1 block text-xs font-semibold text-muted-foreground">Loại đơn</label>
        <select v-model="form.request_type_id" class="mb-3 w-full rounded-xl border border-border bg-background p-3 text-sm"><option value="">Chọn loại đơn</option><option v-for="type in types" :key="type.id" :value="String(type.id)">{{ type.request_type_name }}</option></select>
        <label class="mb-1 block text-xs font-semibold text-muted-foreground">Tiêu đề</label>
        <input v-model="form.title" class="mb-3 w-full rounded-xl border border-border bg-background p-3 text-sm" placeholder="Nội dung cần đề nghị" />
        <label class="mb-1 block text-xs font-semibold text-muted-foreground">Chi tiết</label>
        <textarea v-model="form.description" rows="4" class="mb-4 w-full rounded-xl border border-border bg-background p-3 text-sm" placeholder="Mô tả chi tiết..."></textarea>
        <p v-if="formError" class="mb-3 text-xs text-red-600">{{ formError }}</p>
        <button class="w-full rounded-xl bg-primary py-3.5 font-bold text-primary-foreground disabled:opacity-60" :disabled="saving" @click="submit">{{ saving ? 'Đang gửi...' : (editingId ? 'Lưu thay đổi' : 'Gửi đơn') }}</button>
      </div>
    </div>

    <div v-if="detailOpen" class="fixed inset-0 z-50 bg-black/50" @click.self="detailOpen = false">
      <div class="absolute inset-x-0 bottom-0 max-h-[75vh] overflow-y-auto rounded-t-3xl bg-card p-5" style="padding-bottom: calc(env(safe-area-inset-bottom) + 20px)">
        <h2 class="font-bold">{{ selected?.title }}</h2><p class="mt-1 text-xs text-muted-foreground">{{ typeName(selected?.request_type_id) }}</p>
        <p class="mt-4 whitespace-pre-line text-sm">{{ selected?.description || 'Không có nội dung chi tiết.' }}</p>
        <p v-if="selected?.current_step" class="mt-4 text-xs text-muted-foreground">Đang ở bước duyệt {{ selected.current_step }}.</p>
        <button v-if="selected?.status === 'pending'" class="mt-5 w-full rounded-xl border border-border py-3 text-sm font-semibold" @click="editSelected">Sửa đơn đang chờ</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { requestService } from '../../services/requestService';
import { dmy } from './mformat';

const requests = ref([]); const types = ref([]); const filter = ref('all'); const loading = ref(false);
const formOpen = ref(false); const detailOpen = ref(false); const selected = ref(null); const editingId = ref(null); const saving = ref(false); const formError = ref('');
const form = ref({ request_type_id: '', title: '', description: '' });
const filters = [{ value: 'all', label: 'Tất cả' }, { value: 'pending', label: 'Chờ duyệt' }, { value: 'approved', label: 'Đã duyệt' }, { value: 'rejected', label: 'Từ chối' }];
const normalizedStatus = (status) => ({ 'CHỜ_DUYỆT': 'pending', 'ĐANG_XỬ_LÝ': 'pending', 'ĐÃ_DUYỆT': 'approved', 'TỪ_CHỐI': 'rejected', 'ĐÃ_HỦY': 'cancelled', 'NHÁP': 'draft' }[status] || String(status || '').toLowerCase());
const filtered = computed(() => filter.value === 'all' ? requests.value : requests.value.filter((item) => item.status === filter.value));
const typeName = (id) => types.value.find((type) => Number(type.id) === Number(id))?.request_type_name || 'Đơn nội bộ';
const statusLabel = (status) => ({ pending: 'Chờ duyệt', approved: 'Đã duyệt', rejected: 'Từ chối', cancelled: 'Đã hủy', draft: 'Nháp' }[status] || status);
const statusChip = (status) => ({ approved: 'bg-green-100 text-green-700', rejected: 'bg-red-100 text-red-700', pending: 'bg-amber-100 text-amber-700', draft: 'bg-slate-100 text-slate-700' }[status] || 'bg-muted text-muted-foreground');
const load = async () => { loading.value = true; try { const data = await requestService.getAll({ per_page: 100 }); requests.value = (Array.isArray(data) ? data : data?.items || []).map((item) => ({ ...item, status: normalizedStatus(item.status) })); } finally { loading.value = false; } };
const openForm = () => { editingId.value = null; form.value = { request_type_id: types.value[0]?.id ? String(types.value[0].id) : '', title: '', description: '' }; formError.value = ''; formOpen.value = true; };
const openDetail = async (item) => { selected.value = item; detailOpen.value = true; try { selected.value = { ...(await requestService.get(item.id)), status: item.status }; } catch { /* keep list data */ } };
const editSelected = () => { editingId.value = selected.value.id; form.value = { request_type_id: String(selected.value.request_type_id || ''), title: selected.value.title || '', description: selected.value.description || '' }; detailOpen.value = false; formOpen.value = true; };
const submit = async () => { if (!form.value.request_type_id || !form.value.title.trim()) { formError.value = 'Chọn loại đơn và nhập tiêu đề'; return; } saving.value = true; try { const payload = { request_type_id: Number(form.value.request_type_id), title: form.value.title.trim(), description: form.value.description }; editingId.value ? await requestService.update(editingId.value, payload) : await requestService.create(payload); formOpen.value = false; await load(); } catch (error) { formError.value = error.response?.data?.message || 'Gửi đơn thất bại'; } finally { saving.value = false; } };
const cancel = async (item) => { if (!confirm(item.status === 'draft' ? 'Xóa bản nháp này?' : 'Hủy đơn này?')) return; try { item.status === 'draft' ? await requestService.remove(item.id) : await requestService.cancel(item.id); await load(); } catch (error) { alert(error.response?.data?.message || 'Không thể xử lý đơn'); } };
onMounted(async () => { const data = await requestService.getTypes({ status: 'ACTIVE' }); types.value = Array.isArray(data) ? data : data?.items || []; await load(); });
</script>
