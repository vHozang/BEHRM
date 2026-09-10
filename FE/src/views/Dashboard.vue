<template>
  <div class="dashboard-page space-y-5">
    <!-- HERO -->
    <section class="dashboard-hero">
      <div>
        <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">{{ todayLabel }}</p>
        <h1 class="mt-2 text-2xl font-bold text-foreground sm:text-3xl">
          {{ greeting }}<span v-if="userName">, {{ userName }}</span>
        </h1>
        <p class="mt-1 text-sm text-muted-foreground">{{ t('dashboard.subtitle') }}</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <button class="dashboard-action" @click="$router.push('/attendance')">
          <IconClock class="h-4 w-4" />
          {{ t('dashboard.checkIn') }}
        </button>
        <button class="dashboard-action" @click="$router.push('/leaves')">
          <IconCalendar class="h-4 w-4" />
          {{ t('dashboard.requestLeave') }}
        </button>
        <button v-if="canManageHr" class="dashboard-action dashboard-action--primary" @click="$router.push('/employees')">
          <IconUser class="h-4 w-4" />
          {{ t('dashboard.addEmployee') }}
        </button>
      </div>
    </section>

    <!-- LOADING -->
    <div v-if="loading" class="space-y-6">
      <BaseSkeleton type="cards" :rows="4" />
      <BaseSkeleton type="block" height="18rem" />
      <BaseSkeleton type="cards" :rows="3" grid-class="grid-cols-1 lg:grid-cols-3" />
    </div>

    <!-- API FAILURE BANNER (no fabricated data) -->
    <div
      v-if="!loading && loadError"
      class="flex flex-wrap items-center gap-3 rounded-xl border border-destructive/30 bg-destructive/10 px-4 py-3 text-sm text-foreground"
    >
      <svg class="h-5 w-5 flex-shrink-0 text-destructive" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
      </svg>
      <span class="min-w-0 flex-1">
        <strong class="block font-semibold">{{ t('dashboard.apiUnavailableTitle') }}</strong>
        <span class="text-muted-foreground">{{ t('dashboard.apiUnavailableBody') }}</span>
      </span>
      <button class="dashboard-action" @click="loadDashboard">
        <IconClock class="h-4 w-4" />
        {{ t('dashboard.retry') }}
      </button>
    </div>

    <template v-if="!loading">
      <!-- KPI ROW -->
      <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        <StatCard
          :label="t('dashboard.totalEmployees')"
          :value="totalEmployees"
          :sublabel="`${activeDepartmentCount} ${t('nav.departments').toLowerCase()}`"
          accent="primary"
        >
          <template #icon><IconUser class="h-5 w-5" /></template>
          <template v-if="deptSparkline.length" #sparkline>
            <div class="sparkline">
              <span
                v-for="(h, i) in deptSparkline"
                :key="i"
                class="sparkline__bar"
                :style="{ height: `${h}%` }"
              ></span>
            </div>
          </template>
        </StatCard>

        <StatCard
          :label="t('dashboard.presentToday')"
          accent="success"
          :sublabel="`${todayAttendance.present}/${todayAttendanceTotal} · ${attendanceRate}%`"
        >
          <template #value>{{ todayAttendance.present }}</template>
          <template #icon><IconClock class="h-5 w-5" /></template>
        </StatCard>

        <StatCard
          :label="t('dashboard.pendingLeaves')"
          :value="pendingLeaves"
          accent="warning"
          :sublabel="t('dashboard.needResponse')"
        >
          <template #icon><IconCalendar class="h-5 w-5" /></template>
        </StatCard>

        <StatCard
          v-if="canManageHr"
          :label="t('dashboard.contractsExpiring')"
          :value="contractsExpiringSoon"
          accent="info"
          :sublabel="t('dashboard.withinDays')"
        >
          <template #icon><IconBuilding class="h-5 w-5" /></template>
        </StatCard>

        <StatCard
          v-if="canRecruit"
          :label="t('dashboard.candidates')"
          :value="recruitmentTotal"
          accent="ai"
          :sublabel="t('dashboard.recruitmentPipeline')"
        >
          <template #icon><IconUser class="h-5 w-5" /></template>
        </StatCard>
      </section>

      <!-- CHARTS ROW: trend area line + attendance ring -->
      <section class="grid gap-4 xl:grid-cols-[minmax(0,1.6fr)_minmax(300px,0.8fr)]">
        <BaseCard class="dashboard-panel min-h-[360px]" :title="t('dashboard.attendanceTrend')">
          <div class="mb-4 flex flex-wrap items-center gap-2">
            <span class="chart-legend-dot bg-success"></span>
            <span class="text-xs font-medium text-muted-foreground">{{ t('dashboard.present') }}</span>
            <span class="chart-legend-dot bg-warning"></span>
            <span class="text-xs font-medium text-muted-foreground">{{ t('dashboard.late') }}</span>
          </div>
          <div v-if="attendanceTrend.length === 0" class="empty-panel">{{ t('dashboard.noData') }}</div>
          <div v-else class="h-72">
            <Line :data="attendanceLineData" :options="lineOptions" />
          </div>
        </BaseCard>

        <BaseCard class="dashboard-panel" :title="t('dashboard.attendanceRate')">
          <div class="flex h-full flex-col items-center justify-center gap-4 py-2">
            <div class="ring-wrap">
              <Doughnut :data="attendanceRingData" :options="ringOptions" />
              <div class="ring-center">
                <span class="ring-center__value">{{ attendanceRate }}%</span>
                <span class="ring-center__label">{{ t('dashboard.present') }}</span>
              </div>
            </div>
            <div class="grid w-full grid-cols-4 gap-2 text-center">
              <div class="ring-stat">
                <span class="ring-stat__num text-success">{{ todayAttendance.present }}</span>
                <span class="ring-stat__lbl">{{ t('dashboard.present') }}</span>
              </div>
              <div class="ring-stat">
                <span class="ring-stat__num text-warning">{{ todayAttendance.late }}</span>
                <span class="ring-stat__lbl">{{ t('dashboard.late') }}</span>
              </div>
              <div class="ring-stat">
                <span class="ring-stat__num text-orange-500">{{ todayAttendance.early }}</span>
                <span class="ring-stat__lbl">Về sớm</span>
              </div>
              <div class="ring-stat">
                <span class="ring-stat__num text-destructive">{{ todayAttendance.absent }}</span>
                <span class="ring-stat__lbl">{{ t('dashboard.absent') }}</span>
              </div>
            </div>
          </div>
        </BaseCard>
      </section>

      <!-- OVERTIME ROW: 30-day OT hours trend + month summary -->
      <section class="grid gap-4 xl:grid-cols-[minmax(0,1.6fr)_minmax(300px,0.8fr)]">
        <BaseCard class="dashboard-panel min-h-[320px]" title="Tăng ca (30 ngày)">
          <div class="mb-4 flex flex-wrap items-center gap-2">
            <span class="chart-legend-dot bg-warning"></span>
            <span class="text-xs font-medium text-muted-foreground">Giờ tăng ca/ngày (đã duyệt)</span>
          </div>
          <div v-if="overtimeStats.trend.length === 0" class="empty-panel">{{ t('dashboard.noData') }}</div>
          <div v-else class="h-64">
            <Line :data="overtimeLineData" :options="lineOptions" />
          </div>
        </BaseCard>

        <BaseCard class="dashboard-panel" title="Tăng ca tháng này">
          <div class="flex h-full flex-col items-center justify-center gap-4 py-2">
            <div class="text-center">
              <div class="text-5xl font-bold text-warning">{{ overtimeStats.month_hours }}</div>
              <div class="text-sm text-muted-foreground mt-1">giờ OT đã duyệt</div>
            </div>
            <div class="grid w-full grid-cols-2 gap-2 text-center">
              <div class="ring-stat">
                <span class="ring-stat__num">{{ overtimeStats.month_requests }}</span>
                <span class="ring-stat__lbl">Đơn đã duyệt</span>
              </div>
              <div class="ring-stat">
                <span class="ring-stat__num text-warning">{{ overtimeStats.pending }}</span>
                <span class="ring-stat__lbl">Chờ duyệt</span>
              </div>
            </div>
          </div>
        </BaseCard>
      </section>

      <!-- DISTRIBUTION ROW: headcount-by-dept bars + leave-by-status donut -->
      <section class="grid items-start gap-4 xl:grid-cols-[minmax(0,1.2fr)_minmax(300px,0.85fr)]">
        <BaseCard class="dashboard-panel" :title="t('dashboard.headcountByDept')">
          <div v-if="employeesByDepartment.length === 0" class="empty-panel">{{ t('dashboard.noData') }}</div>
          <div v-else class="space-y-3">
            <div v-for="item in employeesByDepartment" :key="item.label" class="rank-row">
              <div class="flex items-center justify-between gap-3">
                <span class="truncate text-sm font-medium text-foreground">{{ item.label }}</span>
                <span class="text-sm font-bold text-info">{{ item.count }}</span>
              </div>
              <div class="mt-2 h-2 overflow-hidden rounded-full bg-muted">
                <div class="h-full rounded-full bg-info transition-[width] duration-500" :style="{ width: `${barPercent(item.count, maxDepartmentCount)}%` }"></div>
              </div>
            </div>
          </div>
        </BaseCard>

        <BaseCard class="dashboard-panel" :title="t('dashboard.leave')">
          <!-- Donut + legend -->
          <div v-if="leaveTotal === 0" class="empty-panel">{{ t('dashboard.noData') }}</div>
          <div v-else class="flex items-center gap-4">
            <div class="leave-ring">
              <Doughnut :data="leaveChartData" :options="leaveDoughnutOptions" />
              <div class="ring-center">
                <span class="ring-center__value">{{ leaveTotal }}</span>
                <span class="ring-center__label">{{ t('dashboard.leaveTotal') }}</span>
              </div>
            </div>
            <ul class="flex-1 space-y-2">
              <li v-for="seg in leaveLegend" :key="seg.key" class="flex items-center justify-between gap-2 text-sm">
                <span class="flex items-center gap-2">
                  <span class="chart-legend-dot" :style="{ backgroundColor: seg.color }"></span>
                  <span class="text-muted-foreground">{{ seg.label }}</span>
                </span>
                <span class="font-bold text-foreground">{{ seg.count }}</span>
              </li>
            </ul>
          </div>

          <!-- On leave today -->
          <div class="my-4 border-t border-border/60"></div>
          <div class="mb-2 flex items-center justify-between">
            <p class="text-sm font-semibold text-foreground">{{ t('dashboard.onLeaveToday') }}</p>
            <span class="text-xs font-semibold text-muted-foreground">{{ t('dashboard.peopleCount').replace('{n}', onLeaveToday.length) }}</span>
          </div>
          <div v-if="onLeaveToday.length === 0" class="empty-panel py-4">{{ t('dashboard.noOneOnLeave') }}</div>
          <ul v-else class="space-y-2">
            <li v-for="(person, i) in onLeaveTodayVisible" :key="person.employee_id ?? i" class="flex items-center gap-3">
              <span class="timeline-avatar bg-info/15 text-info">{{ leaveInitials(person.full_name) }}</span>
              <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-semibold text-foreground">{{ person.full_name || t('common.noData') }}</p>
              </div>
              <StatusPill tone="info" :dot="false">
                {{ person.leave_type_name || t('dashboard.leave') }} · {{ leaveUntilLabel(person.end_date) }}
              </StatusPill>
            </li>
          </ul>

          <!-- Year quota progress -->
          <template v-if="leaveQuota.total_days > 0">
            <div class="my-4 border-t border-border/60"></div>
            <div class="mb-2 flex items-center justify-between">
              <p class="text-sm font-semibold text-foreground">{{ t('dashboard.yearQuotaUsed') }}</p>
              <span class="text-xs font-semibold text-primary">{{ leaveQuota.percent }}%</span>
            </div>
            <div class="h-2 overflow-hidden rounded-full bg-muted">
              <div class="h-full rounded-full bg-primary transition-[width] duration-500" :style="{ width: `${leaveQuota.percent}%` }"></div>
            </div>
            <p class="mt-1.5 text-xs text-muted-foreground">
              {{ t('dashboard.quotaCaption').replace('{used}', leaveQuota.used_days).replace('{total}', leaveQuota.total_days) }}
            </p>
          </template>
        </BaseCard>
      </section>

      <!-- AI INSIGHTS STRIP -->
      <section>
        <div class="mb-3 flex items-center gap-2">
          <span class="ai-sparkle">
            <svg viewBox="0 0 24 24" class="h-4 w-4" fill="currentColor">
              <path d="M12 2l1.6 4.6L18 8.2l-4.4 1.6L12 14l-1.6-4.2L6 8.2l4.4-1.6L12 2zm6 9l.9 2.6 2.6.9-2.6.9L18 18l-.9-2.6-2.6-.9 2.6-.9L18 11zM6 14l.8 2.2 2.2.8-2.2.8L6 20l-.8-2.2L3 17l2.2-.8L6 14z" />
            </svg>
          </span>
          <h2 class="text-base font-semibold text-foreground">{{ t('dashboard.aiInsights') }}</h2>
        </div>
        <div class="grid gap-4 md:grid-cols-3">
          <article v-for="insight in aiInsights" :key="insight.id" class="ai-card">
            <div class="flex items-center justify-between">
              <span class="ai-badge">
                <svg viewBox="0 0 24 24" class="h-3 w-3" fill="currentColor"><path d="M12 2l1.6 4.6L18 8.2l-4.4 1.6L12 14l-1.6-4.2L6 8.2l4.4-1.6L12 2z" /></svg>
                {{ t('common.createdByAi') }}
              </span>
              <span class="ai-confidence">{{ t('dashboard.confidence') }} {{ insight.confidence }}%</span>
            </div>
            <p class="mt-3 text-sm font-semibold text-foreground">{{ insight.title }}</p>
            <p class="mt-1 text-sm leading-6 text-muted-foreground">{{ insight.body }}</p>
          </article>
        </div>
      </section>

      <!-- BOTTOM ROW: recent activity timeline + upcoming events -->
      <section class="grid gap-4 xl:grid-cols-[minmax(0,1.25fr)_minmax(300px,0.85fr)]">
        <BaseCard v-if="canViewActivities" class="dashboard-panel" :title="t('dashboard.recentActivity')">
          <div v-if="activitiesLoading" class="empty-panel">{{ t('dashboard.loadingData') }}</div>
          <div v-else-if="activities.length === 0" class="empty-panel">{{ t('dashboard.noActivity') }}</div>
          <ul v-else class="timeline">
            <li v-for="activity in activities" :key="activity.id" class="timeline-item">
              <span class="timeline-avatar" :class="getActivityIconClass(activity.action)">
                {{ activityInitials(activity) }}
              </span>
              <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-semibold text-foreground">{{ formatActivityDescription(activity) }}</p>
                <p class="truncate text-xs text-muted-foreground">{{ activity.table_name }} #{{ activity.record_id }}</p>
              </div>
              <span class="whitespace-nowrap text-xs text-muted-foreground">{{ formatTime(activity.at) }}</span>
            </li>
          </ul>
        </BaseCard>

        <BaseCard class="dashboard-panel" :title="t('dashboard.upcoming')">
          <div v-if="upcomingEvents.length === 0" class="empty-panel">{{ t('dashboard.noUpcoming') }}</div>
          <ul v-else class="space-y-2">
            <li v-for="(event, i) in upcomingEvents" :key="i" class="upcoming-item">
              <span class="upcoming-icon" :class="event.iconClass">
                <component :is="event.icon" />
              </span>
              <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-semibold text-foreground">{{ event.name }}</p>
                <p class="truncate text-xs text-muted-foreground">{{ event.detail }}</p>
              </div>
              <span class="upcoming-when">{{ event.when }}</span>
            </li>
          </ul>
        </BaseCard>
      </section>
    </template>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted, computed, h } from 'vue';
import { Chart as ChartJS, ArcElement, Tooltip, Legend, CategoryScale, LinearScale, PointElement, LineElement, Filler } from 'chart.js';
import { Line, Doughnut } from 'vue-chartjs';
import BaseCard from '../components/BaseCard.vue';
import BaseSkeleton from '../components/BaseSkeleton.vue';
import StatCard from '../components/StatCard.vue';
import StatusPill from '../components/StatusPill.vue';
import IconUser from '../components/IconUser.vue';
import IconBuilding from '../components/IconBuilding.vue';
import IconCalendar from '../components/IconCalendar.vue';
import IconClock from '../components/IconClock.vue';
import { activityLogService } from '../services/activityLogService';
import { dashboardService } from '../services/dashboardService';
import { authService } from '../services/authService';
import { useAttendanceStore } from '../stores/attendanceStore';
import { useI18n } from '../i18n';

const { t, locale } = useI18n();

ChartJS.register(ArcElement, Tooltip, Legend, CategoryScale, LinearScale, PointElement, LineElement, Filler);

const loading = ref(true);
const loadError = ref(false);
const activitiesLoading = ref(true);
const activities = ref([]);
const serverStats = ref(null);
const canManageHr = computed(() => authService.canAccessModule('hr'));
const canRecruit = computed(() => authService.canAccessModule('recruitment'));
const canViewActivities = computed(() => authService.canAccessModule('settings'));
const canViewAttendance = computed(() => authService.canAccessModule('time'));
const attendanceStore = useAttendanceStore();
let attendancePrefetchHandle = null;

// ----- Greeting + date (hero) -----
const userName = computed(() => {
  const user = authService.getUser();
  return user?.name || user?.full_name || '';
});

const greeting = computed(() => {
  const h = new Date().getHours();
  if (h < 12) return t('dashboard.greetingMorning');
  if (h < 18) return t('dashboard.greetingAfternoon');
  return t('dashboard.greetingEvening');
});

const todayLabel = computed(() => {
  const loc = locale.value === 'en' ? 'en-US' : 'vi-VN';
  return new Date().toLocaleDateString(loc, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
});

const statusLabels = {
  ACTIVE: 'Đang làm',
  PROBATION: 'Thử việc',
  ON_LEAVE: 'Đang nghỉ',
  TERMINATED: 'Đã nghỉ',
  PENDING: 'Chờ duyệt',
  APPROVED: 'Đã duyệt',
  REJECTED: 'Từ chối',
  CANCELLED: 'Đã hủy',
  HIRED: 'Đã tuyển',
  SCREENING: 'Sàng lọc',
  INTERVIEWING: 'Phỏng vấn',
  OFFERED: 'Đã offer',
  OPEN_POSITIONS: 'Vị trí mở',
  CANDIDATES: 'Ứng viên',
  INTERVIEWS: 'Phỏng vấn',
  UNKNOWN: 'Chưa xác định',
  'CHỜ_DUYỆT': 'Chờ duyệt',
  'ĐÃ_DUYỆT': 'Đã duyệt',
  'TỪ_CHỐI': 'Từ chối',
  'ĐÃ_HỦY': 'Đã hủy'
};

function formatLabel(value, fallback = 'Chưa xác định') {
  if (value === null || value === undefined || value === '') return fallback;
  const key = String(value).toUpperCase();
  return statusLabels[key] || String(value).replaceAll('_', ' ');
}

function normalizeCountCollection(source, labelKey = 'label', fallbackLabel = 'Chưa xác định') {
  if (Array.isArray(source)) {
    return source
      .map((item, index) => {
        if (!item || typeof item !== 'object') {
          return { label: formatLabel(item, fallbackLabel), count: 0, id: index };
        }
        const rawLabel = item[labelKey] ?? item.name ?? item.status ?? item.stage ?? item.department_name;
        return {
          ...item,
          label: formatLabel(rawLabel, fallbackLabel),
          count: Number(item.count ?? item.headcount ?? item.total ?? item.value ?? 0)
        };
      })
      .filter(item => item.count >= 0);
  }

  if (source && typeof source === 'object') {
    return Object.entries(source).map(([key, value]) => ({
      label: formatLabel(key, fallbackLabel),
      count: Number(value || 0)
    }));
  }

  return [];
}

// ----- Real-data computed accessors (all derive from serverStats; ZERO fallback) -----
const totalEmployees = computed(() => Number(serverStats.value?.employees?.total ?? 0));

const pendingLeaves = computed(() => Number(serverStats.value?.leave_requests?.pending ?? 0));

const todayAttendance = computed(() => {
  const att = serverStats.value?.attendances_today;
  if (att && typeof att === 'object') {
    return {
      present: Number(att.present ?? 0),
      late: Number(att.late ?? 0),
      early: Number(att.early ?? 0),
      absent: Number(att.absent ?? 0)
    };
  }
  return { present: 0, late: 0, early: 0, absent: 0 };
});

// Tăng ca (OT) — tổng tháng + xu hướng 30 ngày (giờ OT đã duyệt).
const overtimeStats = computed(() => {
  const ot = serverStats.value?.overtime;
  return {
    month_hours: Number(ot?.month_hours ?? 0),
    month_requests: Number(ot?.month_requests ?? 0),
    pending: Number(ot?.pending ?? 0),
    trend: Array.isArray(ot?.trend) ? ot.trend : [],
  };
});

const overtimeLineData = computed(() => {
  const trend = overtimeStats.value.trend;
  const labels = trend.map(p => { const d = new Date(p.date); return `${d.getDate()}/${d.getMonth() + 1}`; });
  return {
    labels,
    datasets: [{
      label: 'Giờ OT', data: trend.map(p => Number(p.hours || 0)),
      borderColor: WARNING, backgroundColor: WARNING_SOFT,
      fill: true, tension: 0.4, borderWidth: 2, pointRadius: 0, pointHoverRadius: 4,
    }],
  };
});

const employeesByStatus = computed(() => normalizeCountCollection(serverStats.value?.employees?.by_status || {}));

const employeesByDepartment = computed(() =>
  normalizeCountCollection(serverStats.value?.employees?.by_department || {}, 'department_name', 'Chưa phân bổ')
);

const activeDepartmentCount = computed(() => employeesByDepartment.value.filter(item => item.count > 0).length);

const attendanceTrend = computed(() => {
  const trend = serverStats.value?.attendance_trend;
  return Array.isArray(trend) ? trend : [];
});

const recruitmentSummary = computed(() => {
  const recruitment = serverStats.value?.recruitment;
  if (!recruitment) return [];
  const rows = normalizeCountCollection(recruitment.by_status || {});
  if (rows.length > 0) return rows;
  return Object.entries(recruitment)
    .filter(([key]) => key !== 'by_status' && key !== 'total')
    .map(([key, value]) => ({ label: formatLabel(key), count: Number(value || 0) }))
    .filter(item => item.count > 0);
});

const recruitmentTotal = computed(() => {
  const explicit = serverStats.value?.recruitment?.total;
  if (explicit != null) return Number(explicit);
  return recruitmentSummary.value.reduce((sum, item) => sum + Number(item.count || 0), 0);
});

const contractsExpiringSoon = computed(() => {
  const contracts = serverStats.value?.contracts;
  if (!contracts) return 0;
  return contracts.expiring_within_30_days ?? contracts.expiring_soon ?? contracts.expiring ?? 0;
});

const todayAttendanceTotal = computed(() => {
  const att = todayAttendance.value;
  return Number(att.present || 0) + Number(att.late || 0) + Number(att.absent || 0);
});

const attendanceRate = computed(() => {
  if (!todayAttendanceTotal.value) return 0;
  return Math.round((Number(todayAttendance.value.present || 0) / todayAttendanceTotal.value) * 100);
});

const maxDepartmentCount = computed(() => Math.max(1, ...employeesByDepartment.value.map(item => Number(item.count || 0))));

// KPI sparkline: present counts from the attendance trend (last ~14 points), normalized to %.
const deptSparkline = computed(() => {
  const points = attendanceTrend.value.slice(-14).map(p => Number(p.present || 0));
  if (points.length === 0) return [];
  const max = Math.max(1, ...points);
  return points.map(v => Math.max(8, Math.round((v / max) * 100)));
});

function barPercent(value, max) {
  if (!max) return 0;
  return Math.max(4, Math.min(100, Math.round((Number(value || 0) / max) * 100)));
}

// ----- AI INSIGHTS (derived client-side from real stats, honestly labelled) -----
const aiInsights = computed(() => {
  const out = [];
  const trend = attendanceTrend.value;

  if (trend.length >= 14) {
    const last7 = trend.slice(-7).reduce((s, p) => s + Number(p.present || 0), 0);
    const prev7 = trend.slice(-14, -7).reduce((s, p) => s + Number(p.present || 0), 0);
    if (prev7 > 0) {
      const delta = Math.round(((last7 - prev7) / prev7) * 100);
      const up = delta >= 0;
      out.push({
        id: 'attendance-trend',
        title: locale.value === 'en'
          ? `Attendance ${up ? 'up' : 'down'} ${Math.abs(delta)}% week-over-week`
          : `Chuyên cần ${up ? 'tăng' : 'giảm'} ${Math.abs(delta)}% so với tuần trước`,
        body: locale.value === 'en'
          ? `Present check-ins ${up ? 'rose' : 'fell'} from ${prev7} to ${last7} over the last two weeks.`
          : `Số lượt có mặt ${up ? 'tăng' : 'giảm'} từ ${prev7} xuống ${last7} trong hai tuần gần nhất.`,
        confidence: 88,
      });
    }
  }

  if (canManageHr.value && contractsExpiringSoon.value > 0) {
    out.push({
      id: 'contracts-expiring',
      title: locale.value === 'en'
        ? `${contractsExpiringSoon.value} contract(s) expiring soon`
        : `${contractsExpiringSoon.value} hợp đồng sắp hết hạn`,
      body: locale.value === 'en'
        ? `Plan renewals now to avoid lapses within the next 30 days.`
        : `Nên lên kế hoạch gia hạn để tránh gián đoạn trong 30 ngày tới.`,
      confidence: 82,
    });
  }

  if (pendingLeaves.value > 0) {
    out.push({
      id: 'pending-leaves',
      title: locale.value === 'en'
        ? `${pendingLeaves.value} leave request(s) awaiting approval`
        : `${pendingLeaves.value} đơn nghỉ đang chờ duyệt`,
      body: locale.value === 'en'
        ? `Clearing these keeps schedules accurate and employees informed.`
        : `Duyệt sớm giúp lịch làm việc chính xác và nhân viên nắm rõ tình trạng.`,
      confidence: 79,
    });
  }

  if (out.length === 0) {
    out.push({
      id: 'all-clear',
      title: locale.value === 'en' ? 'Everything looks healthy' : 'Mọi chỉ số đang ổn định',
      body: locale.value === 'en'
        ? 'No urgent items detected across attendance, leave or contracts.'
        : 'Không phát hiện vấn đề cần xử lý gấp về chấm công, nghỉ phép hay hợp đồng.',
      confidence: 75,
    });
  }

  return out.slice(0, 3);
});

// ----- Chart palette (dark-mode safe rgba; transparent borders + soft fills) -----
const SUCCESS = 'rgba(34, 197, 94, 1)';
const SUCCESS_SOFT = 'rgba(34, 197, 94, 0.15)';
const WARNING = 'rgba(234, 179, 8, 1)';
const WARNING_SOFT = 'rgba(234, 179, 8, 0.12)';
const DESTRUCTIVE = 'rgba(239, 68, 68, 0.85)';
const MUTED = 'rgba(148, 163, 184, 0.5)';

// CHARTS ROW LEFT: attendance trend as a smooth AREA line (present + late).
const attendanceLineData = computed(() => {
  const trend = attendanceTrend.value;
  const labels = trend.map(point => {
    const d = new Date(point.date);
    return `${d.getDate()}/${d.getMonth() + 1}`;
  });
  return {
    labels,
    datasets: [
      {
        label: t('dashboard.present'),
        data: trend.map(p => Number(p.present || 0)),
        borderColor: SUCCESS,
        backgroundColor: SUCCESS_SOFT,
        fill: true,
        tension: 0.4,
        borderWidth: 2,
        pointRadius: 0,
        pointHoverRadius: 4,
      },
      {
        label: t('dashboard.late'),
        data: trend.map(p => Number(p.late || 0)),
        borderColor: WARNING,
        backgroundColor: WARNING_SOFT,
        fill: true,
        tension: 0.4,
        borderWidth: 2,
        pointRadius: 0,
        pointHoverRadius: 4,
      },
    ],
  };
});

const lineOptions = {
  responsive: true,
  maintainAspectRatio: false,
  interaction: { mode: 'index', intersect: false },
  plugins: {
    legend: { display: false },
    tooltip: { mode: 'index', intersect: false },
  },
  scales: {
    x: { grid: { display: false }, ticks: { maxTicksLimit: 10, color: MUTED } },
    y: { beginAtZero: true, grid: { color: 'rgba(148, 163, 184, 0.18)' }, ticks: { color: MUTED } },
  },
};

// CHARTS ROW RIGHT: attendance ring (Doughnut, ~72% cutout) with centered % overlay.
const attendanceRingData = computed(() => {
  const present = Number(todayAttendance.value.present || 0);
  const remainder = Math.max(0, todayAttendanceTotal.value - present);
  return {
    labels: [t('dashboard.present'), t('dashboard.absent')],
    datasets: [
      {
        data: todayAttendanceTotal.value === 0 ? [0, 1] : [present, remainder],
        backgroundColor: [SUCCESS, 'rgba(148, 163, 184, 0.18)'],
        borderWidth: 0,
      },
    ],
  };
});

const ringOptions = {
  responsive: true,
  maintainAspectRatio: false,
  cutout: '72%',
  plugins: {
    legend: { display: false },
    tooltip: { enabled: false },
  },
};

// DISTRIBUTION ROW RIGHT: leave-by-status donut.
const leaveTotal = computed(() => {
  const byStatus = serverStats.value?.leave_requests?.by_status;
  if (!byStatus || typeof byStatus !== 'object') return 0;
  return Object.values(byStatus).reduce((s, v) => s + Number(v || 0), 0);
});

const leaveChartData = computed(() => {
  const byStatus = serverStats.value?.leave_requests?.by_status || {};
  const pending = Number(byStatus.pending ?? byStatus.PENDING ?? byStatus['CHỜ_DUYỆT'] ?? 0);
  const approved = Number(byStatus.approved ?? byStatus.APPROVED ?? byStatus['ĐÃ_DUYỆT'] ?? 0);
  const rejected = Number(byStatus.rejected ?? byStatus.REJECTED ?? byStatus['TỪ_CHỐI'] ?? 0);
  return {
    labels: [formatLabel('PENDING'), formatLabel('APPROVED'), formatLabel('REJECTED')],
    datasets: [
      {
        data: [pending, approved, rejected],
        backgroundColor: [WARNING, SUCCESS, DESTRUCTIVE],
        borderColor: 'transparent',
        borderWidth: 2,
      },
    ],
  };
});

const leaveDoughnutOptions = {
  responsive: true,
  maintainAspectRatio: false,
  cutout: '72%',
  plugins: {
    legend: { display: false },
    tooltip: { enabled: true },
  },
};

// Right-hand legend rows for the leave donut (status -> count + color).
const leaveLegend = computed(() => {
  const byStatus = serverStats.value?.leave_requests?.by_status || {};
  const pending = Number(byStatus.pending ?? byStatus.PENDING ?? byStatus['CHỜ_DUYỆT'] ?? 0);
  const approved = Number(byStatus.approved ?? byStatus.APPROVED ?? byStatus['ĐÃ_DUYỆT'] ?? 0);
  const rejected = Number(byStatus.rejected ?? byStatus.REJECTED ?? byStatus['TỪ_CHỐI'] ?? 0);
  return [
    { key: 'pending', label: formatLabel('PENDING'), count: pending, color: WARNING },
    { key: 'approved', label: formatLabel('APPROVED'), count: approved, color: SUCCESS },
    { key: 'rejected', label: formatLabel('REJECTED'), count: rejected, color: DESTRUCTIVE },
  ];
});

// "Đang nghỉ hôm nay" — array from API (already filtered to leaves spanning today).
const onLeaveToday = computed(() => {
  const list = serverStats.value?.leave_requests?.on_leave_today;
  return Array.isArray(list) ? list : [];
});

const onLeaveTodayVisible = computed(() => onLeaveToday.value.slice(0, 3));

// "Quỹ phép năm đã dùng" progress.
const leaveQuota = computed(() => {
  const q = serverStats.value?.leave_requests?.quota;
  return {
    used_days: Number(q?.used_days ?? 0),
    total_days: Number(q?.total_days ?? 0),
    percent: Number(q?.percent ?? 0),
  };
});

function leaveInitials(name) {
  const parts = String(name || '?').trim().split(/\s+/).filter(Boolean);
  if (parts.length === 0) return '?';
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

// "đến {date}" if the leave extends beyond today, else "hôm nay".
function leaveUntilLabel(endDate) {
  if (!endDate) return t('dashboard.today');
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const end = new Date(endDate);
  end.setHours(0, 0, 0, 0);
  if (end > today) return t('dashboard.until').replace('{date}', formatShortDate(endDate));
  return t('dashboard.today');
}

// ----- Upcoming events widget (stats.upcoming) -----
// Tiny inline icon renderers (no new deps).
const CakeIcon = () => h('svg', { class: 'h-4 w-4', fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24' }, [
  h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M12 3v4m0 0c-1.5 0-2 1-2 2v1h4V9c0-1-.5-2-2-2zM5 13h14v7a1 1 0 01-1 1H6a1 1 0 01-1-1v-7zM4 13c0-1.5 1-2 2-2h12c1 0 2 .5 2 2' }),
]);
const AwardIcon = () => h('svg', { class: 'h-4 w-4', fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24' }, [
  h('circle', { cx: '12', cy: '8', r: '5', 'stroke-width': '2' }),
  h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M8.5 12.5L7 21l5-2.5L17 21l-1.5-8.5' }),
]);
const UserPlusIcon = () => h('svg', { class: 'h-4 w-4', fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24' }, [
  h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM3 21a7 7 0 0113 0M19 8v6M22 11h-6' }),
]);

function whenLabel(daysUntil) {
  const n = Number(daysUntil || 0);
  if (n <= 0) return t('dashboard.today');
  return t('dashboard.inDays').replace('{n}', n);
}

const upcomingEvents = computed(() => {
  const up = serverStats.value?.upcoming;
  if (!up) return [];
  const out = [];

  (up.birthdays || []).forEach(b => {
    out.push({
      sort: Number(b.days_until ?? 99),
      icon: CakeIcon,
      iconClass: 'bg-warning/15 text-warning',
      name: b.full_name,
      detail: t('dashboard.birthday'),
      when: whenLabel(b.days_until),
    });
  });

  (up.work_anniversaries || []).forEach(a => {
    out.push({
      sort: Number(a.days_until ?? 99),
      icon: AwardIcon,
      iconClass: 'bg-ai/15 text-ai',
      name: a.full_name,
      detail: t('dashboard.yearsAt').replace('{n}', a.years),
      when: whenLabel(a.days_until),
    });
  });

  (up.new_hires || []).forEach(n => {
    out.push({
      sort: -1,
      icon: UserPlusIcon,
      iconClass: 'bg-success/15 text-success',
      name: n.full_name,
      detail: t('dashboard.newHire'),
      when: t('dashboard.joined').replace('{date}', formatShortDate(n.hire_date)),
    });
  });

  return out.sort((a, b) => a.sort - b.sort).slice(0, 8);
});

function formatShortDate(dateStr) {
  if (!dateStr) return '';
  const d = new Date(dateStr);
  const loc = locale.value === 'en' ? 'en-US' : 'vi-VN';
  return d.toLocaleDateString(loc, { day: 'numeric', month: 'short' });
}

// ----- Activity timeline -----
function activityInitials(activity) {
  const name = activity.user_name || activity.actor || activity.table_name || activity.action || '?';
  const parts = String(name).trim().split(/\s+/).filter(Boolean);
  if (parts.length === 0) return '?';
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

function getActivityIconClass(action) {
  switch (action) {
    case 'create':
    case 'insert':
      return 'bg-success/15 text-success';
    case 'update':
      return 'bg-info/15 text-info';
    case 'delete':
      return 'bg-destructive/15 text-destructive';
    default:
      return 'bg-muted text-muted-foreground';
  }
}

function formatActivityDescription(activity) {
  const actionMap = locale.value === 'en'
    ? { create: 'Created', insert: 'Added', update: 'Updated', delete: 'Deleted' }
    : { create: 'Tạo mới', insert: 'Thêm', update: 'Cập nhật', delete: 'Xóa' };
  const action = actionMap[activity.action] || activity.action;
  return `${action} ${activity.table_name}`;
}

function formatTime(dateStr) {
  if (!dateStr) return '';
  const date = new Date(dateStr);
  const now = new Date();
  const diff = now - date;
  const minutes = Math.floor(diff / 60000);
  const hours = Math.floor(diff / 3600000);
  const days = Math.floor(diff / 86400000);
  const en = locale.value === 'en';

  if (minutes < 1) return en ? 'Just now' : 'Vừa xong';
  if (minutes < 60) return en ? `${minutes}m ago` : `${minutes} phút trước`;
  if (hours < 24) return en ? `${hours}h ago` : `${hours} giờ trước`;
  if (days < 7) return en ? `${days}d ago` : `${days} ngày trước`;
  return date.toLocaleDateString(en ? 'en-US' : 'vi-VN');
}

async function fetchActivities() {
  if (!canViewActivities.value) {
    activities.value = [];
    activitiesLoading.value = false;
    return;
  }

  try {
    activitiesLoading.value = true;
    const response = await activityLogService.getAll();
    const data = response?.data || response || [];
    activities.value = Array.isArray(data) ? data.slice(0, 8) : [];
  } catch (err) {
    console.error('Error loading activities:', err);
    activities.value = [];
  } finally {
    activitiesLoading.value = false;
  }
}

async function loadDashboard() {
  loading.value = true;
  loadError.value = false;
  try {
    const statsData = await dashboardService.getStats();
    serverStats.value = statsData || null;
    // Activities are non-critical: failure here does not blank the dashboard.
    await fetchActivities();
  } catch (apiError) {
    console.error('Dashboard stats unavailable:', apiError?.message || apiError);
    // CRITICAL: do NOT fabricate data. Leave everything empty and warn the user.
    serverStats.value = null;
    activities.value = [];
    activitiesLoading.value = false;
    loadError.value = true;
  } finally {
    loading.value = false;
  }
}

const prefetchAttendance = () => {
  if (!canViewAttendance.value) return;
  const now = new Date();
  const params = {
    from: new Date(now.getFullYear(), now.getMonth(), 1).toLocaleDateString('en-CA'),
    to: new Date(now.getFullYear(), now.getMonth() + 1, 0).toLocaleDateString('en-CA'),
  };
  attendanceStore.prefetchFirstPage(params).catch(() => {});
};

onMounted(() => {
  loadDashboard();
  if ('requestIdleCallback' in window) {
    attendancePrefetchHandle = window.requestIdleCallback(prefetchAttendance, { timeout: 2500 });
  } else {
    attendancePrefetchHandle = window.setTimeout(prefetchAttendance, 1200);
  }
});

onUnmounted(() => {
  if (attendancePrefetchHandle === null) return;
  if ('cancelIdleCallback' in window) window.cancelIdleCallback(attendancePrefetchHandle);
  else window.clearTimeout(attendancePrefetchHandle);
});
</script>

<style scoped>
.dashboard-page {
  --panel-radius: 0.85rem;
}

.dashboard-hero {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  justify-content: space-between;
  gap: 1rem;
  border: 1px solid hsl(var(--card-border));
  border-radius: var(--panel-radius);
  padding: 1.25rem 1.5rem;
  background: linear-gradient(135deg, hsl(var(--card)) 0%, hsl(var(--primary) / 0.05) 100%);
  box-shadow: var(--shadow-sm);
}

.dashboard-panel {
  border: 1px solid hsl(var(--card-border));
  background: hsl(var(--card) / 0.96);
  box-shadow: var(--shadow-sm);
}

.dashboard-action {
  display: inline-flex;
  min-height: 2.25rem;
  align-items: center;
  gap: 0.45rem;
  border-radius: 0.65rem;
  border: 1px solid hsl(var(--border));
  background: hsl(var(--card));
  padding: 0.5rem 0.75rem;
  font-size: 0.8125rem;
  font-weight: 700;
  color: hsl(var(--foreground));
  transition: border-color 0.2s ease, background-color 0.2s ease, color 0.2s ease, transform 0.2s ease;
}

.dashboard-action:hover {
  border-color: hsl(var(--primary) / 0.35);
  color: hsl(var(--primary));
  transform: translateY(-1px);
}

.dashboard-action--primary {
  border-color: hsl(var(--primary) / 0.2);
  background: hsl(var(--primary));
  color: hsl(var(--primary-foreground));
}

.dashboard-action--primary:hover {
  color: hsl(var(--primary-foreground));
  background: hsl(var(--primary) / 0.92);
}

/* KPI sparkline */
.sparkline {
  display: flex;
  align-items: flex-end;
  gap: 2px;
  height: 100%;
  width: 100%;
}

.sparkline__bar {
  flex: 1 1 0;
  min-width: 2px;
  border-radius: 2px 2px 0 0;
  background: hsl(var(--primary) / 0.45);
  transition: height 0.4s ease;
}

/* AI visual language */
.ai-sparkle {
  display: inline-flex;
  height: 1.85rem;
  width: 1.85rem;
  align-items: center;
  justify-content: center;
  border-radius: 0.6rem;
  color: hsl(var(--ai-foreground));
  background: linear-gradient(135deg, hsl(var(--ai)) 0%, hsl(217 91% 60%) 100%);
}

.ai-card {
  position: relative;
  border-radius: var(--panel-radius);
  border: 1px solid hsl(var(--ai) / 0.25);
  background: hsl(var(--ai-soft));
  padding: 1rem;
  box-shadow: var(--shadow-sm);
  transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.ai-card:hover {
  transform: translateY(-1px);
  box-shadow: var(--shadow-md);
}

.ai-badge {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  border-radius: 999px;
  padding: 0.15rem 0.55rem;
  font-size: 0.6875rem;
  font-weight: 700;
  color: hsl(var(--ai));
  background: hsl(var(--ai) / 0.12);
  border: 1px solid hsl(var(--ai) / 0.25);
}

.ai-confidence {
  font-size: 0.6875rem;
  font-weight: 600;
  color: hsl(var(--ai));
}

.chart-legend-dot {
  display: inline-flex;
  height: 0.55rem;
  width: 0.55rem;
  border-radius: 999px;
}

/* Attendance ring */
.ring-wrap {
  position: relative;
  height: 11rem;
  width: 11rem;
}

/* Leave donut (smaller, with centered total) */
.leave-ring {
  position: relative;
  height: 8.5rem;
  width: 8.5rem;
  flex-shrink: 0;
}

.ring-center {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  pointer-events: none;
}

.ring-center__value {
  font-size: 1.75rem;
  font-weight: 800;
  line-height: 1;
  color: hsl(var(--foreground));
}

.ring-center__label {
  margin-top: 0.2rem;
  font-size: 0.7rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: hsl(var(--muted-foreground));
}

.ring-stat {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  border-radius: 0.65rem;
  border: 1px solid hsl(var(--border) / 0.65);
  background: hsl(var(--muted) / 0.18);
  padding: 0.5rem 0.25rem;
}

.ring-stat__num {
  font-size: 1.05rem;
  font-weight: 800;
  line-height: 1;
}

.ring-stat__lbl {
  font-size: 0.65rem;
  font-weight: 600;
  color: hsl(var(--muted-foreground));
}

.rank-row {
  border-radius: 0.85rem;
  border: 1px solid hsl(var(--border) / 0.72);
  background: hsl(var(--muted) / 0.18);
  padding: 0.85rem;
}

.empty-panel {
  padding: 2rem 0;
  text-align: center;
  font-size: 0.875rem;
  color: hsl(var(--muted-foreground));
}

/* Activity timeline */
.timeline {
  display: flex;
  flex-direction: column;
}

.timeline-item {
  position: relative;
  display: flex;
  align-items: center;
  gap: 0.85rem;
  padding: 0.7rem 0;
}

.timeline-item + .timeline-item {
  border-top: 1px solid hsl(var(--border) / 0.55);
}

.timeline-avatar {
  display: flex;
  height: 2.25rem;
  width: 2.25rem;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  border-radius: 999px;
  font-size: 0.7rem;
  font-weight: 800;
}

/* Upcoming list */
.upcoming-item {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  border-radius: 0.75rem;
  border: 1px solid hsl(var(--border) / 0.6);
  background: hsl(var(--muted) / 0.16);
  padding: 0.6rem 0.75rem;
  transition: border-color 0.2s ease, transform 0.2s ease;
}

.upcoming-item:hover {
  border-color: hsl(var(--primary) / 0.28);
  transform: translateY(-1px);
}

.upcoming-icon {
  display: flex;
  height: 2.1rem;
  width: 2.1rem;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  border-radius: 0.65rem;
}

.upcoming-when {
  white-space: nowrap;
  font-size: 0.7rem;
  font-weight: 600;
  color: hsl(var(--muted-foreground));
}

@media (max-width: 640px) {
  .dashboard-hero {
    padding: 1rem;
  }

  .dashboard-action {
    flex: 1 1 auto;
    justify-content: center;
  }
}
</style>
