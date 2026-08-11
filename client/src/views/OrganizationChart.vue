<template>
  <div class="flex h-full flex-col gap-4">
    <header class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
      <div>
        <div class="flex flex-wrap items-center gap-2">
          <h1 class="text-2xl font-bold text-foreground">Sơ đồ tổ chức</h1>
          <span v-if="structure.summary" class="rounded-full border border-border bg-card px-3 py-1 text-xs font-semibold text-muted-foreground">
            {{ numberFormat.format(structure.summary.headcount_total || 0) }} nhân viên · {{ structure.summary.unit_count || 0 }} đơn vị
          </span>
        </div>
        <p class="mt-1 text-muted-foreground">Cơ cấu lãnh đạo, chi nhánh và phòng ban theo quy mô tổng hợp</p>
      </div>

      <div class="no-print flex flex-wrap items-center gap-2">
        <div class="relative">
          <input
            v-model="searchQuery"
            type="search"
            placeholder="Tìm đơn vị hoặc người đứng đầu..."
            class="w-64 rounded-lg border border-input bg-background py-2 pl-9 pr-3 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
          />
          <svg class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
          </svg>
        </div>
        <BaseButton variant="outline" size="sm" @click="expandAll">Mở tất cả</BaseButton>
        <BaseButton variant="outline" size="sm" @click="collapseAll">Thu gọn</BaseButton>
        <BaseButton variant="outline" size="sm" @click="exportJson">JSON</BaseButton>
        <BaseButton variant="outline" size="sm" :disabled="capturing" @click="downloadImage">
          {{ capturing ? 'Đang tạo...' : 'Tải PNG' }}
        </BaseButton>
        <BaseButton variant="outline" size="sm" :disabled="loading" @click="loadData">
          <svg class="h-4 w-4" :class="loading ? 'animate-spin' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
          </svg>
        </BaseButton>
      </div>
    </header>

    <BaseCard class="no-print">
      <div class="grid gap-3 md:grid-cols-[minmax(0,220px)_minmax(0,1fr)] xl:grid-cols-[220px_320px_minmax(0,1fr)]">
        <label class="space-y-1.5">
          <span class="text-xs font-bold uppercase tracking-wide text-muted-foreground">Phạm vi</span>
          <select v-model="scopeType" class="filter-select" @change="changeScope">
            <option value="company">Toàn công ty</option>
            <option value="branch">Chi nhánh</option>
            <option value="department">Phòng ban</option>
          </select>
        </label>

        <label v-if="scopeType === 'branch'" class="space-y-1.5">
          <span class="text-xs font-bold uppercase tracking-wide text-muted-foreground">Chọn chi nhánh</span>
          <select v-model="selectedEntityId" class="filter-select" @change="applyFilter">
            <option v-for="entity in legalEntities" :key="entity.id" :value="String(entity.id)">
              {{ entity.name }}{{ entity.code ? ` (${entity.code})` : '' }}
            </option>
          </select>
        </label>

        <label v-if="scopeType === 'department'" class="space-y-1.5">
          <span class="text-xs font-bold uppercase tracking-wide text-muted-foreground">Chọn phòng ban / đơn vị</span>
          <select v-model="selectedDepartmentId" class="filter-select" @change="applyFilter">
            <option v-for="department in departments" :key="department.id" :value="String(department.id)">
              {{ unitLabel(department.unit_type) }} · {{ department.name }}{{ department.code ? ` (${department.code})` : '' }}
            </option>
          </select>
        </label>

        <div class="flex items-end xl:justify-end">
          <div class="rounded-xl border border-border bg-muted/35 px-4 py-2 text-sm text-muted-foreground">
            Đang xem: <strong class="text-foreground">{{ structure.scope?.label || 'Toàn công ty' }}</strong>
          </div>
        </div>
      </div>
    </BaseCard>

    <nav v-if="structure.breadcrumbs?.length > 1" class="no-print flex flex-wrap items-center gap-1 text-sm text-muted-foreground" aria-label="Breadcrumb">
      <template v-for="(crumb, index) in structure.breadcrumbs" :key="`${crumb.scope}-${crumb.department_id || crumb.legal_entity_id || 0}`">
        <button type="button" class="rounded-md px-2 py-1 hover:bg-muted hover:text-foreground" :class="index === structure.breadcrumbs.length - 1 ? 'font-semibold text-foreground' : ''" @click="navigateCrumb(crumb)">
          {{ crumb.label }}
        </button>
        <span v-if="index < structure.breadcrumbs.length - 1">/</span>
      </template>
    </nav>

    <div v-if="structure.warnings?.length" class="no-print rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
      <div class="flex items-start gap-3">
        <svg class="mt-0.5 h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5.07 19h13.86a2 2 0 001.74-3L13.74 4a2 2 0 00-3.48 0L3.33 16a2 2 0 001.74 3z" /></svg>
        <div>
          <p class="font-bold">{{ structure.warnings.length }} cảnh báo cần kiện toàn</p>
          <p class="mt-1">{{ structure.warnings.slice(0, 4).map(item => `${item.unit_name}: ${item.message}`).join(' · ') }}</p>
          <p v-if="structure.warnings.length > 4" class="mt-1 font-medium">Và {{ structure.warnings.length - 4 }} cảnh báo khác trong file JSON.</p>
        </div>
      </div>
    </div>

    <BaseCard class="flex min-h-[68vh] flex-1 flex-col overflow-hidden p-0">
      <div v-if="loading" class="flex flex-1 flex-col items-center justify-center p-12">
        <div class="mb-4 h-12 w-12 animate-spin rounded-full border-4 border-primary/20 border-b-primary"></div>
        <p class="text-muted-foreground">Đang dựng sơ đồ đơn vị...</p>
      </div>

      <div v-else-if="error" class="p-6">
        <div class="rounded-lg border border-destructive/20 bg-destructive/10 p-4 text-destructive">{{ error }}</div>
      </div>

      <div v-else-if="!chartTree.length" class="flex flex-1 items-center justify-center p-12 text-muted-foreground">
        Không có đơn vị phù hợp với phạm vi đã chọn.
      </div>

      <div v-else class="relative flex-1">
        <div
          id="org-chart-canvas"
          ref="canvasRef"
          class="org-canvas absolute inset-0 select-none overflow-auto rounded-lg p-12"
          :class="isPanning ? 'cursor-grabbing' : 'cursor-grab'"
          @mousedown="startPan"
          @mousemove="onPan"
          @mouseup="endPan"
          @mouseleave="endPan"
          @wheel.prevent="onWheel"
        >
          <div ref="contentRef" class="flex min-w-max justify-center pb-12" :style="contentStyle">
            <div class="flex gap-16">
              <OrgTreeNode v-for="root in chartTree" :key="root.key" :node="root" />
            </div>
          </div>
          <div class="no-print pointer-events-none absolute left-3 top-3 rounded-lg border border-border/70 bg-card/85 px-3 py-1.5 text-[11px] text-muted-foreground backdrop-blur">
            Kéo để di chuyển · lăn chuột để thu phóng · bấm đơn vị để xem riêng
          </div>
        </div>

        <div class="no-print absolute bottom-3 right-3 flex items-center gap-1 rounded-full border border-border bg-card/95 px-1.5 py-1 shadow-md backdrop-blur">
          <button class="view-button" title="Thu nhỏ" @click="zoomOut">−</button>
          <span class="w-11 text-center text-xs text-muted-foreground">{{ Math.round(zoom * 100) }}%</span>
          <button class="view-button" title="Phóng to" @click="zoomIn">+</button>
          <span class="mx-1 h-5 w-px bg-border"></span>
          <button class="view-text-button" @click="fitToScreen">⤢ Vừa</button>
          <button class="view-text-button" @click="resetView">100%</button>
        </div>
      </div>

      <footer v-if="chartTree.length" class="no-print flex flex-wrap items-center gap-4 border-t border-border px-4 py-2 text-xs text-muted-foreground">
        <span class="flex items-center gap-1.5"><span class="h-1 w-4 rounded bg-teal-700"></span> Ban điều hành / Phòng ban</span>
        <span class="flex items-center gap-1.5"><span class="h-1 w-4 rounded bg-orange-700"></span> Chi nhánh</span>
        <span class="flex items-center gap-1.5"><span class="h-1 w-4 rounded bg-amber-600"></span> Phân xưởng</span>
        <span class="flex items-center gap-1.5"><span class="h-1 w-4 rounded bg-lime-700"></span> Tổ / Bộ phận</span>
      </footer>
    </BaseCard>
  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, provide, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import BaseButton from '../components/BaseButton.vue';
import BaseCard from '../components/BaseCard.vue';
import OrgTreeNode from '../components/OrgTreeNode.vue';
import { organizationChartService } from '../services/organizationChartService';
import { useNotificationStore } from '../stores/notificationStore';

const route = useRoute();
const router = useRouter();
const notify = useNotificationStore();
const numberFormat = new Intl.NumberFormat('vi-VN');

const loading = ref(true);
const error = ref('');
const structure = ref({ roots: [], executives: [], breadcrumbs: [], filters: { legal_entities: [], departments: [] }, warnings: [], summary: null, scope: null });
const scopeType = ref('company');
const selectedEntityId = ref('');
const selectedDepartmentId = ref('');
const searchQuery = ref('');
const zoom = ref(1);
const capturing = ref(false);
const canvasRef = ref(null);
const contentRef = ref(null);
const isPanning = ref(false);
let panStart = null;

const collapsed = reactive(new Set());
const highlightedKeys = reactive(new Set());
const visibleMatchKeys = reactive(new Set());
const searchState = reactive({ active: false });

const legalEntities = computed(() => structure.value.filters?.legal_entities || []);
const departments = computed(() => structure.value.filters?.departments || []);

const chartTree = computed(() => {
  let children = (structure.value.roots || []).map(node => ({ ...node }));
  const executives = structure.value.executives || [];
  for (let index = executives.length - 1; index >= 0; index -= 1) {
    const executive = executives[index];
    children = [{ ...executive, children }];
  }
  return children;
});

const contentStyle = computed(() => ({
  transform: `scale(${zoom.value})`,
  transformOrigin: '0 0',
  width: 'max-content'
}));

const controls = reactive({
  get searchActive() { return searchState.active; },
  toggle(key) { collapsed.has(key) ? collapsed.delete(key) : collapsed.add(key); },
  isCollapsed(key) { return collapsed.has(key); },
  isHighlighted(key) { return highlightedKeys.has(key); },
  isVisibleMatch(key) { return visibleMatchKeys.has(key); },
  drillDown(node) { drillDown(node); }
});
provide('orgControls', controls);

const buildIndex = (nodes, parentKey = null, acc = { flat: [], parentOf: {} }) => {
  for (const node of nodes || []) {
    acc.flat.push(node);
    acc.parentOf[node.key] = parentKey;
    buildIndex(node.children || [], node.key, acc);
  }
  return acc;
};
const treeIndex = computed(() => buildIndex(chartTree.value));

const nodeSearchText = (node) => {
  if (node.node_type === 'EXECUTIVE') {
    return [node.display_role, node.employee?.full_name, node.employee?.employee_code].filter(Boolean).join(' ').toLowerCase();
  }
  return [node.unit_type_label, node.name, node.code, node.head?.full_name, node.head?.employee_code, node.head?.position_name]
    .filter(Boolean).join(' ').toLowerCase();
};

watch(searchQuery, (value) => {
  highlightedKeys.clear();
  visibleMatchKeys.clear();
  const term = String(value || '').trim().toLowerCase();
  searchState.active = Boolean(term);
  if (!term) return;

  for (const node of treeIndex.value.flat) {
    if (!nodeSearchText(node).includes(term)) continue;
    highlightedKeys.add(node.key);
    visibleMatchKeys.add(node.key);
    let parent = treeIndex.value.parentOf[node.key];
    while (parent) {
      visibleMatchKeys.add(parent);
      collapsed.delete(parent);
      parent = treeIndex.value.parentOf[parent];
    }
  }
});

const applyDefaultCollapse = () => {
  collapsed.clear();
  const walk = (nodes) => {
    for (const node of nodes || []) {
      if (node.collapsed_by_default && node.children?.length) collapsed.add(node.key);
      walk(node.children || []);
    }
  };
  walk(chartTree.value);
};

const expandAll = () => collapsed.clear();
const collapseAll = () => {
  collapsed.clear();
  for (const node of treeIndex.value.flat) {
    if (node.children?.length) collapsed.add(node.key);
  }
};

const queryParams = () => {
  const scope = ['company', 'branch', 'department'].includes(String(route.query.scope)) ? String(route.query.scope) : 'company';
  return {
    scope,
    legal_entity_id: scope === 'branch' && route.query.legal_entity_id ? Number(route.query.legal_entity_id) : undefined,
    department_id: scope === 'department' && route.query.department_id ? Number(route.query.department_id) : undefined
  };
};

const syncControlsFromRoute = () => {
  const params = queryParams();
  scopeType.value = params.scope;
  selectedEntityId.value = params.legal_entity_id ? String(params.legal_entity_id) : '';
  selectedDepartmentId.value = params.department_id ? String(params.department_id) : '';
};

const loadData = async () => {
  loading.value = true;
  error.value = '';
  try {
    const data = await organizationChartService.getStructure(queryParams());
    structure.value = data || structure.value;
    syncControlsFromRoute();
    if (scopeType.value === 'branch' && !selectedEntityId.value && legalEntities.value.length) selectedEntityId.value = String(legalEntities.value[0].id);
    if (scopeType.value === 'department' && !selectedDepartmentId.value && departments.value.length) selectedDepartmentId.value = String(departments.value[0].id);
    applyDefaultCollapse();
    loading.value = false;
    await nextTick();
    await fitToScreen();
  } catch (err) {
    error.value = err.response?.data?.data?.errors?.scope?.[0] || err.response?.data?.message || err.message || 'Không thể tải sơ đồ tổ chức';
  } finally {
    loading.value = false;
  }
};

const navigate = async (scope, legalEntityId = null, departmentId = null) => {
  const query = { scope };
  if (scope === 'branch' && legalEntityId) query.legal_entity_id = String(legalEntityId);
  if (scope === 'department' && departmentId) query.department_id = String(departmentId);
  await router.push({ path: '/organization-chart', query });
};

const changeScope = () => {
  if (scopeType.value === 'company') return navigate('company');
  if (scopeType.value === 'branch') {
    const id = selectedEntityId.value || legalEntities.value[0]?.id;
    if (id) navigate('branch', id);
    return;
  }
  const id = selectedDepartmentId.value || departments.value[0]?.id;
  if (id) navigate('department', null, id);
};

const applyFilter = () => {
  if (scopeType.value === 'branch' && selectedEntityId.value) navigate('branch', selectedEntityId.value);
  if (scopeType.value === 'department' && selectedDepartmentId.value) navigate('department', null, selectedDepartmentId.value);
};

const drillDown = (node) => {
  const target = node.drilldown;
  if (!target) return;
  navigate(target.scope, target.legal_entity_id, target.department_id);
};
const navigateCrumb = (crumb) => navigate(crumb.scope, crumb.legal_entity_id, crumb.department_id);

const unitLabel = (type) => ({ DEPARTMENT: 'Phòng ban', WORKSHOP: 'Phân xưởng', TEAM: 'Tổ / Bộ phận' }[type] || 'Đơn vị');

const ZMIN = 0.2;
const ZMAX = 2;
const clampZoom = value => Math.min(ZMAX, Math.max(ZMIN, Number(Number(value).toFixed(2))));
const zoomIn = () => { zoom.value = clampZoom(zoom.value + 0.1); };
const zoomOut = () => { zoom.value = clampZoom(zoom.value - 0.1); };
const resetView = () => {
  zoom.value = 1;
  if (canvasRef.value) {
    canvasRef.value.scrollLeft = 0;
    canvasRef.value.scrollTop = 0;
  }
};
const onWheel = event => { zoom.value = clampZoom(zoom.value + (event.deltaY > 0 ? -0.1 : 0.1)); };
const startPan = event => {
  if (event.button !== 0 || event.target.closest?.('button, select, input, a, .node-card--clickable')) return;
  isPanning.value = true;
  panStart = { x: event.clientX, y: event.clientY, left: canvasRef.value.scrollLeft, top: canvasRef.value.scrollTop };
};
const onPan = event => {
  if (!isPanning.value || !panStart) return;
  canvasRef.value.scrollLeft = panStart.left - (event.clientX - panStart.x);
  canvasRef.value.scrollTop = panStart.top - (event.clientY - panStart.y);
};
const endPan = () => { isPanning.value = false; panStart = null; };

const fitToScreen = async () => {
  zoom.value = 1;
  await nextTick();
  const canvas = canvasRef.value;
  const content = contentRef.value;
  if (!canvas || !content) return;
  const width = content.scrollWidth;
  const height = content.scrollHeight;
  if (width > 0 && height > 0) {
    zoom.value = clampZoom(Math.min((canvas.clientWidth - 64) / width, (canvas.clientHeight - 64) / height, 1));
  }
  await nextTick();
  canvas.scrollLeft = 0;
  canvas.scrollTop = 0;
};

const exportJson = () => {
  const blob = new Blob([JSON.stringify(structure.value, null, 2)], { type: 'application/json' });
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement('a');
  anchor.href = url;
  anchor.download = `so-do-to-chuc-${structure.value.scope?.type || 'company'}-${new Date().toISOString().slice(0, 10)}.json`;
  document.body.appendChild(anchor);
  anchor.click();
  anchor.remove();
  URL.revokeObjectURL(url);
  notify.addSuccess('Đã xuất dữ liệu sơ đồ theo phạm vi hiện tại');
};

const downloadImage = async () => {
  if (!contentRef.value) return;
  capturing.value = true;
  const savedZoom = zoom.value;
  const savedCollapsed = [...collapsed];
  try {
    expandAll();
    zoom.value = 1;
    await nextTick();
    const { toPng } = await import('html-to-image');
    const dataUrl = await toPng(contentRef.value, { backgroundColor: '#f8fafc', pixelRatio: 2, cacheBust: true });
    const anchor = document.createElement('a');
    anchor.href = dataUrl;
    anchor.download = `so-do-to-chuc-${structure.value.scope?.type || 'company'}-${new Date().toISOString().slice(0, 10)}.png`;
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    notify.addSuccess('Đã tải ảnh sơ đồ đầy đủ');
  } catch (err) {
    console.error('Export organization chart PNG failed', err);
    notify.addError('Không thể tạo ảnh sơ đồ');
  } finally {
    collapsed.clear();
    savedCollapsed.forEach(key => collapsed.add(key));
    zoom.value = savedZoom;
    capturing.value = false;
  }
};

watch(() => [route.query.scope, route.query.legal_entity_id, route.query.department_id], loadData);
onMounted(() => {
  syncControlsFromRoute();
  loadData();
});
</script>

<style scoped>
.filter-select {
  width: 100%;
  border: 1px solid hsl(var(--input));
  border-radius: .7rem;
  background: hsl(var(--background));
  padding: .6rem .75rem;
  color: hsl(var(--foreground));
  font-size: .875rem;
  outline: none;
}
.filter-select:focus { box-shadow: 0 0 0 2px hsl(var(--ring)); }
.org-canvas {
  background-color: #f8fafc;
  background-image:
    linear-gradient(rgba(15, 118, 110, .035) 1px, transparent 1px),
    linear-gradient(90deg, rgba(15, 118, 110, .035) 1px, transparent 1px),
    radial-gradient(circle at 50% 0%, rgba(15, 118, 110, .09), transparent 38%);
  background-size: 24px 24px, 24px 24px, 100% 100%;
}
:global(.dark) .org-canvas {
  background-color: #0f172a;
  background-image:
    linear-gradient(rgba(45, 212, 191, .055) 1px, transparent 1px),
    linear-gradient(90deg, rgba(45, 212, 191, .055) 1px, transparent 1px),
    radial-gradient(circle at 50% 0%, rgba(13, 148, 136, .16), transparent 38%);
}
.view-button { display: grid; width: 1.8rem; height: 1.8rem; place-items: center; border-radius: 999px; color: hsl(var(--muted-foreground)); }
.view-button:hover, .view-text-button:hover { background: hsl(var(--muted)); color: hsl(var(--foreground)); }
.view-text-button { height: 1.8rem; border-radius: 999px; padding: 0 .55rem; color: hsl(var(--foreground)); font-size: .72rem; }

@media print {
  .no-print { display: none !important; }
  #org-chart-canvas { position: static !important; overflow: visible !important; background: white !important; }
}
</style>
