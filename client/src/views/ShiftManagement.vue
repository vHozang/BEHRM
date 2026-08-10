<template>
  <div class="space-y-6">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
      <div>
        <p class="text-xs font-bold uppercase tracking-[0.22em] text-primary">Công &amp; lịch</p>
        <h1 class="mt-1 text-3xl font-bold text-foreground">Ca làm việc &amp; Xếp ca</h1>
        <p class="mt-1 text-muted-foreground">
          Xếp lịch theo phòng ban, xoay CA1 → CA2 → CA3 hoặc nhập lịch từ Excel.
        </p>
      </div>
      <div v-if="activeTab === 'roster' && currentDepartment" class="rounded-2xl border border-border bg-card px-4 py-3 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Phòng đang xếp</p>
        <p class="mt-1 font-bold text-foreground">{{ currentDepartment.code }} · {{ currentDepartment.name }}</p>
      </div>
    </div>

    <div class="flex w-fit gap-1 rounded-xl border border-border bg-muted/40 p-1">
      <button
        v-for="tab in visibleTabs"
        :key="tab.key"
        class="rounded-lg px-4 py-2 text-sm font-semibold transition-colors"
        :class="activeTab === tab.key
          ? 'border border-border bg-card text-foreground shadow-sm'
          : 'text-muted-foreground hover:text-foreground'"
        @click="selectTab(tab.key)"
      >
        {{ tab.label }}
      </button>
    </div>

    <div v-if="activeTab === 'definitions'" class="space-y-4">
      <div class="flex justify-end">
        <BaseButton @click="openCreateShift">+ Thêm ca</BaseButton>
      </div>

      <BaseCard>
        <BaseTable :columns="shiftColumns" :data="workShifts">
          <template #cell-name="{ item }">
            <span :class="{ 'text-muted-foreground line-through': !item.is_active }">{{ item.name }}</span>
          </template>
          <template #cell-is_active="{ item }">
            <StatusPill :status="item.is_active ? 'Hoạt động' : 'Không hoạt động'" />
          </template>
          <template #actions="{ item }">
            <div class="flex flex-wrap gap-1">
              <button class="action-button bg-primary/10 text-primary hover:bg-primary/20" @click="editShift(item)">Chi tiết</button>
              <button
                v-if="item.is_active"
                class="action-button bg-amber-100 text-amber-700 hover:bg-amber-200 dark:bg-amber-900/30 dark:text-amber-400"
                @click="deactivateShift(item)"
              >
                Tạm ngưng
              </button>
              <button
                v-else
                class="action-button bg-green-100 text-green-700 hover:bg-green-200 dark:bg-green-900/30 dark:text-green-400"
                @click="activateShift(item)"
              >
                Kích hoạt
              </button>
              <button class="action-button bg-destructive/10 text-destructive hover:bg-destructive/20" @click="confirmDeleteShift(item)">Xóa</button>
            </div>
          </template>
          <template #empty>Chưa có ca làm việc nào</template>
        </BaseTable>
      </BaseCard>
    </div>

    <div v-else class="space-y-4">
      <BaseCard>
        <div class="grid gap-4 xl:grid-cols-[1.2fr_1fr_0.8fr_1.2fr_auto] xl:items-end">
          <label class="field-label">
            Phòng ban
            <select v-model.number="selectedDepartmentId" class="form-control mt-1" @change="loadCalendar">
              <option v-for="department in departments" :key="department.id" :value="department.id">
                {{ department.code }} · {{ department.name }}
              </option>
            </select>
          </label>
          <label class="field-label">
            Tuần bắt đầu
            <input v-model="selectedWeek" type="date" class="form-control mt-1" @change="normalizeAndLoadWeek" />
          </label>
          <label class="field-label">
            Nhóm ca gốc
            <select v-model="shiftGroupFilter" class="form-control mt-1">
              <option value="">Tất cả</option>
              <option value="CA1">CA1</option>
              <option value="CA2">CA2</option>
              <option value="CA3">CA3</option>
            </select>
          </label>
          <label class="field-label">
            Tìm nhân viên
            <input v-model.trim="employeeSearch" class="form-control mt-1" placeholder="Mã hoặc họ tên" />
          </label>
          <div class="flex items-center justify-end gap-2">
            <BaseButton variant="outline" size="sm" class="px-2" title="Tuần trước" @click="navigateWeek(-1)">←</BaseButton>
            <BaseButton variant="outline" size="sm" class="px-2" title="Tuần sau" @click="navigateWeek(1)">→</BaseButton>
          </div>
        </div>

        <div class="mt-5 flex flex-wrap gap-2 border-t border-border pt-4">
          <BaseButton size="sm" @click="openRotationModal">Xoay ca tự động</BaseButton>
          <BaseButton size="sm" variant="outline" @click="openTemplateModal">Tải mẫu xếp ca</BaseButton>
          <BaseButton size="sm" variant="secondary" @click="openImportModal">Upload lịch Excel</BaseButton>
          <BaseButton size="sm" variant="ghost" :loading="rosterLoading" @click="loadCalendar">Kiểm tra lại</BaseButton>
        </div>
      </BaseCard>

      <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <div class="stat-card">
          <span class="stat-label">Nhân viên hợp lệ</span>
          <strong class="stat-value">{{ rosterEmployees.length }}</strong>
        </div>
        <div class="stat-card">
          <span class="stat-label">Đang hiển thị</span>
          <strong class="stat-value">{{ filteredEmployees.length }}</strong>
        </div>
        <div class="stat-card">
          <span class="stat-label">Thiếu ca gốc</span>
          <strong class="stat-value" :class="skippedEmployees.length ? 'text-amber-600' : ''">{{ skippedEmployees.length }}</strong>
        </div>
        <div class="stat-card">
          <span class="stat-label">Khoảng tuần</span>
          <strong class="text-sm font-bold text-foreground">{{ formatWeekRange() }}</strong>
        </div>
      </div>

      <BaseCard>
        <div class="flex flex-wrap items-center gap-4 text-xs">
          <span class="font-bold uppercase tracking-wider text-muted-foreground">Ký hiệu:</span>
          <div v-for="shift in rosterShiftTypes" :key="shift.id" class="flex items-center gap-1.5">
            <span class="h-3.5 w-3.5 rounded-md border border-border" :style="{ backgroundColor: shift.color_code || '#64748b' }"></span>
            <span class="font-semibold text-foreground">{{ shift.shift_code }}</span>
            <span class="text-muted-foreground">({{ shiftTimeLabel(shift) }})</span>
          </div>
          <div class="flex items-center gap-1.5">
            <span class="h-3.5 w-3.5 rounded-md border-2 border-dashed border-border"></span>
            <span class="text-muted-foreground">OFF = nghỉ rõ ràng</span>
          </div>
          <div class="flex items-center gap-1.5">
            <span class="h-2.5 w-2.5 rounded-full bg-amber-500"></span>
            <span class="text-muted-foreground">Lịch sửa tay</span>
          </div>
        </div>
      </BaseCard>

      <BaseCard>
        <div v-if="rosterLoading" class="py-14 text-center text-muted-foreground">Đang tải lịch phân ca...</div>
        <div v-else-if="rosterError" class="rounded-xl border border-destructive/30 bg-destructive/5 p-5 text-sm text-destructive">
          {{ rosterError }}
        </div>
        <div v-else class="overflow-x-auto">
          <table class="w-full min-w-[1080px] border-collapse text-left text-sm">
            <thead>
              <tr class="border-b border-border bg-muted/30">
                <th class="sticky left-0 z-10 w-64 min-w-[240px] bg-card p-4 font-bold text-foreground">Nhân viên</th>
                <th
                  v-for="day in weekDays"
                  :key="day.dateStr"
                  class="min-w-[118px] p-4 text-center font-bold text-foreground"
                  :class="{ 'bg-primary/5 text-primary': day.isToday }"
                >
                  <div>{{ day.dayName }}</div>
                  <div class="mt-0.5 text-xs text-muted-foreground">{{ formatDateDay(day.date) }}</div>
                </th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border/60">
              <tr v-for="employee in filteredEmployees" :key="employee.id" class="hover:bg-muted/10">
                <td class="sticky left-0 z-[5] bg-card p-4">
                  <div class="flex items-center gap-3">
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary font-bold text-primary-foreground shadow-sm">
                      {{ getInitials(employee.full_name) }}
                    </div>
                    <div class="min-w-0">
                      <div class="truncate font-semibold text-foreground">{{ employee.full_name }}</div>
                      <div class="mt-1 flex items-center gap-2 text-xs text-muted-foreground">
                        <span>{{ employee.employee_code }}</span>
                        <span class="rounded-full bg-muted px-2 py-0.5 font-bold text-foreground">Gốc {{ employee.base_shift_code }}</span>
                      </div>
                    </div>
                  </div>
                </td>
                <td v-for="day in weekDays" :key="day.dateStr" class="p-2 text-center align-middle">
                  <button
                    class="group relative mx-auto flex h-14 w-24 flex-col items-center justify-center rounded-xl border shadow-sm transition hover:-translate-y-0.5 hover:shadow-md"
                    :style="cellStyles(cellFor(employee, day.dateStr))"
                    :title="cellTitle(cellFor(employee, day.dateStr))"
                    @click="openAssignModal(employee, day, cellFor(employee, day.dateStr))"
                  >
                    <span class="text-xs font-black tracking-wider">{{ cellFor(employee, day.dateStr)?.shift_code || 'OFF' }}</span>
                    <span class="mt-0.5 text-[9px] opacity-75">{{ cellTime(cellFor(employee, day.dateStr)) }}</span>
                    <span v-if="cellFor(employee, day.dateStr)?.is_manual" class="absolute right-1.5 top-1.5 h-2 w-2 rounded-full bg-amber-500 ring-2 ring-card"></span>
                  </button>
                </td>
              </tr>
              <tr v-if="filteredEmployees.length === 0">
                <td :colspan="8" class="px-4 py-14 text-center text-muted-foreground">Không có nhân viên phù hợp bộ lọc.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </BaseCard>

      <BaseCard v-if="skippedEmployees.length">
        <div class="flex items-start justify-between gap-4">
          <div>
            <h3 class="font-bold text-foreground">Nhân viên chưa thể xoay ca</h3>
            <p class="mt-1 text-sm text-muted-foreground">Cần có một ca gốc CA1, CA2 hoặc CA3 nhất quán trong tuần liền trước.</p>
          </div>
          <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-bold text-amber-700">{{ skippedEmployees.length }} người</span>
        </div>
        <div class="mt-4 grid gap-2 md:grid-cols-2">
          <div v-for="employee in skippedEmployees" :key="employee.id" class="rounded-xl border border-amber-200 bg-amber-50/60 p-3 dark:border-amber-900/50 dark:bg-amber-950/20">
            <p class="font-semibold text-foreground">{{ employee.employee_code }} · {{ employee.full_name }}</p>
            <p class="mt-1 text-xs text-amber-700 dark:text-amber-400">{{ employee.reason }}</p>
          </div>
        </div>
      </BaseCard>
    </div>

    <BaseModal v-model="showShiftModal" :title="shiftForm.id ? 'Chỉnh sửa ca làm việc' : 'Thêm ca làm việc'">
      <div class="space-y-4">
        <BaseInput v-model="shiftForm.code" label="Mã ca" required />
        <BaseInput v-model="shiftForm.name" label="Tên ca" required />
        <div>
          <label class="field-label">Nhãn màu ca</label>
          <div class="mt-1 flex items-center gap-2">
            <input v-model="shiftForm.color_code" type="color" class="h-10 w-16 cursor-pointer rounded border border-input bg-background" />
            <span class="text-sm text-muted-foreground">{{ shiftForm.color_code }}</span>
          </div>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
          <BaseInput v-model="shiftForm.start_time" type="time" label="Giờ bắt đầu" required />
          <BaseInput v-model="shiftForm.end_time" type="time" label="Giờ kết thúc" required />
        </div>
        <BaseInput v-if="!splitShift" v-model.number="shiftForm.break_minutes" type="number" label="Giờ nghỉ giữa ca (phút)" />
        <textarea
          v-if="!splitShift"
          v-model="shiftForm.break_note"
          rows="2"
          class="form-control"
          placeholder="Mô tả giờ nghỉ cho nhân viên"
        ></textarea>
        <div class="border-t border-border pt-3">
          <label class="flex items-center gap-2">
            <input v-model="splitShift" type="checkbox" class="rounded" />
            <span class="font-medium text-foreground">Ca gãy (nhiều khung giờ)</span>
          </label>
          <div v-if="splitShift" class="mt-3 space-y-2">
            <div v-for="(segment, index) in segments" :key="index" class="flex items-center gap-2">
              <input v-model="segment.start" type="time" class="form-control" />
              <span>→</span>
              <input v-model="segment.end" type="time" class="form-control" />
              <button class="text-xs font-semibold text-destructive" @click="segments.splice(index, 1)">Xóa</button>
            </div>
            <button class="text-xs font-semibold text-primary" @click="segments.push({ start: '', end: '' })">+ Thêm khung giờ</button>
          </div>
        </div>
        <label class="flex items-center gap-2">
          <input v-model="shiftForm.is_active" type="checkbox" class="rounded" />
          <span>Hoạt động</span>
        </label>
      </div>
      <template #footer>
        <BaseButton variant="outline" @click="showShiftModal = false">Hủy</BaseButton>
        <BaseButton :loading="shiftSaving" @click="saveShift">Lưu</BaseButton>
      </template>
    </BaseModal>

    <BaseModal v-model="showDeleteModal" title="Xóa ca làm việc">
      <p class="text-foreground">Bạn có chắc muốn xóa ca <strong>{{ deleteTarget?.name }}</strong>?</p>
      <template #footer>
        <BaseButton variant="outline" @click="showDeleteModal = false">Hủy</BaseButton>
        <BaseButton variant="destructive" :loading="deleting" @click="deleteShift">Xóa</BaseButton>
      </template>
    </BaseModal>

    <BaseModal v-model="showAssignModal" title="Sửa lịch một ngày">
      <div v-if="activeAssignment" class="space-y-4">
        <div class="rounded-xl bg-muted p-3 text-sm">
          <p><strong>Nhân viên:</strong> {{ activeAssignment.employee.full_name }} ({{ activeAssignment.employee.employee_code }})</p>
          <p class="mt-1"><strong>Ngày:</strong> {{ formatDateLong(activeAssignment.day.date) }}</p>
          <p class="mt-1"><strong>Nguồn hiện tại:</strong> {{ sourceLabel(activeAssignment.cell?.source) }}</p>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <button
            class="shift-choice"
            :class="selectedShiftValue === 'OFF' ? 'shift-choice-active' : ''"
            @click="selectedShiftValue = 'OFF'"
          >
            <span class="font-bold">OFF</span>
            <span class="text-[10px] text-muted-foreground">Lưu ngày nghỉ rõ ràng</span>
          </button>
          <button
            v-for="shift in rosterShiftTypes"
            :key="shift.id"
            class="shift-choice"
            :class="String(selectedShiftValue) === String(shift.id) ? 'shift-choice-active' : ''"
            @click="selectedShiftValue = String(shift.id)"
          >
            <span class="font-bold">{{ shift.shift_code }}</span>
            <span class="text-[10px] text-muted-foreground">{{ shiftTimeLabel(shift) }}</span>
          </button>
        </div>
        <div v-if="activeAssignment.cell?.override_assignment_id" class="rounded-xl border border-border bg-muted/30 p-3 text-xs text-muted-foreground">
          “Khôi phục lịch nền” sẽ xóa ngoại lệ đúng ngày này để quay về lịch tuần hoặc ca cố định bên dưới.
        </div>
      </div>
      <template #footer>
        <BaseButton
          v-if="activeAssignment?.cell?.override_assignment_id"
          variant="ghost"
          class="sm:mr-auto"
          :loading="assignmentSaving"
          @click="restoreInheritedAssignment"
        >
          Khôi phục lịch nền
        </BaseButton>
        <BaseButton variant="outline" @click="showAssignModal = false">Hủy</BaseButton>
        <BaseButton :loading="assignmentSaving" @click="saveAssignment">Lưu ngoại lệ</BaseButton>
      </template>
    </BaseModal>

    <BaseModal v-model="showRotationModal" title="Xoay ca tự động" size="xl" :close-on-backdrop="false">
      <div class="space-y-5">
        <div class="grid gap-4 md:grid-cols-3">
          <label class="field-label">
            Phòng ban
            <select v-model.number="rotationDepartmentId" class="form-control mt-1" :disabled="!!rotationPreview" @change="loadRotationCandidates">
              <option v-for="department in departments" :key="department.id" :value="department.id">{{ department.code }} · {{ department.name }}</option>
            </select>
          </label>
          <label class="field-label">
            Thứ Hai bắt đầu
            <input v-model="rotationStart" type="date" class="form-control mt-1" :min="nextMonday" :disabled="!!rotationPreview" @change="loadRotationCandidates" />
          </label>
          <label class="field-label">
            Số tuần (1–26)
            <input v-model.number="rotationWeeks" type="number" min="1" max="26" class="form-control mt-1" :disabled="!!rotationPreview" />
          </label>
        </div>

        <template v-if="!rotationPreview">
          <div class="flex flex-col gap-3 rounded-xl border border-border bg-muted/20 p-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <p class="font-bold text-foreground">Chọn nhân viên tham gia vòng xoay</p>
              <p class="text-xs text-muted-foreground">Mặc định chọn toàn bộ người có ca gốc hợp lệ trong tuần liền trước.</p>
            </div>
            <div class="flex gap-2">
              <button class="text-xs font-bold text-primary" @click="selectAllRotationEmployees">Chọn tất cả</button>
              <button class="text-xs font-bold text-muted-foreground" @click="rotationEmployeeIds = []">Bỏ chọn</button>
            </div>
          </div>
          <input v-model.trim="rotationSearch" class="form-control" placeholder="Tìm mã hoặc tên nhân viên" />
          <div v-if="rotationCandidatesLoading" class="py-8 text-center text-muted-foreground">Đang kiểm tra ca gốc...</div>
          <div v-else class="max-h-72 overflow-y-auto rounded-xl border border-border">
            <label
              v-for="employee in filteredRotationCandidates"
              :key="employee.id"
              class="flex cursor-pointer items-center gap-3 border-b border-border/60 px-4 py-3 last:border-b-0 hover:bg-muted/30"
            >
              <input v-model="rotationEmployeeIds" type="checkbox" :value="employee.id" class="rounded" />
              <span class="min-w-0 flex-1">
                <span class="block truncate font-semibold text-foreground">{{ employee.employee_code }} · {{ employee.full_name }}</span>
                <span class="text-xs text-muted-foreground">Ca gốc {{ employee.base_shift_code }} → {{ nextRotationCode(employee.base_shift_code) }}</span>
              </span>
            </label>
            <p v-if="!filteredRotationCandidates.length" class="p-8 text-center text-sm text-muted-foreground">Không có nhân viên phù hợp.</p>
          </div>
          <div v-if="rotationCandidateSkipped.length" class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm dark:border-amber-900/50 dark:bg-amber-950/20">
            <p class="font-bold text-amber-800 dark:text-amber-300">Bỏ qua {{ rotationCandidateSkipped.length }} nhân viên chưa có ca gốc rõ ràng</p>
            <p class="mt-1 text-xs text-amber-700 dark:text-amber-400">{{ rotationCandidateSkipped.map(item => item.employee_code).join(', ') }}</p>
          </div>
        </template>

        <template v-else>
          <div class="grid gap-3 sm:grid-cols-4">
            <div class="preview-stat"><span>Nhân viên</span><strong>{{ rotationPreview.employees }}</strong></div>
            <div class="preview-stat"><span>Số tuần</span><strong>{{ rotationPreview.weeks }}</strong></div>
            <div class="preview-stat"><span>Phân ca tạo mới</span><strong>{{ rotationPreview.assignments }}</strong></div>
            <div class="preview-stat"><span>Xung đột sửa tay</span><strong :class="rotationPreview.manual_conflicts?.length ? 'text-amber-600' : ''">{{ rotationPreview.manual_conflicts?.length || 0 }}</strong></div>
          </div>
          <div class="max-h-72 overflow-y-auto rounded-xl border border-border">
            <table class="w-full text-sm">
              <thead class="sticky top-0 bg-muted"><tr><th class="p-3 text-left">Nhân viên</th><th class="p-3 text-center">Ca gốc</th><th class="p-3 text-center">Tuần đầu</th></tr></thead>
              <tbody class="divide-y divide-border">
                <tr v-for="item in rotationPreview.transitions" :key="item.id">
                  <td class="p-3 font-semibold">{{ item.employee_code }} · {{ item.full_name }}</td>
                  <td class="p-3 text-center">{{ item.from }}</td>
                  <td class="p-3 text-center font-bold text-primary">{{ item.to }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <label v-if="rotationPreview.manual_conflicts?.length" class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-900/50 dark:bg-amber-950/20">
            <input v-model="rotationOverwriteManual" type="checkbox" class="mt-1 rounded" />
            <span>
              <strong class="block text-foreground">Ghi đè lịch sửa tay</strong>
              <span class="text-xs text-muted-foreground">Nếu không chọn, các ngoại lệ sửa tay được giữ nguyên và lịch tự động chỉ áp dụng cho ô còn lại.</span>
            </span>
          </label>
        </template>
      </div>
      <template #footer>
        <BaseButton v-if="rotationPreview" variant="outline" @click="rotationPreview = null">Quay lại chọn</BaseButton>
        <BaseButton v-else variant="outline" @click="showRotationModal = false">Hủy</BaseButton>
        <BaseButton v-if="!rotationPreview" :loading="rotationBusy" :disabled="rotationEmployeeIds.length === 0" @click="previewRotation">Xem trước lịch xoay</BaseButton>
        <BaseButton v-else :loading="rotationBusy" @click="applyRotation">Áp dụng lịch</BaseButton>
      </template>
    </BaseModal>

    <BaseModal v-model="showTemplateModal" title="Tải mẫu xếp ca Excel">
      <div class="space-y-4">
        <p class="rounded-xl bg-muted p-3 text-sm text-muted-foreground">
          File được điền sẵn mã và tên nhân viên. Chỉ sửa tuần bắt đầu và các ô Thứ Hai–Chủ nhật, mỗi ô phải là mã ca hoặc OFF.
        </p>
        <label class="field-label">
          Phòng ban
          <select v-model.number="templateDepartmentId" class="form-control mt-1">
            <option v-for="department in departments" :key="department.id" :value="department.id">{{ department.code }} · {{ department.name }}</option>
          </select>
        </label>
        <label class="field-label">
          Thứ Hai bắt đầu
          <input v-model="templateWeek" type="date" class="form-control mt-1" :min="nextMonday" />
        </label>
      </div>
      <template #footer>
        <BaseButton variant="outline" @click="showTemplateModal = false">Hủy</BaseButton>
        <BaseButton :loading="templateBusy" @click="downloadTemplate">Tải file .xlsx</BaseButton>
      </template>
    </BaseModal>

    <BaseModal v-model="showImportModal" title="Upload lịch xếp ca Excel" size="xl" :close-on-backdrop="false">
      <div class="space-y-5">
        <div class="grid gap-4 md:grid-cols-2">
          <label class="field-label">
            Phòng ban
            <select v-model.number="importDepartmentId" class="form-control mt-1" :disabled="!!importPreview">
              <option v-for="department in departments" :key="department.id" :value="department.id">{{ department.code }} · {{ department.name }}</option>
            </select>
          </label>
          <label class="field-label">
            File mẫu đã điền
            <input ref="importFileInput" type="file" accept=".xlsx" class="form-control mt-1" :disabled="!!importPreview" @change="selectImportFile" />
          </label>
        </div>

        <template v-if="importPreview">
          <div class="grid gap-3 sm:grid-cols-4">
            <div class="preview-stat"><span>Tuần</span><strong class="text-sm">{{ formatDate(importPreview.week_start) }}</strong></div>
            <div class="preview-stat"><span>Nhân viên</span><strong>{{ importPreview.employees }}</strong></div>
            <div class="preview-stat"><span>Ô thay đổi</span><strong>{{ importChangedCount }}</strong></div>
            <div class="preview-stat"><span>Xung đột sửa tay</span><strong :class="importPreview.manual_conflicts?.length ? 'text-amber-600' : ''">{{ importPreview.manual_conflicts?.length || 0 }}</strong></div>
          </div>

          <div v-if="importPreview.warnings?.length" class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/20 dark:text-amber-300">
            <p class="font-bold">Cảnh báo</p>
            <p v-for="warning in importPreview.warnings" :key="`${warning.row}-${warning.employee_code}`" class="mt-1 text-xs">Dòng {{ warning.row }} · {{ warning.employee_code }}: {{ warning.message }}</p>
          </div>

          <div class="max-h-[360px] overflow-auto rounded-xl border border-border">
            <table class="w-full min-w-[1050px] text-sm">
              <thead class="sticky top-0 z-10 bg-muted">
                <tr>
                  <th class="p-3 text-left">Nhân viên</th>
                  <th v-for="day in importDayHeaders" :key="day" class="p-3 text-center">{{ day }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-border">
                <tr v-for="employee in groupedImportEntries" :key="employee.employee_id">
                  <td class="p-3 font-semibold">{{ employee.employee_code }}<br><span class="font-normal text-muted-foreground">{{ employee.full_name }}</span></td>
                  <td v-for="entry in employee.days" :key="entry.date" class="p-2 text-center">
                    <div class="rounded-lg px-2 py-1" :class="entry.changed ? 'bg-primary/10' : 'bg-muted/40'">
                      <span class="block text-[10px] text-muted-foreground">{{ entry.current_shift_code || 'OFF' }}</span>
                      <span class="block font-black" :class="entry.changed ? 'text-primary' : 'text-foreground'">→ {{ entry.shift_code }}</span>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <label v-if="importPreview.manual_conflicts?.length" class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-900/50 dark:bg-amber-950/20">
            <input v-model="importOverwriteManual" type="checkbox" class="mt-1 rounded" />
            <span>
              <strong class="block text-foreground">Ghi đè lịch sửa tay</strong>
              <span class="text-xs text-muted-foreground">Bỏ chọn để giữ các ngoại lệ sửa tay và chỉ nhập các ô còn lại.</span>
            </span>
          </label>
        </template>
        <div v-else class="rounded-xl border border-dashed border-border bg-muted/20 p-5 text-sm text-muted-foreground">
          Hệ thống sẽ kiểm tra đúng phòng, đúng danh sách nhân viên, đủ 7 ngày, mã ca hợp lệ và mọi thay đổi kể từ lúc tải mẫu. Có một lỗi bất kỳ thì toàn bộ file bị chặn.
        </div>
      </div>
      <template #footer>
        <BaseButton v-if="importPreview" variant="outline" @click="resetImportPreview">Chọn file khác</BaseButton>
        <BaseButton v-else variant="outline" @click="showImportModal = false">Hủy</BaseButton>
        <BaseButton v-if="!importPreview" :loading="importBusy" :disabled="!importFile" @click="previewImport">Kiểm tra &amp; xem trước</BaseButton>
        <BaseButton v-else :loading="importBusy" @click="applyImport">Áp dụng toàn bộ file</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import BaseButton from '../components/BaseButton.vue';
import BaseCard from '../components/BaseCard.vue';
import BaseInput from '../components/BaseInput.vue';
import BaseModal from '../components/BaseModal.vue';
import BaseTable from '../components/BaseTable.vue';
import StatusPill from '../components/StatusPill.vue';
import { useToast } from '../composables/useToast';
import { authService } from '../services/authService';
import { shiftRosterService } from '../services/shiftRosterService';
import { workScheduleService } from '../services/workScheduleService';
import { workShiftService } from '../services/workShiftService';

const route = useRoute();
const router = useRouter();
const toast = useToast();

const pad = (value) => String(value).padStart(2, '0');
const localDateString = (date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
const parseLocalDate = (value) => {
  const [year, month, day] = String(value).split('-').map(Number);
  return new Date(year, month - 1, day);
};
const mondayFor = (value) => {
  const date = value instanceof Date ? new Date(value) : parseLocalDate(value);
  date.setHours(0, 0, 0, 0);
  const day = date.getDay();
  date.setDate(date.getDate() - (day === 0 ? 6 : day - 1));
  return localDateString(date);
};
const nextMondayFor = () => {
  const date = new Date();
  date.setHours(0, 0, 0, 0);
  const days = ((8 - date.getDay()) % 7) || 7;
  date.setDate(date.getDate() + days);
  return localDateString(date);
};

const nextMonday = nextMondayFor();
const selectedWeek = ref(mondayFor(new Date()));
const selectedDepartmentId = ref(null);
const departments = ref([]);
const currentDepartment = ref(null);
const rosterEmployees = ref([]);
const skippedEmployees = ref([]);
const rosterShiftTypes = ref([]);
const rosterLoading = ref(false);
const rosterError = ref('');
const employeeSearch = ref('');
const shiftGroupFilter = ref('');

const access = authService.getAccess();
const roleCodes = access.roles.map(role => String(role.role_code || '').toUpperCase());
const canManageShiftTypes = ref(access.full || roleCodes.some(code => ['ADMIN', 'TENANT_ADMIN', 'HR'].includes(code)));
const allTabs = [
  { key: 'definitions', label: 'Định nghĩa ca' },
  { key: 'roster', label: 'Xếp ca' },
];
const visibleTabs = computed(() => canManageShiftTypes.value ? allTabs : allTabs.filter(tab => tab.key === 'roster'));
const activeTab = ref(route.query.tab === 'roster' || !canManageShiftTypes.value ? 'roster' : 'definitions');

const selectTab = (tab) => {
  activeTab.value = tab;
  router.replace({ query: { ...route.query, tab } });
};

watch(canManageShiftTypes, (allowed) => {
  if (!allowed && activeTab.value === 'definitions') selectTab('roster');
});

const weekDays = computed(() => {
  const monday = parseLocalDate(selectedWeek.value);
  const names = ['Thứ 2', 'Thứ 3', 'Thứ 4', 'Thứ 5', 'Thứ 6', 'Thứ 7', 'Chủ nhật'];
  const today = localDateString(new Date());
  return names.map((dayName, index) => {
    const date = new Date(monday);
    date.setDate(monday.getDate() + index);
    const dateStr = localDateString(date);
    return { dayName, date, dateStr, isToday: dateStr === today };
  });
});

const filteredEmployees = computed(() => {
  const needle = employeeSearch.value.toLocaleLowerCase('vi');
  return rosterEmployees.value.filter((employee) => {
    const groupMatches = !shiftGroupFilter.value || employee.base_shift_code === shiftGroupFilter.value;
    const searchMatches = !needle || `${employee.employee_code} ${employee.full_name}`.toLocaleLowerCase('vi').includes(needle);
    return groupMatches && searchMatches;
  });
});

const loadCalendar = async () => {
  rosterLoading.value = true;
  rosterError.value = '';
  try {
    const data = await shiftRosterService.getCalendar({
      department_id: selectedDepartmentId.value || undefined,
      week_start: selectedWeek.value,
    });
    departments.value = data.departments || [];
    currentDepartment.value = data.department || null;
    selectedDepartmentId.value = data.department?.id || selectedDepartmentId.value;
    selectedWeek.value = data.week_start || selectedWeek.value;
    rosterEmployees.value = data.employees || [];
    skippedEmployees.value = data.skipped_employees || [];
    rosterShiftTypes.value = data.shift_types || [];
    canManageShiftTypes.value = data.permissions?.manage_shift_types === true;
  } catch (error) {
    rosterEmployees.value = [];
    skippedEmployees.value = [];
    rosterError.value = errorMessage(error, 'Không thể tải lịch xếp ca');
  } finally {
    rosterLoading.value = false;
  }
};

const normalizeAndLoadWeek = () => {
  selectedWeek.value = mondayFor(selectedWeek.value);
  loadCalendar();
};

const navigateWeek = (offset) => {
  const date = parseLocalDate(selectedWeek.value);
  date.setDate(date.getDate() + offset * 7);
  selectedWeek.value = localDateString(date);
  loadCalendar();
};

const cellFor = (employee, date) => employee.cells?.find(cell => cell.date === date) || null;
const cellTime = (cell) => cell?.start_time && cell?.end_time ? `${String(cell.start_time).slice(0, 5)}–${String(cell.end_time).slice(0, 5)}` : '';
const cellStyles = (cell) => {
  if (!cell || cell.is_day_off) {
    return { backgroundColor: 'transparent', borderColor: 'var(--border)', color: 'var(--muted-foreground)', borderStyle: 'dashed' };
  }
  const color = cell.color_code || '#0f766e';
  return { backgroundColor: `${color}18`, borderColor: color, color };
};
const sourceLabel = (source) => ({
  manual: 'Sửa tay', rotation: 'Xoay tự động', 'excel-import': 'Excel', standing: 'Ca cố định', 'rest-day': 'Ngày nghỉ theo ca', unassigned: 'Chưa xếp',
}[source] || source || 'Không xác định');
const cellTitle = (cell) => `${cell?.shift_name || 'Nghỉ'} · ${sourceLabel(cell?.source)}`;
const shiftTimeLabel = (shift) => `${String(shift.start_time || '').slice(0, 5)} - ${String(shift.end_time || '').slice(0, 5)}`;
const formatDateDay = (date) => `${date.getDate()}/${date.getMonth() + 1}`;
const formatDate = (value) => value ? parseLocalDate(value).toLocaleDateString('vi-VN') : '';
const formatDateLong = (date) => date?.toLocaleDateString('vi-VN', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' }) || '';
const formatWeekRange = () => `${formatDateDay(weekDays.value[0]?.date)} - ${formatDateDay(weekDays.value[6]?.date)}`;
const getInitials = (name) => String(name || '').split(' ').filter(Boolean).map(word => word[0]).join('').toUpperCase().slice(0, 2);

const workShifts = ref([]);
const showShiftModal = ref(false);
const showDeleteModal = ref(false);
const deleteTarget = ref(null);
const deleting = ref(false);
const shiftSaving = ref(false);
const splitShift = ref(false);
const segments = ref([]);
const emptyShiftForm = () => ({ code: '', name: '', start_time: '', end_time: '', break_minutes: 30, break_note: '', color_code: '#0f766e', is_active: true });
const shiftForm = ref(emptyShiftForm());
const shiftColumns = [
  { key: 'code', label: 'Mã ca' }, { key: 'name', label: 'Tên ca' }, { key: 'start_time', label: 'Giờ bắt đầu' },
  { key: 'end_time', label: 'Giờ kết thúc' }, { key: 'break_minutes', label: 'Nghỉ (phút)' }, { key: 'is_active', label: 'Trạng thái' },
];

const loadShiftDefinitions = async () => {
  if (!canManageShiftTypes.value) return;
  try {
    const response = await workShiftService.getAll();
    workShifts.value = Array.isArray(response) ? response : (response?.data || []);
  } catch (error) {
    workShifts.value = [];
    toast.error(errorMessage(error, 'Không thể tải định nghĩa ca'));
  }
};
const openCreateShift = () => { shiftForm.value = emptyShiftForm(); splitShift.value = false; segments.value = []; showShiftModal.value = true; };
const editShift = (item) => {
  shiftForm.value = { ...item };
  if (shiftForm.value.break_minutes == null) shiftForm.value.break_minutes = 30;
  const sourceSegments = Array.isArray(item.segments) ? item.segments : [];
  splitShift.value = sourceSegments.length > 0;
  segments.value = sourceSegments.map(segment => ({ start: String(segment.start || '').slice(0, 5), end: String(segment.end || '').slice(0, 5) }));
  showShiftModal.value = true;
};
const saveShift = async () => {
  const payload = { ...shiftForm.value };
  payload.segments = splitShift.value ? segments.value.filter(segment => segment.start && segment.end) : null;
  if (splitShift.value && !payload.segments.length) return toast.error('Ca gãy cần ít nhất một khung giờ hợp lệ');
  shiftSaving.value = true;
  try {
    if (payload.id) await workShiftService.update(payload.id, payload);
    else await workShiftService.create(payload);
    toast.success(payload.id ? 'Đã cập nhật ca làm việc' : 'Đã thêm ca làm việc');
    showShiftModal.value = false;
    await Promise.all([loadShiftDefinitions(), loadCalendar()]);
  } catch (error) {
    toast.error(errorMessage(error, 'Không thể lưu ca làm việc'));
  } finally {
    shiftSaving.value = false;
  }
};
const setShiftActive = async (item, isActive) => {
  try { await workShiftService.update(item.id, { is_active: isActive }); await loadShiftDefinitions(); toast.success(isActive ? 'Đã kích hoạt ca' : 'Đã tạm ngưng ca'); }
  catch (error) { toast.error(errorMessage(error, 'Không thể cập nhật ca')); }
};
const deactivateShift = item => setShiftActive(item, false);
const activateShift = item => setShiftActive(item, true);
const confirmDeleteShift = (item) => { deleteTarget.value = item; showDeleteModal.value = true; };
const deleteShift = async () => {
  if (!deleteTarget.value) return;
  deleting.value = true;
  try { await workShiftService.delete(deleteTarget.value.id); showDeleteModal.value = false; toast.success('Đã xóa ca làm việc'); await Promise.all([loadShiftDefinitions(), loadCalendar()]); }
  catch (error) { toast.error(errorMessage(error, 'Không thể xóa ca làm việc')); }
  finally { deleting.value = false; }
};

const showAssignModal = ref(false);
const activeAssignment = ref(null);
const selectedShiftValue = ref('OFF');
const assignmentSaving = ref(false);
const openAssignModal = (employee, day, cell) => {
  activeAssignment.value = { employee, day, cell };
  selectedShiftValue.value = cell?.is_day_off || !cell?.shift_type_id ? 'OFF' : String(cell.shift_type_id);
  showAssignModal.value = true;
};
const saveAssignment = async () => {
  if (!activeAssignment.value) return;
  assignmentSaving.value = true;
  try {
    const { employee, day, cell } = activeAssignment.value;
    const isOff = selectedShiftValue.value === 'OFF';
    const payload = {
      employee_id: employee.id,
      shift_type_id: isOff ? null : Number(selectedShiftValue.value),
      is_day_off: isOff,
      effective_date: day.dateStr,
      expiry_date: day.dateStr,
      status: 'ACTIVE',
    };
    if (cell?.override_assignment_id) await workScheduleService.update(cell.override_assignment_id, payload);
    else await workScheduleService.create(payload);
    showAssignModal.value = false;
    toast.success(isOff ? 'Đã lưu ngày nghỉ OFF' : 'Đã lưu ca sửa tay');
    await loadCalendar();
  } catch (error) {
    toast.error(errorMessage(error, 'Không thể lưu lịch'));
  } finally {
    assignmentSaving.value = false;
  }
};
const restoreInheritedAssignment = async () => {
  const id = activeAssignment.value?.cell?.override_assignment_id;
  if (!id) return;
  assignmentSaving.value = true;
  try {
    await workScheduleService.delete(id);
    showAssignModal.value = false;
    toast.success('Đã khôi phục lịch nền');
    await loadCalendar();
  } catch (error) {
    toast.error(errorMessage(error, 'Không thể khôi phục lịch nền'));
  } finally {
    assignmentSaving.value = false;
  }
};

const showRotationModal = ref(false);
const rotationDepartmentId = ref(null);
const rotationStart = ref(nextMonday);
const rotationWeeks = ref(12);
const rotationCandidates = ref([]);
const rotationCandidateSkipped = ref([]);
const rotationEmployeeIds = ref([]);
const rotationSearch = ref('');
const rotationCandidatesLoading = ref(false);
const rotationBusy = ref(false);
const rotationPreview = ref(null);
const rotationOverwriteManual = ref(false);
const filteredRotationCandidates = computed(() => {
  const needle = rotationSearch.value.toLocaleLowerCase('vi');
  return rotationCandidates.value.filter(employee => !needle || `${employee.employee_code} ${employee.full_name}`.toLocaleLowerCase('vi').includes(needle));
});
const nextRotationCode = code => ({ CA1: 'CA2', CA2: 'CA3', CA3: 'CA1' }[code] || '?');
const selectAllRotationEmployees = () => { rotationEmployeeIds.value = rotationCandidates.value.map(employee => employee.id); };
const openRotationModal = async () => {
  rotationDepartmentId.value = selectedDepartmentId.value;
  rotationStart.value = nextMonday;
  rotationWeeks.value = 12;
  rotationPreview.value = null;
  rotationOverwriteManual.value = false;
  rotationSearch.value = '';
  showRotationModal.value = true;
  await loadRotationCandidates();
};
const loadRotationCandidates = async () => {
  if (!rotationDepartmentId.value || !rotationStart.value || rotationPreview.value) return;
  rotationCandidatesLoading.value = true;
  try {
    rotationStart.value = mondayFor(rotationStart.value);
    const data = await shiftRosterService.getCalendar({ department_id: rotationDepartmentId.value, week_start: rotationStart.value });
    rotationCandidates.value = data.employees || [];
    rotationCandidateSkipped.value = data.skipped_employees || [];
    selectAllRotationEmployees();
  } catch (error) {
    rotationCandidates.value = [];
    rotationEmployeeIds.value = [];
    toast.error(errorMessage(error, 'Không thể tải danh sách xoay ca'));
  } finally {
    rotationCandidatesLoading.value = false;
  }
};
const previewRotation = async () => {
  if (rotationEmployeeIds.value.length === 0) return toast.error('Hãy chọn ít nhất một nhân viên');
  rotationBusy.value = true;
  try {
    rotationPreview.value = await shiftRosterService.previewRotation({
      department_id: rotationDepartmentId.value,
      start_date: rotationStart.value,
      weeks: Math.max(1, Math.min(26, Number(rotationWeeks.value) || 12)),
      employee_ids: rotationEmployeeIds.value,
    });
  } catch (error) {
    toast.error(errorMessage(error, 'Không thể tạo preview lịch xoay'));
  } finally {
    rotationBusy.value = false;
  }
};
const applyRotation = async () => {
  rotationBusy.value = true;
  try {
    const result = await shiftRosterService.applyRotation(rotationPreview.value.preview_token, rotationOverwriteManual.value);
    toast.success(`Đã tạo ${result.assignments_created} phân ca cho ${result.employees} nhân viên`);
    showRotationModal.value = false;
    selectedWeek.value = rotationStart.value;
    selectedDepartmentId.value = rotationDepartmentId.value;
    await loadCalendar();
  } catch (error) {
    toast.error(errorMessage(error, 'Không thể áp dụng lịch xoay'));
  } finally {
    rotationBusy.value = false;
  }
};

const showTemplateModal = ref(false);
const templateDepartmentId = ref(null);
const templateWeek = ref(nextMonday);
const templateBusy = ref(false);
const openTemplateModal = () => {
  templateDepartmentId.value = selectedDepartmentId.value;
  templateWeek.value = selectedWeek.value >= nextMonday ? selectedWeek.value : nextMonday;
  showTemplateModal.value = true;
};
const downloadTemplate = async () => {
  if (!templateDepartmentId.value || !templateWeek.value) return toast.error('Hãy chọn phòng ban và tuần bắt đầu');
  templateBusy.value = true;
  try {
    templateWeek.value = mondayFor(templateWeek.value);
    const { blob, filename } = await shiftRosterService.downloadTemplate({ department_id: templateDepartmentId.value, week_start: templateWeek.value });
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = filename;
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    URL.revokeObjectURL(url);
    showTemplateModal.value = false;
    toast.success('Đã tải file mẫu xếp ca');
  } catch (error) {
    toast.error(errorMessage(error, 'Không thể tải file mẫu'));
  } finally {
    templateBusy.value = false;
  }
};

const showImportModal = ref(false);
const importDepartmentId = ref(null);
const importFile = ref(null);
const importFileInput = ref(null);
const importPreview = ref(null);
const importBusy = ref(false);
const importOverwriteManual = ref(false);
const importDayHeaders = ['Thứ 2', 'Thứ 3', 'Thứ 4', 'Thứ 5', 'Thứ 6', 'Thứ 7', 'Chủ nhật'];
const groupedImportEntries = computed(() => {
  const groups = new Map();
  for (const entry of importPreview.value?.entries || []) {
    if (!groups.has(entry.employee_id)) groups.set(entry.employee_id, { employee_id: entry.employee_id, employee_code: entry.employee_code, full_name: entry.full_name, days: [] });
    groups.get(entry.employee_id).days.push(entry);
  }
  return [...groups.values()];
});
const importChangedCount = computed(() => (importPreview.value?.entries || []).filter(entry => entry.changed).length);
const openImportModal = () => {
  importDepartmentId.value = selectedDepartmentId.value;
  importFile.value = null;
  importPreview.value = null;
  importOverwriteManual.value = false;
  showImportModal.value = true;
};
const selectImportFile = event => { importFile.value = event.target.files?.[0] || null; };
const previewImport = async () => {
  if (!importFile.value) return toast.error('Hãy chọn file .xlsx');
  importBusy.value = true;
  try {
    importPreview.value = await shiftRosterService.previewImport(importDepartmentId.value, importFile.value);
  } catch (error) {
    toast.error(errorMessage(error, 'File xếp ca không hợp lệ'), 9000);
  } finally {
    importBusy.value = false;
  }
};
const resetImportPreview = () => {
  importPreview.value = null;
  importFile.value = null;
  importOverwriteManual.value = false;
  if (importFileInput.value) importFileInput.value.value = '';
};
const applyImport = async () => {
  importBusy.value = true;
  try {
    const result = await shiftRosterService.applyImport(importPreview.value.preview_token, importOverwriteManual.value);
    toast.success(`Đã nhập ${result.assignments_created} ô lịch cho ${result.employees} nhân viên`);
    showImportModal.value = false;
    selectedDepartmentId.value = importDepartmentId.value;
    selectedWeek.value = result.week_start;
    await loadCalendar();
  } catch (error) {
    toast.error(errorMessage(error, 'Không thể áp dụng file xếp ca'));
  } finally {
    importBusy.value = false;
  }
};

function errorMessage(error, fallback) {
  const data = error?.response?.data;
  const errors = data?.data?.errors || data?.errors;
  if (errors && typeof errors === 'object') {
    return Object.values(errors).flat().filter(Boolean).join(' · ');
  }
  return data?.message || error?.message || fallback;
}

onMounted(async () => {
  await loadCalendar();
  await loadShiftDefinitions();
});
</script>

<style scoped>
.field-label { @apply block text-sm font-semibold text-foreground; }
.form-control { @apply w-full rounded-xl border border-input bg-background px-3 py-2 text-sm text-foreground outline-none transition focus:border-primary focus:ring-2 focus:ring-primary/15 disabled:cursor-not-allowed disabled:opacity-60; }
.action-button { @apply rounded-md px-3 py-1.5 text-xs font-medium transition-colors; }
.stat-card { @apply flex min-h-24 flex-col justify-between rounded-2xl border border-border bg-card p-4 shadow-sm; }
.stat-label { @apply text-xs font-bold uppercase tracking-wider text-muted-foreground; }
.stat-value { @apply text-2xl font-black text-foreground; }
.shift-choice { @apply flex min-h-16 flex-col items-start justify-center gap-1 rounded-xl border border-border p-3 text-left text-foreground transition hover:bg-muted; }
.shift-choice-active { @apply border-primary bg-primary/5 text-primary ring-1 ring-primary/30; }
.preview-stat { @apply flex min-h-20 flex-col justify-between rounded-xl border border-border bg-muted/20 p-3 text-xs text-muted-foreground; }
.preview-stat strong { @apply text-xl font-black text-foreground; }
</style>
