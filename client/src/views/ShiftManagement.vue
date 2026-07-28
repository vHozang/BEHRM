<template>
  <div class="space-y-6">
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
      <div>
        <h1 class="text-3xl font-bold text-foreground">Ca làm việc &amp; Xếp ca</h1>
        <p class="text-muted-foreground mt-1">Định nghĩa ca làm việc và xếp ca cho nhân viên</p>
      </div>
    </div>

    <!-- Tab switcher -->
    <div class="flex gap-1 p-1 rounded-xl bg-muted/40 border border-border w-fit">
      <button
        v-for="tab in tabs"
        :key="tab.key"
        @click="activeTab = tab.key"
        class="px-4 py-2 text-sm font-semibold rounded-lg transition-colors"
        :class="activeTab === tab.key
          ? 'bg-card text-foreground shadow-sm border border-border'
          : 'text-muted-foreground hover:text-foreground'"
      >
        {{ tab.label }}
      </button>
    </div>

    <!-- ============ TAB 1: Định nghĩa ca (shift_types CRUD) ============ -->
    <div v-show="activeTab === 'definitions'" class="space-y-4">
      <div class="flex justify-end">
        <BaseButton @click="openCreateShift">+ Thêm ca</BaseButton>
      </div>

      <BaseCard>
        <BaseTable
          :columns="shiftColumns"
          :data="workShifts"
        >
          <template #cell-name="{ item }">
            <span :class="{ 'text-muted-foreground line-through': !item.is_active }">{{ item.name }}</span>
          </template>
          <template #cell-is_active="{ item }">
            <StatusPill :status="item.is_active ? 'Hoạt động' : 'Không hoạt động'" />
          </template>
          <template #actions="{ item }">
            <div class="flex gap-1">
              <button
                @click="editShift(item)"
                class="px-3 py-1.5 text-xs font-medium rounded-md bg-primary/10 text-primary hover:bg-primary/20 transition-colors"
                title="Chỉnh sửa"
              >
                Chi tiết
              </button>
              <button
                v-if="item.is_active"
                @click="deactivateShift(item)"
                class="px-3 py-1.5 text-xs font-medium rounded-md bg-amber-100 text-amber-700 hover:bg-amber-200 dark:bg-amber-900/30 dark:text-amber-400 dark:hover:bg-amber-900/50 transition-colors"
                title="Tạm ngưng"
              >
                Tạm ngưng
              </button>
              <button
                v-else
                @click="activateShift(item)"
                class="px-3 py-1.5 text-xs font-medium rounded-md bg-green-100 text-green-700 hover:bg-green-200 dark:bg-green-900/30 dark:text-green-400 dark:hover:bg-green-900/50 transition-colors"
                title="Kích hoạt"
              >
                Kích hoạt
              </button>
              <button
                @click="confirmDeleteShift(item)"
                class="px-3 py-1.5 text-xs font-medium rounded-md bg-destructive/10 text-destructive hover:bg-destructive/20 transition-colors"
                title="Xóa"
              >
                Xóa
              </button>
            </div>
          </template>
          <template #empty>Chưa có ca làm việc nào</template>
        </BaseTable>
      </BaseCard>
    </div>

    <!-- ============ TAB 2: Xếp ca / Roster (visual) ============ -->
    <div v-show="activeTab === 'roster'" class="space-y-4">
      <!-- Week navigator -->
      <div class="flex justify-end">
        <div class="flex items-center gap-2 bg-card border border-border p-1.5 rounded-xl shadow-xs">
          <BaseButton variant="outline" size="sm" class="px-2" @click="navigateWeek(-1)">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
          </BaseButton>
          <span class="text-xs font-semibold px-2 text-foreground whitespace-nowrap">
            {{ formatWeekRange() }}
          </span>
          <BaseButton variant="outline" size="sm" class="px-2" @click="navigateWeek(1)">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
            </svg>
          </BaseButton>
        </div>
      </div>

      <!-- Shift legend -->
      <BaseCard>
        <div class="flex flex-wrap items-center gap-4 text-xs">
          <span class="font-bold text-muted-foreground uppercase tracking-wider">Ký hiệu ca:</span>
          <span v-if="!rosterShiftTypes.length" class="text-amber-600">Chưa cấu hình loại ca làm việc</span>
          <div v-for="shift in rosterShiftTypes" :key="shift.id" class="flex items-center gap-1.5">
            <span
              class="w-3.5 h-3.5 rounded-md border border-border"
              :style="{ backgroundColor: shift.color_code || '#f3f4f6' }"
            ></span>
            <span class="font-semibold text-foreground">{{ shift.shift_code }}</span>
            <span class="text-muted-foreground">({{ shiftTimeLabel(shift) }})</span>
          </div>
          <div class="flex items-center gap-1.5 ml-auto">
            <span class="w-3.5 h-3.5 rounded-md bg-transparent border-2 border-dashed border-border"></span>
            <span class="text-muted-foreground">Chưa xếp ca (OFF)</span>
          </div>
        </div>
      </BaseCard>

      <!-- Roster grid -->
      <BaseCard>
        <div v-if="rosterLoading" class="text-center py-12">
          <p class="text-muted-foreground">Đang tải lịch phân ca...</p>
        </div>

        <div v-else class="overflow-x-auto">
          <table class="w-full border-collapse text-left text-sm">
            <thead>
              <tr class="border-b border-border bg-muted/30">
                <th class="p-4 font-bold text-foreground w-64 min-w-[240px]">Nhân viên</th>
                <th
                  v-for="day in weekDays"
                  :key="day.dateStr"
                  class="p-4 text-center font-bold text-foreground w-36 min-w-[120px]"
                  :class="{ 'bg-primary/5 text-primary font-black': isToday(day.date) }"
                >
                  <div>{{ day.dayName }}</div>
                  <div class="text-xs text-muted-foreground mt-0.5">{{ formatDateDay(day.date) }}</div>
                </th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border/60">
              <tr v-for="emp in rosterData" :key="emp.id" class="hover:bg-muted/10 transition-colors">
                <td class="p-4 flex items-center gap-3">
                  <div class="w-9 h-9 rounded-full bg-primary text-primary-foreground flex items-center justify-center font-bold shadow-xs flex-shrink-0">
                    {{ getInitials(emp.full_name) }}
                  </div>
                  <div>
                    <div class="font-semibold text-foreground leading-tight">{{ emp.full_name }}</div>
                    <div class="text-xs text-muted-foreground mt-0.5">Mã NV: {{ emp.employee_code }}</div>
                  </div>
                </td>

                <td
                  v-for="day in weekDays"
                  :key="day.dateStr"
                  class="p-3 text-center align-middle"
                >
                  <div
                    @click="openAssignModal(emp, day)"
                    class="group relative mx-auto w-24 h-12 rounded-xl border border-border/50 flex flex-col items-center justify-center cursor-pointer shadow-2xs hover:border-primary hover:shadow-xs active:scale-95 transition-all duration-200"
                    :style="getShiftCellStyles(emp.id, day.dateStr)"
                  >
                    <span class="text-xs font-black tracking-wider">
                      {{ getShiftCode(emp.id, day.dateStr) }}
                    </span>
                    <span class="text-[9px] opacity-75 mt-0.5">
                      {{ getShiftTime(emp.id, day.dateStr) }}
                    </span>

                    <div class="absolute inset-0 bg-primary/10 rounded-xl opacity-0 group-hover:opacity-100 flex items-center justify-center transition-opacity duration-200">
                      <svg class="w-4 h-4 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                      </svg>
                    </div>
                  </div>
                </td>
              </tr>
              <tr v-if="rosterData.length === 0">
                <td :colspan="weekDays.length + 1" class="px-4 py-12 text-center text-muted-foreground">
                  Không có nhân viên nào
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </BaseCard>
    </div>

    <!-- ===== Shift definition create/edit modal ===== -->
    <BaseModal v-model="showShiftModal" :title="shiftForm.id ? 'Chỉnh sửa ca làm việc' : 'Thêm ca làm việc'">
      <div class="space-y-4">
        <BaseInput v-model="shiftForm.code" label="Mã ca" required />
        <BaseInput v-model="shiftForm.name" label="Tên ca" required />
        <div>
          <label class="block text-sm font-medium text-foreground mb-1">Nhãn màu ca (hiển thị trên lịch xếp ca)</label>
          <div class="flex items-center gap-2">
            <input v-model="shiftForm.color_code" type="color" class="h-9 w-14 rounded border border-input bg-background cursor-pointer" />
            <span class="text-sm text-muted-foreground">{{ shiftForm.color_code }}</span>
          </div>
        </div>
        <BaseInput v-model="shiftForm.start_time" type="time" label="Giờ bắt đầu" required />
        <BaseInput v-model="shiftForm.end_time" type="time" label="Giờ kết thúc" required />
        <BaseInput v-if="!splitShift" v-model.number="shiftForm.break_minutes" type="number" label="Giờ nghỉ giữa ca (phút)" />
        <div v-if="!splitShift">
          <label class="block text-sm font-medium text-foreground mb-1">Mô tả giờ nghỉ (nhân viên đọc)</label>
          <textarea v-model="shiftForm.break_note" rows="2"
            class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground text-sm"
            placeholder="VD: Nghỉ 30 phút, chia 2 đợt 15 phút (giữa buổi sáng và giữa buổi chiều)"></textarea>
        </div>

        <!-- Ca gãy (split shift): nhiều khung giờ -->
        <div class="border-t border-border pt-3">
          <label class="flex items-center gap-2 mb-2">
            <input type="checkbox" v-model="splitShift" class="rounded" />
            <span class="font-medium text-foreground">Ca gãy (nhiều khung giờ)</span>
          </label>
          <div v-if="splitShift" class="space-y-2">
            <p class="text-xs text-muted-foreground">Mỗi khung là một đoạn làm việc; nghỉ giữa = khoảng trống giữa các khung. Giờ bắt đầu/kết thúc ở trên = khung đầu/cuối.</p>
            <div v-for="(seg, i) in segments" :key="i" class="flex items-center gap-2">
              <input type="time" v-model="seg.start" class="px-2 py-1.5 rounded-lg border border-input bg-background text-foreground text-sm" />
              <span>→</span>
              <input type="time" v-model="seg.end" class="px-2 py-1.5 rounded-lg border border-input bg-background text-foreground text-sm" />
              <button @click="segments.splice(i,1)" class="text-red-600 dark:text-red-400 text-xs">Xoá</button>
            </div>
            <button @click="segments.push({start:'',end:''})" class="text-primary text-xs font-medium">+ Thêm khung giờ</button>
          </div>
        </div>

        <div class="flex items-center gap-2">
          <input type="checkbox" v-model="shiftForm.is_active" class="rounded" />
          <label>Hoạt động</label>
        </div>
        <BaseButton @click="saveShift" class="w-full">Lưu</BaseButton>
      </div>
    </BaseModal>

    <!-- ===== Delete shift confirm modal ===== -->
    <BaseModal v-model="showDeleteModal" title="Xóa ca làm việc">
      <p class="text-foreground">
        Bạn có chắc muốn xóa ca <strong>{{ deleteTarget?.name }}</strong>? Hành động này không thể hoàn tác.
      </p>
      <template #footer>
        <BaseButton variant="outline" @click="showDeleteModal = false">Hủy</BaseButton>
        <BaseButton variant="destructive" @click="deleteShift" :disabled="deleting">
          {{ deleting ? 'Đang xóa...' : 'Xóa' }}
        </BaseButton>
      </template>
    </BaseModal>

    <!-- ===== Assign shift modal (roster) ===== -->
    <BaseModal v-model="showAssignModal" title="Gán ca làm việc">
      <div v-if="activeAssignment" class="space-y-4">
        <div class="p-3 bg-muted rounded-xl text-sm space-y-1">
          <p><strong>Nhân viên:</strong> {{ activeAssignment.employeeName }}</p>
          <p><strong>Ngày gán ca:</strong> {{ formatDateLong(activeAssignment.date) }}</p>
        </div>

        <div>
          <label class="block text-sm font-semibold text-foreground mb-2">Chọn ca làm việc</label>
          <div class="grid grid-cols-2 gap-3">
            <button
              @click="selectedShiftId = null"
              class="p-3 border rounded-xl flex flex-col items-start gap-1 transition-all duration-200"
              :class="selectedShiftId === null ? 'border-primary bg-primary/5 text-primary font-bold' : 'border-border hover:bg-muted'"
            >
              <span class="text-xs font-bold">Nghỉ (OFF)</span>
              <span class="text-[10px] text-muted-foreground">Không gán ca làm việc</span>
            </button>

            <button
              v-for="shift in rosterShiftTypes"
              :key="shift.id"
              @click="selectedShiftId = shift.id"
              class="p-3 border rounded-xl flex flex-col items-start gap-1 transition-all duration-200"
              :class="selectedShiftId === shift.id ? 'border-primary bg-primary/5 text-primary font-bold' : 'border-border hover:bg-muted'"
            >
              <div class="flex items-center gap-1.5">
                <span class="w-2.5 h-2.5 rounded-full" :style="{ backgroundColor: shift.color_code }"></span>
                <span class="text-xs font-bold">{{ shift.shift_code }}</span>
              </div>
              <span class="text-[10px] text-muted-foreground">
                {{ shift.shift_name }} ({{ shiftTimeLabel(shift) }})
              </span>
            </button>
          </div>
        </div>
      </div>

      <template #footer>
        <BaseButton variant="outline" @click="showAssignModal = false">Hủy</BaseButton>
        <BaseButton @click="saveAssignment" :disabled="saving">
          {{ saving ? 'Đang lưu...' : 'Lưu lịch biểu' }}
        </BaseButton>
      </template>
    </BaseModal>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import BaseButton from '../components/BaseButton.vue';
import BaseCard from '../components/BaseCard.vue';
import BaseTable from '../components/BaseTable.vue';
import BaseModal from '../components/BaseModal.vue';
import BaseInput from '../components/BaseInput.vue';
import StatusPill from '../components/StatusPill.vue';
import { workShiftService } from '../services/workShiftService';
import { workScheduleService } from '../services/workScheduleService';
import { employeeService } from '../services/employeeService';
import { useToast } from '../composables/useToast';

const toast = useToast();

const tabs = [
  { key: 'definitions', label: 'Định nghĩa ca' },
  { key: 'roster', label: 'Xếp ca / Roster' },
];
const activeTab = ref('definitions');

/* ============================================================
 * TAB 1 — Định nghĩa ca (shift_types CRUD)  [port WorkShifts.vue]
 * ============================================================ */
const workShifts = ref([]);
const showShiftModal = ref(false);
const showDeleteModal = ref(false);
const deleteTarget = ref(null);
const deleting = ref(false);

const shiftColumns = [
  { key: 'code', label: 'Mã ca' },
  { key: 'name', label: 'Tên ca' },
  { key: 'start_time', label: 'Giờ bắt đầu' },
  { key: 'end_time', label: 'Giờ kết thúc' },
  { key: 'break_minutes', label: 'Giờ nghỉ (phút)' },
  { key: 'is_active', label: 'Trạng thái' },
];

const emptyShiftForm = () => ({
  code: '',
  name: '',
  start_time: '',
  end_time: '',
  break_minutes: 30, // chuẩn chung các công ty VN: 30 phút/ca
  break_note: '',
  color_code: '#3b82f6', // nhãn màu ca — grid xếp ca đã đọc field này
  is_active: true,
});
const shiftForm = ref(emptyShiftForm());
const splitShift = ref(false);
const segments = ref([]);

const loadShiftDefinitions = async () => {
  try {
    const response = await workShiftService.getAll();
    workShifts.value = response?.data || response || [];
  } catch (err) {
    console.error('Error loading work shifts:', err);
    workShifts.value = [];
  }
};

const openCreateShift = () => {
  shiftForm.value = emptyShiftForm();
  splitShift.value = false;
  segments.value = [];
  showShiftModal.value = true;
};

const editShift = (item) => {
  shiftForm.value = { ...item };
  // Dữ liệu cũ lưu giờ nghỉ dạng break_start/break_end (khoảng thời gian) — suy ra
  // số phút nếu form chưa có break_minutes, để ca cũ hiển thị đúng thay vì 0.
  if (shiftForm.value.break_minutes == null && item.break_start && item.break_end) {
    const [sh, sm] = String(item.break_start).split(':').map(Number);
    const [eh, em] = String(item.break_end).split(':').map(Number);
    shiftForm.value.break_minutes = Math.max(0, (eh * 60 + em) - (sh * 60 + sm));
  }
  if (shiftForm.value.break_minutes == null) shiftForm.value.break_minutes = 30;
  const segs = Array.isArray(item.segments) ? item.segments : null;
  splitShift.value = !!(segs && segs.length);
  segments.value = segs ? segs.map(s => ({ start: (s.start || '').slice(0, 5), end: (s.end || '').slice(0, 5) })) : [];
  showShiftModal.value = true;
};

const saveShift = async () => {
  try {
    // Ca gãy: gửi meta.segments (lọc khung hợp lệ); tắt → xoá segments.
    const payload = { ...shiftForm.value };
    if (splitShift.value) {
      payload.segments = segments.value.filter(s => s.start && s.end);
      if (!payload.segments.length) { toast.error('Ca gãy cần ít nhất một khung giờ hợp lệ'); return; }
    } else {
      payload.segments = null;
    }
    if (payload.id) {
      await workShiftService.update(payload.id, payload);
      toast.success('Cập nhật ca làm việc thành công!');
    } else {
      await workShiftService.create(payload);
      toast.success('Thêm ca làm việc thành công!');
    }
    showShiftModal.value = false;
    shiftForm.value = emptyShiftForm();
    await loadShiftDefinitions();
    await loadRosterShiftTypes();
  } catch (err) {
    console.error('Error saving work shift:', err);
    toast.error('Có lỗi xảy ra khi lưu ca làm việc');
  }
};

const deactivateShift = async (item) => {
  try {
    await workShiftService.update(item.id, { is_active: false });
    toast.success('Đã tạm ngưng ca làm việc');
    await loadShiftDefinitions();
  } catch (err) {
    console.error('Error deactivating work shift:', err);
    toast.error('Có lỗi xảy ra khi tạm ngưng ca làm việc');
  }
};

const activateShift = async (item) => {
  try {
    await workShiftService.update(item.id, { is_active: true });
    toast.success('Đã kích hoạt ca làm việc');
    await loadShiftDefinitions();
  } catch (err) {
    console.error('Error activating work shift:', err);
    toast.error('Có lỗi xảy ra khi kích hoạt ca làm việc');
  }
};

const confirmDeleteShift = (item) => {
  deleteTarget.value = item;
  showDeleteModal.value = true;
};

const deleteShift = async () => {
  if (!deleteTarget.value) return;
  try {
    deleting.value = true;
    await workShiftService.delete(deleteTarget.value.id);
    toast.success('Đã xóa ca làm việc');
    showDeleteModal.value = false;
    deleteTarget.value = null;
    await loadShiftDefinitions();
    await loadRosterShiftTypes();
  } catch (err) {
    console.error('Error deleting work shift:', err);
    toast.error('Có lỗi xảy ra khi xóa ca làm việc');
  } finally {
    deleting.value = false;
  }
};

/* ============================================================
 * TAB 2 — Xếp ca / Roster  [port ShiftRoster.vue]
 * ============================================================ */
const rosterLoading = ref(true);
const saving = ref(false);
const employees = ref([]);
const rosterShiftTypes = ref([]);
const schedules = ref([]);
const currentStartDate = ref(new Date());

const showAssignModal = ref(false);
const activeAssignment = ref(null);
const selectedShiftId = ref(null);

const weekDays = computed(() => {
  const start = new Date(currentStartDate.value);
  const day = start.getDay();
  const diff = start.getDate() - day + (day === 0 ? -6 : 1);
  const monday = new Date(start.setDate(diff));

  const days = [];
  const dayNames = ['Thứ 2', 'Thứ 3', 'Thứ 4', 'Thứ 5', 'Thứ 6', 'Thứ 7', 'Chủ nhật'];

  for (let i = 0; i < 7; i++) {
    const d = new Date(monday);
    d.setDate(monday.getDate() + i);
    days.push({
      date: d,
      dateStr: d.toISOString().split('T')[0],
      dayName: dayNames[i],
    });
  }
  return days;
});

const rosterData = computed(() => {
  return employees.value.map((e) => ({
    id: e.id,
    full_name: e.full_name,
    employee_code: e.employee_code || `NV-${e.id}`,
  }));
});

const shiftTimeLabel = (shift) => {
  const s = (shift.start_time || '').slice(0, 5);
  const e = (shift.end_time || '').slice(0, 5);
  return `${s} - ${e}`;
};

const formatWeekRange = () => {
  const start = weekDays.value[0]?.date;
  const end = weekDays.value[6]?.date;
  if (!start || !end) return '';
  return `Tuần: ${start.toLocaleDateString('vi-VN')} - ${end.toLocaleDateString('vi-VN')}`;
};

const navigateWeek = (dir) => {
  const d = new Date(currentStartDate.value);
  d.setDate(d.getDate() + dir * 7);
  currentStartDate.value = d;
  loadSchedules();
};

const isToday = (date) => {
  const today = new Date();
  return date.toDateString() === today.toDateString();
};

const getInitials = (name) => {
  if (!name) return '';
  return name.split(' ').map((w) => w[0]).join('').toUpperCase().slice(0, 2);
};

const formatDateDay = (date) => date.getDate() + '/' + (date.getMonth() + 1);

const formatDateLong = (date) => {
  if (!date) return '';
  return date.toLocaleDateString('vi-VN', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
};

// Phân giải ca cho 1 ngày từ các phân ca effective-dated (range-resolve):
// ưu tiên bản ghi-đè 1 ngày (có expiry) hơn ca cố định/standing; cùng loại lấy
// effective_date mới nhất. (Trùng logic với ShiftRoster + EmployeePortal "Lịch ca của tôi".)
const getShiftAssignment = (employeeId, dateStr) => {
  const candidates = schedules.value.filter(
    (s) =>
      String(s.employee_id) === String(employeeId) &&
      s.effective_date && String(s.effective_date).slice(0, 10) <= dateStr &&
      (!s.expiry_date || String(s.expiry_date).slice(0, 10) >= dateStr)
  );
  if (!candidates.length) return null;
  candidates.sort((a, b) => {
    const aSpec = a.expiry_date ? 1 : 0;
    const bSpec = b.expiry_date ? 1 : 0;
    if (aSpec !== bSpec) return bSpec - aSpec;
    return String(b.effective_date).localeCompare(String(a.effective_date));
  });
  return candidates[0];
};

const getShiftCode = (employeeId, dateStr) => {
  const assign = getShiftAssignment(employeeId, dateStr);
  if (!assign) return 'OFF';
  const shift = rosterShiftTypes.value.find((s) => String(s.id) === String(assign.shift_type_id));
  return shift ? shift.shift_code : 'OFF';
};

const getShiftTime = (employeeId, dateStr) => {
  const assign = getShiftAssignment(employeeId, dateStr);
  if (!assign) return '';
  const shift = rosterShiftTypes.value.find((s) => String(s.id) === String(assign.shift_type_id));
  return shift ? shiftTimeLabel(shift) : '';
};

const getShiftCellStyles = (employeeId, dateStr) => {
  const assign = getShiftAssignment(employeeId, dateStr);
  if (!assign) return { backgroundColor: 'transparent', borderColor: 'rgba(229, 231, 235, 0.5)', color: 'var(--muted-foreground)' };
  const shift = rosterShiftTypes.value.find((s) => String(s.id) === String(assign.shift_type_id));
  if (!shift) return { backgroundColor: 'transparent', borderColor: 'rgba(229, 231, 235, 0.5)' };

  return {
    backgroundColor: `${shift.color_code}15`,
    borderColor: shift.color_code,
    color: shift.color_code,
  };
};

const openAssignModal = (employee, day) => {
  const assignment = getShiftAssignment(employee.id, day.dateStr);
  selectedShiftId.value = assignment ? assignment.shift_type_id : null;
  activeAssignment.value = {
    employeeId: employee.id,
    employeeName: employee.full_name,
    date: day.date,
    dateStr: day.dateStr,
  };
  showAssignModal.value = true;
};

const saveAssignment = async () => {
  if (!activeAssignment.value) return;
  try {
    saving.value = true;
    const { employeeId, dateStr } = activeAssignment.value;

    // Bản ghi-đè 1 ngày đã có cho đúng ngày này (effective=expiry=dateStr).
    const existingOverride = schedules.value.find(
      (s) =>
        String(s.employee_id) === String(employeeId) &&
        String(s.effective_date).slice(0, 10) === dateStr &&
        String(s.expiry_date).slice(0, 10) === dateStr
    );

    if (selectedShiftId.value === null || selectedShiftId.value === 'OFF') {
      // Bỏ gán ngày này → xoá bản ghi-đè 1 ngày (quay về ca cố định nếu có).
      if (existingOverride?.id) {
        await workScheduleService.delete(existingOverride.id);
      }
      toast.success('Đã bỏ gán ca cho ngày này');
    } else {
      // Ghi-đè ca cho riêng 1 ngày: effective = expiry = ngày đó (không tạo standing rác).
      const payload = {
        employee_id: parseInt(employeeId),
        shift_type_id: parseInt(selectedShiftId.value),
        effective_date: dateStr,
        expiry_date: dateStr,
        status: 'ACTIVE',
      };
      if (existingOverride?.id) {
        await workScheduleService.update(existingOverride.id, payload);
      } else {
        await workScheduleService.create(payload);
      }
      toast.success('Gán ca làm việc thành công');
    }

    showAssignModal.value = false;
    await loadSchedules();
  } catch (err) {
    console.error('Error assigning shift:', err);
    toast.error(err.response?.data?.message || 'Lỗi khi gán ca làm việc');
  } finally {
    saving.value = false;
  }
};

const loadSchedules = async () => {
  try {
    // Lấy tất cả phân ca (kể cả ca cố định/standing effective-dated cũ) để phân giải theo ngày.
    const res = await workScheduleService.getAll({ per_page: 500 });
    schedules.value = res?.data || res || [];
  } catch (err) {
    console.error('Error loading schedules:', err);
    schedules.value = [];
  }
};

const loadRosterShiftTypes = async () => {
  try {
    const res = await workShiftService.getAll().catch(() => []);
    const shifts = res?.data || res || [];
    rosterShiftTypes.value = Array.isArray(shifts) ? shifts : [];
  } catch (err) {
    console.error('Error loading roster shift types:', err);
    rosterShiftTypes.value = [];
  }
};

const loadEmployees = async () => {
  try {
    const employeesRes = await employeeService.getLookup();
    let emps = employeesRes?.data || employeesRes || [];
    if (!Array.isArray(emps)) {
      emps = emps.items || emps.data || [];
    }
    if (!Array.isArray(emps)) emps = [];
    employees.value = emps;
  } catch (err) {
    console.error('Error loading employees:', err);
    employees.value = [];
  }
};

onMounted(async () => {
  try {
    rosterLoading.value = true;
    await Promise.all([
      loadShiftDefinitions(),
      loadRosterShiftTypes(),
      loadEmployees(),
    ]);
    await loadSchedules();
  } catch (err) {
    console.error('Error mounting ShiftManagement:', err);
    toast.error('Lỗi tải dữ liệu ca làm việc');
  } finally {
    rosterLoading.value = false;
  }
});
</script>
