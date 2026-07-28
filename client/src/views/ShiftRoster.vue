<template>
  <div class="space-y-6">
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-6">
      <div>
        <h1 class="text-3xl font-bold text-foreground">Bảng Phân Ca Làm Việc</h1>
        <p class="text-muted-foreground mt-1">Xếp ca, quản lý lịch biểu và điều động nhân sự hàng tuần</p>
      </div>

      <div class="flex items-center gap-2">
      <BaseButton variant="outline" size="sm" @click="openRotateModal" :disabled="!shiftTypes.length">🔄 Tạo lịch ca xoay</BaseButton>
      <!-- Week Navigator -->
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
    </div>

    <!-- Modal tạo lịch ca xoay -->
    <BaseModal v-model="showRotate" title="Tạo lịch ca xoay theo tuần">
      <div class="space-y-4 text-sm">
        <div>
          <label class="block font-medium text-foreground mb-2">Các ca tham gia xoay (theo thứ tự)</label>
          <div class="flex flex-wrap gap-2">
            <label v-for="s in shiftTypes" :key="s.id" class="flex items-center gap-1.5 px-2 py-1 border border-border rounded-lg cursor-pointer">
              <input type="checkbox" :value="s.id" v-model="rotateForm.shift_type_ids" class="h-4 w-4" />
              <span>{{ s.shift_code }} ({{ s.start_time.slice(0,5) }}-{{ s.end_time.slice(0,5) }})</span>
            </label>
          </div>
        </div>
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block font-medium text-foreground mb-1">Tuần bắt đầu (Thứ Hai)</label>
            <input type="date" v-model="rotateForm.start_date" class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground" />
          </div>
          <div>
            <label class="block font-medium text-foreground mb-1">Số tuần</label>
            <input type="number" min="1" max="26" v-model="rotateForm.weeks" class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground" />
          </div>
        </div>
        <label class="flex items-center gap-2">
          <input type="checkbox" v-model="rotateForm.rotate" class="h-4 w-4" />
          <span>Xoay ca mỗi tuần (bỏ chọn = cố định mỗi NV một ca)</span>
        </label>
        <p class="text-xs text-muted-foreground">Áp dụng cho tất cả nhân viên đang làm. Lịch tự sinh cũ trong khoảng sẽ được tạo lại.</p>
        <p v-if="rotateError" class="text-destructive text-xs">{{ rotateError }}</p>
      </div>
      <template #footer>
        <BaseButton variant="outline" @click="showRotate = false" :disabled="rotating">Hủy</BaseButton>
        <BaseButton @click="submitRotate" :disabled="rotating">{{ rotating ? 'Đang tạo…' : 'Tạo lịch' }}</BaseButton>
      </template>
    </BaseModal>

    <!-- Shifts Reference Legend -->
    <BaseCard>
      <div class="flex flex-wrap items-center gap-4 text-xs">
        <span class="font-bold text-muted-foreground uppercase tracking-wider">Ký hiệu ca:</span>
        <span v-if="!shiftTypes.length" class="text-amber-600">Chưa cấu hình loại ca làm việc</span>
        <div v-for="shift in shiftTypes" :key="shift.id" class="flex items-center gap-1.5">
          <span 
            class="w-3.5 h-3.5 rounded-md border border-border" 
            :style="{ backgroundColor: shift.color_code || '#f3f4f6' }"
          ></span>
          <span class="font-semibold text-foreground">{{ shift.shift_code }}</span>
          <span class="text-muted-foreground">({{ shift.start_time.slice(0, 5) }} - {{ shift.end_time.slice(0, 5) }})</span>
        </div>
        <div class="flex items-center gap-1.5 ml-auto">
          <span class="w-3.5 h-3.5 rounded-md bg-transparent border-2 border-dashed border-border"></span>
          <span class="text-muted-foreground">Chưa xếp ca (OFF)</span>
        </div>
      </div>
    </BaseCard>

    <!-- Roster Grid -->
    <BaseCard>
      <div v-if="loading" class="text-center py-12">
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
              <!-- Employee Column -->
              <td class="p-4 flex items-center gap-3">
                <div class="w-9 h-9 rounded-full bg-primary text-primary-foreground flex items-center justify-center font-bold shadow-xs flex-shrink-0">
                  {{ getInitials(emp.full_name) }}
                </div>
                <div>
                  <div class="font-semibold text-foreground leading-tight">{{ emp.full_name }}</div>
                  <div class="text-xs text-muted-foreground mt-0.5">Mã NV: {{ emp.employee_code }}</div>
                </div>
              </td>

              <!-- Weekly Days Cells -->
              <td 
                v-for="day in weekDays" 
                :key="day.dateStr" 
                class="p-3 text-center align-middle"
              >
                <!-- Cell Click Trigger to assign shifts -->
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

                  <!-- Hover edit indicator -->
                  <div class="absolute inset-0 bg-primary/10 rounded-xl opacity-0 group-hover:opacity-100 flex items-center justify-center transition-opacity duration-200">
                    <svg class="w-4 h-4 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                    </svg>
                  </div>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </BaseCard>

    <!-- Assign Shift Modal -->
    <BaseModal v-model="showAssignModal" title="Gán ca làm việc">
      <div v-if="activeAssignment" class="space-y-4">
        <div class="p-3 bg-muted rounded-xl text-sm space-y-1">
          <p><strong>Nhân viên:</strong> {{ activeAssignment.employeeName }}</p>
          <p><strong>Ngày gán ca:</strong> {{ formatDateLong(activeAssignment.date) }}</p>
        </div>

        <div>
          <label class="block text-sm font-semibold text-foreground mb-2">Chọn ca làm việc</label>
          <div class="grid grid-cols-2 gap-3">
            <!-- OFF / Empty Shift Option -->
            <button 
              @click="selectedShiftId = null"
              class="p-3 border rounded-xl flex flex-col items-start gap-1 transition-all duration-200"
              :class="selectedShiftId === null ? 'border-primary bg-primary/5 text-primary font-bold' : 'border-border hover:bg-muted'"
            >
              <span class="text-xs font-bold">Nghỉ (OFF)</span>
              <span class="text-[10px] text-muted-foreground">Không gán ca làm việc</span>
            </button>

            <!-- Shift Types Options -->
            <button 
              v-for="shift in shiftTypes" 
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
                {{ shift.shift_name }} ({{ shift.start_time.slice(0, 5) }} - {{ shift.end_time.slice(0, 5) }})
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
import BaseModal from '../components/BaseModal.vue';
import { employeeService } from '../services/employeeService';
import { workShiftService } from '../services/workShiftService';
import { workScheduleService } from '../services/workScheduleService';
import { useToast } from '../composables/useToast';

const toast = useToast();
const loading = ref(true);
const saving = ref(false);

const employees = ref([]);
const shiftTypes = ref([]);
const schedules = ref([]);

const currentStartDate = ref(new Date());

const showAssignModal = ref(false);
const activeAssignment = ref(null);
const selectedShiftId = ref(null);

// Ca xoay
const showRotate = ref(false);
const rotating = ref(false);
const rotateError = ref('');
const rotateForm = ref({ shift_type_ids: [], start_date: '', weeks: 4, rotate: true });

const mondayOf = (d) => {
  const x = new Date(d);
  const day = (x.getDay() + 6) % 7; // 0 = Monday
  x.setDate(x.getDate() - day);
  return `${x.getFullYear()}-${String(x.getMonth() + 1).padStart(2, '0')}-${String(x.getDate()).padStart(2, '0')}`;
};

const openRotateModal = () => {
  rotateError.value = '';
  rotateForm.value = {
    shift_type_ids: shiftTypes.value.map(s => s.id),
    start_date: mondayOf(currentStartDate.value),
    weeks: 4,
    rotate: true,
  };
  showRotate.value = true;
};

const submitRotate = async () => {
  if (!rotateForm.value.shift_type_ids.length) { rotateError.value = 'Chọn ít nhất một ca'; return; }
  if (!rotateForm.value.start_date) { rotateError.value = 'Chọn tuần bắt đầu'; return; }
  rotating.value = true;
  try {
    const res = await workScheduleService.generateRoster({
      shift_type_ids: rotateForm.value.shift_type_ids,
      start_date: rotateForm.value.start_date,
      weeks: parseInt(rotateForm.value.weeks, 10) || 1,
      rotate: rotateForm.value.rotate,
    });
    toast.success(`Đã tạo lịch ca xoay (${res?.assignments_created || ''} phân ca)`);
    showRotate.value = false;
    await loadSchedules();
  } catch (e) {
    rotateError.value = e.response?.data?.message || 'Lỗi tạo lịch ca xoay';
  } finally {
    rotating.value = false;
  }
};

// Get week range days
const weekDays = computed(() => {
  const start = new Date(currentStartDate.value);
  const day = start.getDay();
  const diff = start.getDate() - day + (day === 0 ? -6 : 1); // Adjust to get Monday
  const monday = new Date(start.setDate(diff));
  
  const days = [];
  const dayNames = ['Thứ 2', 'Thứ 3', 'Thứ 4', 'Thứ 5', 'Thứ 6', 'Thứ 7', 'Chủ nhật'];
  
  for (let i = 0; i < 7; i++) {
    const d = new Date(monday);
    d.setDate(monday.getDate() + i);
    days.push({
      date: d,
      dateStr: d.toISOString().split('T')[0],
      dayName: dayNames[i]
    });
  }
  return days;
});

const rosterData = computed(() => {
  return employees.value.map(e => ({
    id: e.id,
    full_name: e.full_name,
    employee_code: e.employee_code || `NV-${e.id}`,
  }));
});

// Format navigation label
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
  return name.split(' ').map(w => w[0]).join('').toUpperCase().slice(0, 2);
};

const formatDateDay = (date) => {
  return date.getDate() + '/' + (date.getMonth() + 1);
};

const formatDateLong = (date) => {
  if (!date) return '';
  return date.toLocaleDateString('vi-VN', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
};

// Shift lookup: resolve the assignment covering a date (effective-dated ranges).
// Prefers a dated one-day override (non-null expiry) over a standing/permanent
// assignment; ties broken by latest effective_date.
const getShiftAssignment = (employeeId, dateStr) => {
  const candidates = schedules.value.filter(s =>
    String(s.employee_id) === String(employeeId) &&
    s.effective_date && String(s.effective_date) <= dateStr &&
    (!s.expiry_date || String(s.expiry_date) >= dateStr)
  );
  if (!candidates.length) return null;
  candidates.sort((a, b) => {
    const aSpec = a.expiry_date ? 1 : 0;
    const bSpec = b.expiry_date ? 1 : 0;
    if (aSpec !== bSpec) return bSpec - aSpec; // dated override first
    return String(b.effective_date).localeCompare(String(a.effective_date));
  });
  return candidates[0];
};

const getShiftCode = (employeeId, dateStr) => {
  const assign = getShiftAssignment(employeeId, dateStr);
  if (!assign) return 'OFF';
  const shift = shiftTypes.value.find(s => String(s.id) === String(assign.shift_type_id));
  return shift ? shift.shift_code : 'OFF';
};

const getShiftTime = (employeeId, dateStr) => {
  const assign = getShiftAssignment(employeeId, dateStr);
  if (!assign) return '';
  const shift = shiftTypes.value.find(s => String(s.id) === String(assign.shift_type_id));
  return shift ? `${shift.start_time.slice(0, 5)}-${shift.end_time.slice(0, 5)}` : '';
};

const getShiftCellStyles = (employeeId, dateStr) => {
  const assign = getShiftAssignment(employeeId, dateStr);
  if (!assign) return { backgroundColor: 'transparent', borderColor: 'rgba(229, 231, 235, 0.5)', color: 'var(--muted-foreground)' };
  const shift = shiftTypes.value.find(s => String(s.id) === String(assign.shift_type_id));
  if (!shift) return { backgroundColor: 'transparent', borderColor: 'rgba(229, 231, 235, 0.5)' };
  
  return {
    backgroundColor: `${shift.color_code}15`,
    borderColor: shift.color_code,
    color: shift.color_code
  };
};

const openAssignModal = (employee, day) => {
  const assignment = getShiftAssignment(employee.id, day.dateStr);
  selectedShiftId.value = assignment ? assignment.shift_type_id : null;
  activeAssignment.value = {
    employeeId: employee.id,
    employeeName: employee.full_name,
    date: day.date,
    dateStr: day.dateStr
  };
  showAssignModal.value = true;
};

const saveAssignment = async () => {
  if (!activeAssignment.value) return;
  try {
    saving.value = true;
    const { employeeId, dateStr } = activeAssignment.value;

    // Bản ghi ghi-đè 1 ngày đã có cho đúng ngày này (effective=expiry=dateStr).
    const existingOverride = schedules.value.find(s =>
      String(s.employee_id) === String(employeeId) &&
      String(s.effective_date) === dateStr && String(s.expiry_date) === dateStr
    );

    if (selectedShiftId.value === null || selectedShiftId.value === 'OFF') {
      // Bỏ gán ngày này → xoá bản ghi-đè 1 ngày (quay về ca cố định nếu có).
      if (existingOverride?.id) {
        await workScheduleService.delete(existingOverride.id);
      }
      toast.success('Đã bỏ gán ca cho ngày này');
    } else {
      // Ghi-đè ca cho riêng 1 ngày (ca xoay): effective = expiry = ngày đó.
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
    // Lấy tất cả phân ca (kể cả ca cố định effective-dated cũ) để phân giải theo ngày.
    const res = await workScheduleService.getAll({ per_page: 500 });
    schedules.value = res?.data || res || [];
  } catch (err) {
    console.error('Error loading schedules:', err);
    schedules.value = [];
  }
};

onMounted(async () => {
  try {
    loading.value = true;
    
    const [employeesRes, shiftsRes] = await Promise.all([
      employeeService.getLookup(),
      workShiftService.getAll().catch(() => [])
    ]);

    let emps = employeesRes?.data || employeesRes || [];
    if (!Array.isArray(emps)) {
      emps = emps.items || emps.data || [];
    }
    if (!Array.isArray(emps)) emps = [];
    
    employees.value = emps;
    shiftTypes.value = Array.isArray(shiftsRes) ? shiftsRes : [];
    
    await loadSchedules();
  } catch (err) {
    console.error('Error mounting ShiftRoster:', err);
    toast.error('Lỗi tải dữ liệu ca làm việc');
  } finally {
    loading.value = false;
  }
});
</script>
