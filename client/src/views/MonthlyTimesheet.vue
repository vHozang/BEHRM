<template>
  <div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
      <div>
        <h1 class="text-3xl font-bold text-foreground">Bảng công tháng</h1>
        <p class="text-muted-foreground mt-1">
          Tổng hợp công nhân viên × ngày — phân loại theo ca làm việc &amp; Bộ luật Lao động VN.
        </p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <!-- Điều hướng tháng -->
        <div class="flex items-center rounded-lg border border-border overflow-hidden">
          <button @click="shiftMonth(-1)" :disabled="loading" class="px-3 py-2 hover:bg-muted text-foreground disabled:opacity-50" title="Tháng trước">‹</button>
          <input
            type="month"
            v-model="month"
            class="px-3 py-2 bg-card text-foreground text-sm border-x border-border focus:outline-none"
            @change="loadTimesheet"
            data-testid="timesheet-month"
          />
          <button @click="shiftMonth(1)" :disabled="loading" class="px-3 py-2 hover:bg-muted text-foreground disabled:opacity-50" title="Tháng sau">›</button>
        </div>
        <button
          @click="showHelp = !showHelp"
          class="px-4 py-2 rounded-lg border border-border text-sm font-medium text-foreground hover:bg-muted"
          title="Hướng dẫn sử dụng trang Bảng công tháng"
        >
          ❓ Hướng dẫn
        </button>
        <select v-model="exportFormat" class="rounded-lg border border-border bg-card px-3 py-2 text-sm">
          <option value="xlsx">Excel (.xlsx)</option>
          <option value="csv">CSV (.csv)</option>
        </select>
        <button
          @click="exportTimesheet"
          :disabled="loading || !rows.length"
          class="px-4 py-2 rounded-lg border border-border text-sm font-medium text-foreground hover:bg-muted disabled:opacity-50"
          title="Xuất bảng công ra Excel/CSV"
        >
          {{ exporting ? 'Đang xuất…' : '⭳ Xuất toàn bộ' }}
        </button>
        <button
          @click="recompute"
          :disabled="loading || recomputing"
          class="px-4 py-2 rounded-lg border border-border text-sm font-medium text-foreground hover:bg-muted disabled:opacity-50"
          data-testid="timesheet-recompute"
          title="Tái phân loại trạng thái chấm công theo ca + dung sai đi trễ"
        >
          {{ recomputing ? 'Đang xử lý…' : 'Tái phân loại' }}
        </button>
        <button
          v-if="salaryPeriod && salaryPeriod.status !== 'CLOSED'"
          @click="summarizeToPayroll"
          :disabled="loading || summarizing"
          class="px-4 py-2 rounded-lg bg-primary text-primary-foreground text-sm font-medium hover:opacity-90 disabled:opacity-50"
          title="Chốt tổng hợp công của tháng để feed sang tính lương"
        >
          {{ summarizing ? 'Đang chốt…' : 'Chốt công → Lương' }}
        </button>
        <button
          v-if="salaryPeriod && salaryPeriod.status !== 'CLOSED'"
          @click="lockPeriod"
          :disabled="loading || locking"
          class="px-4 py-2 rounded-lg border border-red-500/40 text-red-600 dark:text-red-400 text-sm font-medium hover:bg-red-500/10 disabled:opacity-50"
          title="Khoá kỳ lương — không cho sửa chấm công sau khi đã tính lương"
        >
          {{ locking ? 'Đang khoá…' : '🔒 Khoá kỳ' }}
        </button>
      </div>
    </div>

    <!-- Tiêu đề tháng + trạng thái kỳ lương -->
    <div class="flex flex-wrap items-center gap-3">
      <h2 class="text-xl font-semibold text-foreground">{{ monthLabel }}</h2>
      <span v-if="salaryPeriod" :class="['px-2 py-0.5 rounded text-xs font-medium',
            salaryPeriod.status === 'CLOSED' ? 'bg-green-500/15 text-green-700 dark:text-green-400' : 'bg-amber-500/15 text-amber-700 dark:text-amber-400']">
        Kỳ lương {{ salaryPeriod.period_code }} · {{ salaryPeriod.status === 'CLOSED' ? 'Đã chốt' : 'Đang mở' }}
      </span>
      <span v-else class="text-xs text-muted-foreground">Chưa có kỳ lương cho tháng này</span>
    </div>

    <!-- Hướng dẫn sử dụng -->
    <BaseCard v-if="showHelp">
      <div class="space-y-3 text-sm">
        <div class="flex items-center justify-between">
          <h3 class="font-semibold text-foreground">Trang này dùng để làm gì?</h3>
          <button @click="showHelp = false" class="text-muted-foreground hover:text-foreground text-xs">Ẩn ✕</button>
        </div>
        <p class="text-muted-foreground">
          <strong class="text-foreground">Bảng công tháng</strong> tổng hợp công của <strong class="text-foreground">mọi nhân viên × từng ngày</strong> trong tháng.
          Đây là <strong class="text-foreground">nguồn dữ liệu để tính lương</strong>: hệ thống tự gom từ chấm công, ca làm việc, nghỉ phép, lễ tết và tăng ca.
          Bạn <strong class="text-foreground">không nhập tay</strong> ở đây — chỉ kiểm tra, đối soát rồi “chốt” để đẩy sang lương.
        </p>

        <div>
          <p class="font-medium text-foreground mb-1">Quy trình chuẩn mỗi tháng:</p>
          <ol class="list-decimal list-inside space-y-1 text-muted-foreground">
            <li><strong class="text-foreground">Nhân viên chấm công</strong> hằng ngày (hoặc HR sửa qua “Đơn điều chỉnh công”).</li>
            <li>Bảng công <strong class="text-foreground">tự tổng hợp</strong>. Cuối tháng bấm <strong class="text-foreground">“Tái phân loại”</strong> để cập nhật lại trễ/sớm/vắng theo ca (nếu vừa đổi cấu hình/ca).</li>
            <li>Đối soát các ô bất thường: <strong class="text-foreground">VẮNG</strong> (bấm vào ô để tạo đơn điều chỉnh), <strong class="text-foreground">Chờ duyệt phép</strong>, <strong class="text-foreground">đi làm ngày nghỉ/lễ</strong> (ô chàm ＋ → nhắc tạo đơn OT).</li>
            <li><strong class="text-foreground">“Chốt công → Lương”</strong>: ghi số liệu công của tháng vào kỳ lương rồi mở trang Lương để tính/duyệt.</li>
            <li><strong class="text-foreground">“🔒 Khoá kỳ”</strong>: sau khi lương đã đúng — khoá cứng để không ai sửa chấm công/lương của kỳ nữa.</li>
          </ol>
        </div>

        <div>
          <p class="font-medium text-foreground mb-1">Các nút thao tác:</p>
          <ul class="space-y-1 text-muted-foreground">
            <li>• <strong class="text-foreground">‹ ›</strong> + ô tháng: xem tháng khác.</li>
            <li>• <strong class="text-foreground">⭳ Xuất Excel</strong>: tải bảng công ra file (CSV, mở bằng Excel).</li>
            <li>• <strong class="text-foreground">Tái phân loại</strong>: chạy lại engine phân loại trạng thái từ giờ chấm công (không tạo dữ liệu mới).</li>
            <li>• <strong class="text-foreground">Chốt công → Lương</strong>: tổng hợp <code>công chuẩn / công thực / vắng / nghỉ / OT</code> vào bảng <em>salary_attendance_summary</em> của kỳ → trang Lương dùng số này để prorate lương + BHXH + thuế.</li>
            <li>• <strong class="text-foreground">🔒 Khoá kỳ</strong>: đặt kỳ lương sang trạng thái <em>CLOSED</em> → chặn sửa/tính lại. Chỉ làm khi đã chắc chắn.</li>
          </ul>
        </div>

        <div>
          <p class="font-medium text-foreground mb-1">Cột tổng hợp bên phải:</p>
          <p class="text-muted-foreground">
            <strong class="text-foreground">Công</strong> = công thực tế trả lương (ngày làm đủ = 1, nửa công = 0.5, nghỉ phép có lương = 1) ·
            <strong class="text-foreground">Trễ/Sớm (p)</strong> = tổng phút · <strong class="text-foreground">Vắng / Nghỉ / OT(h)</strong>.
            “Công chuẩn tháng” (thẻ trên) = số ngày làm chuẩn (đã trừ ngày nghỉ tuần + lễ) — là mẫu số để prorate lương.
          </p>
        </div>
      </div>
    </BaseCard>

    <!-- Summary stat cards -->
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
      <BaseCard>
        <div class="text-center">
          <p class="text-sm text-muted-foreground">Công chuẩn tháng</p>
          <p class="text-3xl font-bold text-primary mt-2">{{ standardDays }}</p>
          <p class="text-xs text-muted-foreground mt-1">ngày (đã trừ CN + lễ)</p>
        </div>
      </BaseCard>
      <BaseCard>
        <div class="text-center">
          <p class="text-sm text-muted-foreground">Đúng giờ</p>
          <p class="text-3xl font-bold text-green-600 dark:text-green-400 mt-2">{{ totals.on_time_days }}</p>
        </div>
      </BaseCard>
      <BaseCard>
        <div class="text-center">
          <p class="text-sm text-muted-foreground">Đi muộn</p>
          <p class="text-3xl font-bold text-amber-600 dark:text-amber-400 mt-2">{{ totals.late_days }}</p>
        </div>
      </BaseCard>
      <BaseCard>
        <div class="text-center">
          <p class="text-sm text-muted-foreground">Về sớm</p>
          <p class="text-3xl font-bold text-orange-600 dark:text-orange-400 mt-2">{{ totals.early_leave_days }}</p>
        </div>
      </BaseCard>
      <BaseCard>
        <div class="text-center">
          <p class="text-sm text-muted-foreground">Vắng</p>
          <p class="text-3xl font-bold text-red-600 dark:text-red-400 mt-2">{{ totals.absent_days }}</p>
        </div>
      </BaseCard>
    </div>

    <!-- Legend -->
    <div class="flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
      <span class="font-medium text-foreground">Chú thích:</span>
      <span v-for="lg in legend" :key="lg.code" class="inline-flex items-center gap-1.5">
        <span :class="['inline-flex w-6 h-5 items-center justify-center rounded font-semibold', lg.cls]">{{ lg.char }}</span>
        {{ lg.label }}
      </span>
      <span class="inline-flex items-center gap-1.5">
        <span class="inline-flex w-6 h-5 items-center justify-center rounded bg-indigo-500/25 text-indigo-700 dark:text-indigo-300 font-semibold">✓</span>
        Đi làm ngày nghỉ/lễ (gợi ý OT)
      </span>
      <span class="inline-flex items-center gap-2 pr-1">
        <span class="relative inline-flex w-6 h-5 items-center justify-center rounded bg-green-500/15 text-green-700 dark:text-green-400 font-semibold">✓<span class="absolute -top-2 -right-2 min-w-[14px] h-[14px] px-1 flex items-center justify-center text-[9px] leading-none rounded-full bg-amber-500 text-white font-bold ring-1 ring-card">2</span></span>
        Số nhỏ = giờ OT đã duyệt ngày đó
      </span>
    </div>

    <!-- Grid -->
    <BaseCard>
      <div v-if="loading" class="py-16 text-center text-muted-foreground">Đang tải bảng công…</div>
      <div v-else-if="!rows.length" class="py-16 text-center text-muted-foreground">Không có dữ liệu nhân viên cho tháng này.</div>
      <div v-else class="overflow-x-auto">
        <table class="min-w-full border-collapse text-xs">
          <thead>
            <tr class="text-muted-foreground">
              <th class="sticky left-0 z-10 bg-card text-left font-semibold px-3 py-2 border-b border-border min-w-[180px]">
                Nhân viên
              </th>
              <th
                v-for="d in days"
                :key="d.date"
                :class="['px-1 py-2 text-center border-b border-border font-medium w-8',
                         d.is_holiday ? 'text-purple-600 dark:text-purple-400' : d.is_rest ? 'text-red-500/70' : '']"
                :title="d.date"
              >
                <div>{{ d.day }}</div>
                <div class="text-[10px] opacity-70">{{ dowLabel(d.dow) }}</div>
              </th>
              <th class="px-2 py-2 text-center border-b border-border font-semibold border-l border-border" title="Công thực tế (có mặt + nghỉ có lương)">Công</th>
              <th class="px-2 py-2 text-center border-b border-border font-semibold" title="Tổng phút đi muộn">Trễ (p)</th>
              <th class="px-2 py-2 text-center border-b border-border font-semibold" title="Tổng phút về sớm">Sớm (p)</th>
              <th class="px-2 py-2 text-center border-b border-border font-semibold" title="Số ngày vắng">Vắng</th>
              <th class="px-2 py-2 text-center border-b border-border font-semibold" title="Số ngày nghỉ phép">Nghỉ</th>
              <th class="px-2 py-2 text-center border-b border-border font-semibold" title="Tổng giờ tăng ca (đã duyệt)">OT (h)</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in rows" :key="row.employee_id" class="hover:bg-muted/40">
              <td class="sticky left-0 z-10 bg-card px-3 py-1.5 border-b border-border">
                <div class="font-medium text-foreground truncate max-w-[170px]">{{ row.full_name }}</div>
                <div class="text-[10px] text-muted-foreground">{{ row.employee_code }}</div>
              </td>
              <td
                v-for="d in days"
                :key="d.date"
                class="px-0.5 py-1.5 text-center border-b border-border"
              >
                <span
                  :class="['relative inline-flex w-6 h-5 items-center justify-center rounded font-semibold',
                           cellClass(row.cells[d.date]),
                           row.cells[d.date] && row.cells[d.date].status === 'ABSENT' ? 'cursor-pointer hover:ring-1 hover:ring-primary' : '']"
                  :title="cellTitle(row, d)"
                  @click="onCellClick(row, d)"
                >
                  {{ cellMeta(row.cells[d.date]).char }}
                  <span
                    v-if="row.cells[d.date] && row.cells[d.date].overtime_hours > 0"
                    class="absolute -top-2 -right-2 min-w-[14px] h-[14px] px-1 flex items-center justify-center text-[9px] leading-none rounded-full bg-amber-500 text-white font-bold ring-1 ring-card"
                    :title="`Tăng ca ${row.cells[d.date].overtime_hours}h`"
                  >{{ row.cells[d.date].overtime_hours }}</span>
                </span>
              </td>
              <td class="px-2 py-1.5 text-center border-b border-border border-l border-border font-semibold text-foreground">{{ row.totals.payable_days }}</td>
              <td class="px-2 py-1.5 text-center border-b border-border" :class="row.totals.late_minutes ? 'text-amber-600 dark:text-amber-400' : 'text-muted-foreground'">{{ row.totals.late_minutes }}</td>
              <td class="px-2 py-1.5 text-center border-b border-border" :class="row.totals.early_leave_minutes ? 'text-orange-600 dark:text-orange-400' : 'text-muted-foreground'">{{ row.totals.early_leave_minutes }}</td>
              <td class="px-2 py-1.5 text-center border-b border-border" :class="row.totals.absent_days ? 'text-red-600 dark:text-red-400 font-semibold' : 'text-muted-foreground'">{{ row.totals.absent_days }}</td>
              <td class="px-2 py-1.5 text-center border-b border-border text-muted-foreground">{{ row.totals.leave_days }}</td>
              <td class="px-2 py-1.5 text-center border-b border-border" :class="row.totals.overtime_hours ? 'text-blue-600 dark:text-blue-400' : 'text-muted-foreground'">{{ row.totals.overtime_hours }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </BaseCard>

    <div v-if="pagination.last_page > 1" class="flex items-center justify-between rounded-xl border border-border bg-card px-4 py-3 text-sm">
      <span>Trang {{ pagination.current_page }}/{{ pagination.last_page }} · {{ pagination.total }} nhân viên</span>
      <div class="flex gap-2">
        <button class="rounded-lg border border-border px-3 py-1.5 disabled:opacity-50" :disabled="loading || pagination.current_page <= 1" @click="changePage(-1)">Trước</button>
        <button class="rounded-lg border border-border px-3 py-1.5 disabled:opacity-50" :disabled="loading || pagination.current_page >= pagination.last_page" @click="changePage(1)">Sau</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import BaseCard from '../components/BaseCard.vue';
import { attendanceService } from '../services/attendanceService';
import { useToast } from '../composables/useToast';

const router = useRouter();
const toast = useToast();

const DOW = ['CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7'];

// Cell status → ký hiệu + lớp màu.
const STATUS_META = {
  ON_TIME: { char: '✓', cls: 'bg-green-500/15 text-green-700 dark:text-green-400' },
  LATE: { char: 'M', cls: 'bg-amber-500/20 text-amber-700 dark:text-amber-400' },
  EARLY_LEAVE: { char: 'S', cls: 'bg-orange-500/20 text-orange-700 dark:text-orange-400' },
  HALF_DAY: { char: '½', cls: 'bg-teal-500/20 text-teal-700 dark:text-teal-400' },
  ABSENT: { char: 'V', cls: 'bg-red-500/20 text-red-700 dark:text-red-400' },
  LEAVE: { char: 'P', cls: 'bg-blue-500/15 text-blue-700 dark:text-blue-400' },
  LEAVE_HALF: { char: 'P½', cls: 'bg-blue-500/15 text-blue-700 dark:text-blue-400' },
  LEAVE_PENDING: { char: 'P…', cls: 'bg-amber-500/15 text-amber-700 dark:text-amber-400' },
  HOLIDAY: { char: 'L', cls: 'bg-purple-500/15 text-purple-700 dark:text-purple-400' },
  REST: { char: '·', cls: 'text-muted-foreground/50' },
  '': { char: '', cls: '' }
};

const legend = [
  { code: 'ON_TIME', char: '✓', label: 'Đúng giờ', cls: STATUS_META.ON_TIME.cls },
  { code: 'LATE', char: 'M', label: 'Đi muộn', cls: STATUS_META.LATE.cls },
  { code: 'EARLY_LEAVE', char: 'S', label: 'Về sớm', cls: STATUS_META.EARLY_LEAVE.cls },
  { code: 'HALF_DAY', char: '½', label: 'Nửa công', cls: STATUS_META.HALF_DAY.cls },
  { code: 'ABSENT', char: 'V', label: 'Vắng', cls: STATUS_META.ABSENT.cls },
  { code: 'LEAVE', char: 'P', label: 'Nghỉ phép', cls: STATUS_META.LEAVE.cls },
  { code: 'LEAVE_HALF', char: 'P½', label: 'Nghỉ nửa ngày', cls: STATUS_META.LEAVE_HALF.cls },
  { code: 'LEAVE_PENDING', char: 'P…', label: 'Chờ duyệt phép', cls: STATUS_META.LEAVE_PENDING.cls },
  { code: 'HOLIDAY', char: 'L', label: 'Lễ/Tết', cls: STATUS_META.HOLIDAY.cls },
  { code: 'REST', char: '·', label: 'Nghỉ tuần', cls: STATUS_META.REST.cls }
];

function currentMonth() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
}

const month = ref(currentMonth());
const loading = ref(false);
const recomputing = ref(false);
const summarizing = ref(false);
const locking = ref(false);
const showHelp = ref(false);
const days = ref([]);
const rows = ref([]);
const standardDays = ref(0);
const salaryPeriod = ref(null);
const pagination = ref({ current_page: 1, per_page: 25, total: 0, last_page: 1 });
const exportFormat = ref('xlsx');
const exporting = ref(false);

const monthLabel = computed(() => {
  const [y, m] = month.value.split('-');
  return `Tháng ${parseInt(m, 10)}/${y}`;
});

function shiftMonth(delta) {
  const [y, m] = month.value.split('-').map(Number);
  const d = new Date(y, m - 1 + delta, 1);
  month.value = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
  pagination.value.current_page = 1;
  loadTimesheet();
}

const totals = computed(() => {
  const t = { on_time_days: 0, late_days: 0, early_leave_days: 0, absent_days: 0 };
  for (const r of rows.value) {
    t.on_time_days += r.totals.on_time_days || 0;
    t.late_days += r.totals.late_days || 0;
    t.early_leave_days += r.totals.early_leave_days || 0;
    t.absent_days += r.totals.absent_days || 0;
  }
  return t;
});

function dowLabel(dow) {
  return DOW[dow] ?? '';
}

function cellMeta(cell) {
  const status = cell?.status ?? '';
  return STATUS_META[status] || STATUS_META[''];
}

// Đi làm ngày nghỉ/lễ (OT) → nền chàm nổi bật để phân biệt với đúng giờ.
function cellClass(cell) {
  if (cell && cell.is_ot_day) return 'bg-indigo-500/25 text-indigo-700 dark:text-indigo-300';
  return cellMeta(cell).cls;
}

function cellTitle(row, d) {
  const cell = row.cells[d.date];
  if (!cell) return d.date;
  const parts = [`${d.date} (${dowLabel(d.dow)})`];
  const labelMap = {
    ON_TIME: 'Đúng giờ', LATE: 'Đi muộn', EARLY_LEAVE: 'Về sớm', HALF_DAY: 'Nửa công',
    ABSENT: 'Vắng', LEAVE: 'Nghỉ phép', LEAVE_HALF: 'Nghỉ nửa ngày', LEAVE_PENDING: 'Chờ duyệt phép', HOLIDAY: 'Lễ/Tết', REST: 'Nghỉ tuần'
  };
  if (cell.status) parts.push(labelMap[cell.status] || cell.status);
  if (cell.check_in) parts.push(`Vào: ${String(cell.check_in).slice(0, 5)}`);
  if (cell.check_out) parts.push(`Ra: ${String(cell.check_out).slice(0, 5)}`);
  if (cell.late_minutes) parts.push(`Trễ ${cell.late_minutes}p`);
  if (cell.early_leave_minutes) parts.push(`Sớm ${cell.early_leave_minutes}p`);
  if (cell.worked_hours) parts.push(`${cell.worked_hours}h`);
  if (cell.overtime_hours) parts.push(`OT ${cell.overtime_hours}h`);
  if (cell.is_ot_day) parts.push('Đi làm ngày nghỉ/lễ → nên tạo đơn OT');
  if (cell.leave_code) parts.push(`Phép: ${cell.leave_code}`);
  if (cell.status === 'ABSENT') parts.push('Bấm để tạo đơn điều chỉnh công');
  return parts.join(' · ');
}

// Bấm ô VẮNG → tạo đơn điều chỉnh công (điền sẵn nhân viên + ngày).
function onCellClick(row, d) {
  const cell = row.cells[d.date];
  if (cell && cell.status === 'ABSENT') {
    router.push({ path: '/attendance-adjustments', query: { employee_id: row.employee_id, work_date: d.date } });
  }
}

async function loadTimesheet() {
  loading.value = true;
  try {
    const data = await attendanceService.getTimesheet(month.value, {
      page: pagination.value.current_page,
      per_page: pagination.value.per_page,
    });
    days.value = data.days || [];
    rows.value = data.rows || [];
    standardDays.value = data.standard_days || 0;
    salaryPeriod.value = data.salary_period || null;
    pagination.value = data.pagination || pagination.value;
  } catch (e) {
    console.error('Lỗi tải bảng công:', e);
    days.value = [];
    rows.value = [];
    salaryPeriod.value = null;
  } finally {
    loading.value = false;
  }
}

async function exportTimesheet() {
  exporting.value = true;
  try {
    const job = await attendanceService.createTimesheetExport({ month: month.value, format: exportFormat.value });
    for (let attempt = 0; attempt < 120; attempt += 1) {
      const status = await attendanceService.getTimesheetExport(job.id);
      if (status.status === 'COMPLETED') {
        await attendanceService.downloadTimesheetExport(status.id, `bang-cong-${month.value}.${status.format}`);
        toast.success('Đã xuất toàn bộ bảng công.');
        return;
      }
      if (status.status === 'FAILED') throw new Error(status.error || 'Xuất bảng công thất bại');
      await new Promise((resolve) => setTimeout(resolve, 2000));
    }
    throw new Error('Tác vụ xuất mất quá nhiều thời gian');
  } catch (error) {
    toast.error(error.response?.data?.message || error.message || 'Xuất bảng công thất bại');
  } finally {
    exporting.value = false;
  }
}

function changePage(delta) {
  pagination.value.current_page += delta;
  loadTimesheet();
}

// Chốt công: tổng hợp công của kỳ → feed sang tính lương.
async function summarizeToPayroll() {
  if (!salaryPeriod.value) return;
  if (!confirm(`Chốt tổng hợp công kỳ ${salaryPeriod.value.period_code} để tính lương?`)) return;
  summarizing.value = true;
  try {
    await attendanceService.runSummary(salaryPeriod.value.id);
    toast.success('Đã tổng hợp công kỳ lương. Sang trang Lương để tính/duyệt.');
    router.push('/salaries');
  } catch (e) {
    toast.error(e.response?.data?.message || 'Lỗi tổng hợp công');
  } finally {
    summarizing.value = false;
  }
}

// Khoá kỳ lương: chốt cứng, không cho sửa chấm công/lương sau đó.
async function lockPeriod() {
  if (!salaryPeriod.value) return;
  if (!confirm(`Khoá kỳ ${salaryPeriod.value.period_code}? Sau khi khoá sẽ không sửa được chấm công/lương của kỳ này.`)) return;
  locking.value = true;
  try {
    await attendanceService.closePeriod(salaryPeriod.value.id);
    toast.success('Đã khoá kỳ lương');
    await loadTimesheet();
  } catch (e) {
    toast.error(e.response?.data?.message || 'Lỗi khoá kỳ');
  } finally {
    locking.value = false;
  }
}

async function recompute() {
  recomputing.value = true;
  try {
    await attendanceService.recomputeTimesheet(month.value);
    await loadTimesheet();
  } catch (e) {
    console.error('Lỗi tái phân loại:', e);
  } finally {
    recomputing.value = false;
  }
}

onMounted(loadTimesheet);
</script>
