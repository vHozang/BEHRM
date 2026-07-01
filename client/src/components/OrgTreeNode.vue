<template>
  <div class="org-tree-node flex flex-col items-center">
    <!-- Nút hiện tại -->
    <div
      class="node-card bg-card border rounded-xl p-4 shadow-sm w-64 flex flex-col items-center text-center relative z-10 transition-all"
      :class="[
        isHighlighted ? 'border-primary ring-2 ring-primary/40 shadow-md' : 'border-border hover:shadow-md',
        dimmed ? 'opacity-40' : ''
      ]"
    >
      <!-- Hành động trên node (chỉ admin/HR) -->
      <div v-if="controls.canEdit" class="absolute top-1.5 right-1.5 flex items-center gap-0.5">
        <button
          @click.stop="controls.addSubordinate(node)"
          class="w-7 h-7 rounded-md text-muted-foreground hover:text-green-600 hover:bg-green-50 flex items-center justify-center transition-colors"
          title="Thêm cấp dưới"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
        </button>
        <button
          @click.stop="controls.editNode(node)"
          class="w-7 h-7 rounded-md text-muted-foreground hover:text-primary hover:bg-primary/10 flex items-center justify-center transition-colors"
          title="Đổi quản lý / báo cáo"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
        </button>
        <button
          @click.stop="controls.removeNode(node)"
          class="w-7 h-7 rounded-md text-muted-foreground hover:text-red-600 hover:bg-red-50 flex items-center justify-center transition-colors"
          title="Gỡ khỏi sơ đồ (cho nghỉ việc)"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
        </button>
      </div>

      <div
        class="w-12 h-12 rounded-full flex items-center justify-center font-bold text-lg mb-3"
        :class="node.status === 'PROBATION' ? 'bg-amber-100 text-amber-700' : 'bg-primary/10 text-primary'"
      >
        {{ initials }}
      </div>
      <h3 class="font-semibold text-foreground text-base leading-tight">{{ node.full_name }}</h3>
      <p class="text-sm text-primary font-medium mt-1">{{ node.position_name || 'Chưa cập nhật chức danh' }}</p>
      <p class="text-xs text-muted-foreground mt-1">{{ node.department_name || 'Chưa phân bổ' }}</p>

      <!-- Kiêm nhiệm (dotted-line / quan hệ ma trận) -->
      <div v-if="secondary.length" class="mt-2 pt-2 border-t border-dashed border-border w-full">
        <p class="text-[10px] uppercase tracking-wide text-muted-foreground mb-1">Kiêm nhiệm</p>
        <div class="flex flex-wrap gap-1 justify-center">
          <span
            v-for="s in secondary"
            :key="s.department_id"
            class="text-[11px] px-2 py-0.5 rounded-full border border-dashed border-indigo-400 text-indigo-600 bg-indigo-50"
            :title="s.role_in_dept || 'Kiêm nhiệm'"
          >{{ s.department_name }}</span>
        </div>
      </div>

      <!-- Số cấp dưới trực tiếp -->
      <p v-if="hasChildren" class="text-[11px] text-muted-foreground mt-2">
        {{ node.children.length }} cấp dưới trực tiếp
      </p>

      <!-- Nút toggle để ẩn hiện nhánh con (nếu có con) -->
      <button
        v-if="hasChildren"
        @click.stop="controls.toggle(node.id)"
        class="absolute -bottom-3 left-1/2 -translate-x-1/2 w-6 h-6 rounded-full bg-background border border-border shadow-sm flex items-center justify-center text-muted-foreground hover:text-primary hover:border-primary transition-colors"
      >
        <svg v-if="isExpanded" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4" /></svg>
        <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
      </button>
    </div>

    <!-- Nhánh con đệ quy -->
    <div v-if="hasChildren" v-show="isExpanded" class="children-container flex gap-6 pt-8 relative">
      <OrgTreeNode
        v-for="child in node.children"
        :key="child.id"
        :node="child"
      />
    </div>
  </div>
</template>

<script setup>
import { computed, inject } from 'vue';

const props = defineProps({
  node: {
    type: Object,
    required: true
  }
});

// Điều khiển dùng chung do OrganizationChart cung cấp (expand/collapse, search, edit).
const controls = inject('orgControls', {
  collapsed: new Set(),
  toggle: () => {},
  editNode: () => {},
  canEdit: false,
  highlightId: null,
  searchActive: false,
});

const hasChildren = computed(() => props.node.children && props.node.children.length > 0);

const isExpanded = computed(() => !controls.collapsed.has(props.node.id));

const secondary = computed(() => Array.isArray(props.node.secondary_departments) ? props.node.secondary_departments : []);

const isHighlighted = computed(() => controls.highlightId && controls.highlightId === props.node.id);

// Khi đang tìm kiếm, làm mờ các node không trùng để dễ nhìn node trúng.
const dimmed = computed(() => controls.searchActive && controls.highlightId && controls.highlightId !== props.node.id);

const initials = computed(() => {
  if (!props.node.full_name) return '?';
  const parts = props.node.full_name.trim().split(' ');
  if (parts.length >= 2) {
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
  }
  return parts[0].substring(0, 2).toUpperCase();
});
</script>

<style scoped>
.org-tree-node {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
}

.children-container > .org-tree-node {
  padding-top: 2rem;
  padding-left: 0.5rem;
  padding-right: 0.5rem;
}

.children-container {
  display: flex;
  justify-content: center;
  padding-top: 2rem;
  position: relative;
}

/* The vertical drop down line from the parent node */
.children-container::before {
  content: '';
  position: absolute;
  top: 0;
  left: 50%;
  width: 2px;
  height: 2rem;
  background-color: hsl(var(--border));
  transform: translateX(-50%);
}

/* The horizontal connectors and vertical drop to each child card */
.children-container > .org-tree-node::before,
.children-container > .org-tree-node::after {
  content: '';
  position: absolute;
  top: 0;
  width: 50%;
  height: 2rem;
}

.children-container > .org-tree-node::before {
  right: 50%;
  border-top: 2px solid hsl(var(--border));
  border-right: 2px solid hsl(var(--border));
  border-top-right-radius: 6px;
}

.children-container > .org-tree-node::after {
  left: 50%;
  border-top: 2px solid hsl(var(--border));
  border-top-left-radius: 6px;
}

.children-container > .org-tree-node:first-child::before {
  border-top: none;
  border-top-right-radius: 0;
}

.children-container > .org-tree-node:last-child::after {
  border-top: none;
  border-top-left-radius: 0;
}

.children-container > .org-tree-node:only-child::after {
  display: none;
}

.node-card {
  position: relative;
  z-index: 10;
}
</style>
