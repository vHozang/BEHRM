<template>
  <div class="space-y-4 flex flex-col h-full">
    <div class="flex flex-wrap justify-between items-start gap-3">
      <div>
        <h1 class="text-2xl font-bold text-foreground">Sơ đồ tổ chức</h1>
        <p class="text-muted-foreground mt-1">Cấu trúc nhân sự toàn công ty</p>
      </div>
      <div class="flex flex-wrap items-center gap-2 no-print">
        <!-- Tìm kiếm nhân viên -->
        <div class="relative">
          <input
            v-model="searchQuery"
            type="text"
            placeholder="Tìm nhân viên trong sơ đồ..."
            class="w-56 pl-9 pr-3 py-2 rounded-lg border border-input bg-background text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
          />
          <svg class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
        </div>

        <BaseButton variant="outline" size="sm" @click="expandAll" title="Mở rộng tất cả">Mở tất cả</BaseButton>
        <BaseButton variant="outline" size="sm" @click="collapseAll" title="Thu gọn tất cả">Thu gọn</BaseButton>

        <BaseButton variant="outline" size="sm" @click="exportJson" title="Xuất JSON">JSON</BaseButton>
        <BaseButton variant="outline" size="sm" @click="downloadImage" :disabled="capturing" title="Tải toàn bộ sơ đồ ra ảnh PNG (in đầy đủ)">{{ capturing ? 'Đang tạo...' : 'Tải ảnh' }}</BaseButton>
        <BaseButton variant="outline" size="sm" @click="loadData" :disabled="loading">
          <svg class="w-4 h-4" :class="{'animate-spin': loading}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
          </svg>
        </BaseButton>
      </div>
    </div>

    <!-- Cảnh báo vị trí trống (phòng ban chưa có quản lý) -->
    <div
      v-if="vacantDepartments.length"
      class="no-print bg-amber-50 border border-amber-200 rounded-lg px-4 py-3 text-sm text-amber-800 flex items-start gap-3"
    >
      <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5.07 19h13.86a2 2 0 001.74-3L13.74 4a2 2 0 00-3.48 0L3.33 16a2 2 0 001.74 3z" /></svg>
      <div>
        <span class="font-medium">Vị trí cần kiện toàn:</span>
        {{ vacantDepartments.length }} phòng ban chưa có quản lý —
        <span class="font-medium">{{ vacantDepartments.map(d => d.name).join(', ') }}</span>.
        Gán quản lý trong trang Phòng ban hoặc đổi quản lý trực tiếp trên sơ đồ.
      </div>
    </div>

    <!-- Main Card -->
    <BaseCard class="flex-1 overflow-hidden flex flex-col">
      <div v-if="loading" class="flex-1 flex items-center justify-center p-12">
        <div class="flex flex-col items-center">
          <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-primary mb-4"></div>
          <p class="text-muted-foreground">Đang tải sơ đồ tổ chức...</p>
        </div>
      </div>

      <div v-else-if="error" class="p-6">
        <div class="bg-destructive/10 border border-destructive/20 rounded-lg p-4 text-destructive flex items-center">
          <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          {{ error }}
        </div>
      </div>

      <div v-else-if="!treeData || treeData.length === 0" class="flex-1 flex flex-col items-center justify-center p-12 text-muted-foreground">
        <p>Không có dữ liệu tổ chức</p>
      </div>

      <!-- Org Chart Container (workspace: kéo chuột để di chuyển, lăn chuột để thu phóng) -->
      <div v-else class="relative flex-1 min-h-[60vh]">
        <div
          ref="canvasRef"
          class="org-canvas absolute inset-0 overflow-auto p-12 rounded-lg border border-border select-none"
          :class="isPanning ? 'cursor-grabbing' : 'cursor-grab'"
          id="org-chart-canvas"
          @mousedown="startPan"
          @mousemove="onPan"
          @mouseup="endPan"
          @mouseleave="endPan"
          @wheel.prevent="onWheel"
        >
          <div ref="contentRef" class="min-w-max flex justify-center pb-12" :style="{ transform: `scale(${zoom})`, transformOrigin: '0 0', width: 'max-content' }">
            <div class="flex gap-16">
              <OrgTreeNode
                v-for="rootNode in treeData"
                :key="rootNode.id"
                :node="rootNode"
              />
            </div>
          </div>

          <!-- Gợi ý thao tác (mờ, góc trên-trái) -->
          <div class="no-print absolute top-2 left-2 text-[11px] text-muted-foreground/80 bg-card/70 backdrop-blur rounded-md px-2 py-1 pointer-events-none">
            💡 Kéo chuột di chuyển · lăn chuột thu/phóng
          </div>
        </div>

        <!-- Cụm điều khiển nổi (kiểu công cụ vẽ sơ đồ) -->
        <div class="no-print absolute bottom-3 right-3 flex items-center gap-1 bg-card/95 backdrop-blur border border-border rounded-full shadow-md px-1.5 py-1">
          <button @click="zoomOut" class="w-7 h-7 rounded-full hover:bg-muted text-muted-foreground flex items-center justify-center" title="Thu nhỏ">−</button>
          <span class="text-xs text-muted-foreground w-10 text-center select-none">{{ Math.round(zoom * 100) }}%</span>
          <button @click="zoomIn" class="w-7 h-7 rounded-full hover:bg-muted text-muted-foreground flex items-center justify-center" title="Phóng to">+</button>
          <span class="w-px h-5 bg-border mx-0.5"></span>
          <button @click="fitToScreen" class="px-2 h-7 rounded-full hover:bg-muted text-xs text-foreground flex items-center" title="Vừa màn hình">⤢ Vừa</button>
          <button @click="resetView" class="px-2 h-7 rounded-full hover:bg-muted text-xs text-foreground flex items-center" title="Về 100%">100%</button>
        </div>
      </div>

      <!-- Chú thích -->
      <div v-if="treeData && treeData.length" class="no-print flex flex-wrap items-center gap-4 px-4 py-2 border-t border-border text-xs text-muted-foreground">
        <span class="flex items-center gap-1.5"><span class="w-3 h-0.5 bg-border"></span> Báo cáo trực tiếp</span>
        <span class="flex items-center gap-1.5"><span class="inline-block px-2 py-0.5 rounded-full border border-dashed border-indigo-400 text-indigo-600 bg-indigo-50 text-[10px]">Phòng ban</span> Kiêm nhiệm (ma trận)</span>
        <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full bg-amber-100 border border-amber-300"></span> Thử việc</span>
      </div>
    </BaseCard>

    <!-- Modal đổi quản lý -->
    <BaseModal v-model="showEdit" title="Đổi quản lý / báo cáo" size="sm">
      <div v-if="editTarget" class="space-y-4">
        <div class="bg-muted/50 rounded-lg p-3">
          <p class="font-semibold text-foreground">{{ editTarget.full_name }}</p>
          <p class="text-sm text-muted-foreground">{{ editTarget.position_name || 'Chưa có chức danh' }} · {{ editTarget.department_name || 'Chưa phân bổ' }}</p>
        </div>
        <BaseSelect
          v-model="editManagerId"
          label="Báo cáo cho (quản lý trực tiếp)"
          :options="managerOptions"
          placeholder="— Không có (đưa lên cấp gốc) —"
        />
        <p class="text-xs text-muted-foreground">
          Không thể chọn chính nhân viên này hoặc cấp dưới của họ (tránh tạo vòng lặp). Thay đổi sẽ tự ghi nhận vào lịch sử công tác nếu kèm điều chuyển.
        </p>
      </div>
      <template #footer>
        <BaseButton variant="outline" @click="showEdit = false" :disabled="saving">Hủy</BaseButton>
        <BaseButton variant="primary" @click="saveManager" :disabled="saving">
          {{ saving ? 'Đang lưu...' : 'Lưu' }}
        </BaseButton>
      </template>
    </BaseModal>

    <!-- Modal thêm cấp dưới -->
    <BaseModal v-model="showAdd" title="Thêm cấp dưới" size="sm">
      <div v-if="addParent" class="space-y-4">
        <p class="text-sm text-muted-foreground">
          Nhân viên mới sẽ báo cáo cho <span class="font-medium text-foreground">{{ addParent.full_name }}</span>
          <span v-if="addParent.department_name"> · {{ addParent.department_name }}</span>
        </p>
        <div>
          <label class="block text-sm font-medium mb-1">Họ tên <span class="text-destructive">*</span></label>
          <input v-model="addForm.full_name" type="text" class="w-full px-3 py-2 rounded-lg border border-input bg-background focus:outline-none focus:ring-2 focus:ring-ring" />
        </div>
        <div>
          <label class="block text-sm font-medium mb-1">Email công ty <span class="text-destructive">*</span></label>
          <input v-model="addForm.company_email" type="email" class="w-full px-3 py-2 rounded-lg border border-input bg-background focus:outline-none focus:ring-2 focus:ring-ring" />
        </div>
        <div>
          <label class="block text-sm font-medium mb-1">Mã nhân viên</label>
          <input v-model="addForm.employee_code" type="text" placeholder="Tự sinh nếu để trống" class="w-full px-3 py-2 rounded-lg border border-input bg-background focus:outline-none focus:ring-2 focus:ring-ring" />
          <p class="text-xs text-muted-foreground mt-1">Định dạng: 2–4 chữ in hoa + 4–8 số (vd: NV0001).</p>
        </div>
      </div>
      <template #footer>
        <BaseButton variant="outline" @click="showAdd = false" :disabled="addSaving">Hủy</BaseButton>
        <BaseButton variant="primary" @click="saveAdd" :disabled="addSaving">{{ addSaving ? 'Đang thêm...' : 'Thêm' }}</BaseButton>
      </template>
    </BaseModal>

    <!-- Modal gỡ khỏi sơ đồ -->
    <BaseModal v-model="showRemove" title="Gỡ khỏi sơ đồ tổ chức" size="sm">
      <div v-if="removeTarget" class="space-y-3">
        <p class="text-sm">Gỡ <span class="font-semibold text-foreground">{{ removeTarget.full_name }}</span> khỏi sơ đồ?</p>
        <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 text-sm text-amber-800">
          <p>Nhân viên sẽ được đánh dấu <span class="font-medium">nghỉ việc (TERMINATED)</span> và biến mất khỏi sơ đồ.</p>
          <p v-if="(removeTarget.children || []).length" class="mt-1">
            {{ removeTarget.children.length }} cấp dưới trực tiếp sẽ được chuyển lên báo cáo cho quản lý cấp trên.
          </p>
        </div>
      </div>
      <template #footer>
        <BaseButton variant="outline" @click="showRemove = false" :disabled="removeSaving">Hủy</BaseButton>
        <BaseButton variant="destructive" @click="confirmRemove" :disabled="removeSaving">{{ removeSaving ? 'Đang gỡ...' : 'Gỡ' }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>

<script setup>
import { ref, reactive, computed, provide, watch, onMounted, nextTick } from 'vue';
import BaseCard from '../components/BaseCard.vue';
import BaseButton from '../components/BaseButton.vue';
import BaseModal from '../components/BaseModal.vue';
import BaseSelect from '../components/BaseSelect.vue';
import OrgTreeNode from '../components/OrgTreeNode.vue';
import { employeeService } from '../services/employeeService';
import { departmentService } from '../services/departmentService';
import { authService } from '../services/authService';
import { useNotificationStore } from '../stores/notificationStore';

const notify = useNotificationStore();

const loading = ref(true);
const error = ref('');
const treeData = ref([]);
const departments = ref([]);
const zoom = ref(1);
const searchQuery = ref('');

// Workspace pan/zoom refs.
const canvasRef = ref(null);
const contentRef = ref(null);
const isPanning = ref(false);
const capturing = ref(false);
let panStart = null;

// ── Điều khiển dùng chung cho các node (provide/inject) ───────────────
const collapsed = reactive(new Set());
const controls = reactive({
  collapsed,
  canEdit: authService.isAdmin(),
  highlightId: null,
  searchActive: false,
  toggle(id) {
    collapsed.has(id) ? collapsed.delete(id) : collapsed.add(id);
  },
  editNode(node) {
    openEdit(node);
  },
  addSubordinate(node) {
    openAdd(node);
  },
  removeNode(node) {
    openRemove(node);
  },
});
provide('orgControls', controls);

// ── Làm phẳng cây + bản đồ cha để phục vụ tìm kiếm / chọn quản lý ──────
const buildIndex = (nodes, parentId = null, acc = { flat: [], parentOf: {} }) => {
  for (const n of nodes || []) {
    acc.flat.push(n);
    acc.parentOf[n.id] = parentId;
    if (n.children && n.children.length) buildIndex(n.children, n.id, acc);
  }
  return acc;
};

const index = computed(() => buildIndex(treeData.value));
const allEmployees = computed(() => index.value.flat);

// Phòng ban chưa có quản lý = vị trí trống cần kiện toàn.
const vacantDepartments = computed(() =>
  departments.value.filter(d => d.is_active && !d.manager_id)
);

// ── Tìm kiếm: highlight + bung các node cha để node trúng hiện ra ──────
watch(searchQuery, (q) => {
  const term = (q || '').trim().toLowerCase();
  controls.searchActive = !!term;
  if (!term) { controls.highlightId = null; return; }
  const match = allEmployees.value.find(e =>
    (e.full_name || '').toLowerCase().includes(term) ||
    (e.employee_code || '').toLowerCase().includes(term)
  );
  if (!match) { controls.highlightId = null; return; }
  controls.highlightId = match.id;
  // Bung tất cả tổ tiên để node trúng không bị ẩn.
  let cur = index.value.parentOf[match.id];
  while (cur != null) {
    collapsed.delete(cur);
    cur = index.value.parentOf[cur];
  }
});

// ── Expand / collapse all ─────────────────────────────────────────────
const expandAll = () => collapsed.clear();
const collapseAll = () => {
  collapsed.clear();
  // Thu gọn mọi node CÓ con (giữ lại các node gốc hiển thị).
  for (const n of allEmployees.value) {
    if (n.children && n.children.length) collapsed.add(n.id);
  }
};

// ── Zoom / Pan workspace ──────────────────────────────────────────────
const ZMIN = 0.2, ZMAX = 2;
const clampZoom = (z) => Math.min(ZMAX, Math.max(ZMIN, +Number(z).toFixed(2)));
const zoomIn = () => { zoom.value = clampZoom(zoom.value + 0.1); };
const zoomOut = () => { zoom.value = clampZoom(zoom.value - 0.1); };
const resetView = () => {
  zoom.value = 1;
  if (canvasRef.value) { canvasRef.value.scrollLeft = 0; canvasRef.value.scrollTop = 0; }
};

const onWheel = (e) => {
  zoom.value = clampZoom(zoom.value + (e.deltaY > 0 ? -0.1 : 0.1));
};

const startPan = (e) => {
  // Không pan khi đang bấm vào nút/ô nhập trong node.
  if (e.button !== 0 || (e.target.closest && e.target.closest('button, select, input, a'))) return;
  isPanning.value = true;
  panStart = { x: e.clientX, y: e.clientY, left: canvasRef.value.scrollLeft, top: canvasRef.value.scrollTop };
};
const onPan = (e) => {
  if (!isPanning.value || !panStart) return;
  canvasRef.value.scrollLeft = panStart.left - (e.clientX - panStart.x);
  canvasRef.value.scrollTop = panStart.top - (e.clientY - panStart.y);
};
const endPan = () => { isPanning.value = false; panStart = null; };

// Tự co/giãn để thấy toàn bộ sơ đồ vừa khung nhìn.
const fitToScreen = async () => {
  zoom.value = 1;
  await nextTick();
  const c = canvasRef.value, ct = contentRef.value;
  if (!c || !ct) return;
  const cw = c.clientWidth - 48, ch = c.clientHeight - 48; // trừ padding p-12
  const w = ct.scrollWidth, h = ct.scrollHeight;
  if (w > 0 && h > 0) zoom.value = clampZoom(Math.min(cw / w, ch / h, 1));
  await nextTick();
  c.scrollLeft = 0; c.scrollTop = 0;
};

// ── Modal đổi quản lý ─────────────────────────────────────────────────
const showEdit = ref(false);
const editTarget = ref(null);
const editManagerId = ref('');
const saving = ref(false);

// Tập id của chính NV + toàn bộ cấp dưới (không được chọn làm quản lý).
const descendantIds = (node) => {
  const ids = new Set([node.id]);
  const walk = (n) => (n.children || []).forEach(c => { ids.add(c.id); walk(c); });
  walk(node);
  return ids;
};

const managerOptions = computed(() => {
  if (!editTarget.value) return [];
  const blocked = descendantIds(editTarget.value);
  return allEmployees.value
    .filter(e => !blocked.has(e.id))
    .map(e => ({
      value: e.id,
      label: `${e.full_name}${e.position_name ? ' — ' + e.position_name : ''}${e.department_name ? ' (' + e.department_name + ')' : ''}`,
    }));
});

const openEdit = (node) => {
  editTarget.value = node;
  editManagerId.value = node.manager_id || '';
  showEdit.value = true;
};

const saveManager = async () => {
  if (!editTarget.value) return;
  saving.value = true;
  try {
    await employeeService.updateManager(editTarget.value.id, editManagerId.value);
    notify.addSuccess(`Đã cập nhật quản lý cho "${editTarget.value.full_name}"`);
    showEdit.value = false;
    await loadData();
  } catch (err) {
    const msg = err.response?.data?.data?.errors?.manager_id?.[0]
      || err.response?.data?.message
      || 'Không thể cập nhật quản lý';
    notify.addError(msg);
  } finally {
    saving.value = false;
  }
};

// ── Thêm cấp dưới (tạo nhân viên mới báo cáo cho node) ────────────────
const showAdd = ref(false);
const addParent = ref(null);
const addSaving = ref(false);
const addForm = ref({ full_name: '', company_email: '', employee_code: '', position_id: '' });

const openAdd = (node) => {
  addParent.value = node;
  addForm.value = { full_name: '', company_email: '', employee_code: '', position_id: '' };
  showAdd.value = true;
};

const saveAdd = async () => {
  if (!addForm.value.full_name || !addForm.value.company_email) {
    notify.addError('Vui lòng nhập họ tên và email công ty');
    return;
  }
  addSaving.value = true;
  try {
    await employeeService.create({
      full_name: addForm.value.full_name,
      company_email: addForm.value.company_email,
      employee_code: addForm.value.employee_code || undefined,
      manager_id: addParent.value?.id || null,
      department_id: addParent.value?.department_id || null,
      position_id: addForm.value.position_id || null,
    });
    notify.addSuccess(`Đã thêm "${addForm.value.full_name}" vào sơ đồ`);
    showAdd.value = false;
    await loadData();
  } catch (err) {
    const errs = err.response?.data?.data?.errors;
    notify.addError(errs ? Object.values(errs).flat()[0] : (err.response?.data?.message || 'Không thể thêm nhân viên'));
  } finally {
    addSaving.value = false;
  }
};

// ── Gỡ khỏi sơ đồ (cho nghỉ việc + reparent cấp dưới) ─────────────────
const showRemove = ref(false);
const removeTarget = ref(null);
const removeSaving = ref(false);

const openRemove = (node) => {
  removeTarget.value = node;
  showRemove.value = true;
};

const confirmRemove = async () => {
  if (!removeTarget.value) return;
  removeSaving.value = true;
  try {
    await employeeService.detachFromOrg(removeTarget.value.id);
    notify.addSuccess(`Đã gỡ "${removeTarget.value.full_name}" khỏi sơ đồ`);
    showRemove.value = false;
    await loadData();
  } catch (err) {
    notify.addError(err.response?.data?.message || 'Không thể gỡ');
  } finally {
    removeSaving.value = false;
  }
};

// ── Export ────────────────────────────────────────────────────────────
const exportJson = () => {
  try {
    const blob = new Blob([JSON.stringify(treeData.value, null, 2)], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `so-do-to-chuc-${new Date().toISOString().slice(0, 10)}.json`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
    notify.addSuccess('Đã xuất sơ đồ tổ chức (JSON)');
  } catch (e) {
    notify.addError('Không thể xuất file');
  }
};

// Tải TOÀN BỘ sơ đồ ra ảnh PNG (chụp đầy đủ, không bị cắt — để in/lưu/chia sẻ).
const downloadImage = async () => {
  if (!contentRef.value) return;
  capturing.value = true;
  const savedZoom = zoom.value;
  try {
    expandAll();
    zoom.value = 1; // chụp ở kích thước thật cho nét + đầy đủ
    await nextTick();
    const mod = await import('html-to-image');
    const dataUrl = await mod.toPng(contentRef.value, { backgroundColor: '#ffffff', pixelRatio: 2, cacheBust: true });
    const a = document.createElement('a');
    a.href = dataUrl;
    a.download = `so-do-to-chuc-${new Date().toISOString().slice(0, 10)}.png`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    notify.addSuccess('Đã tải sơ đồ tổ chức (ảnh PNG đầy đủ)');
  } catch (e) {
    console.error('export png', e);
    notify.addError('Không thể tạo ảnh sơ đồ');
  } finally {
    zoom.value = savedZoom;
    capturing.value = false;
  }
};

// ── Load ──────────────────────────────────────────────────────────────
const loadData = async () => {
  try {
    loading.value = true;
    error.value = '';
    collapsed.clear();
    const [orgRes, deptRes] = await Promise.allSettled([
      employeeService.getOrgChart(),
      departmentService.getAll(),
    ]);

    if (orgRes.status === 'fulfilled') {
      let data = orgRes.value?.data || orgRes.value || [];
      if (!Array.isArray(data)) data = data.items || data.data || [];
      if (!Array.isArray(data)) data = [];
      treeData.value = data;
    } else {
      throw orgRes.reason;
    }

    departments.value = deptRes.status === 'fulfilled' && Array.isArray(deptRes.value) ? deptRes.value : [];
  } catch (err) {
    console.error('Error loading org chart:', err);
    error.value = err.response?.data?.message || err.message || 'Không thể tải dữ liệu sơ đồ tổ chức';
  } finally {
    loading.value = false;
  }
};

onMounted(loadData);
</script>

<style>
/* Nền chấm lưới kiểu công cụ vẽ sơ đồ (mindmap). */
.org-canvas {
  background-color: hsl(var(--muted) / 0.35);
  background-image: radial-gradient(hsl(var(--border)) 1px, transparent 1px);
  background-size: 22px 22px;
}

/* In ấn: chỉ in vùng sơ đồ, ẩn thanh công cụ và điều hướng. */
@media print {
  .no-print { display: none !important; }
  #org-chart-canvas {
    overflow: visible !important;
    border: none !important;
    background: #fff !important;
  }
}
</style>
