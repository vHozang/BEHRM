<template>
  <div class="org-tree-node">
    <article
      class="node-card"
      :class="[
        node.node_type === 'EXECUTIVE' ? 'node-card--executive' : `node-card--${String(node.unit_type || 'department').toLowerCase()}`,
        isHighlighted ? 'node-card--highlighted' : '',
        dimmed ? 'node-card--dimmed' : '',
        node.drilldown ? 'node-card--clickable' : ''
      ]"
      @click="openScope"
    >
      <template v-if="node.node_type === 'EXECUTIVE'">
        <div class="executive-mark">{{ initials }}</div>
        <span class="node-kicker">Ban điều hành</span>
        <h3>{{ node.employee?.full_name || 'Chưa xác định' }}</h3>
        <p class="node-role">{{ node.display_role }}</p>
        <p v-if="node.employee?.employee_code" class="node-code">{{ node.employee.employee_code }}</p>
      </template>

      <template v-else>
        <div class="flex w-full items-start justify-between gap-3">
          <span class="unit-badge">{{ node.unit_type_label }}</span>
          <span v-if="node.code" class="node-code">{{ node.code }}</span>
        </div>
        <h3 class="unit-name">{{ node.name }}</h3>
        <div v-if="node.head_label !== null" class="leader-block" :class="node.head ? '' : 'leader-block--vacant'">
          <span class="leader-label">{{ node.head_label || 'Người phụ trách' }}</span>
          <template v-if="node.head">
            <strong>{{ node.head.full_name }}</strong>
            <small>{{ node.head.display_role || node.head.position_name || node.head.employee_code }}</small>
          </template>
          <template v-else>
            <strong>Chưa gán người phụ trách</strong>
            <small>Cần cập nhật trong danh mục đơn vị</small>
          </template>
        </div>
        <span v-if="node.drilldown" class="drill-hint">Bấm để xem riêng đơn vị →</span>
      </template>
    </article>

    <div v-if="node.node_type !== 'EXECUTIVE'" class="headcount-card" :class="dimmed ? 'opacity-35' : ''">
      <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
      </svg>
      <span>Tổng nhân viên</span>
      <strong>{{ numberFormat.format(Number(node.headcount_total || 0)) }}</strong>
    </div>

    <button
      v-if="hasChildren"
      type="button"
      class="branch-toggle no-print"
      :title="isExpanded ? 'Thu gọn nhánh' : `Mở ${node.children.length} đơn vị trực thuộc`"
      @click.stop="controls.toggle(node.key)"
    >
      <svg class="h-4 w-4 transition-transform" :class="isExpanded ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
      </svg>
      <span v-if="!isExpanded">{{ node.children.length }}</span>
    </button>

    <div v-if="hasChildren" v-show="isExpanded" class="children-container">
      <OrgTreeNode v-for="child in node.children" :key="child.key" :node="child" />
    </div>
  </div>
</template>

<script setup>
import { computed, inject } from 'vue';

const props = defineProps({
  node: { type: Object, required: true }
});

const controls = inject('orgControls', {
  toggle: () => {},
  isCollapsed: () => false,
  isHighlighted: () => false,
  isVisibleMatch: () => true,
  searchActive: false,
  drillDown: () => {}
});

const numberFormat = new Intl.NumberFormat('vi-VN');
const hasChildren = computed(() => Array.isArray(props.node.children) && props.node.children.length > 0);
const isExpanded = computed(() => !controls.isCollapsed(props.node.key));
const isHighlighted = computed(() => controls.isHighlighted(props.node.key));
const dimmed = computed(() => controls.searchActive && !controls.isVisibleMatch(props.node.key));

const initials = computed(() => {
  const name = props.node.employee?.full_name || '';
  const parts = name.trim().split(/\s+/).filter(Boolean);
  if (!parts.length) return '?';
  return `${parts[0][0]}${parts.at(-1)?.[0] || ''}`.toUpperCase();
});

const openScope = () => {
  if (props.node.drilldown) controls.drillDown(props.node);
};
</script>

<style scoped>
.org-tree-node {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  min-width: 17rem;
}

.node-card {
  position: relative;
  z-index: 10;
  width: 17rem;
  min-height: 11rem;
  padding: 1rem;
  border: 1px solid hsl(var(--border));
  border-top: 4px solid #0f766e;
  border-radius: 1rem;
  background: linear-gradient(145deg, hsl(var(--card)), hsl(var(--muted) / 0.35));
  color: hsl(var(--foreground));
  box-shadow: 0 16px 38px -30px rgba(15, 23, 42, 0.75);
  transition: opacity 160ms ease, transform 160ms ease, box-shadow 160ms ease, border-color 160ms ease;
}

.node-card--clickable { cursor: pointer; }
.node-card--clickable:hover { transform: translateY(-2px); box-shadow: 0 22px 42px -28px rgba(15, 23, 42, 0.8); }
.node-card--branch { border-top-color: #c2410c; }
.node-card--head_office { border-top-color: #1d4ed8; }
.node-card--workshop { border-top-color: #b45309; }
.node-card--team { border-top-color: #4d7c0f; }
.node-card--highlighted { border-color: hsl(var(--primary)); box-shadow: 0 0 0 3px hsl(var(--primary) / 0.2), 0 18px 40px -28px rgba(15, 23, 42, 0.8); }
.node-card--dimmed { opacity: 0.3; }

.node-card--executive {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  text-align: center;
  border: 0;
  border-radius: 1.25rem;
  color: white;
  background:
    radial-gradient(circle at 18% 12%, rgba(255,255,255,.22), transparent 32%),
    linear-gradient(135deg, #0f3f49, #0f766e 62%, #115e59);
  box-shadow: 0 24px 45px -28px rgba(15, 118, 110, 0.85);
}

.executive-mark {
  display: grid;
  place-items: center;
  width: 3.25rem;
  height: 3.25rem;
  margin-bottom: .6rem;
  border: 1px solid rgba(255,255,255,.4);
  border-radius: 999px;
  background: rgba(255,255,255,.14);
  font-weight: 800;
}

.node-kicker, .unit-badge, .leader-label {
  font-size: .65rem;
  font-weight: 800;
  letter-spacing: .08em;
  text-transform: uppercase;
}

.node-kicker { color: rgba(255,255,255,.7); }
.node-card h3 { margin-top: .35rem; font-size: 1rem; font-weight: 800; line-height: 1.3; }
.node-role { margin-top: .25rem; color: #ccfbf1; font-size: .86rem; font-weight: 700; }
.node-code { font-size: .7rem; color: hsl(var(--muted-foreground)); }
.node-card--executive .node-code { color: rgba(255,255,255,.65); }

.unit-badge {
  display: inline-flex;
  padding: .25rem .55rem;
  border-radius: 999px;
  color: #0f766e;
  background: #ccfbf1;
}
.node-card--branch .unit-badge { color: #9a3412; background: #ffedd5; }
.node-card--workshop .unit-badge { color: #92400e; background: #fef3c7; }
.node-card--team .unit-badge { color: #3f6212; background: #ecfccb; }
.unit-name { min-height: 2.7rem; margin-top: .75rem !important; }

.leader-block {
  display: flex;
  flex-direction: column;
  width: 100%;
  margin-top: .7rem;
  padding: .65rem .75rem;
  border: 1px solid hsl(var(--border));
  border-radius: .75rem;
  background: hsl(var(--background) / .72);
}
.leader-block strong { margin-top: .12rem; font-size: .82rem; }
.leader-block small { margin-top: .1rem; color: hsl(var(--muted-foreground)); font-size: .7rem; }
.leader-label { color: hsl(var(--muted-foreground)); }
.leader-block--vacant { border-color: #fbbf24; background: #fffbeb; color: #92400e; }
:global(.dark) .leader-block--vacant { border-color: #92400e; background: rgba(120, 53, 15, .22); color: #fcd34d; }
:global(.dark) .unit-badge { color: #99f6e4; background: rgba(15, 118, 110, .28); }
:global(.dark) .node-card--branch .unit-badge { color: #fed7aa; background: rgba(154, 52, 18, .3); }
:global(.dark) .node-card--workshop .unit-badge { color: #fde68a; background: rgba(146, 64, 14, .3); }
:global(.dark) .node-card--team .unit-badge { color: #d9f99d; background: rgba(63, 98, 18, .3); }
.drill-hint { display: block; margin-top: .55rem; color: hsl(var(--primary)); font-size: .68rem; font-weight: 700; }

.headcount-card {
  position: relative;
  z-index: 11;
  display: flex;
  align-items: center;
  gap: .45rem;
  min-width: 12rem;
  margin-top: .5rem;
  padding: .55rem .8rem;
  border: 1px solid hsl(var(--border));
  border-radius: .75rem;
  background: hsl(var(--card));
  color: hsl(var(--muted-foreground));
  box-shadow: 0 10px 28px -24px rgba(15, 23, 42, .8);
  font-size: .75rem;
}
.headcount-card strong { margin-left: auto; color: hsl(var(--foreground)); font-size: .95rem; }

.branch-toggle {
  position: relative;
  z-index: 12;
  display: inline-flex;
  align-items: center;
  gap: .2rem;
  min-width: 1.75rem;
  height: 1.75rem;
  margin-top: .45rem;
  padding: 0 .35rem;
  border: 1px solid hsl(var(--border));
  border-radius: 999px;
  background: hsl(var(--background));
  color: hsl(var(--muted-foreground));
  font-size: .65rem;
  box-shadow: 0 5px 16px -10px rgba(15,23,42,.8);
}
.branch-toggle:hover { border-color: hsl(var(--primary)); color: hsl(var(--primary)); }

.children-container {
  position: relative;
  display: flex;
  justify-content: center;
  gap: 2rem;
  padding-top: 2.4rem;
}
.children-container::before {
  content: '';
  position: absolute;
  top: 0;
  left: 50%;
  width: 2px;
  height: 2.4rem;
  background: hsl(var(--border));
  transform: translateX(-50%);
}
.children-container > .org-tree-node { padding: 2.4rem .45rem 0; }
.children-container > .org-tree-node::before,
.children-container > .org-tree-node::after {
  content: '';
  position: absolute;
  top: 0;
  width: 50%;
  height: 2.4rem;
  border-top: 2px solid hsl(var(--border));
}
.children-container > .org-tree-node::before { right: 50%; border-right: 2px solid hsl(var(--border)); border-top-right-radius: .5rem; }
.children-container > .org-tree-node::after { left: 50%; border-top-left-radius: .5rem; }
.children-container > .org-tree-node:first-child::before { border-top: 0; border-top-right-radius: 0; }
.children-container > .org-tree-node:last-child::after { border-top: 0; border-top-left-radius: 0; }
.children-container > .org-tree-node:only-child::after { display: none; }
</style>
