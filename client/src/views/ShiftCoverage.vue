<template>
  <div class="space-y-6">
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
      <div>
        <h1 class="text-3xl font-bold text-foreground">Phủ ca khi vắng đột xuất</h1>
        <p class="text-muted-foreground mt-1">Tạo yêu cầu phủ ca, mời người ở lại tăng ca và theo dõi chuỗi giao ca</p>
      </div>
      <BaseButton @click="openCreate">+ Tạo yêu cầu phủ ca</BaseButton>
    </div>

    <!-- Filter -->
    <BaseCard>
      <div class="flex flex-wrap items-center gap-3 text-sm">
        <label class="font-medium text-foreground">Trạng thái:</label>
        <select v-model="statusFilter" @change="load" class="px-3 py-1.5 rounded-lg border border-input bg-background text-foreground">
          <option value="">Tất cả</option>
          <option value="OPEN">Đang mở</option>
          <option value="PARTIALLY_COVERED">Phủ một phần</option>
          <option value="COVERED">Đã phủ đủ</option>
          <option value="CANCELLED">Đã huỷ</option>
        </select>
      </div>
    </BaseCard>

    <div v-if="loading" class="text-center py-12 text-muted-foreground">Đang tải...</div>
    <div v-else-if="!requests.length" class="text-center py-12 text-muted-foreground">Chưa có yêu cầu phủ ca nào.</div>

    <BaseCard v-for="r in requests" :key="r.id">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <div class="flex items-center gap-2">
            <span class="text-lg font-bold text-foreground">{{ shiftCode(r.shift_type_id) }}</span>
            <span class="text-muted-foreground">· {{ formatDate(r.work_date) }}</span>
            <BaseBadge :variant="statusVariant(r.status)">{{ statusText(r.status) }}</BaseBadge>
          </div>
          <p class="text-sm text-muted-foreground mt-1">
            Người vắng: <strong>{{ r.absent_employee_id ? (r.absent_employee_name || empName(r.absent_employee_id)) : '— (thiếu người)' }}</strong>
            · Lý do: {{ reasonText(r.reason) }}
          </p>
          <p class="text-sm mt-1">
            Đã phủ <strong :class="r.status === 'COVERED' ? 'text-success' : 'text-warning'">{{ Number(r.hours_covered) }}</strong> / {{ Number(r.hours_needed) }} giờ
          </p>
        </div>
        <div class="flex gap-2">
          <BaseButton size="sm" v-if="!['COVERED','CANCELLED'].includes(r.status)" @click="openOffer(r)">Mời người phủ</BaseButton>
          <BaseButton size="sm" variant="outline" v-if="r.status !== 'CANCELLED'" @click="cancel(r)">Huỷ</BaseButton>
        </div>
      </div>

      <!-- Chuỗi giao ca (offers) -->
      <div v-if="r.offers && r.offers.length" class="mt-3 border-t border-border pt-3 space-y-1.5">
        <p class="text-xs font-semibold text-muted-foreground uppercase tracking-wider">Chuỗi giao ca</p>
        <div v-for="o in r.offers" :key="o.id" class="flex items-center justify-between text-sm">
          <span>{{ o.employee_name || empName(o.employee_id) }} · {{ (o.start_time||'').slice(0,5) }}–{{ (o.end_time||'').slice(0,5) }} ({{ Number(o.hours) }}h)</span>
          <BaseBadge :variant="offerVariant(o.status)">{{ offerText(o.status) }}</BaseBadge>
        </div>
      </div>
    </BaseCard>

    <!-- Create modal -->
    <BaseModal v-model="showCreate" title="Tạo yêu cầu phủ ca">
      <div class="space-y-4">
        <BaseInput v-model="form.work_date" type="date" label="Ngày cần phủ" required />
        <div>
          <label class="block text-sm font-medium text-foreground mb-1">Ca cần phủ <span class="text-destructive">*</span></label>
          <select v-model="form.shift_type_id" class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground">
            <option value="">-- Chọn ca --</option>
            <option v-for="s in shiftTypes" :key="s.id" :value="s.id">{{ s.shift_code }} ({{ (s.start_time||'').slice(0,5) }}–{{ (s.end_time||'').slice(0,5) }})</option>
          </select>
        </div>
        <RemoteEmployeeSelect
          v-model="form.absent_employee_id"
          label="Người vắng (nếu có)"
          placeholder="Không xác định hoặc nhập mã/tên"
          :initial-label="employeeLabel(form.absent_employee_id)"
          @select="rememberEmployee"
        />
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-sm font-medium text-foreground mb-1">Lý do</label>
            <select v-model="form.reason" class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground">
              <option value="no_show">Vắng không báo</option>
              <option value="sick">Ốm/đột xuất</option>
              <option value="leave">Nghỉ phép</option>
              <option value="extra_demand">Cần thêm người</option>
            </select>
          </div>
          <BaseInput v-model.number="form.hours_needed" type="number" step="0.5" label="Số giờ cần phủ (trống = cả ca)" />
        </div>
        <p v-if="formError" class="text-destructive text-sm">{{ formError }}</p>
      </div>
      <template #footer>
        <BaseButton variant="outline" @click="showCreate = false" :disabled="busy">Hủy</BaseButton>
        <BaseButton @click="submitCreate" :disabled="busy">{{ busy ? 'Đang tạo...' : 'Tạo' }}</BaseButton>
      </template>
    </BaseModal>

    <!-- Offer modal -->
    <BaseModal v-model="showOffer" title="Mời người phủ ca">
      <div v-if="offerTarget" class="space-y-4">
        <div class="p-3 bg-muted rounded-lg text-sm">
          <p>{{ shiftCode(offerTarget.shift_type_id) }} · {{ formatDate(offerTarget.work_date) }} · còn thiếu
            <strong>{{ Number(offerTarget.hours_needed) - Number(offerTarget.hours_covered) }}h</strong></p>
        </div>
        <RemoteEmployeeSelect
          v-model="offerForm.employee_id"
          label="Người phủ ca"
          :initial-label="employeeLabel(offerForm.employee_id)"
          @select="rememberEmployee"
        />
        <div class="grid grid-cols-2 gap-3">
          <BaseInput v-model="offerForm.start_time" type="time" label="Từ giờ" />
          <BaseInput v-model="offerForm.end_time" type="time" label="Đến giờ" />
        </div>
        <p class="text-xs text-muted-foreground">Để trống giờ nếu phủ trọn ca. Mời nhiều người cho từng khúc giờ để tạo chuỗi giao ca (vd 14–18h và 18–22h).</p>
        <p v-if="offerError" class="text-destructive text-sm">{{ offerError }}</p>
      </div>
      <template #footer>
        <BaseButton variant="outline" @click="showOffer = false" :disabled="busy">Hủy</BaseButton>
        <BaseButton @click="submitOffer" :disabled="busy">{{ busy ? 'Đang mời...' : 'Gửi lời mời' }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import BaseButton from '../components/BaseButton.vue';
import BaseCard from '../components/BaseCard.vue';
import BaseModal from '../components/BaseModal.vue';
import BaseInput from '../components/BaseInput.vue';
import BaseBadge from '../components/BaseBadge.vue';
import RemoteEmployeeSelect from '../components/RemoteEmployeeSelect.vue';
import { shiftCoverageService } from '../services/shiftCoverageService';
import { workShiftService } from '../services/workShiftService';
import { useToast } from '../composables/useToast';

const toast = useToast();
const loading = ref(true);
const busy = ref(false);
const requests = ref([]);
const shiftTypes = ref([]);
const employees = ref([]);
const statusFilter = ref('');

const empMap = ref({});
const shiftMap = ref({});
const empName = (id) => empMap.value[id] || `NV #${id}`;
const shiftCode = (id) => shiftMap.value[id] || `Ca #${id}`;
const employeeLabel = (id) => {
  const employee = employees.value.find(item => String(item.id) === String(id));
  return employee ? `${employee.employee_code || ''} · ${employee.full_name}`.replace(/^ · /, '') : '';
};
const rememberEmployee = (employee) => {
  if (!employee) return;
  const index = employees.value.findIndex(item => String(item.id) === String(employee.id));
  if (index >= 0) employees.value[index] = employee;
  else employees.value.push(employee);
  empMap.value[employee.id] = employee.full_name;
};

const formatDate = (d) => (d ? new Date(d).toLocaleDateString('vi-VN') : '-');
const reasonText = (r) => ({ no_show: 'Vắng không báo', sick: 'Ốm/đột xuất', leave: 'Nghỉ phép', extra_demand: 'Cần thêm người' }[r] || r || '-');
const statusText = (s) => ({ OPEN: 'Đang mở', PARTIALLY_COVERED: 'Phủ một phần', COVERED: 'Đã phủ đủ', CANCELLED: 'Đã huỷ' }[s] || s);
const statusVariant = (s) => ({ OPEN: 'warning', PARTIALLY_COVERED: 'warning', COVERED: 'success', CANCELLED: 'default' }[s] || 'default');
const offerText = (s) => ({ OFFERED: 'Đã mời', ACCEPTED: 'Đã nhận', DECLINED: 'Từ chối', CANCELLED: 'Đã huỷ' }[s] || s);
const offerVariant = (s) => ({ OFFERED: 'warning', ACCEPTED: 'success', DECLINED: 'destructive', CANCELLED: 'default' }[s] || 'default');

// Create
const showCreate = ref(false);
const form = ref({ work_date: '', shift_type_id: '', absent_employee_id: '', reason: 'no_show', hours_needed: null });
const formError = ref('');
const openCreate = () => {
  form.value = { work_date: '', shift_type_id: '', absent_employee_id: '', reason: 'no_show', hours_needed: null };
  formError.value = '';
  showCreate.value = true;
};
const submitCreate = async () => {
  formError.value = '';
  if (!form.value.work_date) { formError.value = 'Chọn ngày cần phủ'; return; }
  if (!form.value.shift_type_id) { formError.value = 'Chọn ca cần phủ'; return; }
  busy.value = true;
  try {
    const payload = {
      work_date: form.value.work_date,
      shift_type_id: parseInt(form.value.shift_type_id),
      reason: form.value.reason,
    };
    if (form.value.absent_employee_id) payload.absent_employee_id = parseInt(form.value.absent_employee_id);
    if (form.value.hours_needed) payload.hours_needed = Number(form.value.hours_needed);
    await shiftCoverageService.createRequest(payload);
    toast.success('Đã tạo yêu cầu phủ ca');
    showCreate.value = false;
    await load();
  } catch (e) {
    formError.value = e.response?.data?.message || 'Lỗi tạo yêu cầu';
  } finally {
    busy.value = false;
  }
};

// Offer
const showOffer = ref(false);
const offerTarget = ref(null);
const offerForm = ref({ employee_id: '', start_time: '', end_time: '' });
const offerError = ref('');
const openOffer = (r) => {
  offerTarget.value = r;
  offerForm.value = { employee_id: '', start_time: '', end_time: '' };
  offerError.value = '';
  showOffer.value = true;
};
const submitOffer = async () => {
  offerError.value = '';
  if (!offerForm.value.employee_id) { offerError.value = 'Chọn người phủ ca'; return; }
  busy.value = true;
  try {
    const payload = { employee_id: parseInt(offerForm.value.employee_id) };
    if (offerForm.value.start_time) payload.start_time = offerForm.value.start_time;
    if (offerForm.value.end_time) payload.end_time = offerForm.value.end_time;
    await shiftCoverageService.offer(offerTarget.value.id, payload);
    toast.success('Đã gửi lời mời phủ ca');
    showOffer.value = false;
    await load();
  } catch (e) {
    offerError.value = e.response?.data?.message || 'Lỗi gửi lời mời';
  } finally {
    busy.value = false;
  }
};

const cancel = async (r) => {
  if (!confirm('Huỷ yêu cầu phủ ca này?')) return;
  busy.value = true;
  try {
    await shiftCoverageService.cancelRequest(r.id);
    toast.success('Đã huỷ');
    await load();
  } catch (e) {
    toast.error(e.response?.data?.message || 'Lỗi huỷ');
  } finally {
    busy.value = false;
  }
};

const load = async () => {
  loading.value = true;
  try {
    const params = statusFilter.value ? { status: statusFilter.value } : {};
    requests.value = await shiftCoverageService.getRequests(params);
    requests.value.forEach((request) => {
      if (request.absent_employee_id && request.absent_employee_name) {
        empMap.value[request.absent_employee_id] = request.absent_employee_name;
      }
      (request.offers || []).forEach((offer) => {
        if (offer.employee_id && offer.employee_name) empMap.value[offer.employee_id] = offer.employee_name;
      });
    });
  } catch (e) {
    console.error('load coverage', e);
    requests.value = [];
  } finally {
    loading.value = false;
  }
};

onMounted(async () => {
  const shiftsRes = await workShiftService.getAll().catch(() => []);
  shiftTypes.value = shiftsRes?.data || shiftsRes || [];
  shiftTypes.value.forEach((s) => { shiftMap.value[s.id] = s.shift_code; });
  await load();
});
</script>
