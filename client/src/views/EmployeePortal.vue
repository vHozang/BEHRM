<template>
  <div class="space-y-6">
    <div>
      <h1 class="text-3xl font-bold text-foreground">Cổng Nhân viên</h1>
      <p class="text-muted-foreground mt-1">Xem thông tin cá nhân, yêu cầu nghỉ phép và chấm công</p>
    </div>

    <!-- Loading State -->
    <div v-if="pageLoading" class="space-y-6">
      <BaseSkeleton type="block" height="9rem" />
      <BaseSkeleton type="cards" :rows="3" grid-class="grid-cols-1 sm:grid-cols-3" />
      <BaseSkeleton type="block" height="16rem" />
    </div>

    <!-- No Employee Linked -->
    <div v-else-if="!currentEmployee.id" class="bg-destructive/10 border border-destructive/20 rounded-lg p-6 text-center">
      <p class="text-destructive font-medium">Tài khoản này chưa được liên kết với hồ sơ nhân viên nào.</p>
      <p class="text-sm text-muted-foreground mt-1">Vui lòng liên hệ bộ phận nhân sự để được hỗ trợ.</p>
    </div>

    <template v-else>
      <!-- MOBILE APP VIEW (Grab Food Style) - Visible only on mobile/tablet -->
      <div class="lg:hidden space-y-6">
        <!-- User Welcome Banner (Glassmorphism & Gradients) -->
        <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-primary to-[#0f3460] p-6 text-white shadow-md">
          <div class="absolute -right-10 -bottom-10 w-40 h-40 rounded-full bg-white/10 blur-2xl"></div>
          <div class="absolute -left-10 -top-10 w-32 h-32 rounded-full bg-white/5 blur-xl"></div>
          
          <div class="flex items-center gap-4 relative z-10">
            <div class="w-14 h-14 rounded-full bg-white/20 backdrop-blur-md border border-white/30 flex items-center justify-center text-xl font-bold flex-shrink-0">
              {{ getInitials(currentEmployee.full_name) }}
            </div>
            <div>
              <p class="text-xs text-white/70">Xin chào,</p>
              <h2 class="text-lg font-bold leading-tight">{{ currentEmployee.full_name }}</h2>
              <p class="text-[11px] text-white/80 bg-white/10 px-2 py-0.5 rounded-full inline-block mt-1 font-medium">
                {{ currentEmployee.job_title_name || 'Nhân viên' }}
              </p>
            </div>
          </div>
          
          <!-- Quick Check-in Banner inside Card -->
          <div class="mt-6 pt-4 border-t border-white/10 flex items-center justify-between relative z-10">
            <div>
              <p class="text-xs text-white/60">Mã NV: {{ currentEmployee.employee_code || '-' }}</p>
              <p class="text-xs font-semibold text-white/90 mt-0.5">Phòng: {{ currentEmployee.department_name || '-' }}</p>
            </div>
            <button 
              @click="handleCheckIn" 
              :disabled="checkInLoading"
              class="px-4 py-2 bg-white text-primary rounded-full text-xs font-bold shadow-sm hover:scale-105 active:scale-95 transition-all flex items-center gap-1.5 disabled:opacity-50"
            >
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              {{ checkInLoading ? 'Đang gửi...' : 'Chấm công' }}
            </button>
          </div>
        </div>

        <!-- Grab Food style Grid Icons (Quick Actions) -->
        <div>
          <h3 class="text-xs font-bold text-muted-foreground mb-3 uppercase tracking-wider">Tiện ích nhanh</h3>
          <div class="grid grid-cols-4 gap-y-5 gap-x-2 bg-card border border-border/50 rounded-2xl p-4 shadow-sm">
            <!-- Icon 1: Chấm công -->
            <button @click="activeTab = 'attendance'; scrollToTabContent()" class="flex flex-col items-center gap-1.5">
              <div class="w-12 h-12 rounded-2xl bg-indigo-50 dark:bg-indigo-950/40 text-indigo-500 dark:text-indigo-400 flex items-center justify-center shadow-2xs hover:scale-105 active:scale-95 transition-transform">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
              </div>
              <span class="text-[10px] font-medium text-foreground text-center line-clamp-1">Chấm công</span>
            </button>

            <!-- Icon: Lịch ca -->
            <button @click="activeTab = 'roster'; scrollToTabContent()" class="flex flex-col items-center gap-1.5">
              <div class="w-12 h-12 rounded-2xl bg-sky-50 dark:bg-sky-950/40 text-sky-500 dark:text-sky-400 flex items-center justify-center shadow-2xs hover:scale-105 active:scale-95 transition-transform">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
              </div>
              <span class="text-[10px] font-medium text-foreground text-center line-clamp-1">Lịch ca</span>
            </button>

            <!-- Icon 2: Xin nghỉ -->
            <button @click="activeTab = 'leaves'; scrollToTabContent()" class="flex flex-col items-center gap-1.5">
              <div class="w-12 h-12 rounded-2xl bg-amber-50 dark:bg-amber-950/40 text-amber-500 dark:text-amber-400 flex items-center justify-center shadow-2xs hover:scale-105 active:scale-95 transition-transform">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
              </div>
              <span class="text-[10px] font-medium text-foreground text-center line-clamp-1">Xin nghỉ</span>
            </button>

            <!-- Icon 3: Phiếu lương -->
            <button @click="activeTab = 'salary'; scrollToTabContent()" class="flex flex-col items-center gap-1.5">
              <div class="w-12 h-12 rounded-2xl bg-emerald-50 dark:bg-emerald-950/40 text-emerald-500 dark:text-emerald-400 flex items-center justify-center shadow-2xs hover:scale-105 active:scale-95 transition-transform">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
              </div>
              <span class="text-[10px] font-medium text-foreground text-center line-clamp-1">Phiếu lương</span>
            </button>

            <!-- Icon 4: Tin tức -->
            <router-link to="/news" class="flex flex-col items-center gap-1.5">
              <div class="w-12 h-12 rounded-2xl bg-blue-50 dark:bg-blue-950/40 text-blue-500 dark:text-blue-400 flex items-center justify-center shadow-2xs hover:scale-105 active:scale-95 transition-transform">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z" />
                </svg>
              </div>
              <span class="text-[10px] font-medium text-foreground text-center line-clamp-1">Tin tức</span>
            </router-link>

            <!-- Icon 5: Chính sách -->
            <router-link to="/policies" class="flex flex-col items-center gap-1.5">
              <div class="w-12 h-12 rounded-2xl bg-teal-50 dark:bg-teal-950/40 text-teal-500 dark:text-teal-400 flex items-center justify-center shadow-2xs hover:scale-105 active:scale-95 transition-transform">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                </svg>
              </div>
              <span class="text-[10px] font-medium text-foreground text-center line-clamp-1">Chính sách</span>
            </router-link>

            <!-- Icon 6: Trợ giúp -->
            <router-link to="/service-tickets" class="flex flex-col items-center gap-1.5">
              <div class="w-12 h-12 rounded-2xl bg-rose-50 dark:bg-rose-950/40 text-rose-500 dark:text-rose-400 flex items-center justify-center shadow-2xs hover:scale-105 active:scale-95 transition-transform">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z" />
                </svg>
              </div>
              <span class="text-[10px] font-medium text-foreground text-center line-clamp-1">Trợ giúp</span>
            </router-link>

            <!-- Icon 7: Profile -->
            <button @click="activeTab = 'profile'; scrollToTabContent()" class="flex flex-col items-center gap-1.5">
              <div class="w-12 h-12 rounded-2xl bg-violet-50 dark:bg-violet-950/40 text-violet-500 dark:text-violet-400 flex items-center justify-center shadow-2xs hover:scale-105 active:scale-95 transition-transform">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                </svg>
              </div>
              <span class="text-[10px] font-medium text-foreground text-center line-clamp-1">Tài khoản</span>
            </button>
          </div>
        </div>
      </div>

      <!-- DESKTOP APP VIEW - Visible only on desktop screens -->
      <BaseCard class="hidden lg:block">
        <div class="flex flex-col sm:flex-row items-center sm:items-start text-center sm:text-left gap-4 sm:gap-6">
          <div class="w-20 h-20 rounded-full bg-primary text-primary-foreground flex items-center justify-center text-2xl font-bold flex-shrink-0">
            {{ getInitials(currentEmployee.full_name) }}
          </div>
          <div>
            <h2 class="text-2xl font-bold">{{ currentEmployee.full_name }}</h2>
            <p class="text-muted-foreground">Mã NV: {{ currentEmployee.employee_code || '-' }}</p>
            <p class="text-muted-foreground">{{ currentEmployee.job_title_name || '-' }} | {{ currentEmployee.department_name || '-' }}</p>
          </div>
        </div>
      </BaseCard>

      <!-- Action list: việc cần xử lý / thông tin nhanh -->
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div class="rounded-xl border border-border bg-card p-4 flex items-center justify-between">
          <div>
            <p class="text-xs text-muted-foreground">Chấm công hôm nay</p>
            <p class="text-lg font-bold" :class="todayCheckedIn ? 'text-success' : 'text-warning'">
              {{ todayCheckedIn ? 'Đã chấm công' : 'Chưa chấm công' }}
            </p>
          </div>
          <BaseButton v-if="!todayCheckedIn" @click="handleCheckIn" :disabled="checkInLoading" class="shrink-0">
            {{ checkInLoading ? '...' : 'Chấm công' }}
          </BaseButton>
          <svg v-else class="w-8 h-8 text-success" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
        </div>
        <button class="rounded-xl border border-border bg-card p-4 text-left hover:bg-accent/40 transition-colors" @click="activeTab = 'leaves'; scrollToTabContent()">
          <p class="text-xs text-muted-foreground">Đơn nghỉ đang chờ duyệt</p>
          <p class="text-3xl font-bold mt-1" :class="pendingLeaveCount ? 'text-warning' : 'text-foreground'">{{ pendingLeaveCount }}</p>
        </button>
        <button class="rounded-xl border border-border bg-card p-4 text-left hover:bg-accent/40 transition-colors" @click="activeTab = 'leaves'; scrollToTabContent()">
          <p class="text-xs text-muted-foreground">Phép năm còn lại</p>
          <p class="text-3xl font-bold text-primary mt-1">{{ remainingLeave }} <span class="text-sm font-normal text-muted-foreground">ngày</span></p>
        </button>
      </div>

      <!-- Tabs -->
      <div class="flex gap-4 border-b border-border overflow-x-auto" ref="tabContentRef">
        <button
          @click="activeTab = 'attendance'"
          :class="['px-4 py-2 font-medium whitespace-nowrap', activeTab === 'attendance' ? 'border-b-2 border-primary text-primary' : 'text-muted-foreground hover:text-foreground']"
        >
          Chấm công
        </button>
        <button
          @click="activeTab = 'roster'"
          :class="['px-4 py-2 font-medium whitespace-nowrap', activeTab === 'roster' ? 'border-b-2 border-primary text-primary' : 'text-muted-foreground hover:text-foreground']"
        >
          Lịch ca của tôi
        </button>
        <button
          @click="activeTab = 'leaves'"
          :class="['px-4 py-2 font-medium whitespace-nowrap', activeTab === 'leaves' ? 'border-b-2 border-primary text-primary' : 'text-muted-foreground hover:text-foreground']"
        >
          Yêu cầu nghỉ phép
        </button>
        <button
          v-if="onboardingChecklists.length"
          @click="activeTab = 'onboarding'"
          :class="['px-4 py-2 font-medium whitespace-nowrap', activeTab === 'onboarding' ? 'border-b-2 border-primary text-primary' : 'text-muted-foreground hover:text-foreground']"
        >
          Hội nhập / Nghỉ việc
        </button>
        <button
          @click="activeTab = 'salary'"
          :class="['px-4 py-2 font-medium whitespace-nowrap', activeTab === 'salary' ? 'border-b-2 border-primary text-primary' : 'text-muted-foreground hover:text-foreground']"
        >
          Lương
        </button>
        <button
          @click="activeTab = 'profile'"
          :class="['px-4 py-2 font-medium whitespace-nowrap', activeTab === 'profile' ? 'border-b-2 border-primary text-primary' : 'text-muted-foreground hover:text-foreground']"
        >
          Thông tin cá nhân
        </button>
      </div>

      <!-- ===== ATTENDANCE TAB ===== -->
      <div v-if="activeTab === 'attendance'">
        <BaseCard>
          <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
            <h3 class="text-lg font-bold text-foreground">Tổng quan Điểm danh</h3>
            <BaseButton @click="handleCheckIn" :disabled="checkInLoading" class="shadow-sm w-full sm:w-auto">
              <svg class="w-4 h-4 mr-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              {{ checkInLoading ? 'Đang xử lý...' : 'Chấm công ngay' }}
            </BaseButton>
          </div>

          <div class="mb-8 p-6 bg-gradient-to-r from-primary/10 to-transparent rounded-2xl border border-primary/20">
            <h4 class="text-xl font-extrabold text-primary mb-2">Tiến độ tháng này</h4>
            <p class="text-sm text-muted-foreground mb-4">Bạn đã có mặt <strong class="text-foreground text-base">{{ attendanceStats.present }}</strong> ngày. Luôn giữ vững năng lượng nhé!</p>

            <div class="w-full bg-border h-4 rounded-full overflow-hidden shadow-inner">
              <div class="bg-primary h-full rounded-full transition-all duration-1000 origin-left" :style="{ width: Math.min((attendanceStats.present / workingDaysInMonth) * 100, 100) + '%' }"></div>
            </div>

            <div class="flex flex-wrap items-center gap-6 mt-5 text-sm font-semibold">
              <div class="flex items-center gap-2"><span class="w-3 h-3 rounded-full bg-success shadow-sm"></span> Đúng giờ: {{ attendanceStats.present }}</div>
              <div class="flex items-center gap-2"><span class="w-3 h-3 rounded-full bg-warning shadow-sm"></span> Đi muộn: {{ attendanceStats.late }}</div>
              <div class="flex items-center gap-2"><span class="w-3 h-3 rounded-full bg-destructive shadow-sm"></span> Vắng mặt: {{ attendanceStats.absent }}</div>
            </div>
          </div>

          <!-- Month filter -->
          <div class="flex items-center gap-3 mb-4">
            <label class="text-sm font-medium text-foreground">Tháng:</label>
            <select v-model="attendanceMonth" @change="loadAttendanceData(currentEmployee.id)" class="px-3 py-1.5 rounded-lg border border-input bg-background text-foreground text-sm focus:outline-none focus:ring-2 focus:ring-ring">
              <option v-for="m in monthOptions" :key="m.value" :value="m.value">{{ m.label }}</option>
            </select>
          </div>

          <div class="space-y-4">
            <h3 class="font-semibold text-foreground">Lịch sử Chấm công</h3>
            <div v-if="attendanceRecords.length === 0" class="text-center py-8 text-muted-foreground">Chưa có dữ liệu chấm công tháng này</div>
            <BaseTable
              v-else
              :columns="[
                { key: 'attendance_date', label: 'Ngày làm việc' },
                { key: 'time_log', label: 'Vào - Ra' },
                { key: 'work_hours', label: 'Số giờ làm' },
                { key: 'status', label: 'Trạng thái' }
              ]"
              :data="attendanceRecords"
            >
              <template #cell-attendance_date="{ item }">
                <span>{{ formatDate(item.attendance_date) }}</span>
              </template>

              <template #cell-time_log="{ item }">
                <div class="flex items-center gap-2 font-medium">
                  <span class="px-2 py-1 bg-green-100 text-green-700 rounded text-xs">{{ formatTime(item.check_in_time) }}</span>
                  <span class="text-muted-foreground">→</span>
                  <span class="px-2 py-1 bg-gray-100 text-gray-700 rounded text-xs">{{ item.check_out_time ? formatTime(item.check_out_time) : '--:--' }}</span>
                </div>
              </template>

              <template #cell-work_hours="{ item }">
                <span class="font-bold text-primary">{{ item.total_work_hours ? item.total_work_hours + 'h' : '-' }}</span>
              </template>

              <template #cell-status="{ item }">
                <BaseBadge :variant="item.status === 'present' ? 'success' : (item.status === 'late' ? 'warning' : 'destructive')">
                  {{ item.status === 'present' ? 'Có mặt' : (item.status === 'late' ? 'Muộn' : 'Vắng') }}
                </BaseBadge>
              </template>
            </BaseTable>
          </div>
        </BaseCard>
      </div>

      <!-- ===== MY ROSTER TAB ===== -->
      <div v-if="activeTab === 'roster'">
        <BaseCard>
          <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
            <div>
              <h3 class="text-lg font-bold text-foreground">Lịch ca của tôi</h3>
              <p class="text-sm text-muted-foreground mt-0.5">Ca làm việc được phân trong 2 tuần. Ngày hôm nay được tô đậm.</p>
            </div>
            <div class="flex items-center gap-2 bg-card border border-border p-1.5 rounded-xl shadow-xs self-start sm:self-auto">
              <BaseButton variant="outline" size="sm" class="px-2" @click="rosterWeekOffset--">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
              </BaseButton>
              <span class="text-xs font-semibold px-2 text-foreground whitespace-nowrap">{{ rosterRangeLabel }}</span>
              <BaseButton variant="outline" size="sm" class="px-2" @click="rosterWeekOffset++">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
              </BaseButton>
            </div>
          </div>

          <!-- Today's shift highlight -->
          <div class="mb-6 p-5 rounded-2xl border" :class="todayShift ? 'bg-primary/5 border-primary/30' : 'bg-muted/40 border-border'">
            <p class="text-xs font-semibold text-muted-foreground uppercase tracking-wider mb-1">Ca hôm nay</p>
            <div v-if="todayShift" class="flex items-center gap-3">
              <span class="w-3.5 h-3.5 rounded-md" :style="{ backgroundColor: todayShift.color_code || '#3b82f6' }"></span>
              <span class="text-2xl font-extrabold" :style="{ color: todayShift.color_code || 'var(--primary)' }">{{ todayShift.shift_code }}</span>
              <span class="text-foreground font-medium">{{ todayShift.shift_name }}</span>
              <span class="text-muted-foreground text-sm">{{ shiftTimeRange(todayShift) }}</span>
            </div>
            <p v-else class="text-lg font-bold text-muted-foreground">Hôm nay không có ca (OFF)</p>
          </div>

          <!-- Lời mời phủ ca (tăng ca thay người vắng) -->
          <div v-if="pendingCoverageOffers.length" class="mb-6 rounded-2xl border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-950/30 p-5">
            <p class="text-sm font-bold text-amber-700 dark:text-amber-400 mb-1">🆘 Lời mời phủ ca</p>
            <p class="text-xs text-muted-foreground mb-3">Quản lý mời bạn ở lại tăng ca để phủ ca có người vắng. Bấm "Nhận" sẽ tự tạo đơn tăng ca (chờ duyệt).</p>
            <div v-for="offer in pendingCoverageOffers" :key="offer.id" class="flex flex-wrap items-center justify-between gap-3 bg-card border border-border rounded-xl p-3 mb-2 last:mb-0">
              <div class="text-sm">
                <p class="font-semibold text-foreground">
                  Ngày {{ formatDate(offer.work_date) }} · {{ (offer.start_time || '').slice(0, 5) }}–{{ (offer.end_time || '').slice(0, 5) }}
                  <span class="text-primary font-bold">({{ Number(offer.hours) }}h)</span>
                </p>
                <p class="text-xs text-muted-foreground">Phủ ca thay người vắng</p>
              </div>
              <div class="flex gap-2">
                <BaseButton size="sm" @click="respondCoverage(offer, 'accept')" :disabled="coverageBusy">Nhận tăng ca</BaseButton>
                <BaseButton size="sm" variant="outline" @click="respondCoverage(offer, 'decline')" :disabled="coverageBusy">Từ chối</BaseButton>
              </div>
            </div>
          </div>

          <!-- 14-day roster grid -->
          <div v-if="rosterLoading" class="text-center py-8 text-muted-foreground">Đang tải lịch ca...</div>
          <div v-else class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-7 gap-3">
            <div
              v-for="d in rosterDays"
              :key="d.dateStr"
              class="rounded-xl border p-3 flex flex-col items-center text-center transition-colors"
              :class="d.isToday ? 'border-primary bg-primary/5 ring-1 ring-primary/30' : 'border-border bg-card'"
            >
              <p class="text-[11px] font-semibold" :class="d.isWeekend ? 'text-destructive' : 'text-muted-foreground'">{{ d.dayName }}</p>
              <p class="text-sm font-bold text-foreground mb-2">{{ formatDateDay(d.date) }}</p>
              <div
                v-if="d.shift"
                class="w-full rounded-lg py-2 px-1"
                :style="{ backgroundColor: (d.shift.color_code || '#3b82f6') + '18', color: d.shift.color_code || '#3b82f6' }"
              >
                <p class="text-sm font-black tracking-wide">{{ d.shift.shift_code }}</p>
                <p class="text-[10px] opacity-80 mt-0.5">{{ shiftTimeRange(d.shift) }}</p>
              </div>
              <div v-else class="w-full rounded-lg py-2 px-1 border border-dashed border-border text-muted-foreground">
                <p class="text-xs font-semibold">OFF</p>
                <p class="text-[10px]">Nghỉ</p>
              </div>
            </div>
          </div>

          <p class="text-xs text-muted-foreground mt-4">
            Cần đổi ca? Vào mục <router-link to="/work-schedules" class="text-primary hover:underline font-medium">Lịch làm việc</router-link> để gửi yêu cầu đổi ca tới quản lý.
          </p>
        </BaseCard>
      </div>

      <!-- ===== LEAVES TAB ===== -->
      <div v-if="activeTab === 'leaves'">
        <BaseCard>
          <div class="mb-6">
            <h3 class="font-semibold mb-4">Số ngày nghỉ phép còn lại</h3>
            <div v-if="visibleLeaveBalances.length === 0" class="text-sm text-muted-foreground">Chưa có thông tin số dư nghỉ phép.</div>
            <div v-else class="grid grid-cols-2 md:grid-cols-3 gap-4">
              <div v-for="balance in visibleLeaveBalances" :key="balance.id" class="p-4 border rounded-lg">
                <p class="font-medium text-sm">{{ balance.leave_type || balance.leave_type_name || 'Loại nghỉ' }}</p>
                <p class="text-2xl font-bold text-primary">{{ balance.remaining ?? balance.total_entitled ?? 0 }}</p>
                <p class="text-sm text-muted-foreground">ngày còn lại / {{ balance.entitlement ?? balance.total_entitled ?? balance.total_days ?? 0 }} ngày</p>
              </div>
            </div>
          </div>

          <div>
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-4">
              <h3 class="font-semibold">Yêu cầu gần đây</h3>
              <BaseButton @click="showLeaveModal = true" class="w-full sm:w-auto">+ Tạo yêu cầu</BaseButton>
            </div>
            <div v-if="leaveRequests.length === 0" class="text-center py-8 text-muted-foreground">Chưa có yêu cầu nghỉ phép nào</div>
            <BaseTable
              v-else
              :columns="[
                { key: 'leave_type', label: 'Loại nghỉ' },
                { key: 'dates', label: 'Thời gian' },
                { key: 'reason', label: 'Lý do' },
                { key: 'status', label: 'Trạng thái' }
              ]"
              :data="leaveRequests.slice(0, 10)"
            >
              <template #cell-leave_type="{ item }">
                <span>{{ leaveTypeName(item) }}</span>
              </template>
              <template #cell-dates="{ item }">
                <div class="text-sm">
                  <p>{{ formatDate(item.start_date) }} - {{ formatDate(item.end_date) }}</p>
                  <p class="text-xs text-muted-foreground">{{ item.total_days || item.days_count || 0 }} ngày</p>
                </div>
              </template>
              <template #cell-reason="{ item }">
                <span class="text-sm text-muted-foreground">{{ item.reason || '-' }}</span>
              </template>
              <template #cell-status="{ item }">
                <BaseBadge :variant="item.status === 'approved' ? 'success' : (item.status === 'pending' ? 'warning' : 'destructive')">
                  {{ item.status === 'approved' ? 'Đã duyệt' : (item.status === 'pending' ? 'Chờ duyệt' : 'Từ chối') }}
                </BaseBadge>
              </template>
              <template #actions="{ item }">
                <button
                  v-if="['pending', 'approved'].includes(item.status)"
                  @click="cancelLeaveRequest(item)"
                  class="p-1.5 rounded hover:bg-orange-100 dark:hover:bg-orange-900 text-orange-600 dark:text-orange-400"
                  title="Hủy đơn"
                  :disabled="leaveFormLoading"
                >
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                  </svg>
                </button>
              </template>
            </BaseTable>
          </div>
        </BaseCard>
      </div>

      <!-- ===== ONBOARDING TAB ===== -->
      <div v-if="activeTab === 'onboarding'">
        <BaseCard v-for="cl in onboardingChecklists" :key="cl.id" class="mb-4">
          <div class="flex items-center justify-between mb-3">
            <h3 class="font-bold text-foreground">{{ cl.type === 'OFFBOARDING' ? 'Thủ tục nghỉ việc' : 'Hội nhập nhân viên mới' }}</h3>
            <BaseBadge :variant="cl.status === 'COMPLETED' ? 'success' : (cl.status === 'CANCELLED' ? 'default' : 'warning')">
              {{ cl.status === 'COMPLETED' ? 'Hoàn tất' : (cl.status === 'CANCELLED' ? 'Đã hủy' : 'Đang thực hiện') }}
            </BaseBadge>
          </div>
          <div class="flex items-center gap-3 mb-4">
            <div class="flex-1 h-2 rounded-full bg-muted overflow-hidden">
              <div class="h-full bg-primary transition-all" :style="{ width: checklistProgress(cl).pct + '%' }"></div>
            </div>
            <span class="text-sm text-muted-foreground whitespace-nowrap">{{ checklistProgress(cl).done }}/{{ checklistProgress(cl).total }}</span>
          </div>
          <ul class="space-y-2">
            <li v-for="task in cl.tasks" :key="task.id" class="flex items-center gap-3 p-3 border border-border rounded-lg">
              <input
                type="checkbox"
                :checked="isTaskDone(task)"
                :disabled="onboardingBusy || cl.status === 'CANCELLED'"
                @change="toggleOnboardingTask(cl, task)"
                class="h-4 w-4 rounded border-input text-primary focus:ring-ring"
              />
              <span :class="['flex-1 text-sm', isTaskDone(task) ? 'line-through text-muted-foreground' : 'text-foreground']">{{ task.title }}</span>
              <span v-if="task.due_date" class="text-xs text-muted-foreground">Hạn: {{ formatDate(task.due_date) }}</span>
            </li>
          </ul>
        </BaseCard>
      </div>

      <!-- ===== SALARY TAB ===== -->
      <div v-if="activeTab === 'salary'">
        <BaseCard>
          <div class="space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
              <div>
                <h3 class="font-semibold">Phiếu lương<span v-if="salaryPeriodLabel" class="text-sm font-normal text-muted-foreground"> — Kỳ {{ salaryPeriodLabel }}</span></h3>
                <p v-if="salaryDocument" class="mt-1 text-xs text-muted-foreground">
                  {{ salaryDocumentStatusLabel(salaryDocument) }}
                </p>
              </div>
              <div class="flex flex-wrap gap-2 w-full sm:w-auto justify-start sm:justify-end" v-if="salaryDocumentReady">
                <button
                  @click="viewSalaryPdf"
                  :disabled="salaryActionBusy"
                  class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-border text-sm font-medium hover:bg-muted transition-colors"
                  title="Mở PDF chính thức đã phát hành"
                >
                  Xem PDF
                </button>
                <button
                  @click="downloadSalaryPdf"
                  :disabled="salaryActionBusy"
                  class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-border text-sm font-medium hover:bg-muted transition-colors"
                  title="Tải PDF chính thức"
                >
                  <svg class="w-4 h-4 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                  </svg>
                  Tải PDF
                </button>
                <button
                  @click="emailSalaryPdf"
                  :disabled="salaryActionBusy"
                  class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-primary/30 text-primary text-sm font-medium hover:bg-primary/5 transition-colors"
                >
                  Gửi về email
                </button>
              </div>
            </div>

            <div v-if="salaryHistory.length" class="rounded-xl border border-border overflow-hidden">
              <div class="px-4 py-3 bg-muted/40 border-b border-border">
                <h4 class="text-sm font-semibold">Lịch sử phiếu đã phát hành</h4>
              </div>
              <div class="divide-y divide-border">
                <button
                  v-for="item in salaryHistory"
                  :key="item.id"
                  type="button"
                  @click="selectSalaryPeriod(item)"
                  :class="[
                    'w-full px-4 py-3 flex items-center justify-between gap-3 text-left hover:bg-muted/40 transition-colors',
                    Number(item.id) === Number(selectedSalaryDetailId) ? 'bg-primary/5' : ''
                  ]"
                >
                  <span>
                    <span class="block text-sm font-medium">Kỳ {{ item.period?.period_code || item.period_id }}</span>
                    <span class="block text-xs text-muted-foreground mt-0.5">Phát hành {{ formatDate(item.payslip_document?.published_at) }}</span>
                  </span>
                  <span class="text-right">
                    <span class="block text-sm font-bold text-primary">{{ formatCurrency(item.net_salary) }}</span>
                    <span class="block text-[11px] text-muted-foreground">{{ salaryDocumentStatusLabel(item.payslip_document) }}</span>
                  </span>
                </button>
              </div>
            </div>

            <!-- Summary cards -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
              <div class="p-4 border rounded-lg">
                <p class="text-sm text-muted-foreground">Lương cơ bản</p>
                <p class="text-2xl font-bold">{{ formatCurrency(salaryTotals.basic) }}</p>
              </div>
              <div class="p-4 border rounded-lg">
                <p class="text-sm text-muted-foreground">Phụ cấp</p>
                <p class="text-2xl font-bold">{{ formatCurrency(salaryTotals.allowances) }}</p>
              </div>
              <div class="p-4 border rounded-lg">
                <p class="text-sm text-muted-foreground">Khấu trừ</p>
                <p class="text-2xl font-bold text-destructive">- {{ formatCurrency(salaryTotals.deductions) }}</p>
              </div>
              <div class="p-4 border rounded-lg bg-primary/5 border-primary/20">
                <p class="text-sm text-muted-foreground">Lương ròng</p>
                <p class="text-2xl font-bold text-primary">{{ formatCurrency(salaryTotals.net) }}</p>
              </div>
            </div>

            <!-- Detail table -->
            <div v-if="salaryDetails.length > 0">
              <h4 class="font-medium text-sm text-muted-foreground mb-3 mt-4">Chi tiết từng thành phần</h4>
              <div class="rounded-lg border overflow-hidden">
                <table class="w-full text-sm">
                  <thead class="bg-muted/50">
                    <tr>
                      <th class="text-left px-4 py-3 font-medium">Thành phần</th>
                      <th class="text-left px-4 py-3 font-medium">Loại</th>
                      <th class="text-right px-4 py-3 font-medium">Số tiền</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="item in salaryDetails" :key="item.id" class="border-t border-border">
                      <td class="px-4 py-3">{{ item.component_name || item.salary_component_name || '-' }}</td>
                      <td class="px-4 py-3">
                        <BaseBadge :variant="item.type === 'deduction' ? 'destructive' : 'success'">
                          {{ item.type === 'deduction' ? 'Khấu trừ' : 'Khoản thu' }}
                        </BaseBadge>
                      </td>
                      <td class="px-4 py-3 text-right font-medium" :class="item.type === 'deduction' ? 'text-destructive' : ''">
                        {{ item.type === 'deduction' ? '-' : '' }}{{ formatCurrency(item.amount) }}
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
            <div v-else class="text-center py-8 text-muted-foreground">Chưa có dữ liệu lương</div>
          </div>
        </BaseCard>
      </div>

      <!-- ===== PROFILE TAB ===== -->
      <div v-if="activeTab === 'profile'">
        <BaseCard>
          <div class="flex items-center justify-between mb-6">
            <h3 class="text-lg font-bold">Thông tin cá nhân</h3>
            <BaseButton v-if="!editingProfile" @click="startEditProfile" variant="outline">
              <svg class="w-4 h-4 mr-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
              </svg>
              Chỉnh sửa
            </BaseButton>
          </div>

          <div v-if="!editingProfile" class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
              <p class="text-sm text-muted-foreground">Mã nhân viên</p>
              <p class="font-medium mt-1">{{ currentEmployee.employee_code || '-' }}</p>
            </div>
            <div>
              <p class="text-sm text-muted-foreground">Họ tên</p>
              <p class="font-medium mt-1">{{ currentEmployee.full_name || '-' }}</p>
            </div>
            <div>
              <p class="text-sm text-muted-foreground">Email</p>
              <p class="font-medium mt-1">{{ currentEmployee.email || '-' }}</p>
            </div>
            <div>
              <p class="text-sm text-muted-foreground">Số điện thoại</p>
              <p class="font-medium mt-1">{{ currentEmployee.phone || '-' }}</p>
            </div>
            <div>
              <p class="text-sm text-muted-foreground">Ngày sinh</p>
              <p class="font-medium mt-1">{{ formatDate(currentEmployee.date_of_birth) || '-' }}</p>
            </div>
            <div>
              <p class="text-sm text-muted-foreground">Giới tính</p>
              <!-- Canonical DB là MALE/FEMALE (hoa) — so sánh không phân biệt hoa thường. -->
              <p class="font-medium mt-1">{{ String(currentEmployee.gender).toUpperCase() === 'MALE' ? 'Nam' : String(currentEmployee.gender).toUpperCase() === 'FEMALE' ? 'Nữ' : '-' }}</p>
            </div>
            <div class="md:col-span-2">
              <p class="text-sm text-muted-foreground">Địa chỉ</p>
              <p class="font-medium mt-1">{{ currentEmployee.address || '-' }}</p>
            </div>
            <div>
              <p class="text-sm text-muted-foreground">Phòng ban</p>
              <p class="font-medium mt-1">{{ currentEmployee.department_name || '-' }}</p>
            </div>
            <div>
              <p class="text-sm text-muted-foreground">Chức vụ</p>
              <p class="font-medium mt-1">{{ currentEmployee.job_title_name || '-' }}</p>
            </div>
            <div>
              <p class="text-sm text-muted-foreground">Ngày vào làm</p>
              <p class="font-medium mt-1">{{ formatDate(currentEmployee.hire_date) || '-' }}</p>
            </div>
            <div>
              <p class="text-sm text-muted-foreground">Trạng thái</p>
              <!-- Bảng employees KHÔNG có cột is_active (luôn undefined → ai cũng "Đã nghỉ").
                   Đọc status thật: ACTIVE/PROBATION là đang làm. -->
              <BaseBadge :variant="['ACTIVE','PROBATION'].includes(String(currentEmployee.status).toUpperCase()) ? 'success' : 'destructive'">
                {{ String(currentEmployee.status).toUpperCase() === 'PROBATION' ? 'Thử việc'
                  : String(currentEmployee.status).toUpperCase() === 'ACTIVE' ? 'Đang làm việc' : 'Đã nghỉ' }}
              </BaseBadge>
            </div>
          </div>

          <!-- Edit form -->
          <div v-else class="space-y-4">
            <p class="text-xs text-muted-foreground">Bạn có thể tự cập nhật các thông tin liên hệ dưới đây. Giấy tờ/MST/ngân hàng do bộ phận Nhân sự quản lý.</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-foreground mb-1">Số điện thoại</label>
                <input v-model="profileForm.phone" type="tel" class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring" />
              </div>
              <div>
                <label class="block text-sm font-medium text-foreground mb-1">Tình trạng hôn nhân</label>
                <select v-model="profileForm.marital_status" class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring">
                  <option value="">--</option>
                  <option value="SINGLE">Độc thân</option>
                  <option value="MARRIED">Đã kết hôn</option>
                  <option value="OTHER">Khác</option>
                </select>
              </div>
              <div class="md:col-span-2">
                <label class="block text-sm font-medium text-foreground mb-1">Địa chỉ</label>
                <input v-model="profileForm.address" type="text" class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring" />
              </div>
              <div class="md:col-span-2">
                <label class="block text-sm font-medium text-foreground mb-1">Quê quán</label>
                <input v-model="profileForm.hometown" type="text" class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring" />
              </div>
            </div>
            <div class="border-t border-border pt-4">
              <p class="text-sm font-semibold mb-3">Liên hệ khẩn cấp</p>
              <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <input v-model="profileForm.emergency_contact_name" placeholder="Họ tên" class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring" />
                <input v-model="profileForm.emergency_contact_relationship" placeholder="Mối quan hệ" class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring" />
                <input v-model="profileForm.emergency_contact_phone" placeholder="Số điện thoại" class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring" />
              </div>
            </div>
            <div v-if="profileError" class="p-3 bg-destructive/10 border border-destructive/20 rounded-lg">
              <p class="text-destructive text-sm">{{ profileError }}</p>
            </div>
            <div class="flex gap-3">
              <BaseButton @click="saveProfile" :disabled="savingProfile">
                {{ savingProfile ? 'Đang lưu...' : 'Lưu thay đổi' }}
              </BaseButton>
              <BaseButton variant="outline" @click="editingProfile = false">Hủy</BaseButton>
            </div>
          </div>
        </BaseCard>

        <!-- Hợp đồng của tôi -->
        <BaseCard class="mt-6">
          <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <h3 class="text-lg font-bold">Hợp đồng lao động</h3>
              <p class="mt-1 text-sm text-muted-foreground">
                {{ myContracts.length ? `${myContracts.length} hợp đồng` : 'Chưa có hợp đồng' }}
                <span v-if="myContracts.some(c => contractSignStatus(c) === 'PENDING_SIGN')" class="font-semibold text-warning"> · Có hợp đồng cần ký</span>
              </p>
            </div>
            <router-link to="/employee-contracts" class="inline-flex min-h-10 items-center justify-center rounded-xl bg-primary px-5 py-2.5 font-medium text-primary-foreground transition-all hover:shadow-md">
              Xem hợp đồng
            </router-link>
          </div>
        </BaseCard>

        <!-- Thông tin nhạy cảm do Nhân sự quản lý — đổi phải làm đơn -->
        <BaseCard class="mt-6">
          <div class="flex items-center justify-between mb-4">
            <div>
              <h3 class="text-lg font-bold">Thông tin do Nhân sự quản lý</h3>
              <p class="text-xs text-muted-foreground mt-1">
                Giấy tờ tùy thân, mã số thuế, tài khoản ngân hàng, BHXH... được nhập từ hồ sơ khi onboarding.
                Để thay đổi, vui lòng gửi đơn đề nghị — Nhân sự sẽ duyệt trước khi cập nhật.
              </p>
            </div>
            <BaseButton variant="outline" @click="openChangeModal" :disabled="!sensitiveFields.length">
              Đề nghị thay đổi
            </BaseButton>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div v-for="f in sensitiveFields" :key="f.key">
              <p class="text-sm text-muted-foreground">{{ f.label }}</p>
              <p class="font-medium mt-1">{{ currentEmployee[f.key] || '—' }}</p>
            </div>
          </div>

          <!-- Đơn đổi thông tin của tôi -->
          <div class="border-t border-border pt-4 mt-6">
            <p class="text-sm font-semibold mb-3">Đơn đổi thông tin của tôi</p>
            <div v-if="!myChangeRequests.length" class="text-sm text-muted-foreground">Chưa có đơn nào.</div>
            <div v-else class="space-y-2">
              <div
                v-for="req in myChangeRequests"
                :key="req.id"
                class="flex items-start justify-between gap-3 border border-border rounded-lg p-3"
              >
                <div class="min-w-0">
                  <div class="flex flex-wrap gap-1.5">
                    <span
                      v-for="(c, key) in req.changes"
                      :key="key"
                      class="text-xs px-2 py-0.5 rounded-full bg-muted text-foreground"
                    >{{ c.label || key }}: {{ c.new }}</span>
                  </div>
                  <p v-if="req.reason" class="text-xs text-muted-foreground mt-1">Lý do: {{ req.reason }}</p>
                  <p v-if="req.status === 'REJECTED' && req.decision_comment" class="text-xs text-red-600 mt-1">Lý do từ chối: {{ req.decision_comment }}</p>
                </div>
                <div class="flex items-center gap-2 flex-shrink-0">
                  <BaseBadge :variant="changeStatusVariant(req.status)">{{ changeStatusText(req.status) }}</BaseBadge>
                  <button
                    v-if="req.status === 'PENDING'"
                    @click="cancelChangeRequest(req)"
                    class="text-xs text-orange-600 hover:underline"
                    :disabled="changeBusy"
                  >Hủy</button>
                </div>
              </div>
            </div>
          </div>
        </BaseCard>
      </div>
    </template>

    <!-- ===== PROFILE CHANGE REQUEST MODAL ===== -->
    <BaseModal v-model="showChangeModal" title="Đề nghị thay đổi thông tin">
      <div class="space-y-4">
        <p class="text-xs text-muted-foreground">
          Nhập giá trị mới cho các trường cần đổi (để trống hoặc giữ nguyên nếu không thay đổi).
          Đơn sẽ được gửi tới Nhân sự để duyệt.
        </p>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div v-for="f in sensitiveFields" :key="f.key">
            <label class="block text-sm font-medium text-foreground mb-1">{{ f.label }}</label>
            <input
              v-model="changeForm[f.key]"
              type="text"
              class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
            />
          </div>
        </div>
        <div>
          <label class="block text-sm font-medium text-foreground mb-1">Lý do thay đổi</label>
          <textarea
            v-model="changeReason"
            rows="3"
            placeholder="Ví dụ: đổi số tài khoản ngân hàng mới..."
            class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
          ></textarea>
        </div>
        <div v-if="changeError" class="p-3 bg-destructive/10 border border-destructive/20 rounded-lg">
          <p class="text-destructive text-sm">{{ changeError }}</p>
        </div>
      </div>
      <template #footer>
        <BaseButton variant="outline" @click="showChangeModal = false" :disabled="changeBusy">Hủy</BaseButton>
        <BaseButton @click="submitChangeRequest" :disabled="changeBusy">
          {{ changeBusy ? 'Đang gửi...' : 'Gửi đơn' }}
        </BaseButton>
      </template>
    </BaseModal>

    <!-- ===== LEAVE REQUEST MODAL ===== -->
    <BaseModal v-model="showLeaveModal" title="Tạo đơn xin nghỉ">
      <div class="space-y-4">
        <div class="p-3 bg-muted rounded-lg">
          <p class="text-sm text-muted-foreground">Nhân viên</p>
          <p class="font-medium">{{ currentEmployee.full_name }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-foreground mb-1">Loại nghỉ <span class="text-destructive">*</span></label>
          <select v-model="leaveForm.leave_type_id" class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring">
            <option value="">-- Chọn loại nghỉ --</option>
            <option v-for="lt in leaveTypes" :key="lt.id" :value="lt.id">{{ lt.name || lt.leave_type_name || lt.leave_type_code }}</option>
          </select>
        </div>
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-foreground mb-1">Từ ngày <span class="text-destructive">*</span></label>
            <input v-model="leaveForm.start_date" type="date" class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring" />
          </div>
          <div>
            <label class="block text-sm font-medium text-foreground mb-1">Đến ngày <span class="text-destructive">*</span></label>
            <input v-model="leaveForm.end_date" type="date" class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring" />
          </div>
        </div>
        <div>
          <label class="block text-sm font-medium text-foreground mb-1">Lý do</label>
          <textarea v-model="leaveForm.reason" class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring" rows="3" placeholder="Nhập lý do xin nghỉ..."></textarea>
        </div>
        <div v-if="leaveFormError" class="p-3 bg-destructive/10 border border-destructive/20 rounded-lg">
          <p class="text-destructive text-sm">{{ leaveFormError }}</p>
        </div>
      </div>
      <template #footer>
        <BaseButton variant="outline" @click="showLeaveModal = false" :disabled="leaveFormLoading">Hủy</BaseButton>
        <BaseButton @click="submitLeaveRequest" :disabled="leaveFormLoading">
          {{ leaveFormLoading ? 'Đang tạo...' : 'Tạo đơn' }}
        </BaseButton>
      </template>
    </BaseModal>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import BaseButton from '../components/BaseButton.vue';
import BaseCard from '../components/BaseCard.vue';
import BaseTable from '../components/BaseTable.vue';
import BaseBadge from '../components/BaseBadge.vue';
import BaseModal from '../components/BaseModal.vue';
import BaseSkeleton from '../components/BaseSkeleton.vue';
import { employeeService } from '../services/employeeService';
import { leaveService } from '../services/leaveService';
import { attendanceService } from '../services/attendanceService';
import { workScheduleService } from '../services/workScheduleService';
import { workShiftService } from '../services/workShiftService';
import { shiftCoverageService } from '../services/shiftCoverageService';
import { onboardingService } from '../services/onboardingService';
import { profileChangeService } from '../services/profileChangeService';
import { contractService } from '../services/contractService';
import { salaryService } from '../services/salaryService';
import { useNotificationStore } from '../stores/notificationStore';
import { downloadCsv } from '../utils/csv';

const notificationStore = useNotificationStore();
const route = useRoute();
const portalTabs = ['attendance', 'roster', 'leaves', 'onboarding', 'salary', 'profile'];
const activeTab = ref(portalTabs.includes(String(route.query.tab)) ? String(route.query.tab) : 'attendance');
const showLeaveModal = ref(false);
const pageLoading = ref(true);

// ── Hợp đồng của tôi (xem + ký OTP) ──
const myContracts = ref([]);

// ── Profile change requests (đơn đổi thông tin nhạy cảm) ──
const sensitiveFields = ref([]);
const myChangeRequests = ref([]);
const showChangeModal = ref(false);
const changeForm = ref({});
const changeReason = ref('');
const changeError = ref('');
const changeBusy = ref(false);

const tabContentRef = ref(null);
const scrollToTabContent = () => {
  setTimeout(() => {
    if (tabContentRef.value) {
      tabContentRef.value.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  }, 50);
};

// ── Employee state ──
const currentEmployee = ref({
  id: null,
  employee_code: '',
  full_name: '',
  email: '',
  phone: '',
  date_of_birth: null,
  gender: '',
  address: '',
  department_name: '',
  job_title_name: '',
  hire_date: null,
  is_active: true,
});

// ── Attendance state ──
const attendanceStats = ref({ present: 0, absent: 0, late: 0 });
const attendanceRecords = ref([]);
const checkInLoading = ref(false);

// ── My roster (Lịch ca của tôi) ──
const myShiftAssignments = ref([]);
const myShiftTypes = ref([]);
const rosterWeekOffset = ref(0); // 0 = tuần này; ±1 = tuần trước/sau
const rosterLoading = ref(false);

const loadMyRoster = async (empId) => {
  rosterLoading.value = true;
  try {
    const [assignRes, typesRes] = await Promise.all([
      workScheduleService.getAll({ employee_id: empId, per_page: 500 }).catch(() => []),
      workShiftService.getAll().catch(() => []),
    ]);
    const assigns = Array.isArray(assignRes) ? assignRes : (assignRes?.data || []);
    // Tự lọc theo NV hiện tại (phòng khi BE không lọc theo employee_id).
    myShiftAssignments.value = assigns.filter((s) => String(s.employee_id) === String(empId));
    myShiftTypes.value = Array.isArray(typesRes) ? typesRes : (typesRes?.data || []);
  } catch (e) {
    console.error('Error loading my roster:', e);
  } finally {
    rosterLoading.value = false;
  }
};

// Phân giải ca cho 1 ngày từ các phân ca effective-dated (chuẩn range-resolve):
// ưu tiên bản ghi-đè 1 ngày (có expiry) hơn ca cố định; cùng loại lấy effective_date mới nhất.
const resolveShiftFor = (dateStr) => {
  const candidates = myShiftAssignments.value.filter((s) =>
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
  const assign = candidates[0];
  return myShiftTypes.value.find((t) => String(t.id) === String(assign.shift_type_id)) || null;
};

const localDateStr = (d) =>
  `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

const shiftTimeRange = (shift) => {
  if (!shift) return '';
  const s = (shift.start_time || '').slice(0, 5);
  const e = (shift.end_time || '').slice(0, 5);
  return s && e ? `${s} - ${e}` : '';
};

const formatDateDay = (date) => date.getDate() + '/' + (date.getMonth() + 1);

const rosterDays = computed(() => {
  const base = new Date();
  base.setHours(0, 0, 0, 0);
  base.setDate(base.getDate() + rosterWeekOffset.value * 7);
  const day = base.getDay();
  const diff = base.getDate() - day + (day === 0 ? -6 : 1); // về Thứ Hai
  const monday = new Date(base.setDate(diff));
  const names = ['Thứ 2', 'Thứ 3', 'Thứ 4', 'Thứ 5', 'Thứ 6', 'Thứ 7', 'Chủ nhật'];
  const todayStr = localDateStr(new Date());
  const days = [];
  for (let i = 0; i < 14; i++) {
    const d = new Date(monday);
    d.setDate(monday.getDate() + i);
    const dateStr = localDateStr(d);
    days.push({
      date: d,
      dateStr,
      dayName: names[i % 7],
      isToday: dateStr === todayStr,
      isWeekend: i % 7 >= 5,
      // Chủ nhật = ngày nghỉ toàn hệ thống (TimePolicy) — không hiện ca dù có
      // phân ca cố định (assignment permanent áp mọi ngày).
      shift: i % 7 === 6 ? null : resolveShiftFor(dateStr),
    });
  }
  return days;
});

const rosterRangeLabel = computed(() => {
  const days = rosterDays.value;
  if (!days.length) return '';
  return `${formatDateDay(days[0].date)} → ${formatDateDay(days[13].date)}`;
});

const todayShift = computed(() => resolveShiftFor(localDateStr(new Date())));

// ── Lời mời phủ ca (coverage) ──
const myCoverageOffers = ref([]);
const coverageBusy = ref(false);
const pendingCoverageOffers = computed(() => myCoverageOffers.value.filter((o) => o.status === 'OFFERED'));

const loadCoverageOffers = async (empId) => {
  try {
    myCoverageOffers.value = await shiftCoverageService.getMyOffers(empId);
  } catch (e) {
    console.error('Error loading coverage offers:', e);
  }
};

const respondCoverage = async (offer, decision) => {
  if (coverageBusy.value) return;
  coverageBusy.value = true;
  try {
    await shiftCoverageService.respond(offer.id, decision);
    notificationStore.addSuccess(
      decision === 'accept'
        ? 'Đã nhận phủ ca — đơn tăng ca đã được tạo, chờ quản lý duyệt'
        : 'Đã từ chối lời mời phủ ca'
    );
    await loadCoverageOffers(currentEmployee.value.id);
  } catch (err) {
    const errs = err.response?.data?.data?.errors;
    notificationStore.addError(errs ? Object.values(errs)[0]?.[0] : (err.response?.data?.message || 'Không thể phản hồi lời mời'));
  } finally {
    coverageBusy.value = false;
  }
};

// ── Leave state ──
const leaveBalances = ref([]);
const leaveRequests = ref([]);
const leaveTypes = ref([]);
// Tên loại nghỉ cho danh sách đơn: đơn chỉ có leave_type_id → tra trong leaveTypes.
const leaveTypeName = (item) => {
  if (item.leave_type) return item.leave_type;
  if (item.leave_type_name) return item.leave_type_name;
  const t = leaveTypes.value.find((x) => String(x.id) === String(item.leave_type_id));
  return t ? (t.name || t.leave_type_name || t.leave_type_code) : `Loại #${item.leave_type_id}`;
};
const leaveForm = ref({ leave_type_id: '', start_date: '', end_date: '', reason: '' });
const leaveFormError = ref('');
const leaveFormLoading = ref(false);

// ── Salary state ──
const salaryDetails = ref([]);
const salaryNet = ref(0);            // net thật từ salary_detail (chuẩn), không tự cộng lại
const salaryPeriodLabel = ref('');   // mã kỳ của phiếu lương đang xem
const salaryHistory = ref([]);
const selectedSalaryDetailId = ref(null);
const salaryDocument = ref(null);
const salaryActionBusy = ref(false);
const salaryDocumentReady = computed(() => salaryDocument.value?.generation_status === 'READY');
const salaryTotals = computed(() => {
  let basic = 0, allowances = 0, deductions = 0;
  salaryDetails.value.forEach(item => {
    const amt = Number(item.amount) || 0;
    if (item.type === 'deduction') {
      deductions += amt;
    } else {
      // Distinguish basic from other earnings by component name
      const name = (item.component_name || '').toLowerCase();
      if (name.includes('cơ bản') || name.includes('co ban') || name.includes('basic')) {
        basic += amt;
      } else {
        allowances += amt;
      }
    }
  });
  const net = salaryNet.value || (basic + allowances - deductions);
  return { basic, allowances, deductions, net };
});

// ── Onboarding / Offboarding (checklist của tôi) ──
const onboardingChecklists = ref([]);
const onboardingBusy = ref(false);
const isTaskDone = (t) => t?.is_done === true || t?.is_done === 't' || t?.is_done === 1;
const checklistProgress = (cl) => {
  const total = (cl.tasks || []).length;
  const done = (cl.tasks || []).filter(isTaskDone).length;
  return { done, total, pct: total ? Math.round((done / total) * 100) : 0 };
};

const loadOnboarding = async (empId) => {
  try {
    const { items } = await onboardingService.getAll({ employee_id: empId });
    const detailed = await Promise.all((items || []).map((c) => onboardingService.get(c.id).catch(() => null)));
    onboardingChecklists.value = detailed.filter(Boolean).map((d) => d?.data || d);
  } catch (e) {
    console.log('Could not load onboarding checklists');
  }
};

const toggleOnboardingTask = async (cl, task) => {
  if (onboardingBusy.value || cl.status === 'CANCELLED') return;
  onboardingBusy.value = true;
  try {
    await onboardingService.updateTask(cl.id, task.id, { is_done: !isTaskDone(task) });
    const fresh = await onboardingService.get(cl.id);
    const updated = fresh?.data || fresh;
    const idx = onboardingChecklists.value.findIndex((c) => c.id === cl.id);
    if (idx !== -1 && updated) onboardingChecklists.value[idx] = updated;
  } catch (e) {
    notificationStore.addError('Không thể cập nhật công việc');
  } finally {
    onboardingBusy.value = false;
  }
};

// ── Action-list (việc cần xử lý) ──
const todayCheckedIn = computed(() => {
  const today = new Date().toLocaleDateString('en-CA'); // local YYYY-MM-DD
  return attendanceRecords.value.some((r) => {
    const d = r.attendance_date || r.work_date || r.record_date || r.date;
    return d && String(d).slice(0, 10) === today;
  });
});
const pendingLeaveCount = computed(() =>
  leaveRequests.value.filter((r) => String(r.status || '').toLowerCase() === 'pending').length
);
const remainingLeave = computed(() => {
  const currentYear = new Date().getFullYear();
  const annual = leaveBalances.value.find((b) =>
    Number(b.year) === currentYear && String(b.leave_type_code || '').toUpperCase() === 'ANNUAL'
  );
  return Number(annual?.remaining ?? 0) || 0;
});
// Chỉ hiện số dư phép NĂM HIỆN TẠI — tránh lẫn số dư năm cũ (vd 2024) trông như trùng.
const visibleLeaveBalances = computed(() => {
  const y = new Date().getFullYear();
  const current = leaveBalances.value.filter((b) => Number(b.year) === y);
  return current.length ? current : leaveBalances.value;
});

// ── Profile edit state ──
const editingProfile = ref(false);
const savingProfile = ref(false);
const profileError = ref('');
const profileForm = ref({ phone: '', address: '', marital_status: '', hometown: '', emergency_contact_name: '', emergency_contact_relationship: '', emergency_contact_phone: '' });

// ── Month filter for attendance ──
const today = new Date();
const attendanceMonth = ref(`${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}`);
const monthOptions = computed(() => {
  const opts = [];
  for (let i = 0; i < 12; i++) {
    const d = new Date(today.getFullYear(), today.getMonth() - i, 1);
    const val = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
    opts.push({ value: val, label: `Tháng ${d.getMonth() + 1}/${d.getFullYear()}` });
  }
  return opts;
});

const workingDaysInMonth = computed(() => {
  const [year, month] = attendanceMonth.value.split('-').map(Number);
  let count = 0;
  const daysInMonth = new Date(year, month, 0).getDate();
  for (let d = 1; d <= daysInMonth; d++) {
    const day = new Date(year, month - 1, d).getDay();
    if (day !== 0 && day !== 6) count++;
  }
  return count;
});

// ── Helpers ──
const getInitials = (name) => {
  if (!name) return '';
  return name.split(' ').map(p => p[0]).join('').toUpperCase().slice(0, 2);
};

const formatCurrency = (value) => {
  return new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(value || 0);
};

const formatDate = (date) => {
  if (!date) return '-';
  return new Date(date).toLocaleDateString('vi-VN');
};

const formatTime = (dt) => {
  if (!dt) return '--:--';
  // check_in_time thường là chuỗi giờ-đơn "HH:MM[:SS]" → new Date() không parse
  // được (ra "Invalid Date"). Lấy HH:MM trực tiếp; nếu là datetime đầy đủ thì parse.
  const s = String(dt);
  const m = s.match(/(\d{1,2}):(\d{2})/);
  if (/^\d{1,2}:\d{2}/.test(s.trim()) && m) return `${m[1].padStart(2, '0')}:${m[2]}`;
  const d = new Date(s);
  return isNaN(d.getTime()) ? (m ? `${m[1].padStart(2, '0')}:${m[2]}` : '--:--')
    : d.toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' });
};

// ── Data loaders ──
const loadEmployeeInfo = async (userId) => {
  // `user` lưu lúc đăng nhập CHÍNH LÀ bản ghi nhân viên (id, full_name,
  // employee_code, department...). Dùng trực tiếp — tránh fallback sai tên/MS NV.
  const stored = JSON.parse(localStorage.getItem('user') || '{}');
  if (stored && String(stored.id) === String(userId) && (stored.full_name || stored.employee_code)) {
    currentEmployee.value = stored;
    // Bổ sung phòng ban/chức danh từ danh sách employees (object login không kèm các field này).
    try {
      const full = await employeeService.getById(userId);
      if (full) currentEmployee.value = { ...stored, ...full };
    } catch (e) { /* giữ thông tin login nếu không lấy được danh sách */ }
    return stored.id;
  }
  try {
    const me = await employeeService.getById(userId);
    if (me) {
      currentEmployee.value = me;
      return me.id;
    }
  } catch (error) {
    console.log('Cannot fetch from employeeService, using fallback info.');
  }
  
  // Fallback: If we couldn't fetch from API (403), use localStorage user data
  try {
    const user = JSON.parse(localStorage.getItem('user') || '{}');
    if (user && user.id) {
      const fallbackEmp = {
        id: user.employee_id || user.id,
        user_id: user.id,
        full_name: user.name || user.email?.split('@')[0] || 'Nhân viên',
        employee_code: `EMP${String(user.id).padStart(4, '0')}`,
        email: user.email,
        job_title_name: 'Nhân viên',
        department_name: 'Khối sản xuất'
      };
      currentEmployee.value = fallbackEmp;
      return fallbackEmp.id;
    }
  } catch(e) {}

  notificationStore.addError('Tài khoản này chưa được liên kết với hồ sơ nhân viên nào!');
  return null;
};

const loadAttendanceData = async (empId) => {
  try {
    const [year, month] = attendanceMonth.value.split('-').map(Number);
    const response = await attendanceService.getAll({
      employee_id: empId,
      month: month,
      year: year
    });
    const raw = Array.isArray(response) ? response
      : (response?.items || response?.data?.items || response?.data || []);
    // Backend hiện KHÔNG lọc theo month/year (không phải cột) → trả toàn bộ lịch sử.
    // Lọc đúng tháng đang chọn ở client để "Tiến độ tháng này" + lịch sử khớp nhãn.
    const records = raw.filter(r => {
      const d = String(r.attendance_date || r.work_date || r.record_date || '').slice(0, 7);
      return d === `${year}-${String(month).padStart(2, '0')}`;
    });
    attendanceRecords.value = records;

    const stats = { present: 0, absent: 0, late: 0 };
    records.forEach(record => {
      if (record.status === 'present') stats.present++;
      else if (record.status === 'absent') stats.absent++;
      else if (record.status === 'late') stats.late++;
    });
    attendanceStats.value = stats;
  } catch (error) {
    console.error('Error loading attendance data:', error);
  }
};

const loadLeaveData = async (empId) => {
  try {
    const [balancesRes, requestsRes, typesRes] = await Promise.all([
      leaveService.getBalances(empId).catch(() => ({ data: [] })),
      leaveService.getAllRequests({ employee_id: empId }).catch(() => ({ data: [] })),
      leaveService.getTypes().catch(() => ({ data: [] })),
    ]);
    // Các service này TRẢ VỀ MẢNG (axiosClient đã bóc 1 lớp data). Nếu bóc thêm
    // .data?.data sẽ luôn undefined → số dư/đơn/loại nghỉ rỗng (hiện 0). Nhận cả 2 dạng.
    const unwrap = (res) => Array.isArray(res) ? res : (res?.data?.data || res?.data || []);
    leaveBalances.value = unwrap(balancesRes);
    leaveRequests.value = unwrap(requestsRes);
    leaveTypes.value = unwrap(typesRes);
  } catch (error) {
    console.error('Error loading leave data:', error);
  }
};

const loadSalaryData = async (empId) => {
  try {
    // /salary-details trả DANH SÁCH kỳ (mới nhất trước) — KHÔNG phải thành phần lương.
    // Lấy kỳ mới nhất rồi gọi payslip để có breakdown thật (BHXH, phụ cấp…).
    const list = await employeeService.getSalaries(empId);
    const rows = (Array.isArray(list) ? list : (list?.items || list?.data || []))
      .sort((a, b) => String(b.period?.period_code || '').localeCompare(String(a.period?.period_code || '')));
    salaryHistory.value = rows;
    if (!rows.length) {
      salaryDetails.value = []; salaryNet.value = 0; salaryPeriodLabel.value = '';
      salaryDocument.value = null; selectedSalaryDetailId.value = null;
      return;
    }
    const requestedId = Number(route.query.payslip || 0);
    const selected = rows.find(item => Number(item.id) === requestedId) || rows[0];
    await selectSalaryPeriod(selected);
  } catch (error) {
    console.error('Error loading salary data:', error);
  }
};

const selectSalaryPeriod = async (summary) => {
  if (!summary?.id) return;
  try {
    selectedSalaryDetailId.value = summary.id;
    salaryPeriodLabel.value = summary.period?.period_code || '';
    salaryNet.value = Number(summary.net_salary) || 0;
    const payslip = await employeeService.getPayslip(summary.id);
    salaryDocument.value = payslip?.document || summary.payslip_document || null;
    const breakdowns = payslip?.breakdowns || payslip?.data?.breakdowns || [];
    // Chỉ hiển thị khoản NV thực nhận/đóng (EARNING + DEDUCTION), bỏ chi phí DN/INFO/NET và dòng 0đ.
    salaryDetails.value = breakdowns
      .filter(b => ['EARNING', 'DEDUCTION'].includes(b.item_type) && Number(b.amount) !== 0)
      .map(b => ({
        id: b.id,
        component_name: b.item_name,
        type: b.item_type === 'DEDUCTION' ? 'deduction' : 'earning',
        amount: Number(b.amount) || 0,
      }));
  } catch (error) {
    console.error('Error loading payslip:', error);
    notificationStore.addError('Không tải được phiếu lương đã chọn');
  }
};

const salaryDocumentStatusLabel = (document) => {
  if (!document) return 'Chưa phát hành';
  if (document.email_status === 'SENT') return 'Đã gửi email';
  if (document.email_status === 'MISSING_RECIPIENT') return 'Chưa gửi: thiếu email';
  if (document.email_status === 'FAILED') return 'PDF sẵn sàng, gửi email lỗi';
  if (document.email_status === 'QUEUED' || document.email_status === 'SENDING') return 'Đang gửi email';
  return document.generation_status === 'READY' ? 'PDF đã phát hành' : 'Đang tạo PDF';
};

const openSalaryBlob = (blob, filename, download) => {
  const url = URL.createObjectURL(blob);
  if (download) {
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    link.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
    return;
  }
  const viewer = window.open(url, '_blank', 'noopener,noreferrer');
  if (!viewer) notificationStore.addError('Trình duyệt đang chặn cửa sổ xem PDF');
  setTimeout(() => URL.revokeObjectURL(url), 60_000);
};

const salaryPdfFilename = () => `Phieu_luong_${currentEmployee.value.employee_code || 'NhanVien'}_${salaryPeriodLabel.value || 'ky'}.pdf`;

const viewSalaryPdf = async () => {
  if (!selectedSalaryDetailId.value || salaryActionBusy.value) return;
  salaryActionBusy.value = true;
  try {
    openSalaryBlob(await salaryService.getPayslipPdf(selectedSalaryDetailId.value), salaryPdfFilename(), false);
  } catch (error) {
    notificationStore.addError(error.response?.data?.message || 'Không mở được PDF phiếu lương');
  } finally {
    salaryActionBusy.value = false;
  }
};

const downloadSalaryPdf = async () => {
  if (!selectedSalaryDetailId.value || salaryActionBusy.value) return;
  salaryActionBusy.value = true;
  try {
    openSalaryBlob(await salaryService.getPayslipPdf(selectedSalaryDetailId.value, true), salaryPdfFilename(), true);
  } catch (error) {
    notificationStore.addError(error.response?.data?.message || 'Không tải được PDF phiếu lương');
  } finally {
    salaryActionBusy.value = false;
  }
};

const emailSalaryPdf = async () => {
  if (!selectedSalaryDetailId.value || salaryActionBusy.value) return;
  salaryActionBusy.value = true;
  try {
    await salaryService.emailPayslip(selectedSalaryDetailId.value);
    salaryDocument.value = { ...salaryDocument.value, email_status: 'QUEUED' };
    notificationStore.addSuccess('Đã đưa phiếu lương vào hàng đợi gửi về email hồ sơ của bạn');
  } catch (error) {
    notificationStore.addError(error.response?.data?.message || 'Không gửi được phiếu lương');
  } finally {
    salaryActionBusy.value = false;
  }
};

// ── Hợp đồng của tôi ──
const contractSignStatus = (c) => {
  const meta = typeof c.meta === 'string' ? (() => { try { return JSON.parse(c.meta); } catch { return {}; } })() : (c.meta || {});
  return meta.sign_status || null;
};

const loadMyContracts = async (empId) => {
  try {
    const res = await contractService.getAll({ employee_id: empId });
    myContracts.value = Array.isArray(res) ? res : (res?.items || res?.data || []);
  } catch (e) {
    console.error('Error loading my contracts:', e);
  }
};

// ── Profile change requests (đơn đổi thông tin nhạy cảm) ──
const loadChangeRequests = async (empId) => {
  try {
    if (!sensitiveFields.value.length) {
      sensitiveFields.value = await profileChangeService.getFields();
    }
    myChangeRequests.value = await profileChangeService.getAll({ employee_id: empId });
  } catch (e) {
    console.error('Error loading profile change requests:', e);
  }
};

const changeStatusText = (s) => ({
  PENDING: 'Chờ duyệt', APPROVED: 'Đã duyệt', REJECTED: 'Từ chối', CANCELLED: 'Đã hủy',
}[s] || s);

const changeStatusVariant = (s) => ({
  PENDING: 'warning', APPROVED: 'success', REJECTED: 'destructive', CANCELLED: 'default',
}[s] || 'default');

const openChangeModal = () => {
  changeError.value = '';
  changeReason.value = '';
  // Prefill with current values so the employee only edits what changes.
  const form = {};
  sensitiveFields.value.forEach(f => { form[f.key] = currentEmployee.value[f.key] || ''; });
  changeForm.value = form;
  showChangeModal.value = true;
};

const submitChangeRequest = async () => {
  changeError.value = '';
  // Send only fields that differ from the current value.
  const changes = {};
  sensitiveFields.value.forEach(f => {
    const next = (changeForm.value[f.key] ?? '').toString().trim();
    const cur = (currentEmployee.value[f.key] ?? '').toString().trim();
    if (next !== cur) changes[f.key] = next;
  });
  if (Object.keys(changes).length === 0) {
    changeError.value = 'Bạn chưa thay đổi trường nào.';
    return;
  }
  changeBusy.value = true;
  try {
    await profileChangeService.create({
      employee_id: currentEmployee.value.id,
      changes,
      reason: changeReason.value,
    });
    notificationStore.addSuccess('Đã gửi đơn đề nghị thay đổi thông tin');
    showChangeModal.value = false;
    await loadChangeRequests(currentEmployee.value.id);
  } catch (err) {
    changeError.value = err.response?.data?.data?.errors?.changes?.[0]
      || err.response?.data?.message || 'Không thể gửi đơn';
  } finally {
    changeBusy.value = false;
  }
};

const cancelChangeRequest = async (req) => {
  if (!confirm('Hủy đơn đề nghị thay đổi này?')) return;
  changeBusy.value = true;
  try {
    await profileChangeService.cancel(req.id, currentEmployee.value.id);
    notificationStore.addSuccess('Đã hủy đơn');
    await loadChangeRequests(currentEmployee.value.id);
  } catch (err) {
    notificationStore.addError(err.response?.data?.message || 'Không thể hủy đơn');
  } finally {
    changeBusy.value = false;
  }
};

// ── Actions ──
const handleCheckIn = async () => {
  if (!currentEmployee.value.id) return;
  checkInLoading.value = true;
  try {
    await attendanceService.checkIn({ employee_id: currentEmployee.value.id });
    notificationStore.addSuccess('Chấm công thành công!');
    await loadAttendanceData(currentEmployee.value.id);
  } catch (error) {
    const msg = error.response?.data?.error || 'Lỗi chấm công';
    if (msg.includes('Already checked in')) {
      notificationStore.addError('Bạn đã chấm công hôm nay rồi!');
    } else {
      notificationStore.addError(msg);
    }
  } finally {
    checkInLoading.value = false;
  }
};

const submitLeaveRequest = async () => {
  leaveFormError.value = '';
  if (!leaveForm.value.leave_type_id) { leaveFormError.value = 'Vui lòng chọn loại nghỉ'; return; }
  if (!leaveForm.value.start_date || !leaveForm.value.end_date) { leaveFormError.value = 'Vui lòng chọn ngày bắt đầu và kết thúc'; return; }
  if (leaveForm.value.end_date < leaveForm.value.start_date) { leaveFormError.value = 'Ngày kết thúc phải sau ngày bắt đầu'; return; }

  leaveFormLoading.value = true;
  try {
    const start = new Date(leaveForm.value.start_date);
    const end = new Date(leaveForm.value.end_date);
    const diffDays = Math.ceil((end - start) / (1000 * 60 * 60 * 24)) + 1;

    await leaveService.createRequest({
      employee_id: currentEmployee.value.id,
      leave_type_id: parseInt(leaveForm.value.leave_type_id),
      start_date: leaveForm.value.start_date,
      end_date: leaveForm.value.end_date,
      reason: leaveForm.value.reason
    });

    notificationStore.addSuccess('Tạo đơn xin nghỉ thành công!');
    showLeaveModal.value = false;
    leaveForm.value = { leave_type_id: '', start_date: '', end_date: '', reason: '' };
    await loadLeaveData(currentEmployee.value.id);
  } catch (error) {
    leaveFormError.value = error.response?.data?.error || error.response?.data?.message || 'Có lỗi xảy ra, vui lòng thử lại';
  } finally {
    leaveFormLoading.value = false;
  }
};

const cancelLeaveRequest = async (request) => {
  if (leaveFormLoading.value) return;
  if (!['pending', 'approved'].includes(request.status)) return;
  if (!confirm('Bạn có chắc chắn muốn hủy đơn nghỉ phép này?')) return;

  leaveFormLoading.value = true;
  try {
    await leaveService.cancel(request.id);
    notificationStore.addSuccess('Hủy đơn nghỉ phép thành công!');
    await loadLeaveData(currentEmployee.value.id);
  } catch (error) {
    notificationStore.addError(error.response?.data?.error || error.response?.data?.message || 'Có lỗi xảy ra khi hủy đơn');
  } finally {
    leaveFormLoading.value = false;
  }
};

const startEditProfile = () => {
  const e = currentEmployee.value;
  profileForm.value = {
    phone: e.phone || e.personal_phone || '',
    address: e.address || '',
    marital_status: (e.marital_status || '').toUpperCase(),
    hometown: e.hometown || '',
    emergency_contact_name: e.emergency_contact_name || '',
    emergency_contact_relationship: e.emergency_contact_relationship || '',
    emergency_contact_phone: e.emergency_contact_phone || '',
  };
  profileError.value = '';
  editingProfile.value = true;
};

const saveProfile = async () => {
  savingProfile.value = true;
  profileError.value = '';
  try {
    const response = await employeeService.update(currentEmployee.value.id, {
      personal_phone: profileForm.value.phone,
      address: profileForm.value.address,
      marital_status: profileForm.value.marital_status,
      hometown: profileForm.value.hometown,
      emergency_contact_name: profileForm.value.emergency_contact_name,
      emergency_contact_relationship: profileForm.value.emergency_contact_relationship,
      emergency_contact_phone: profileForm.value.emergency_contact_phone,
    });
    currentEmployee.value = { ...currentEmployee.value, ...(response.data || response) };
    notificationStore.addSuccess('Cập nhật thông tin thành công!');
    editingProfile.value = false;
  } catch (error) {
    profileError.value = error.response?.data?.error || 'Không thể cập nhật, vui lòng thử lại';
  } finally {
    savingProfile.value = false;
  }
};

// ── Export Salary ──
const sanitizeFilename = (str) =>
  (str || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^\w\s.-]/g, '_').trim();

const exportSalaryCsv = () => {
  try {
    const emp = currentEmployee.value;
    const totals = salaryTotals.value;

    const data = [
      ['PHIẾU LƯƠNG NHÂN VIÊN'],
      [],
      ['Họ tên', emp.full_name || '-'],
      ['Mã nhân viên', emp.employee_code || '-'],
      ['Phòng ban', emp.department_name || '-'],
      ['Chức vụ', emp.job_title_name || '-'],
      ['Ngày xuất', new Date().toLocaleDateString('vi-VN')],
      [],
      ['THÀNH PHẦN LƯƠNG', 'LOẠI', 'SỐ TIỀN (VNĐ)'],
      ...salaryDetails.value.map(item => [
        item.component_name || item.salary_component_name || '-',
        item.type === 'deduction' ? 'Khấu trừ' : 'Khoản thu',
        item.type === 'deduction' ? -Number(item.amount || 0) : Number(item.amount || 0),
      ]),
      [],
      ['Lương cơ bản', '', totals.basic],
      ['Phụ cấp', '', totals.allowances],
      ['Khấu trừ', '', -totals.deductions],
      ['LƯƠNG THỰC LĨNH', '', totals.net],
    ];

    const dateStr = new Date().toISOString().slice(0, 10);
    const fileName = `PhieuLuong_${sanitizeFilename(emp.full_name || 'NhanVien')}_${dateStr}.csv`;
    downloadCsv(data, fileName);
    
    notificationStore.addSuccess('Tải xuống file CSV thành công!');
  } catch (err) {
    console.error('CSV export error:', err);
    notificationStore.addError('Khong the xuat file: ' + (err.message || 'Unknown error'));
  }
};


const exportSalaryPDF = () => {
  const emp = currentEmployee.value;
  const totals = salaryTotals.value;
  const exportDate = new Date().toLocaleDateString('vi-VN', { day: '2-digit', month: '2-digit', year: 'numeric' });

  const rows = salaryDetails.value.map(item => `
    <tr>
      <td style="padding:8px 12px;border-bottom:1px solid #e5e7eb;">${item.component_name || item.salary_component_name || '-'}</td>
      <td style="padding:8px 12px;border-bottom:1px solid #e5e7eb;">
        <span style="padding:2px 8px;border-radius:9999px;font-size:11px;font-weight:600;background:${item.type === 'deduction' ? '#fee2e2' : '#dcfce7'};color:${item.type === 'deduction' ? '#dc2626' : '#16a34a'}">
          ${item.type === 'deduction' ? 'Khấu trừ' : 'Khoản thu'}
        </span>
      </td>
      <td style="padding:8px 12px;border-bottom:1px solid #e5e7eb;text-align:right;font-weight:600;color:${item.type === 'deduction' ? '#dc2626' : '#111827'}">
        ${item.type === 'deduction' ? '-' : ''}${Number(item.amount).toLocaleString('vi-VN')} ₫
      </td>
    </tr>
  `).join('');

  const html = `<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <title>Phiếu lương - ${emp.full_name || 'Nhân viên'}</title>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'Segoe UI', Arial, sans-serif; color: #111827; background: #fff; }
    .page { max-width: 700px; margin: 0 auto; padding: 40px 32px; }
    .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 32px; border-bottom: 3px solid #2563eb; padding-bottom: 20px; }
    .company { font-size: 22px; font-weight: 800; color: #2563eb; letter-spacing: -0.5px; }
    .title-block { text-align: right; }
    .title { font-size: 20px; font-weight: 700; color: #111827; }
    .subtitle { font-size: 12px; color: #6b7280; margin-top: 2px; }
    .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 32px; margin-bottom: 28px; padding: 20px; background: #f9fafb; border-radius: 10px; }
    .info-item label { font-size: 11px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; display: block; margin-bottom: 2px; }
    .info-item span { font-size: 14px; font-weight: 600; color: #111827; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
    thead th { background: #f3f4f6; padding: 10px 12px; text-align: left; font-size: 12px; text-transform: uppercase; color: #6b7280; letter-spacing: 0.5px; }
    thead th:last-child { text-align: right; }
    .summary { background: #eff6ff; border: 2px solid #bfdbfe; border-radius: 10px; padding: 16px 20px; }
    .summary-row { display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 6px; color: #374151; }
    .summary-row.net { border-top: 1px solid #93c5fd; margin-top: 10px; padding-top: 10px; font-size: 16px; font-weight: 800; color: #1d4ed8; }
    .footer { text-align: center; margin-top: 40px; font-size: 11px; color: #9ca3af; }
    @media print { .page { padding: 20px; } }
  </style>
</head>
<body>
  <div class="page">
    <div class="header">
      <div>
        <div class="company">CODEDENNGU</div>
        <div style="font-size:12px;color:#6b7280;margin-top:4px;">Hệ thống Quản lý Nhân sự</div>
      </div>
      <div class="title-block">
        <div class="title">PHIẾU LƯƠNG</div>
        <div class="subtitle">Ngày xuất: ${exportDate}</div>
      </div>
    </div>

    <div class="info-grid">
      <div class="info-item"><label>Họ và tên</label><span>${emp.full_name || '-'}</span></div>
      <div class="info-item"><label>Mã nhân viên</label><span>${emp.employee_code || '-'}</span></div>
      <div class="info-item"><label>Phòng ban</label><span>${emp.department_name || '-'}</span></div>
      <div class="info-item"><label>Chức vụ</label><span>${emp.job_title_name || '-'}</span></div>
    </div>

    <table>
      <thead>
        <tr>
          <th>Thành phần lương</th>
          <th>Loại</th>
          <th style="text-align:right">Số tiền</th>
        </tr>
      </thead>
      <tbody>${rows}</tbody>
    </table>

    <div class="summary">
      <div class="summary-row"><span>Lương cơ bản</span><span>${totals.basic.toLocaleString('vi-VN')} ₫</span></div>
      <div class="summary-row"><span>Phụ cấp</span><span>${totals.allowances.toLocaleString('vi-VN')} ₫</span></div>
      <div class="summary-row"><span>Khấu trừ</span><span style="color:#dc2626">- ${totals.deductions.toLocaleString('vi-VN')} ₫</span></div>
      <div class="summary-row net"><span>Lương thực nhận</span><span>${totals.net.toLocaleString('vi-VN')} ₫</span></div>
    </div>

    <div class="footer">
      <p>Phiếu lương được tạo tự động bởi hệ thống HRM — ${exportDate}</p>
    </div>
  </div>
  <script>window.onload = () => { window.print(); }<\/script>
</body>
</html>`;
  // Blob URL preserves UTF-8 — full Vietnamese diacritics
  const blob = new Blob([html], { type: 'text/html;charset=utf-8' });
  const blobUrl = URL.createObjectURL(blob);
  const printWindow = window.open(blobUrl, '_blank', 'width=800,height=900');
  if (printWindow) printWindow.addEventListener('unload', () => URL.revokeObjectURL(blobUrl));
};

// ── Init ──
onMounted(async () => {
  pageLoading.value = true;
  const user = JSON.parse(localStorage.getItem('user') || '{}');
  if (user.id) {
    const empId = await loadEmployeeInfo(user.id);
    if (empId) {
      await Promise.all([
        loadAttendanceData(empId),
        loadMyRoster(empId),
        loadCoverageOffers(empId),
        loadLeaveData(empId),
        loadSalaryData(empId),
        loadOnboarding(empId),
        loadChangeRequests(empId),
        loadMyContracts(empId),
      ]);
    }
  }
  pageLoading.value = false;
});
</script>
