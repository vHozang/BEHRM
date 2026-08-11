<template>
  <div class="space-y-6">
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
      <div>
        <h1 class="text-3xl font-bold text-foreground">{{ pageTitle }}</h1>
        <p class="text-muted-foreground mt-1">{{ pageSubtitle }}</p>
      </div>
    </div>

    <!-- Dual-lens tabs (admins only) -->
    <div v-if="isAdmin" class="flex gap-1 border-b border-border">
      <button
        @click="lens = 'org'"
        :class="['px-4 py-2 text-sm font-medium border-b-2 transition-colors', lens === 'org' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground']"
        data-testid="tab-attendance-org"
      >
        Toàn công ty
      </button>
      <button
        @click="lens = 'mine'"
        :class="['px-4 py-2 text-sm font-medium border-b-2 transition-colors', lens === 'mine' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground']"
        data-testid="tab-attendance-mine"
      >
        Của tôi
      </button>
    </div>

    <BaseCard v-if="orgLens && syncAccessAvailable" class="border border-sky-500/20 bg-gradient-to-r from-sky-500/10 via-background to-background">
      <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
        <div class="flex items-start gap-3">
          <div class="w-11 h-11 rounded-xl bg-sky-500/15 text-sky-600 dark:text-sky-400 flex items-center justify-center shrink-0">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
            </svg>
          </div>
          <div>
            <div class="flex flex-wrap items-center gap-2">
              <h2 class="font-semibold text-foreground">Đồng bộ máy chấm công</h2>
              <span class="px-2 py-0.5 rounded-full text-[11px] font-medium bg-sky-500/15 text-sky-700 dark:text-sky-300">
                Tự động sau {{ deviceSync.upload_delay_minutes || 15 }} phút
              </span>
            </div>
            <p class="text-sm text-muted-foreground mt-1">{{ deviceSyncMessage }}</p>
            <div v-if="deviceSync.devices?.length" class="flex flex-wrap gap-x-4 gap-y-1 mt-2 text-xs text-muted-foreground">
              <span>{{ deviceSync.devices.length }} máy đang bật</span>
              <span :class="onlineDeviceCount ? 'text-green-600 dark:text-green-400' : 'text-amber-600 dark:text-amber-400'">
                {{ onlineDeviceCount }}/{{ deviceSync.devices.length }} bridge online
              </span>
            </div>
          </div>
        </div>
        <BaseButton @click="syncAttendanceDevices" :disabled="syncingDevices || !onlineDeviceCount" class="shrink-0">
          {{ syncingDevices ? 'Đang đồng bộ…' : 'Đồng bộ ngay' }}
        </BaseButton>
      </div>
    </BaseCard>

    <template v-if="orgLens">
      <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
        <BaseCard>
          <div class="text-center">
            <p class="text-sm text-muted-foreground">Có mặt <span class="text-[11px]">({{ scopeLabel }})</span></p>
            <p class="text-3xl font-bold text-green-600 dark:text-green-400 mt-2">{{ recordsSummary.present }}</p>
            <p class="text-xs text-muted-foreground mt-1">{{ recordsSummary.total }} bản ghi</p>
          </div>
        </BaseCard>
        <BaseCard>
          <div class="text-center">
            <p class="text-sm text-muted-foreground">Vắng mặt <span class="text-[11px]">({{ scopeLabel }})</span></p>
            <p class="text-3xl font-bold text-red-600 dark:text-red-400 mt-2">{{ recordsSummary.absent }}</p>
          </div>
        </BaseCard>
        <BaseCard>
          <div class="text-center">
            <p class="text-sm text-muted-foreground">Đi muộn <span class="text-[11px]">({{ scopeLabel }})</span></p>
            <p class="text-3xl font-bold text-amber-600 dark:text-amber-400 mt-2">{{ recordsSummary.late }}</p>
          </div>
        </BaseCard>
        <BaseCard>
          <div class="text-center">
            <p class="text-sm text-muted-foreground">Về sớm <span class="text-[11px]">({{ scopeLabel }})</span></p>
            <p class="text-3xl font-bold text-orange-600 dark:text-orange-400 mt-2">{{ recordsSummary.early }}</p>
          </div>
        </BaseCard>
      </div>
    </template>
    
    <BaseCard v-if="!orgLens" class="border-2 border-primary/20 bg-gradient-to-r from-primary/5 to-transparent">
      <div class="flex flex-col md:flex-row items-center justify-between gap-6">
        <div class="flex items-center gap-4">
          <div class="w-16 h-16 rounded-full bg-primary/10 flex items-center justify-center">
            <svg class="w-8 h-8 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </div>
          <div>
            <h2 class="text-xl font-bold text-foreground">Đồng hồ chấm công</h2>
            <p class="text-2xl font-mono text-primary">{{ currentTime }}</p>
            <p class="text-sm text-muted-foreground">{{ currentDate }}</p>
          </div>
        </div>

        <div class="flex flex-col items-center gap-3">
          <!-- State: Not yet checked in -->
          <template v-if="todayClockState === 'none'">
            <!-- Chế độ làm việc -->
            <div class="flex items-center gap-1.5 bg-muted p-1 rounded-lg">
              <button v-for="m in workModes" :key="m.value"
                @click="workMode = m.value"
                :class="['px-3 py-1.5 text-xs font-semibold rounded-md transition-all', workMode === m.value ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground']"
              >{{ m.label }}</button>
            </div>
            <input v-if="workMode === 'on_site'" v-model="siteName" type="text" placeholder="Tên công trình/địa điểm"
              class="px-3 py-2 text-sm rounded-lg border border-input bg-background text-foreground w-64" />
            <BaseButton
              @click="handleSelfCheckIn"
              :disabled="clockProcessing"
              class="px-8 py-4 text-lg bg-green-600 hover:bg-green-700 text-white"
            >
              <svg class="w-6 h-6 mr-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1" />
              </svg>
              {{ clockProcessing ? 'Đang xử lý...' : 'CHECK IN' }}
            </BaseButton>
            <p class="text-xs text-muted-foreground">Bạn chưa chấm công hôm nay</p>
          </template>

          <!-- State: Checked in, not yet checked out -->
          <template v-else-if="todayClockState === 'checked-in'">
            <p class="text-sm text-success font-medium">✓ Đã vào: {{ formatTime(todayRecord?.check_in_time) }}</p>
            <BaseButton
              @click="handleSelfCheckOut"
              :disabled="clockProcessing"
              class="px-8 py-4 text-lg bg-orange-500 hover:bg-orange-600 text-white"
            >
              <svg class="w-6 h-6 mr-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
              </svg>
              {{ clockProcessing ? 'Đang xử lý...' : 'CHECK OUT' }}
            </BaseButton>
          </template>

          <!-- State: Fully done -->
          <template v-else>
            <div class="text-center">
              <div class="w-14 h-14 rounded-full bg-success/20 flex items-center justify-center mx-auto mb-2">
                <svg class="w-7 h-7 text-success" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
              </div>
              <p class="text-sm font-semibold text-success">Đã hoàn thành hôm nay</p>
              <p class="text-xs text-muted-foreground mt-1">
                {{ formatTime(todayRecord?.check_in_time) }} → {{ formatTime(todayRecord?.check_out_time) }}
              </p>
            </div>
          </template>
        </div>
      </div>
    </BaseCard>
    
    <template v-if="orgLens">
      <BaseCard>
        <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
          <BaseInput
            v-model="filters.startDate"
            type="date"
            label="Từ ngày"
          />
          <BaseInput
            v-model="filters.endDate"
            type="date"
            label="Đến ngày"
          />
          <BaseSelect
            v-model="filters.employee_id"
            label="Nhân viên"
            :options="employeeOptions"
            placeholder="Tất cả"
          />
          <BaseSelect
            v-model="filters.status"
            label="Trạng thái"
            :options="statusOptions"
          />
          <div class="flex items-end">
            <BaseButton
              variant="outline"
              @click="applyFilters"
            >
              Áp dụng
            </BaseButton>
          </div>
        </div>
      </BaseCard>
    </template>
    
    <BaseCard :title="orgLens ? 'Bảng chấm công' : 'Lịch sử chấm công của tôi'">
      <template #header-actions>
        <!-- View mode toggles for the personal lens -->
        <div v-if="!orgLens" class="flex items-center gap-1.5 bg-muted p-1 rounded-lg">
          <button
            @click="viewMode = 'calendar'"
            :class="viewMode === 'calendar' ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:bg-background/50'"
            class="px-3 py-1.5 text-xs font-semibold rounded-md transition-all"
          >
            Lịch tháng
          </button>
          <button
            @click="viewMode = 'list'"
            :class="viewMode === 'list' ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:bg-background/50'"
            class="px-3 py-1.5 text-xs font-semibold rounded-md transition-all"
          >
            Danh sách
          </button>
        </div>

        <div v-else class="flex flex-wrap items-center justify-end gap-2">
          <!-- Xác minh vị trí/thiết bị và review khấu trừ là hai nghiệp vụ độc lập. -->
          <button
            @click="needsReviewOnly = !needsReviewOnly"
            :class="['px-3 py-1.5 text-xs font-semibold rounded-lg border transition-colors flex items-center gap-1.5',
                     needsReviewOnly ? 'border-amber-500 bg-amber-500/10 text-amber-700 dark:text-amber-400' : 'border-border text-muted-foreground hover:bg-muted']"
            title="Chỉ hiện các lượt chấm công ngoài phạm vi cần duyệt"
          >
            <span>⚠ Cần xem xét</span>
            <span v-if="needsReviewCount" class="px-1.5 rounded-full bg-amber-500 text-white text-[10px]">{{ needsReviewCount }}</span>
          </button>
          <button
            v-if="canReviewPayroll"
            @click="payrollReviewOnly = !payrollReviewOnly"
            :class="['px-3 py-1.5 text-xs font-semibold rounded-lg border transition-colors flex items-center gap-1.5',
                     payrollReviewOnly ? 'border-red-500 bg-red-500/10 text-red-700 dark:text-red-400' : 'border-border text-muted-foreground hover:bg-muted']"
            title="Chỉ hiện ngày đi trễ/về sớm đang chờ HR quyết định"
            data-testid="filter-attendance-payroll-review"
          >
            <span>Khấu trừ chờ HR duyệt</span>
            <span v-if="payrollReviewCount" class="px-1.5 rounded-full bg-red-500 text-white text-[10px]">{{ payrollReviewCount }}</span>
          </button>
        </div>
      </template>

      <!-- Loading State -->
      <div v-if="loading" class="text-center py-8">
        <p class="text-muted-foreground">Đang tải dữ liệu...</p>
      </div>

      <!-- Calendar View -->
      <div v-else-if="!orgLens && viewMode === 'calendar'" class="space-y-4">
        <!-- Month Navigator -->
        <div class="flex items-center justify-between border-b border-border pb-4">
          <h3 class="text-base sm:text-lg font-bold text-foreground">{{ monthNames[currentMonth] }} / {{ currentYear }}</h3>
          <div class="flex gap-2">
            <button @click="prevMonth" class="px-2.5 py-1 text-sm rounded-lg hover:bg-muted border border-border transition-colors">◀</button>
            <button @click="currentMonth = new Date().getMonth(); currentYear = new Date().getFullYear();" class="px-3 py-1 text-xs rounded-lg hover:bg-muted border border-border transition-colors">Tháng này</button>
            <button @click="nextMonth" class="px-2.5 py-1 text-sm rounded-lg hover:bg-muted border border-border transition-colors">▶</button>
          </div>
        </div>

        <!-- Week days grid -->
        <div class="grid grid-cols-7 gap-2 text-center text-xs font-bold text-muted-foreground uppercase py-2 border-b border-border">
          <div>T2</div>
          <div>T3</div>
          <div>T4</div>
          <div>T5</div>
          <div>T6</div>
          <div class="text-amber-500">T7</div>
          <div class="text-red-500">CN</div>
        </div>

        <!-- Days grid -->
        <div class="grid grid-cols-7 gap-2 sm:gap-3">
          <div 
            v-for="(dayObj, idx) in calendarDays" 
            :key="idx"
            :class="[
              'min-h-[70px] sm:min-h-[85px] p-2 rounded-xl border border-border transition-all flex flex-col justify-between relative group',
              dayObj.isCurrentMonth ? 'bg-card hover:border-primary hover:shadow-sm' : 'bg-muted/10 opacity-30 select-none pointer-events-none'
            ]"
          >
            <!-- Day number -->
            <span class="text-xs sm:text-sm font-semibold text-muted-foreground group-hover:text-primary">{{ dayObj.day }}</span>

            <!-- Day status -->
            <div v-if="dayObj.isCurrentMonth && dayObj.record" class="mt-1 flex flex-col items-center">
              <span 
                :class="[
                  'w-2 h-2 sm:w-2.5 sm:h-2.5 rounded-full',
                  dayObj.record.status === 'present' ? 'bg-green-500' :
                  dayObj.record.status === 'late' ? 'bg-amber-500' :
                  dayObj.record.status === 'absent' ? 'bg-red-500' : 'bg-blue-500'
                ]"
                class="block"
              ></span>
              <span class="text-[9px] sm:text-[10px] font-mono mt-1 text-muted-foreground">
                {{ formatTime(dayObj.record.check_in_time) }}
              </span>

              <!-- Custom CSS Tooltip on Hover -->
              <div class="absolute bottom-full mb-2 left-1/2 transform -translate-x-1/2 bg-popover text-popover-foreground border border-border rounded-xl shadow-xl p-3 z-50 w-48 sm:w-56 pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity text-xs space-y-1">
                <p class="font-bold text-sm border-b pb-1 text-primary">{{ formatDate(dayObj.record.record_date) }}</p>
                <p class="flex justify-between"><span>Trạng thái:</span> <span class="font-medium text-foreground">{{ getStatusText(dayObj.record.status) }}</span></p>
                <p class="flex justify-between"><span>Giờ vào:</span> <span class="font-medium text-foreground">{{ formatTime(dayObj.record.check_in_time) }}</span></p>
                <p class="flex justify-between"><span>Giờ ra:</span> <span class="font-medium text-foreground">{{ formatTime(dayObj.record.check_out_time) }}</span></p>
                <p class="flex justify-between"><span>Ca:</span> <span class="font-medium text-foreground">{{ shiftLabel(dayObj.record) }}</span></p>
                <p class="flex justify-between"><span>Công hợp lệ:</span> <span class="font-medium text-foreground">{{ formatMinutes(dayObj.record.regular_worked_minutes) }}</span></p>
                <p v-if="dayObj.record.early_arrival_minutes" class="flex justify-between text-sky-600"><span>Đến sớm:</span> <span class="font-medium">{{ dayObj.record.early_arrival_minutes }} phút</span></p>
                <p v-if="dayObj.record.late_minutes" class="flex justify-between text-amber-600"><span>Đi muộn:</span> <span class="font-medium">{{ dayObj.record.late_minutes }} phút</span></p>
                <p v-if="dayObj.record.early_leave_minutes" class="flex justify-between text-orange-600"><span>Về sớm:</span> <span class="font-medium">{{ dayObj.record.early_leave_minutes }} phút</span></p>
                <p v-if="dayObj.record.after_shift_minutes" class="flex justify-between text-sky-600"><span>Ở lại sau ca:</span> <span class="font-medium">{{ dayObj.record.after_shift_minutes }} phút</span></p>
                <p v-if="dayObj.record.notes" class="text-muted-foreground italic border-t pt-1 mt-1">{{ dayObj.record.notes }}</p>
              </div>
            </div>
            <div v-else-if="dayObj.isCurrentMonth" class="text-center py-1 sm:py-2">
              <span class="text-[9px] sm:text-[10px] text-muted-foreground/40">-</span>
            </div>
          </div>
        </div>
      </div>

      <!-- List View -->
      <div v-else>
        <div v-if="displayRecords.length === 0" class="text-center py-8 text-muted-foreground">
          Chưa có dữ liệu chấm công hôm nay hoặc khoảng ngày này
        </div>
        <BaseTable
          v-else
          :columns="displayColumns"
          :data="displayRecords"
        >
          <template v-if="orgLens" #cell-employee="{ item }">
            <div class="flex items-center gap-2">
              <div class="w-8 h-8 rounded-full bg-primary text-primary-foreground flex items-center justify-center text-sm font-semibold">
                {{ getInitials(item.full_name || item.employee_name) }}
              </div>
              <div>
                <span class="font-medium">{{ item.full_name || item.employee_name || item.employee_code || `NV#${item.employee_id}` }}</span>
                <p class="text-xs text-muted-foreground">{{ item.employee_code || '' }}</p>
              </div>
            </div>
          </template>
          
          <template #cell-attendance_date="{ value }">
            <span class="text-sm">{{ formatDate(value) }}</span>
          </template>
          
          <template #cell-check_in_time="{ value }">
            <span class="text-sm font-medium">{{ formatTime(value) }}</span>
          </template>
          
          <template #cell-check_out_time="{ value }">
            <span class="text-sm font-medium">{{ formatTime(value) }}</span>
          </template>
          
          <template #cell-total_work_hours="{ value }">
            <span class="text-sm font-medium">{{ value || 0 }}h</span>
          </template>

          <template #cell-assigned_shift="{ item }">
            <div class="text-xs">
              <p class="font-semibold">{{ shiftLabel(item) }}</p>
              <p class="text-muted-foreground">{{ shiftWindow(item) }}</p>
            </div>
          </template>

          <template #cell-valid_work="{ item }">
            <span class="text-sm font-semibold">{{ formatMinutes(item.regular_worked_minutes) }}</span>
          </template>

          <template #cell-early_arrival_minutes="{ value }">
            <span :class="timingMetricClass(value, 'info')">{{ formatMinutes(value) }}</span>
          </template>

          <template #cell-late_minutes="{ value }">
            <span :class="timingMetricClass(value, 'warning')">{{ formatMinutes(value) }}</span>
          </template>

          <template #cell-early_leave_minutes="{ value }">
            <span :class="timingMetricClass(value, 'danger')">{{ formatMinutes(value) }}</span>
          </template>

          <template #cell-after_shift_minutes="{ value }">
            <span :class="timingMetricClass(value, 'info')">{{ formatMinutes(value) }}</span>
          </template>
          
          <template #cell-status="{ value }">
            <BaseBadge :variant="getStatusVariant(value)">
              {{ getStatusText(value) }}
            </BaseBadge>
          </template>

          <template v-if="orgLens" #cell-verify="{ item }">
            <span
              :class="['inline-flex items-center px-2 py-0.5 rounded text-xs font-medium', verifyInfo(item).cls]"
              :title="verifyInfo(item).title"
            >{{ verifyInfo(item).label }}</span>
          </template>

          <template v-if="orgLens" #cell-payroll_review="{ item }">
            <button
              v-if="isUnresolvedPayrollReview(item) && canReviewPayroll"
              type="button"
              :class="payrollReviewBadge(item)"
              @click="openPayrollReview(item)"
              data-testid="button-attendance-payroll-review"
            >
              {{ payrollReviewLabel(item) }}
            </button>
            <span v-else :class="payrollReviewBadge(item)">{{ payrollReviewLabel(item) }}</span>
          </template>

          <template v-if="orgLens" #actions="{ item }">
            <div class="flex items-center gap-2">
              <template v-if="item.review_status === 'needs_review'">
                <button
                  @click="verifyAttendance(item, 'approve')"
                  :disabled="processingVerify"
                  class="p-1.5 rounded hover:bg-green-100 dark:hover:bg-green-900 text-green-600 dark:text-green-400"
                  title="Xác nhận hợp lệ"
                >
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                  </svg>
                </button>
                <button
                  @click="verifyAttendance(item, 'reject')"
                  :disabled="processingVerify"
                  class="p-1.5 rounded hover:bg-red-100 dark:hover:bg-red-900 text-red-600 dark:text-red-400"
                  title="Từ chối (tính vắng)"
                >
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                  </svg>
                </button>
              </template>
              <button
                @click="openLogModal(item)"
                class="p-1.5 rounded hover:bg-muted text-muted-foreground"
                title="Xem chi tiết / log"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                </svg>
              </button>
              <button
                @click="openEditModal(item)"
                class="p-1.5 rounded hover:bg-muted"
                title="Chỉnh sửa"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                </svg>
              </button>
            </div>
          </template>
        </BaseTable>
      </div>
    </BaseCard>

    <BaseModal
      v-model="showEditModal"
      title="Chỉnh sửa chấm công"
    >
      <div v-if="editingRecord" class="space-y-4">
        <div class="grid grid-cols-2 gap-4">
          <div>
            <p class="text-sm text-muted-foreground">Nhân viên</p>
            <p class="font-medium">{{ editingRecord.full_name || editingRecord.employee_code || `NV#${editingRecord.employee_id}` }} <span v-if="editingRecord.employee_code" class="text-xs text-muted-foreground">({{ editingRecord.employee_code }})</span></p>
          </div>
          <div>
            <p class="text-sm text-muted-foreground">Ngày</p>
            <p class="font-medium">{{ formatDate(editingRecord.record_date) }}</p>
          </div>
        </div>
        <div class="grid grid-cols-2 gap-4">
          <BaseInput v-model="editForm.check_in_time" label="Giờ vào buổi 1" type="time" />
          <BaseInput v-model="editForm.check_out_time" label="Giờ ra buổi 1" type="time" />
          <BaseInput v-model="editForm.check_in_time_2" label="Giờ vào buổi 2" type="time" />
          <BaseInput v-model="editForm.check_out_time_2" label="Giờ ra buổi 2" type="time" />
        </div>
        <p class="text-xs text-muted-foreground">Công hợp lệ, đi trễ, về sớm và thời gian ngoài ca sẽ được hệ thống tính lại từ các lượt chấm và ca được gán.</p>
        <BaseInput
          v-model="editForm.notes"
          label="Ghi chú"
        />
      </div>

      <template #footer>
        <BaseButton variant="outline" @click="showEditModal = false">Hủy</BaseButton>
        <BaseButton @click="handleUpdate">
          Cập nhật
        </BaseButton>
      </template>
    </BaseModal>

    <!-- Chi tiết / log chấm công 1 ngày -->
    <BaseModal v-model="showLogModal" title="Chi tiết chấm công">
      <div v-if="logRecord" class="space-y-4 text-sm">
        <div class="grid grid-cols-2 gap-4">
          <div>
            <p class="text-muted-foreground">Nhân viên</p>
            <p class="font-medium">{{ logRecord.full_name || logRecord.employee_name || logRecord.employee_code || `NV#${logRecord.employee_id}` }} <span v-if="logRecord.employee_code" class="text-xs text-muted-foreground">({{ logRecord.employee_code }})</span></p>
          </div>
          <div>
            <p class="text-muted-foreground">Ngày</p>
            <p class="font-medium">{{ formatDate(logRecord.attendance_date || logRecord.record_date) }}</p>
          </div>
          <div>
            <p class="text-muted-foreground">Trạng thái</p>
            <BaseBadge :variant="getStatusVariant(logRecord.status)">{{ getStatusText(logRecord.status) }}</BaseBadge>
          </div>
          <div>
            <p class="text-muted-foreground">Công hợp lệ</p>
            <p class="font-medium">{{ formatMinutes(logRecord.regular_worked_minutes) }}</p>
          </div>
        </div>

        <div class="rounded-xl border border-border bg-muted/20 p-3">
          <div class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-3">
            <div><p class="text-muted-foreground">Ca được gán</p><p class="font-medium">{{ shiftLabel(logRecord) }}</p></div>
            <div><p class="text-muted-foreground">Khung ca</p><p class="font-medium">{{ shiftWindow(logRecord) }}</p></div>
            <div><p class="text-muted-foreground">Có mặt thực tế</p><p class="font-medium">{{ formatMinutes(logRecord.raw_presence_minutes) }}</p></div>
            <div><p class="text-muted-foreground">Đến sớm</p><p class="font-medium text-sky-600">{{ formatMinutes(logRecord.early_arrival_minutes) }}</p></div>
            <div><p class="text-muted-foreground">Đi trễ</p><p class="font-medium text-amber-600">{{ formatMinutes(logRecord.late_minutes) }}</p></div>
            <div><p class="text-muted-foreground">Về sớm</p><p class="font-medium text-orange-600">{{ formatMinutes(logRecord.early_leave_minutes) }}</p></div>
            <div><p class="text-muted-foreground">Ở lại sau ca</p><p class="font-medium text-sky-600">{{ formatMinutes(logRecord.after_shift_minutes) }}</p></div>
            <div><p class="text-muted-foreground">Review khấu trừ</p><p><span :class="payrollReviewBadge(logRecord)">{{ payrollReviewLabel(logRecord) }}</span></p></div>
          </div>
          <p class="mt-3 text-xs text-muted-foreground">Đến sớm và ở lại sau ca chỉ là cảnh báo; không cộng vào công thường hoặc OT khi chưa có đơn/ticket được duyệt.</p>
        </div>

        <!-- Lượt chấm (hỗ trợ 2 buổi/ngày) -->
        <div class="border-t border-border pt-3">
          <p class="font-semibold text-foreground mb-2">Lượt chấm công</p>
          <div class="grid grid-cols-2 gap-3">
            <div class="rounded-lg border border-border p-2">
              <p class="text-xs text-muted-foreground">Buổi 1</p>
              <p>Vào: <span class="font-medium">{{ formatTime(logRecord.check_in_time) }}</span> · Ra: <span class="font-medium">{{ formatTime(logRecord.check_out_time) }}</span></p>
            </div>
            <div class="rounded-lg border border-border p-2" v-if="logRecord.check_in_time_2 || logRecord.check_out_time_2">
              <p class="text-xs text-muted-foreground">Buổi 2</p>
              <p>Vào: <span class="font-medium">{{ formatTime(logRecord.check_in_time_2) }}</span> · Ra: <span class="font-medium">{{ formatTime(logRecord.check_out_time_2) }}</span></p>
            </div>
          </div>
          <div class="flex gap-4 mt-2 text-xs text-muted-foreground">
            <span v-if="logRecord.late_minutes">Đi muộn: <span class="text-amber-600 dark:text-amber-400 font-medium">{{ logRecord.late_minutes }} phút</span></span>
            <span v-if="logRecord.early_leave_minutes">Về sớm: <span class="text-orange-600 dark:text-orange-400 font-medium">{{ logRecord.early_leave_minutes }} phút</span></span>
          </div>
        </div>

        <!-- Xác minh vị trí / thiết bị (chống gian lận) -->
        <div class="border-t border-border pt-3">
          <p class="font-semibold text-foreground mb-2">Xác minh (chống gian lận)</p>
          <div v-if="logRecord.verification" class="space-y-1">
            <p>Trạng thái:
              <span :class="['px-2 py-0.5 rounded text-xs font-medium', verifyInfo(logRecord).cls]">{{ verifyInfo(logRecord).label }}</span>
            </p>
            <p v-if="logRecord.verification.ip">IP: <span class="font-medium">{{ logRecord.verification.ip }}</span></p>
            <p v-if="logRecord.verification.lat != null">
              Vị trí: <span class="font-medium">{{ logRecord.verification.lat }}, {{ logRecord.verification.lng }}</span>
              <a :href="`https://www.google.com/maps?q=${logRecord.verification.lat},${logRecord.verification.lng}`" target="_blank" class="text-primary ml-1">(bản đồ)</a>
              <span v-if="logRecord.verification.distance_m != null"> · cách VP {{ logRecord.verification.distance_m }}m</span>
            </p>
            <p v-if="logRecord.verification.device">Thiết bị: <span class="text-xs">{{ logRecord.verification.device }}</span></p>
            <p v-if="logRecord.verification.source">Nguồn: {{ logRecord.verification.source }}</p>
            <p v-if="logRecord.verification.flags && logRecord.verification.flags.length">
              Cờ: <span v-for="f in logRecord.verification.flags" :key="f" class="px-1.5 py-0.5 rounded bg-amber-500/15 text-amber-700 dark:text-amber-400 text-xs mr-1">{{ verifyFlagLabel(f) }}</span>
            </p>
          </div>
          <p v-else class="text-muted-foreground text-xs">Không có dữ liệu vị trí (chấm công không gửi vị trí, hoặc bản ghi nhập tay/điều chỉnh).</p>
        </div>

        <!-- Lý do (từ đơn điều chỉnh công / ghi chú) -->
        <div class="border-t border-border pt-3" v-if="logRecord.reason || logRecord.notes">
          <p class="font-semibold text-foreground mb-1">Lý do / ghi chú</p>
          <p class="text-muted-foreground">{{ logRecord.reason || logRecord.notes }}</p>
        </div>
        <div v-if="logRecord.status === 'absent'" class="border-t border-border pt-3">
          <p class="text-xs text-muted-foreground mb-2">
            Ngày vắng: lý do nghỉ (nếu có) đến từ <span class="font-medium">đơn nghỉ phép đã duyệt</span> (hiển thị ở Bảng công tháng), hoặc gửi <span class="font-medium">đơn điều chỉnh công</span> để bổ sung giờ (cần quản lý duyệt).
          </p>
          <BaseButton variant="outline" @click="createAdjustment(logRecord)">+ Tạo đơn điều chỉnh công</BaseButton>
        </div>
      </div>
      <template #footer>
        <BaseButton variant="outline" @click="showLogModal = false">Đóng</BaseButton>
      </template>
    </BaseModal>

    <BaseModal v-model="showPayrollReviewModal" title="Duyệt khấu trừ đi trễ / về sớm">
      <div v-if="payrollReviewRecord" class="space-y-4">
        <div class="rounded-xl border border-border bg-muted/30 p-4 text-sm">
          <p class="font-semibold">{{ payrollReviewRecord.full_name || payrollReviewRecord.employee_name || payrollReviewRecord.employee_code }}</p>
          <p class="mt-1 text-muted-foreground">{{ formatDate(payrollReviewRecord.attendance_date) }} · {{ shiftLabel(payrollReviewRecord) }}</p>
          <div class="mt-3 grid grid-cols-2 gap-3">
            <p>Đi trễ: <strong class="text-amber-600">{{ payrollReviewRecord.late_minutes || 0 }} phút</strong></p>
            <p>Về sớm: <strong class="text-orange-600">{{ payrollReviewRecord.early_leave_minutes || 0 }} phút</strong></p>
          </div>
          <p class="mt-2 text-xs text-muted-foreground">HR chỉ quyết định tỷ lệ; màn hình này không hiển thị lương hoặc số tiền khấu trừ.</p>
        </div>
        <BaseSelect
          v-model="payrollDecision.percent"
          label="Mức xử lý"
          :options="payrollPercentOptions"
          required
        />
        <div>
          <label class="mb-2 block text-sm font-medium">Lý do / ghi chú</label>
          <textarea
            v-model="payrollDecision.note"
            rows="4"
            class="w-full rounded-lg border border-input bg-background px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-ring"
            :placeholder="payrollDecisionNeedsNote ? 'Bắt buộc khi miễn hoặc đổi mức mặc định' : 'Ghi chú quyết định (không bắt buộc)'"
          ></textarea>
          <p v-if="payrollDecisionNeedsNote" class="mt-1 text-xs text-amber-600">Bắt buộc ghi lý do khi chọn 0% hoặc khác mức mặc định {{ payrollDefaultPercent }}%.</p>
        </div>
      </div>
      <template #footer>
        <BaseButton variant="outline" :disabled="savingPayrollDecision" @click="showPayrollReviewModal = false">Hủy</BaseButton>
        <BaseButton :disabled="savingPayrollDecision" @click="submitPayrollDecision">
          {{ savingPayrollDecision ? 'Đang lưu...' : 'Lưu quyết định' }}
        </BaseButton>
      </template>
    </BaseModal>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { useRouter } from 'vue-router';
import BaseCard from '../components/BaseCard.vue';
import BaseButton from '../components/BaseButton.vue';
import BaseInput from '../components/BaseInput.vue';
import BaseSelect from '../components/BaseSelect.vue';
import BaseBadge from '../components/BaseBadge.vue';
import BaseTable from '../components/BaseTable.vue';
import BaseModal from '../components/BaseModal.vue';
import { attendanceService } from '../services/attendanceService';
import { employeeService } from '../services/employeeService';
import { authService } from '../services/authService';
import { useToast } from '../composables/useToast';

const toast = useToast();

const isAdmin = computed(() => authService.isAdmin());
const access = computed(() => authService.getAccess());
const roleCodes = computed(() => access.value.roles.map((role) => String(role.role_code || '').toUpperCase()));
const canReviewPayroll = computed(() => access.value.full || roleCodes.value.some((role) => ['ADMIN', 'TENANT_ADMIN', 'HR'].includes(role)));

// Dual-lens: admins toggle company-wide vs their own ("My Space").
const lens = ref('org'); // 'org' = toàn công ty | 'mine' = của tôi
const myEmployeeId = computed(() => {
  const u = JSON.parse(localStorage.getItem('user') || '{}');
  return u.employee_id || u.id || null;
});
const orgLens = computed(() => isAdmin.value && lens.value === 'org');
const pageTitle = computed(() => orgLens.value ? 'Quản lý Chấm công' : 'Chấm công của tôi');
const pageSubtitle = computed(() => orgLens.value ? 'Theo dõi và báo cáo chấm công nhân viên' : 'Xem và chấm công cá nhân');

const loading = ref(true);
const clockProcessing = ref(false);
const processingVerify = ref(false);
const workMode = ref('office');
const siteName = ref('');
const workModes = [
  { value: 'office', label: '🏢 Văn phòng' },
  { value: 'wfh', label: '🏠 WFH' },
  { value: 'on_site', label: '🚧 Công trình' },
];
const needsReviewOnly = ref(false);
const payrollReviewOnly = ref(false);
const showPayrollReviewModal = ref(false);
const payrollReviewRecord = ref(null);
const savingPayrollDecision = ref(false);
const payrollDecision = ref({ percent: '0', note: '' });
const payrollPercentOptions = [0, 25, 50, 75, 100].map((value) => ({
  value: String(value),
  label: value === 0 ? '0% - miễn khấu trừ' : `${value}% lương cơ bản một ngày`,
}));
const syncAccessAvailable = ref(false);
const syncingDevices = ref(false);
const deviceSync = ref({ upload_delay_minutes: 15, devices: [] });
let syncPollTimer = null;
let syncPollAttempts = 0;

const onlineDeviceCount = computed(() => (deviceSync.value.devices || []).filter((device) => device.online).length);
const currentSyncDevices = computed(() => {
  const requestId = deviceSync.value.latest_request_id;
  if (!requestId) return [];
  return (deviceSync.value.devices || []).filter((device) => {
    if (device.sync_request?.id !== requestId) return false;
    return device.online || ['SUCCESS', 'FAILED'].includes(device.sync_request?.status);
  });
});
const syncInProgress = computed(() => currentSyncDevices.value.some((device) =>
  ['PENDING', 'RUNNING'].includes(device.sync_request?.status)
));
const deviceSyncMessage = computed(() => {
  const devices = deviceSync.value.devices || [];
  if (!devices.length) return 'Chưa có máy chấm công đang hoạt động.';

  const running = currentSyncDevices.value.filter((device) => device.sync_request?.status === 'RUNNING').length;
  const pending = currentSyncDevices.value.filter((device) => device.sync_request?.status === 'PENDING').length;
  if (running) return `Bridge đang đọc dữ liệu từ ${running} máy.`;
  if (pending) return `Đã gửi lệnh, đang chờ ${pending} bridge nhận yêu cầu.`;

  const latest = devices
    .map((device) => device.last_sync)
    .filter((sync) => sync?.completed_at)
    .sort((a, b) => new Date(b.completed_at) - new Date(a.completed_at))[0];
  if (latest?.status === 'FAILED') return `Lần đồng bộ gần nhất thất bại: ${latest.error || 'không đọc được dữ liệu máy'}`;
  if (latest) return `Đồng bộ gần nhất ${formatDateTime(latest.completed_at)}, nhận ${latest.processed || 0} lượt chấm công mới.`;

  return 'Dữ liệu mới sẽ tự tải sau thời gian chờ; HR có thể lấy ngay khi cần.';
});

const showEditModal = ref(false);
const editingRecord = ref(null);
const router = useRouter();
const showLogModal = ref(false);
const logRecord = ref(null);
const openLogModal = (record) => { logRecord.value = record; showLogModal.value = true; };
const createAdjustment = (record) => {
  router.push({
    path: '/attendance-adjustments',
    query: {
      employee_id: record.employee_id,
      work_date: record.attendance_date || record.record_date || record.work_date,
    },
  });
};
const verifyFlagLabel = (f) => ({
  ip_outside_office: 'IP ngoài văn phòng',
  outside_geofence: 'Ngoài bán kính cho phép',
  no_location: 'Không có vị trí',
  wfh_bypass: 'Được phép WFH',
  wfh: 'Làm từ xa (WFH)',
  on_site: 'Đi công trình',
}[f] || f);

const records = ref([]);
const employees = ref([]);

const currentTime = ref('');
const currentDate = ref('');
let clockInterval = null;

const viewMode = ref('calendar');
const currentYear = ref(new Date().getFullYear());
const currentMonth = ref(new Date().getMonth());

const monthNames = [
  'Tháng 1', 'Tháng 2', 'Tháng 3', 'Tháng 4', 'Tháng 5', 'Tháng 6',
  'Tháng 7', 'Tháng 8', 'Tháng 9', 'Tháng 10', 'Tháng 11', 'Tháng 12'
];

const nextMonth = () => {
  if (currentMonth.value === 11) {
    currentMonth.value = 0;
    currentYear.value++;
  } else {
    currentMonth.value++;
  }
};

const prevMonth = () => {
  if (currentMonth.value === 0) {
    currentMonth.value = 11;
    currentYear.value--;
  } else {
    currentMonth.value--;
  }
};

const calendarDays = computed(() => {
  const year = currentYear.value;
  const month = currentMonth.value;
  
  const firstDayIndex = new Date(year, month, 1).getDay();
  const startOffset = firstDayIndex === 0 ? 6 : firstDayIndex - 1;
  const totalDays = new Date(year, month + 1, 0).getDate();
  const prevMonthTotalDays = new Date(year, month, 0).getDate();
  
  const days = [];
  
  for (let i = startOffset - 1; i >= 0; i--) {
    days.push({
      day: prevMonthTotalDays - i,
      isCurrentMonth: false,
      dateStr: '',
      record: null
    });
  }
  
  for (let i = 1; i <= totalDays; i++) {
    const monthStr = String(month + 1).padStart(2, '0');
    const dayStr = String(i).padStart(2, '0');
    const dateStr = `${year}-${monthStr}-${dayStr}`;
    
    const record = scopedRecords.value.find(r => {
      const rd = r.attendance_date || r.record_date || '';
      return String(rd).startsWith(dateStr);
    }) || null;
    
    days.push({
      day: i,
      isCurrentMonth: true,
      dateStr,
      record
    });
  }
  
  const remaining = 42 - days.length;
  for (let i = 1; i <= remaining; i++) {
    days.push({
      day: i,
      isCurrentMonth: false,
      dateStr: '',
      record: null
    });
  }
  
  return days;
});

const updateClock = () => {
  const now = new Date();
  currentTime.value = now.toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
  currentDate.value = now.toLocaleDateString('vi-VN', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
};

// Nhãn phạm vi đang thống kê — tránh hiểu nhầm số liệu cũ là của tháng này.
const scopeLabel = computed(() => {
  const f = filters.value.startDate, t = filters.value.endDate;
  if (f && t) {
    const fm = f.slice(0, 7), tm = t.slice(0, 7);
    if (fm === tm) {
      const [y, m] = fm.split('-');
      return `tháng ${Number(m)}/${y}`;
    }
    return `${f} → ${t}`;
  }
  if (f) return `từ ${f}`;
  if (t) return `đến ${t}`;
  return 'tất cả';
});

// Summary of records in the CURRENT filter scope (defaults to current month)
const recordsSummary = computed(() => ({
  total: records.value.length,
  present: records.value.filter(r => r.status === 'present').length,
  absent:  records.value.filter(r => r.status === 'absent').length,
  late:    records.value.filter(r => r.status === 'late').length,
  early:   records.value.filter(r => r.status === 'early').length,
  halfDay: records.value.filter(r => r.status === 'half_day').length,
}));

// Local YYYY-MM-DD (Vietnam time) — NOT UTC, so early-morning check-ins match.
const localToday = () => {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};

// Today summary (for employee clock widget)
const todaySummary = computed(() => {
  const todayStr = localToday();
  const todayRecords = records.value.filter(r => {
    const d = r.attendance_date || r.record_date || '';
    return String(d).startsWith(todayStr);
  });
  return {
    present: todayRecords.filter(r => r.status === 'present').length,
    absent:  todayRecords.filter(r => r.status === 'absent').length,
    late:    todayRecords.filter(r => r.status === 'late').length,
    halfDay: todayRecords.filter(r => r.status === 'half_day').length,
  };
});

// Today's clock state for employee
const todayRecord = computed(() => {
  const todayStr = localToday();
  const user = JSON.parse(localStorage.getItem('user') || '{}');
  const userEmpId = user.employee_id || user.id;

  return records.value.find(r => {
    const d = String(r.attendance_date || r.record_date || '');
    return d.startsWith(todayStr) && String(r.employee_id) === String(userEmpId);
  }) || null;
});

const todayClockState = computed(() => {
  const rec = todayRecord.value;
  if (!rec) return 'none';
  if (rec.check_in_time && !rec.check_out_time) return 'checked-in';
  if (rec.check_in_time && rec.check_out_time) return 'done';
  return 'none';
});

const filters = ref({
  startDate: '',
  endDate: '',
  employee_id: '',
  status: ''
});

const editForm = ref({
  check_in_time: '',
  check_out_time: '',
  check_in_time_2: '',
  check_out_time_2: '',
  notes: ''
});

const adminColumns = [
  { key: 'employee', label: 'Nhân viên' },
  { key: 'attendance_date', label: 'Ngày' },
  { key: 'assigned_shift', label: 'Ca được gán' },
  { key: 'check_in_time', label: 'Giờ vào' },
  { key: 'check_out_time', label: 'Giờ ra' },
  { key: 'valid_work', label: 'Công hợp lệ' },
  { key: 'early_arrival_minutes', label: 'Đến sớm' },
  { key: 'late_minutes', label: 'Đi trễ' },
  { key: 'early_leave_minutes', label: 'Về sớm' },
  { key: 'after_shift_minutes', label: 'Sau ca' },
  { key: 'status', label: 'Trạng thái' },
  { key: 'payroll_review', label: 'Khấu trừ' },
  { key: 'verify', label: 'Xác minh' },
];

const employeeColumns = [
  { key: 'attendance_date', label: 'Ngày' },
  { key: 'assigned_shift', label: 'Ca được gán' },
  { key: 'check_in_time', label: 'Giờ vào' },
  { key: 'check_out_time', label: 'Giờ ra' },
  { key: 'valid_work', label: 'Công hợp lệ' },
  { key: 'early_arrival_minutes', label: 'Đến sớm' },
  { key: 'late_minutes', label: 'Đi trễ' },
  { key: 'early_leave_minutes', label: 'Về sớm' },
  { key: 'after_shift_minutes', label: 'Sau ca' },
  { key: 'status', label: 'Trạng thái' },
];

const displayColumns = computed(() => orgLens.value ? adminColumns : employeeColumns);

// In the org lens, all records; otherwise scope to the current user's own
// records (also fixes employees seeing others' rows in the calendar/list).
const scopedRecords = computed(() => {
  if (orgLens.value) return records.value;
  const myId = myEmployeeId.value;
  if (!myId) return records.value;
  return records.value.filter(r => String(r.employee_id) === String(myId));
});

const displayRecords = computed(() => {
  let result = scopedRecords.value;
  if (orgLens.value && needsReviewOnly.value) {
    result = result.filter(r => r.review_status === 'needs_review');
  }
  if (orgLens.value && payrollReviewOnly.value) {
    result = result.filter(isUnresolvedPayrollReview);
  }
  return result;
});

// Số lượt chấm công cần admin xem xét (chống gian lận).
const needsReviewCount = computed(() =>
  records.value.filter(r => r.review_status === 'needs_review').length
);
const payrollReviewCount = computed(() => records.value.filter(isUnresolvedPayrollReview).length);

const statusOptions = [
  { label: 'Tất cả', value: '' },
  { label: 'Có mặt', value: 'present' },
  { label: 'Vắng', value: 'absent' },
  { label: 'Đi muộn', value: 'late' },
  { label: 'Nửa ngày', value: 'half_day' },
];

const employeeOptions = computed(() => {
  const options = [{ label: 'Tất cả', value: '' }];
  employees.value.forEach(e => {
    options.push({ label: e.full_name, value: String(e.id) });
  });
  return options;
});

const getInitials = (name) => {
  if (!name) return '';
  return name.split(' ').map(w => w[0]).join('').toUpperCase().slice(0, 2);
};

const formatDate = (date) => {
  if (!date) return '-';
  return new Date(date).toLocaleDateString('vi-VN');
};

const formatTime = (time) => {
  if (!time) return '-';
  const s = String(time);
  // Plain time string: "08:42:00" or "08:42" — no timezone info, return directly
  if (/^\d{2}:\d{2}/.test(s)) {
    return s.slice(0, 5);
  }
  // ISO datetime: force display in Vietnam time regardless of browser locale
  const d = new Date(s);
  if (!isNaN(d)) {
    return d.toLocaleTimeString('vi-VN', {
      hour: '2-digit',
      minute: '2-digit',
      timeZone: 'Asia/Ho_Chi_Minh',
    });
  }
  return s;
};

const formatMinutes = (value) => {
  const minutes = Math.max(0, Number(value || 0));
  if (!minutes) return '0 phút';
  const hours = Math.floor(minutes / 60);
  const rest = Math.round(minutes % 60);
  if (!hours) return `${rest} phút`;
  return rest ? `${hours}h ${rest}p` : `${hours}h`;
};

const shiftLabel = (record) => record?.assigned_shift_code
  || record?.assigned_shift?.shift_code
  || record?.assigned_shift_name
  || record?.assigned_shift?.shift_name
  || 'Chưa gán ca';

const shiftWindow = (record) => {
  const start = record?.shift_start;
  const end = record?.shift_end;
  if (!start && !end) return '—';
  return `${formatTime(start)} - ${formatTime(end)}`;
};

const timingMetricClass = (value, tone) => {
  const active = Number(value || 0) > 0;
  if (!active) return 'text-xs text-muted-foreground';
  const tones = {
    info: 'bg-sky-500/10 text-sky-700 dark:text-sky-300',
    warning: 'bg-amber-500/10 text-amber-700 dark:text-amber-300',
    danger: 'bg-orange-500/10 text-orange-700 dark:text-orange-300',
  };
  return `inline-flex rounded-full px-2 py-0.5 text-xs font-semibold ${tones[tone] || tones.info}`;
};

const payrollReviewOf = (record) => record?.payroll_review || null;
const payrollReviewStatus = (record) => String(payrollReviewOf(record)?.status || record?.payroll_review_status || '').toUpperCase();
const isUnresolvedPayrollReview = (record) => ['PENDING', 'STALE'].includes(payrollReviewStatus(record));
const payrollReviewLabel = (record) => ({
  PENDING: 'Chờ HR duyệt',
  STALE: 'Cần duyệt lại',
  APPROVED: `Đã duyệt ${payrollReviewOf(record)?.approved_percent ?? record?.payroll_review_percent ?? 0}%`,
  WAIVED: 'Đã miễn',
  APPLIED: `Đã áp dụng ${payrollReviewOf(record)?.approved_percent ?? record?.payroll_review_percent ?? 0}%`,
}[payrollReviewStatus(record)] || 'Không phát sinh');
const payrollReviewBadge = (record) => {
  const status = payrollReviewStatus(record);
  const base = 'inline-flex rounded-full px-2 py-0.5 text-xs font-semibold whitespace-nowrap';
  if (status === 'PENDING') return `${base} bg-amber-500/15 text-amber-700 dark:text-amber-300`;
  if (status === 'STALE') return `${base} bg-red-500/15 text-red-700 dark:text-red-300`;
  if (['APPROVED', 'APPLIED'].includes(status)) return `${base} bg-blue-500/15 text-blue-700 dark:text-blue-300`;
  if (status === 'WAIVED') return `${base} bg-emerald-500/15 text-emerald-700 dark:text-emerald-300`;
  return `${base} bg-muted text-muted-foreground`;
};
const payrollDefaultPercent = computed(() => Number(payrollReviewOf(payrollReviewRecord.value)?.default_percent || 0));
const payrollDecisionNeedsNote = computed(() => {
  const percent = Number(payrollDecision.value.percent);
  return percent === 0 || percent !== payrollDefaultPercent.value;
});

const openPayrollReview = (record) => {
  if (!canReviewPayroll.value || !isUnresolvedPayrollReview(record)) return;
  payrollReviewRecord.value = record;
  payrollDecision.value = {
    percent: String(payrollReviewOf(record)?.default_percent ?? 0),
    note: '',
  };
  showPayrollReviewModal.value = true;
};

const submitPayrollDecision = async () => {
  const review = payrollReviewOf(payrollReviewRecord.value);
  if (!review?.id || savingPayrollDecision.value) return;
  if (payrollDecisionNeedsNote.value && !String(payrollDecision.value.note || '').trim()) {
    toast.error('Vui lòng ghi lý do khi miễn hoặc thay đổi mức mặc định.');
    return;
  }
  savingPayrollDecision.value = true;
  try {
    await attendanceService.decidePayrollReview(
      review.id,
      Number(payrollDecision.value.percent),
      String(payrollDecision.value.note || '').trim(),
    );
    toast.success('Đã lưu quyết định khấu trừ chấm công.');
    showPayrollReviewModal.value = false;
    payrollReviewRecord.value = null;
    await loadData();
  } catch (err) {
    toast.error(firstError(err, 'Không thể lưu quyết định khấu trừ'));
  } finally {
    savingPayrollDecision.value = false;
  }
};

const getStatusVariant = (status) => {
  const variants = {
    present: 'success',
    late: 'warning',
    early: 'warning',
    absent: 'error',
    half_day: 'info',
    holiday: 'default'
  };
  return variants[status] || 'default';
};

const getStatusText = (status) => {
  const texts = {
    present: 'Có mặt',
    absent: 'Vắng',
    late: 'Đi muộn',
    early: 'Về sớm',
    half_day: 'Nửa ngày',
    holiday: 'Nghỉ lễ',
    leave: 'Nghỉ phép'
  };
  return texts[status] || status;
};

const openEditModal = (record) => {
  editingRecord.value = record;
  editForm.value = {
    check_in_time: record.check_in_time ? String(record.check_in_time).slice(0, 5) : '',
    check_out_time: record.check_out_time ? String(record.check_out_time).slice(0, 5) : '',
    check_in_time_2: record.check_in_time_2 ? String(record.check_in_time_2).slice(0, 5) : '',
    check_out_time_2: record.check_out_time_2 ? String(record.check_out_time_2).slice(0, 5) : '',
    notes: record.notes || ''
  };
  showEditModal.value = true;
};

// Lấy vị trí (geofence chống gian lận). Không chặn nếu bị từ chối/timeout —
// backend sẽ tự đánh dấu "thiếu vị trí" để admin xem xét.
const getGeo = () => new Promise((resolve) => {
  if (!('geolocation' in navigator)) return resolve({});
  navigator.geolocation.getCurrentPosition(
    (pos) => resolve({
      latitude: pos.coords.latitude,
      longitude: pos.coords.longitude,
      accuracy: pos.coords.accuracy,
      source: 'web',
    }),
    () => resolve({}),
    { enableHighAccuracy: true, timeout: 8000, maximumAge: 60000 }
  );
});

const firstError = (err, fallback) => {
  const fe = err.response?.data?.data?.errors;
  const first = fe && typeof fe === 'object' ? Object.values(fe)[0] : null;
  return (Array.isArray(first) ? first[0] : first) || err.response?.data?.error || err.response?.data?.message || fallback;
};

const formatDateTime = (value) => {
  if (!value) return '—';
  try { return new Date(value).toLocaleString('vi-VN'); }
  catch { return value; }
};

const loadDeviceSyncStatus = async (silent = true) => {
  try {
    deviceSync.value = await attendanceService.getDeviceSyncStatus();
    syncAccessAvailable.value = true;
    return true;
  } catch (err) {
    if (err.response?.status === 403) {
      syncAccessAvailable.value = false;
      return false;
    }
    if (!silent) toast.error(firstError(err, 'Không tải được trạng thái máy chấm công'));
    return false;
  }
};

const scheduleDeviceSyncPoll = () => {
  if (syncPollTimer) clearTimeout(syncPollTimer);
  syncPollTimer = setTimeout(async () => {
    syncPollAttempts += 1;
    const loaded = await loadDeviceSyncStatus(false);
    if (loaded && syncInProgress.value && syncPollAttempts < 45) {
      scheduleDeviceSyncPoll();
      return;
    }

    syncingDevices.value = false;
    if (loaded) {
      if (syncInProgress.value) {
        toast.error('Bridge chưa phản hồi sau 90 giây. Lệnh vẫn được giữ và sẽ chạy khi laptop online.');
        return;
      }
      const failed = currentSyncDevices.value.some((device) => device.sync_request?.status === 'FAILED');
      if (failed) toast.error('Có máy chấm công đồng bộ thất bại; hãy kiểm tra trạng thái bridge.');
      else toast.success('Đã đồng bộ xong dữ liệu máy chấm công.');
      await loadData();
    }
  }, 2000);
};

const syncAttendanceDevices = async () => {
  if (syncingDevices.value) return;
  syncingDevices.value = true;
  syncPollAttempts = 0;
  try {
    deviceSync.value = await attendanceService.requestDeviceSync();
    syncAccessAvailable.value = true;
    toast.success('Đã gửi lệnh đồng bộ tới bridge máy chấm công.');
    scheduleDeviceSyncPoll();
  } catch (err) {
    syncingDevices.value = false;
    toast.error(firstError(err, 'Không gửi được lệnh đồng bộ'));
  }
};

const handleSelfCheckIn = async () => {
  if (clockProcessing.value) return;
  clockProcessing.value = true;
  try {
    const empId = myEmployeeId.value;
    if (!empId) {
      toast.error('Không tìm thấy mã nhân viên. Vui lòng liên hệ HR.');
      return;
    }
    const geo = await getGeo();
    geo.work_mode = workMode.value;
    if (workMode.value === 'on_site') geo.site_name = siteName.value;
    const rec = await attendanceService.checkIn(empId, geo);
    if (rec?.review_status === 'needs_review') {
      toast.error('Đã ghi nhận nhưng ngoài phạm vi cho phép — cần quản trị xem xét.');
    } else {
      toast.success('Check-in thành công!');
    }
    await loadData();
  } catch (err) {
    toast.error(firstError(err, 'Lỗi chấm công'));
  } finally {
    clockProcessing.value = false;
  }
};
const handleSelfCheckOut = async () => {
  if (clockProcessing.value) return;
  clockProcessing.value = true;
  try {
    const empId = myEmployeeId.value;
    if (!empId) {
      toast.error('Không tìm thấy mã nhân viên.');
      return;
    }
    const geo = await getGeo();
    await attendanceService.checkOut(empId, geo);
    toast.success('Check-out thành công!');
    await loadData();
  } catch (err) {
    toast.error(firstError(err, 'Lỗi check-out'));
  } finally {
    clockProcessing.value = false;
  }
};

// ── Admin: xác minh chấm công (chống gian lận) ──
// Chỉ áp dụng cho LƯỢT CHECK-IN có dữ liệu xác minh (IP/vị trí/thiết bị).
// Ngày VẮNG hoặc bản ghi không có dữ liệu xác minh (vd nhập tay, dữ liệu cũ)
// → không phải "hợp lệ/không hợp lệ" mà là "—" (không áp dụng).
const verifyInfo = (record) => {
  const v = record.verification || null;
  const rs = record.review_status;
  const parts = [];
  if (v?.ip) parts.push(`IP: ${v.ip}`);
  if (v?.distance_m != null) parts.push(`Cách VP: ${v.distance_m}m`);
  if (Array.isArray(v?.flags) && v.flags.length) parts.push(`Cờ: ${v.flags.join(', ')}`);
  if (v?.device) parts.push(`TB: ${String(v.device).slice(0, 40)}`);

  // Trạng thái duyệt tường minh luôn ưu tiên.
  if (rs === 'needs_review') return { label: '⚠ Cần xem xét', cls: 'bg-amber-500/15 text-amber-700 dark:text-amber-400', title: parts.join(' · ') || 'Ngoài phạm vi' };
  if (rs === 'approved') return { label: '✓ Đã duyệt', cls: 'bg-green-500/15 text-green-700 dark:text-green-400', title: parts.join(' · ') };
  if (rs === 'rejected') return { label: '✗ Từ chối', cls: 'bg-red-500/15 text-red-700 dark:text-red-400', title: parts.join(' · ') };

  // Vắng / không có check-in / không có dữ liệu xác minh → không áp dụng.
  if (record.status === 'absent' || !v || (!v.ip && v.lat == null)) {
    return { label: '—', cls: 'text-muted-foreground', title: 'Không áp dụng (không có lượt chấm công cần xác minh vị trí)' };
  }

  // Có dữ liệu xác minh và trong phạm vi.
  return { label: '✓ Trong phạm vi', cls: 'bg-green-500/15 text-green-700 dark:text-green-400', title: parts.join(' · ') };
};

const verifyAttendance = async (record, decision) => {
  if (processingVerify.value) return;
  if (decision === 'reject' && !confirm('Từ chối lượt chấm công này (sẽ tính VẮNG)?')) return;
  processingVerify.value = true;
  try {
    await attendanceService.verify(record.id, decision);
    toast.success(decision === 'approve' ? 'Đã xác nhận hợp lệ' : 'Đã từ chối');
    await loadData();
  } catch (err) {
    toast.error(firstError(err, 'Lỗi xác minh'));
  } finally {
    processingVerify.value = false;
  }
};

const handleUpdate = async () => {
  if (!editingRecord.value) return;
  try {
    await attendanceService.update(editingRecord.value.id, {
      ...editingRecord.value,
      ...editForm.value,
    });
    toast.success('Cập nhật thành công!');
    showEditModal.value = false;
    editingRecord.value = null;
    await loadData();
  } catch (err) {
    toast.error(err.response?.data?.error || 'Cập nhật thất bại');
  }
};

const applyFilters = async () => {
  await loadData();
};

const loadData = async () => {
  try {
    loading.value = true;

    const params = {};
    if (filters.value.startDate) params.from = filters.value.startDate;
    if (filters.value.endDate) params.to = filters.value.endDate;
    if (filters.value.employee_id) params.employee_id = filters.value.employee_id;
    if (filters.value.status) params.status = filters.value.status;
    // Ngoài lăng kính toàn công ty → CHỈ lấy chấm công của chính mình. NV thường gọi
    // theo khoảng ngày mà không kèm employee_id sẽ bị 403 (RBAC) → lịch trống.
    if (!orgLens.value && myEmployeeId.value) params.employee_id = myEmployeeId.value;

    try {
      const [attendanceData, employeesData] = await Promise.all([
        attendanceService.getRecords(params),
        // per_page cao — mặc định API chỉ trả trang 1 (15/21 NV) làm thiếu
        // người trong dropdown lọc và map tên.
        employeeService.getLookup()
      ]);

      // API returns array directly
      const rawRecords = Array.isArray(attendanceData)
        ? attendanceData
        : (attendanceData?.data || []);

      const rawEmployees = Array.isArray(employeesData)
        ? employeesData
        : (employeesData?.data || []);
      if (rawEmployees.length > 0) {
        employees.value = rawEmployees;
      }

      // Map employee_id → {tên, mã NV} để hiển thị CHUẨN NVxxxx (đồng bộ với
      // các module khác), thay cho fallback "NV #12" khi API không join tên.
      const empMap = {};
      for (const e of employees.value) {
        empMap[String(e.id)] = { name: e.full_name, code: e.employee_code || e.code };
      }

      if (rawRecords.length > 0) {
        // Normalize: ensure both attendance_date and record_date exist
        records.value = rawRecords.map(r => {
          // Ưu tiên relation `employee` mà API đã join sẵn; fallback map từ
          // danh sách nhân viên. Hiển thị CHUẨN: Họ tên + mã NVxxxx.
          const rel = r.employee || {};
          const emp = empMap[String(r.employee_id)] || {};
          return {
            ...r,
            attendance_date: r.attendance_date || r.record_date,
            record_date: r.record_date || r.attendance_date,
            full_name: r.full_name || r.employee_name || rel.full_name || emp.name,
            employee_name: r.employee_name || r.full_name || rel.full_name || emp.name,
            employee_code: r.employee_code || rel.employee_code || emp.code,
          };
        });
      } else {
        records.value = [];
      }
    } catch (apiError) {
      console.error('Attendance API error:', apiError.message);
      records.value = [];
      toast.error('Không thể tải dữ liệu chấm công');
    }

  } catch (err) {
    console.error('Error loading attendance data:', err);
    records.value = [];
  } finally {
    loading.value = false;
  }
};

onMounted(() => {
  updateClock();
  clockInterval = setInterval(updateClock, 1000);
  // Mặc định thống kê THEO THÁNG HIỆN TẠI — tránh cộng dồn dữ liệu cũ
  // khiến thẻ "Có mặt/Vắng" hiển thị số của tháng trước như thể là tháng này.
  const now = new Date();
  const y = now.getFullYear(), m = now.getMonth();
  const pad = (n) => String(n).padStart(2, '0');
  filters.value.startDate = `${y}-${pad(m + 1)}-01`;
  filters.value.endDate = `${y}-${pad(m + 1)}-${pad(new Date(y, m + 1, 0).getDate())}`;
  if (orgLens.value) loadDeviceSyncStatus();
  loadData();
});

onUnmounted(() => {
  if (clockInterval) clearInterval(clockInterval);
  if (syncPollTimer) clearTimeout(syncPollTimer);
});
</script>
