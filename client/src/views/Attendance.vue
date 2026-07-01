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

    <template v-if="orgLens">
      <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
        <BaseCard>
          <div class="text-center">
            <p class="text-sm text-muted-foreground">Có mặt</p>
            <p class="text-3xl font-bold text-green-600 dark:text-green-400 mt-2">{{ recordsSummary.present }}</p>
            <p class="text-xs text-muted-foreground mt-1">{{ recordsSummary.total }} bản ghi</p>
          </div>
        </BaseCard>
        <BaseCard>
          <div class="text-center">
            <p class="text-sm text-muted-foreground">Vắng mặt</p>
            <p class="text-3xl font-bold text-red-600 dark:text-red-400 mt-2">{{ recordsSummary.absent }}</p>
          </div>
        </BaseCard>
        <BaseCard>
          <div class="text-center">
            <p class="text-sm text-muted-foreground">Đi muộn</p>
            <p class="text-3xl font-bold text-amber-600 dark:text-amber-400 mt-2">{{ recordsSummary.late }}</p>
          </div>
        </BaseCard>
        <BaseCard>
          <div class="text-center">
            <p class="text-sm text-muted-foreground">Về sớm</p>
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

        <!-- Admin: lọc lượt cần xem xét (chống gian lận) -->
        <button
          v-else
          @click="needsReviewOnly = !needsReviewOnly"
          :class="['px-3 py-1.5 text-xs font-semibold rounded-lg border transition-colors flex items-center gap-1.5',
                   needsReviewOnly ? 'border-amber-500 bg-amber-500/10 text-amber-700 dark:text-amber-400' : 'border-border text-muted-foreground hover:bg-muted']"
          title="Chỉ hiện các lượt chấm công ngoài phạm vi cần duyệt"
        >
          <span>⚠ Cần xem xét</span>
          <span v-if="needsReviewCount" class="px-1.5 rounded-full bg-amber-500 text-white text-[10px]">{{ needsReviewCount }}</span>
        </button>
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
                <p v-if="dayObj.record.total_work_hours" class="flex justify-between"><span>Tổng giờ:</span> <span class="font-medium text-foreground">{{ dayObj.record.total_work_hours }}h</span></p>
                <p v-if="dayObj.record.late_minutes" class="flex justify-between text-amber-600"><span>Đi muộn:</span> <span class="font-medium">{{ dayObj.record.late_minutes }} phút</span></p>
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
                <span class="font-medium">{{ item.full_name || item.employee_name || `NV #${item.employee_id}` }}</span>
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
            <p class="font-medium">{{ editingRecord.full_name || `NV #${editingRecord.employee_id}` }}</p>
          </div>
          <div>
            <p class="text-sm text-muted-foreground">Ngày</p>
            <p class="font-medium">{{ formatDate(editingRecord.record_date) }}</p>
          </div>
        </div>
        <BaseSelect
          v-model="editForm.status"
          label="Trạng thái"
          :options="checkInStatusOptions"
        />
        <BaseInput
          v-model="editForm.late_minutes"
          label="Phút đi muộn"
          type="number"
        />
        <BaseInput
          v-model="editForm.overtime_hours"
          label="Giờ tăng ca"
          type="number"
          step="0.5"
        />
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
            <p class="font-medium">{{ logRecord.full_name || logRecord.employee_name || `NV #${logRecord.employee_id}` }}</p>
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
            <p class="text-muted-foreground">Tổng giờ làm</p>
            <p class="font-medium">{{ logRecord.worked_hours || logRecord.total_work_hours || 0 }}h</p>
          </div>
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

// Summary of ALL currently visible records (for admin overview)
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
  status: '',
  late_minutes: 0,
  overtime_hours: 0,
  notes: ''
});

const adminColumns = [
  { key: 'employee', label: 'Nhân viên' },
  { key: 'attendance_date', label: 'Ngày' },
  { key: 'check_in_time', label: 'Giờ vào' },
  { key: 'check_out_time', label: 'Giờ ra' },
  { key: 'total_work_hours', label: 'Tổng giờ' },
  { key: 'status', label: 'Trạng thái' },
  { key: 'verify', label: 'Xác minh' },
];

const employeeColumns = [
  { key: 'attendance_date', label: 'Ngày' },
  { key: 'check_in_time', label: 'Giờ vào' },
  { key: 'check_out_time', label: 'Giờ ra' },
  { key: 'total_work_hours', label: 'Tổng giờ' },
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
  if (orgLens.value && needsReviewOnly.value) {
    return scopedRecords.value.filter(r => r.review_status === 'needs_review');
  }
  return scopedRecords.value;
});

// Số lượt chấm công cần admin xem xét (chống gian lận).
const needsReviewCount = computed(() =>
  records.value.filter(r => r.review_status === 'needs_review').length
);

const statusOptions = [
  { label: 'Tất cả', value: '' },
  { label: 'Có mặt', value: 'present' },
  { label: 'Vắng', value: 'absent' },
  { label: 'Đi muộn', value: 'late' },
  { label: 'Nửa ngày', value: 'half_day' },
];

const checkInStatusOptions = [
  { label: 'Có mặt', value: 'present' },
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
    status: record.status || 'present',
    late_minutes: record.late_minutes || 0,
    overtime_hours: record.overtime_hours || 0,
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

    try {
      const [attendanceData, employeesData] = await Promise.all([
        attendanceService.getRecords(params),
        employeeService.getAll()
      ]);

      // API returns array directly
      const rawRecords = Array.isArray(attendanceData)
        ? attendanceData
        : (attendanceData?.data || []);

      if (rawRecords.length > 0) {
        // Normalize: ensure both attendance_date and record_date exist
        records.value = rawRecords.map(r => ({
          ...r,
          attendance_date: r.attendance_date || r.record_date,
          record_date: r.record_date || r.attendance_date,
          // employee_name comes from the JOIN; also expose as full_name
          full_name: r.full_name || r.employee_name,
          employee_name: r.employee_name || r.full_name,
        }));
      } else {
        records.value = [];
      }

      const rawEmployees = Array.isArray(employeesData)
        ? employeesData
        : (employeesData?.data || []);
      if (rawEmployees.length > 0) {
        employees.value = rawEmployees;
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
  loadData();
});

onUnmounted(() => {
  if (clockInterval) clearInterval(clockInterval);
});
</script>
