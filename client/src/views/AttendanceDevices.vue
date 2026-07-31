<template>
  <div class="space-y-6">
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
      <div>
        <h1 class="text-2xl font-bold text-foreground">Máy chấm công</h1>
        <p class="text-muted-foreground mt-1">Khai báo thiết bị chấm công của công ty (vân tay / khuôn mặt / thẻ). Mỗi máy có token riêng để kết nối.</p>
      </div>
      <BaseButton @click="openCreate">+ Thêm thiết bị</BaseButton>
    </div>

    <div v-if="loading" class="py-12 text-center text-muted-foreground">Đang tải…</div>

    <div v-else-if="!devices.length" class="py-12 text-center text-muted-foreground">
      Chưa khai báo máy chấm công nào. Bấm “Thêm thiết bị” để bắt đầu.
    </div>

    <div v-else class="grid grid-cols-1 lg:grid-cols-2 gap-4">
      <BaseCard v-for="d in devices" :key="d.id">
        <div class="flex items-start justify-between gap-3">
          <div>
            <div class="flex items-center gap-2">
              <h3 class="font-semibold text-foreground">{{ d.name }}</h3>
              <span :class="['px-2 py-0.5 rounded text-xs font-medium', d.status === 'ACTIVE' ? 'bg-green-500/15 text-green-700 dark:text-green-400' : 'bg-gray-500/15 text-gray-500']">
                {{ d.status === 'ACTIVE' ? 'Đang dùng' : 'Tắt' }}
              </span>
            </div>
            <p class="text-xs text-muted-foreground mt-1">
              {{ brandLabel(d.brand) }} · {{ d.protocol_label }}
              <span v-if="d.location"> · {{ d.location }}</span>
            </p>
            <p class="text-xs mt-1" :class="d.last_seen_at ? 'text-green-600 dark:text-green-400' : 'text-muted-foreground'">
              {{ d.last_seen_at ? ('Nhận dữ liệu gần nhất: ' + formatDateTime(d.last_seen_at)) : 'Chưa nhận dữ liệu lần nào' }}
            </p>
          </div>
          <div class="flex gap-1 shrink-0">
            <button @click="openEdit(d)" class="p-1.5 rounded hover:bg-muted text-muted-foreground" title="Sửa">✎</button>
            <button @click="remove(d)" class="p-1.5 rounded hover:bg-red-100 dark:hover:bg-red-900 text-red-600 dark:text-red-400" title="Xoá">🗑</button>
          </div>
        </div>

        <!-- Token -->
        <div class="mt-3 rounded-lg border border-border bg-muted/40 p-2.5">
          <p class="text-xs text-muted-foreground mb-1">Device token (dùng cho bridge/thiết bị):</p>
          <div class="flex items-center gap-2">
            <code class="text-xs break-all flex-1 text-foreground">{{ tokenDisplay(d) }}</code>
            <button v-if="tokenFor(d)" @click="shown[d.id] = !shown[d.id]" class="text-xs text-primary shrink-0">{{ shown[d.id] ? 'Ẩn' : 'Hiện' }}</button>
            <button v-if="tokenFor(d)" @click="copy(tokenFor(d))" class="text-xs text-primary shrink-0">Sao chép</button>
            <button @click="rotate(d)" class="text-xs text-amber-600 dark:text-amber-400 shrink-0" title="Cấp token mới (token cũ hết hiệu lực)">Đổi</button>
          </div>
        </div>

        <!-- Hướng dẫn kết nối theo phương thức -->
        <details class="mt-3 text-sm">
          <summary class="cursor-pointer text-primary text-xs font-medium">Hướng dẫn kết nối</summary>
          <div class="mt-2 text-xs text-muted-foreground space-y-1.5" v-html="connectionGuide(d)"></div>
        </details>
      </BaseCard>
    </div>

    <!-- Modal thêm/sửa -->
    <BaseModal v-model="showModal" :title="editing ? 'Sửa thiết bị' : 'Thêm thiết bị'">
      <div class="space-y-4">
        <BaseInput v-model="form.name" label="Tên thiết bị" required placeholder="VD: Máy cổng chính" />
        <div class="grid grid-cols-2 gap-4">
          <BaseSelect v-model="form.brand" label="Hãng" :options="brandOptions" />
          <BaseSelect v-model="form.protocol" label="Phương thức kết nối" :options="protocolOptions" />
        </div>
        <BaseInput v-model="form.location" label="Vị trí (tuỳ chọn)" placeholder="VD: Sảnh tầng 1" />

        <!-- Thông số theo phương thức -->
        <div v-if="form.protocol === 'zk_pull'" class="grid grid-cols-2 gap-4">
          <BaseInput v-model="form.ip" label="Địa chỉ IP máy" placeholder="192.168.1.201" />
          <BaseInput v-model="form.port" label="Cổng" placeholder="4370" />
        </div>
        <BaseInput v-if="form.protocol === 'adms_push'" v-model="form.serial" label="Serial máy (SN)" placeholder="In trên tem máy" />
        <BaseInput v-if="form.protocol === 'cloud_api'" v-model="form.api_key" label="API key của hãng" placeholder="Lấy từ cổng quản trị của hãng" />

        <div class="rounded-lg border border-border bg-muted/40 p-3 text-xs text-muted-foreground" v-html="protocolHint(form.protocol)"></div>
      </div>

      <div v-if="formError" class="mt-3 p-3 bg-destructive/10 border border-destructive/20 rounded-lg">
        <p class="text-destructive text-sm">{{ formError }}</p>
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
import BaseSelect from '../components/BaseSelect.vue';
import BaseModal from '../components/BaseModal.vue';
import { attendanceDeviceService } from '../services/attendanceDeviceService';
import { useToast } from '../composables/useToast';

const toast = useToast();
const loading = ref(false);
const saving = ref(false);
const showModal = ref(false);
const editing = ref(null);
const formError = ref('');
const devices = ref([]);
const shown = reactive({});
const issuedTokens = reactive({});

const BRANDS = { zkteco: 'ZKTeco', wiseeye: 'Wise Eye', hikvision: 'Hikvision', suprema: 'Suprema', other: 'Khác' };
const brandOptions = Object.entries(BRANDS).map(([value, label]) => ({ value, label }));
const protocolOptions = [
  { value: 'zk_pull', label: 'Kéo qua LAN (ZKTeco/Wise Eye)' },
  { value: 'adms_push', label: 'Máy tự đẩy (ADMS/Push)' },
  { value: 'cloud_api', label: 'API đám mây của hãng' },
];

const form = reactive({ name: '', brand: 'wiseeye', protocol: 'zk_pull', location: '', ip: '', port: '4370', serial: '', api_key: '' });

const brandLabel = (b) => BRANDS[b] || b;
const maskToken = (t) => (t ? t.slice(0, 8) + '••••••••••••' + t.slice(-4) : '');
const tokenFor = (d) => issuedTokens[d.id] || d.device_token || '';
const tokenDisplay = (d) => {
  const token = tokenFor(d);
  if (token) return shown[d.id] ? token : maskToken(token);
  return d.has_device_token ? `Đã cấu hình (••••${d.device_token_hint || ''}) — đổi token để xem lại` : 'Chưa có token';
};
const formatDateTime = (s) => { try { return new Date(s).toLocaleString('vi-VN'); } catch { return s; } };

const apiBase = () => (typeof window !== 'undefined' ? window.location.origin : '') + '/api/v1';

const protocolHint = (p) => ({
  zk_pull: `Phù hợp <b>ZKTeco / Wise Eye</b> (cổng 4370). Cài bridge <code>tools/zk-bridge</code> trên máy tính cùng mạng LAN với máy chấm công, chạy với <code>DEVICE_TOKEN</code> của thiết bị này.`,
  adms_push: `Máy tự gửi dữ liệu lên server. Trên máy vào <b>Comm → Cloud/ADMS</b>, đặt Server = <code>${apiBase()}</code>.`,
  cloud_api: `Dùng cho hãng có nền tảng đám mây (Hikvision/Suprema). Nhập API key; hệ thống sẽ đồng bộ định kỳ.`,
  file_import: `Khi máy không mở SDK: xuất Excel/CSV từ phần mềm hãng rồi nhập vào hệ thống.`,
}[p] || '');

const connectionGuide = (d) => {
  const t = tokenFor(d);
  const ip = d.meta?.ip || '192.168.1.201';
  if (d.protocol === 'zk_pull') {
    return `1. Cắm máy vào mạng LAN, đảm bảo PC chạy bridge cùng mạng.<br>
      2. <code>cd tools/zk-bridge &amp;&amp; npm install</code><br>
      3. Chạy:<br><code>DEVICE_IP=${ip} API_BASE=${apiBase()} DEVICE_TOKEN=${maskToken(t) || '[đổi token để lấy giá trị mới]'} node bridge.js</code><br>
      4. Đăng ký vân tay với <b>User ID = mã nhân viên</b> (enroll_id) tương ứng trong HRM.`;
  }
  if (d.protocol === 'adms_push') {
    return `Trên máy: <b>Menu → Comm → Cloud/ADMS</b> → Server Address = <code>${apiBase()}</code>. Gửi kèm device token để xác thực.`;
  }
  if (d.protocol === 'cloud_api') {
    return `Nhập API key của hãng ở phần Sửa. Hệ thống sẽ kéo dữ liệu chấm công định kỳ qua API đám mây.`;
  }
  return `Xuất file chấm công từ phần mềm hãng (vd Wise Eye On 39) → nhập vào hệ thống theo định dạng punch.`;
};

const openCreate = () => {
  editing.value = null;
  formError.value = '';
  Object.assign(form, { name: '', brand: 'wiseeye', protocol: 'zk_pull', location: '', ip: '', port: '4370', serial: '', api_key: '' });
  showModal.value = true;
};

const openEdit = (d) => {
  editing.value = d;
  formError.value = '';
  Object.assign(form, {
    name: d.name, brand: d.brand, protocol: d.protocol, location: d.location || '',
    ip: d.meta?.ip || '', port: d.meta?.port || '4370', serial: d.meta?.serial || '', api_key: d.meta?.api_key || '',
  });
  showModal.value = true;
};

const save = async () => {
  if (!form.name.trim()) { formError.value = 'Vui lòng nhập tên thiết bị'; return; }
  saving.value = true;
  try {
    const payload = {
      name: form.name.trim(), brand: form.brand, protocol: form.protocol, location: form.location,
      ip: form.ip, port: form.port, serial: form.serial, api_key: form.api_key,
    };
    if (editing.value) {
      await attendanceDeviceService.update(editing.value.id, payload);
      toast.success('Đã cập nhật thiết bị');
    } else {
      const created = await attendanceDeviceService.create(payload);
      if (created?.id && created?.device_token) issuedTokens[created.id] = created.device_token;
      toast.success('Đã thêm thiết bị; hãy sao chép token đang hiển thị');
    }
    showModal.value = false;
    await load();
  } catch (e) {
    formError.value = e.response?.data?.message || 'Có lỗi khi lưu';
  } finally {
    saving.value = false;
  }
};

const remove = async (d) => {
  if (!confirm(`Xoá thiết bị "${d.name}"?`)) return;
  try { await attendanceDeviceService.remove(d.id); toast.success('Đã xoá'); await load(); }
  catch (e) { toast.error(e.response?.data?.message || 'Lỗi xoá'); }
};

const rotate = async (d) => {
  if (!confirm('Cấp token mới? Bridge/thiết bị đang dùng token cũ sẽ ngừng hoạt động cho tới khi cập nhật token mới.')) return;
  try {
    const rotated = await attendanceDeviceService.rotateToken(d.id);
    if (rotated?.device_token) issuedTokens[d.id] = rotated.device_token;
    shown[d.id] = true;
    toast.success('Đã cấp token mới; hãy sao chép trước khi rời trang');
    await load();
  }
  catch (e) { toast.error(e.response?.data?.message || 'Lỗi đổi token'); }
};

const copy = async (t) => {
  try { await navigator.clipboard.writeText(t); toast.success('Đã sao chép token'); }
  catch { toast.error('Không sao chép được'); }
};

const load = async () => {
  loading.value = true;
  try { devices.value = await attendanceDeviceService.getAll(); }
  catch (e) { toast.error('Không tải được danh sách thiết bị'); devices.value = []; }
  finally { loading.value = false; }
};

onMounted(load);
</script>
