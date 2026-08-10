<template>
  <div class="space-y-6">
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
      <div>
        <h1 class="text-2xl font-bold text-foreground">Công khoán sản phẩm</h1>
        <p class="text-muted-foreground mt-1">Ghi nhận sản lượng công nhân (lương = số lượng × đơn giá). Tổng của kỳ tự cộng vào bảng lương.</p>
      </div>
      <BaseButton @click="openCreate">+ Ghi nhận sản lượng</BaseButton>
    </div>

    <BaseCard>
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4">
        <BaseSelect v-model="filters.employee_id" label="Nhân viên" :options="employeeFilterOptions" @update:modelValue="load" />
        <BaseInput v-model="filters.from" type="date" label="Từ ngày" />
        <div class="flex items-end gap-2">
          <BaseInput v-model="filters.to" type="date" label="Đến ngày" class="flex-1" />
          <BaseButton variant="outline" @click="load">Lọc</BaseButton>
        </div>
      </div>

      <div v-if="loading" class="py-8 text-center text-muted-foreground">Đang tải…</div>
      <div v-else-if="!entries.length" class="py-8 text-center text-muted-foreground">Chưa có dữ liệu công khoán.</div>
      <div v-else class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead>
            <tr class="text-muted-foreground border-b border-border text-left">
              <th class="py-2 px-2">Nhân viên</th>
              <th class="py-2 px-2">Ngày</th>
              <th class="py-2 px-2">Sản phẩm</th>
              <th class="py-2 px-2 text-right">Số lượng</th>
              <th class="py-2 px-2 text-right">Đơn giá</th>
              <th class="py-2 px-2 text-right">Thành tiền</th>
              <th class="py-2 px-2"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="e in entries" :key="e.id" class="border-b border-border hover:bg-muted/40">
              <td class="py-2 px-2">{{ empName(e.employee_id) }}</td>
              <td class="py-2 px-2">{{ formatDate(e.work_date) }}</td>
              <td class="py-2 px-2">{{ e.product_name }}</td>
              <td class="py-2 px-2 text-right">{{ fmt(e.quantity) }}</td>
              <td class="py-2 px-2 text-right">{{ fmt(e.unit_rate) }}</td>
              <td class="py-2 px-2 text-right font-semibold text-foreground">{{ fmt(e.amount) }}</td>
              <td class="py-2 px-2 text-right">
                <button @click="remove(e)" class="text-red-600 dark:text-red-400 hover:underline text-xs">Xoá</button>
              </td>
            </tr>
          </tbody>
          <tfoot>
            <tr class="font-semibold text-foreground">
              <td class="py-2 px-2" colspan="5">Tổng</td>
              <td class="py-2 px-2 text-right">{{ fmt(totalAmount) }}</td>
              <td></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </BaseCard>

    <BaseModal v-model="showModal" title="Ghi nhận công khoán">
      <div class="space-y-4">
        <BaseSelect v-model="form.employee_id" label="Nhân viên" :options="employeeOptions" required />
        <BaseInput v-model="form.work_date" type="date" label="Ngày" required />
        <BaseInput v-model="form.product_name" label="Sản phẩm / công đoạn" required placeholder="VD: May áo sơ mi" />
        <div class="grid grid-cols-2 gap-4">
          <BaseInput v-model="form.quantity" type="number" label="Số lượng" required />
          <BaseMoneyInput v-model="form.unit_rate" label="Đơn giá (VND)" required />
        </div>
        <div class="rounded-lg border border-border bg-muted/40 p-3 text-sm">
          Thành tiền: <span class="font-bold text-foreground">{{ fmt(previewAmount) }} đ</span>
        </div>
        <p v-if="formError" class="text-destructive text-sm">{{ formError }}</p>
      </div>
      <template #footer>
        <BaseButton variant="outline" @click="showModal = false" :disabled="saving">Hủy</BaseButton>
        <BaseButton @click="save" :disabled="saving">{{ saving ? 'Đang lưu…' : 'Lưu' }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import BaseCard from '../components/BaseCard.vue';
import BaseButton from '../components/BaseButton.vue';
import BaseInput from '../components/BaseInput.vue';
import BaseMoneyInput from '../components/BaseMoneyInput.vue';
import BaseSelect from '../components/BaseSelect.vue';
import BaseModal from '../components/BaseModal.vue';
import { pieceRateService } from '../services/pieceRateService';
import { employeeService } from '../services/employeeService';
import { useToast } from '../composables/useToast';

const toast = useToast();
const loading = ref(false);
const saving = ref(false);
const showModal = ref(false);
const formError = ref('');
const entries = ref([]);
const employees = ref([]);
const filters = reactive({ employee_id: '', from: '', to: '' });
const form = reactive({ employee_id: '', work_date: '', product_name: '', quantity: '', unit_rate: '' });

const employeeOptions = computed(() => employees.value.map(e => ({ label: `${e.employee_code || ''} ${e.full_name}`.trim(), value: String(e.id) })));
const employeeFilterOptions = computed(() => [{ label: 'Tất cả nhân viên', value: '' }, ...employeeOptions.value]);
const empName = (id) => { const e = employees.value.find(x => String(x.id) === String(id)); return e ? e.full_name : `NV #${id}`; };
const totalAmount = computed(() => entries.value.reduce((s, e) => s + Number(e.amount || 0), 0));
const previewAmount = computed(() => (parseFloat(form.quantity) || 0) * (parseFloat(form.unit_rate) || 0));

const fmt = (n) => Number(n || 0).toLocaleString('vi-VN');
const formatDate = (d) => { try { return new Date(d).toLocaleDateString('vi-VN'); } catch { return d; } };

const openCreate = () => {
  formError.value = '';
  Object.assign(form, { employee_id: '', work_date: new Date().toISOString().slice(0, 10), product_name: '', quantity: '', unit_rate: '' });
  showModal.value = true;
};

const save = async () => {
  if (!form.employee_id || !form.work_date || !form.product_name) { formError.value = 'Vui lòng nhập đủ nhân viên, ngày, sản phẩm'; return; }
  if (!(parseFloat(form.quantity) >= 0) || !(parseFloat(form.unit_rate) >= 0)) { formError.value = 'Số lượng & đơn giá không hợp lệ'; return; }
  saving.value = true;
  try {
    await pieceRateService.create({
      employee_id: parseInt(form.employee_id, 10),
      work_date: form.work_date,
      product_name: form.product_name,
      quantity: parseFloat(form.quantity),
      unit_rate: parseFloat(form.unit_rate),
    });
    toast.success('Đã ghi nhận công khoán');
    showModal.value = false;
    await load();
  } catch (e) {
    formError.value = e.response?.data?.message || 'Lỗi khi lưu';
  } finally {
    saving.value = false;
  }
};

const remove = async (e) => {
  if (!confirm('Xoá dòng công khoán này?')) return;
  try { await pieceRateService.remove(e.id); toast.success('Đã xoá'); await load(); }
  catch (err) { toast.error('Lỗi xoá'); }
};

const load = async () => {
  loading.value = true;
  try {
    const params = {};
    if (filters.employee_id) params.employee_id = filters.employee_id;
    if (filters.from) params.from = filters.from;
    if (filters.to) params.to = filters.to;
    entries.value = await pieceRateService.getAll(params);
  } catch (e) { entries.value = []; }
  finally { loading.value = false; }
};

onMounted(async () => {
  try {
    const data = await employeeService.getLookup();
    employees.value = Array.isArray(data) ? data : (data?.items || data?.data || []);
  } catch { employees.value = []; }
  await load();
});
</script>
