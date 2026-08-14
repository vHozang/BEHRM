<template>
  <div class="space-y-6">
    <template v-if="issuesOnly">
      <div>
        <h1 class="text-2xl sm:text-3xl font-bold text-foreground">Phiếu lương chưa phát hành</h1>
        <p class="text-sm sm:text-base text-muted-foreground mt-1">Danh sách nhân viên cần bổ sung dữ liệu; chế độ HR không hiển thị số tiền lương.</p>
      </div>
      <BaseCard>
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4">
          <div>
            <p class="font-semibold">Vấn đề cần xử lý</p>
            <p class="text-xs text-muted-foreground">Sau khi cập nhật email, HR có thể bấm gửi lại. Lỗi payroll đã chốt sẽ điều chỉnh ở kỳ sau.</p>
          </div>
          <BaseButton variant="outline" :disabled="issuesLoading" @click="loadIssues">
            {{ issuesLoading ? 'Đang tải...' : 'Tải lại' }}
          </BaseButton>
        </div>
        <BaseSkeleton v-if="issuesLoading" type="table" />
        <div v-else-if="!payslipIssues.length" class="py-10 text-center text-sm text-muted-foreground">Không có phiếu lương cần HR xử lý.</div>
        <div v-else class="overflow-x-auto">
          <table class="w-full text-sm" data-testid="table-payslip-issues-hr">
            <thead><tr class="border-b border-border text-left text-xs uppercase text-muted-foreground">
              <th class="py-2.5 pr-3">Nhân viên</th><th class="px-3 py-2.5">Kỳ</th><th class="px-3 py-2.5">Vấn đề</th><th class="px-3 py-2.5">Hướng xử lý</th><th class="px-3 py-2.5">Trạng thái</th><th class="py-2.5 pl-3"></th>
            </tr></thead>
            <tbody>
              <tr v-for="issue in payslipIssues" :key="issue.id" class="border-b border-border/60 last:border-0 align-top">
                <td class="py-3 pr-3">
                  <p class="font-medium">{{ issue.full_name }}</p>
                  <p class="text-xs text-muted-foreground">{{ issue.employee_code }} · {{ issue.department_name || 'Chưa có phòng ban' }}</p>
                </td>
                <td class="px-3 py-3 font-medium">{{ issue.period_code }}</td>
                <td class="px-3 py-3"><p>{{ issue.message }}</p><p class="mt-1 text-[11px] text-muted-foreground">{{ issue.issue_code }}</p></td>
                <td class="px-3 py-3 text-muted-foreground">{{ issue.resolution_hint || 'Liên hệ Kế toán.' }}</td>
                <td class="px-3 py-3"><span :class="issueBadgeClass(issue.status)">{{ issueStatusLabel(issue.status) }}</span></td>
                <td class="py-3 pl-3 text-right whitespace-nowrap">
                  <router-link :to="`/employees/${issue.employee_id}`" class="text-xs font-semibold text-primary hover:underline">Hồ sơ</router-link>
                  <button v-if="issue.can_retry" class="ml-3 text-xs font-semibold text-primary hover:underline" @click="retryIssue(issue)">Gửi lại</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </BaseCard>
    </template>

    <template v-else>
    <!-- ═══ Header ═══ -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
      <div>
        <h1 class="text-2xl sm:text-3xl font-bold text-foreground">{{ isAdmin ? 'Tính lương' : 'Phiếu lương của tôi' }}</h1>
        <p class="text-sm sm:text-base text-muted-foreground mt-1">
          {{ isAdmin ? 'Bảng lương theo kỳ — engine lương luật Việt Nam' : 'Xem chi tiết lương theo kỳ' }}
        </p>
      </div>
      <div v-if="isAdmin && activeSalaryTab === 'payroll' && details.length" class="flex flex-wrap gap-2 items-center">
        <button
          @click="bulkExportCsv"
          class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-green-200 bg-green-50 text-green-700 text-sm font-medium hover:bg-green-100 transition-colors"
        >
          CSV (Cả kỳ)
        </button>
        <button
          @click="bulkExportPDF"
          :disabled="!periodClosed || !(publicationStatus?.documents?.ready > 0)"
          :title="periodClosed ? 'Tải ZIP các PDF chính thức đã phát hành' : 'Chỉ tải được sau khi kỳ đã chốt và phát hành'"
          class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-blue-200 bg-blue-50 text-blue-700 text-sm font-medium hover:bg-blue-100 transition-colors disabled:cursor-not-allowed disabled:opacity-50"
        >
          PDF ZIP (Cả kỳ)
        </button>
        <button
          @click="exportBankFile"
          data-testid="button-bank-file"
          class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-indigo-200 bg-indigo-50 text-indigo-700 text-sm font-medium hover:bg-indigo-100 transition-colors"
        >
          🏦 File ngân hàng
        </button>
      </div>
    </div>

    <div v-if="salaryTabs.length > 1" class="flex gap-2 overflow-x-auto rounded-xl border border-border bg-card p-2">
      <button
        v-for="tab in salaryTabs"
        :key="tab.value"
        class="whitespace-nowrap rounded-lg px-3 py-2 text-sm font-semibold transition-colors"
        :class="activeSalaryTab === tab.value ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-muted'"
        @click="activeSalaryTab = tab.value"
      >{{ tab.label }}</button>
    </div>

    <template v-if="activeSalaryTab === 'payroll'">

    <!-- ═══ Chọn kỳ + hành động ═══ -->
    <BaseCard>
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 items-end">
        <BaseSelect
          v-model="selectedPeriodId"
          label="Kỳ lương"
          :options="periodOptions"
          data-testid="select-period"
        />
        <div v-if="selectedPeriod" class="pb-1">
          <p class="text-xs text-muted-foreground mb-1">Trạng thái kỳ</p>
          <span :class="periodBadgeClass(selectedPeriod.status)">
            {{ periodStatusVN(selectedPeriod.status) }}
          </span>
          <span class="ml-2 text-xs text-muted-foreground">{{ selectedPeriod.start_date }} → {{ selectedPeriod.end_date }}</span>
        </div>
        <div v-if="isAdmin" class="flex flex-wrap gap-2 lg:col-span-2 lg:justify-end">
          <BaseButton
            variant="outline"
            :disabled="!selectedPeriodId || periodLocked"
            data-testid="button-run-bonus"
            @click="openBonusModal"
          >
            Tạo thưởng đợt
          </BaseButton>
          <BaseButton
            :disabled="!selectedPeriodId || periodLocked || runLoading"
            @click="runPayroll"
            data-testid="button-run-payroll"
          >
            <span v-if="runLoading">{{ runProgress || 'Đang tính…' }}</span>
            <span v-else>⚡ Tính lương kỳ này</span>
          </BaseButton>
          <!-- Maker–checker: kế toán TRÌNH → admin DUYỆT & chốt (không ai tự chốt 1 bước). -->
          <BaseButton
            v-if="!periodPendingClose"
            variant="outline"
            :disabled="!selectedPeriodId || periodLocked || !details.length"
            @click="submitClose"
            data-testid="button-submit-period"
          >
            📤 Trình chốt kỳ
          </BaseButton>
          <template v-else>
            <BaseButton v-if="isFullAdmin" variant="outline" @click="approveClose" data-testid="button-close-period">
              ✅ Duyệt &amp; chốt kỳ
            </BaseButton>
            <BaseButton variant="outline" @click="reopenPeriodFn" data-testid="button-reopen-period">
              ↩ Trả về / Thu hồi
            </BaseButton>
          </template>
          <BaseButton
            v-if="periodClosed"
            :disabled="publicationBusy || !details.length"
            @click="publishPayslips"
            data-testid="button-publish-payslips"
          >
            {{ publicationBusy ? 'Đang phát hành...' : '✉ Phát hành & gửi phiếu' }}
          </BaseButton>
        </div>
      </div>
      <p v-if="isAdmin && periodPendingClose" class="mt-3 text-xs text-amber-600">
        Kỳ đã trình chốt, đang chờ admin duyệt — tạm khóa tính lại. Người trình không thể tự duyệt.
      </p>
      <p v-else-if="isAdmin && periodLocked" class="mt-3 text-xs text-amber-600">
        Kỳ đã {{ periodStatusVN(selectedPeriod?.status).toLowerCase() }} — không thể tính lại hoặc chỉnh sửa (bảo toàn lương đã trả).
      </p>
      <p v-else-if="isAdmin" class="mt-3 text-xs text-muted-foreground">
        „Tính lương" sẽ tự tổng hợp lại bảng công tháng rồi tính: lương theo công → bảo hiểm → giảm trừ → thuế TNCN (5 bậc, luật 2026) → thực nhận.
      </p>
    </BaseCard>

    <BaseCard v-if="isAdmin && periodClosed && publicationStatus" title="Tiến độ phát hành phiếu lương">
      <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 text-center">
        <div class="rounded-lg bg-muted/40 p-3"><p class="text-xs text-muted-foreground">Đủ điều kiện</p><p class="text-xl font-bold">{{ publicationStatus.pass_count || 0 }}</p></div>
        <div class="rounded-lg bg-muted/40 p-3"><p class="text-xs text-muted-foreground">PDF sẵn sàng</p><p class="text-xl font-bold text-blue-600">{{ publicationStatus.documents?.ready || 0 }}</p></div>
        <div class="rounded-lg bg-muted/40 p-3"><p class="text-xs text-muted-foreground">Đã gửi</p><p class="text-xl font-bold text-emerald-600">{{ publicationStatus.documents?.sent || 0 }}</p></div>
        <div class="rounded-lg bg-muted/40 p-3"><p class="text-xs text-muted-foreground">Thiếu email</p><p class="text-xl font-bold text-amber-600">{{ publicationStatus.documents?.missing_email || 0 }}</p></div>
        <div class="rounded-lg bg-muted/40 p-3"><p class="text-xs text-muted-foreground">Lỗi PDF/mail</p><p class="text-xl font-bold text-red-600">{{ (publicationStatus.documents?.pdf_failed || 0) + (publicationStatus.documents?.email_failed || 0) }}</p></div>
        <div class="rounded-lg bg-muted/40 p-3"><p class="text-xs text-muted-foreground">Không pass</p><p class="text-xl font-bold text-red-600">{{ publicationStatus.fail_count || 0 }}</p></div>
      </div>
    </BaseCard>

    <!-- ═══ Thẻ tổng hợp ═══ -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
      <BaseCard>
        <div class="text-center">
          <p class="text-xs sm:text-sm text-muted-foreground">Tổng thu nhập (Gross)</p>
          <p class="text-xl sm:text-2xl font-bold text-green-600 dark:text-green-400 mt-1.5">{{ formatMoney(stats.gross) }}</p>
        </div>
      </BaseCard>
      <BaseCard>
        <div class="text-center">
          <p class="text-xs sm:text-sm text-muted-foreground">Bảo hiểm + Thuế + Khấu trừ</p>
          <p class="text-xl sm:text-2xl font-bold text-red-600 dark:text-red-400 mt-1.5">{{ formatMoney(stats.deductions) }}</p>
        </div>
      </BaseCard>
      <BaseCard>
        <div class="text-center">
          <p class="text-xs sm:text-sm text-muted-foreground">Thực lĩnh (Net)</p>
          <p class="text-xl sm:text-2xl font-bold text-primary mt-1.5">{{ formatMoney(stats.net) }}</p>
        </div>
      </BaseCard>
      <BaseCard>
        <div class="text-center">
          <p class="text-xs sm:text-sm text-muted-foreground">{{ isAdmin ? 'Nhân viên đã tính' : 'Ngày công (thực tế/chuẩn)' }}</p>
          <p class="text-xl sm:text-2xl font-bold text-foreground mt-1.5">
            {{ isAdmin ? details.length : myWorkdays }}
          </p>
        </div>
      </BaseCard>
    </div>

    <!-- ═══ Bảng lương kỳ (admin) ═══ -->
    <BaseCard v-if="isAdmin" :title="`Bảng lương ${selectedPeriod?.period_code || ''}`">
      <BaseSkeleton v-if="loading" type="table" />
      <template v-else>
        <div v-if="!details.length" class="text-center py-10 text-muted-foreground">
          <p class="font-medium">Kỳ này chưa có dữ liệu lương</p>
          <p class="text-sm mt-1">Bấm „⚡ Tính lương kỳ này" để chạy engine cho toàn bộ nhân viên.</p>
        </div>
        <div v-else class="overflow-x-auto">
          <table class="w-full text-sm" data-testid="table-salary-details">
            <thead>
              <tr class="border-b border-border text-left text-xs uppercase text-muted-foreground">
                <th class="py-2.5 pr-3">Nhân viên</th>
                <th class="py-2.5 px-3 text-right">Gross</th>
                <th class="py-2.5 px-3 text-right">Khấu trừ</th>
                <th class="py-2.5 px-3 text-right">Thực lĩnh</th>
                <th class="py-2.5 px-3 text-center">Công</th>
                <th class="py-2.5 px-3 text-center">Tăng ca</th>
                <th class="py-2.5 px-3 text-center">Chuyển khoản</th>
                <th class="py-2.5 px-3 text-center">Phát hành</th>
                <th class="py-2.5 pl-3"></th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="d in details"
                :key="d.id"
                class="border-b border-border/60 last:border-0 hover:bg-muted/40 transition-colors"
              >
                <td class="py-2.5 pr-3">
                  <p class="font-medium text-foreground">{{ d.employee?.full_name || '—' }}</p>
                  <p class="text-xs text-muted-foreground">{{ d.employee?.employee_code }}</p>
                </td>
                <td class="py-2.5 px-3 text-right font-medium text-green-600 dark:text-green-400">{{ formatMoney(d.gross_salary) }}</td>
                <td class="py-2.5 px-3 text-right text-red-600 dark:text-red-400">−{{ formatMoney(d.gross_salary - d.net_salary) }}</td>
                <td class="py-2.5 px-3 text-right font-semibold text-primary">{{ formatMoney(d.net_salary) }}</td>
                <td class="py-2.5 px-3 text-center">
                  <span v-if="prorationDays(d)" class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-amber-50 text-amber-700 border border-amber-200" :title="d.proration">
                    {{ prorationDays(d) }}
                  </span>
                  <span v-else class="text-xs text-muted-foreground">Đủ công</span>
                </td>
                <td class="py-2.5 px-3 text-center text-xs">
                  <span v-if="Number(d.overtime_hours) > 0" class="font-medium">{{ Number(d.overtime_hours) }}h</span>
                  <span v-else class="text-muted-foreground">—</span>
                </td>
                <td class="py-2.5 px-3 text-center">
                  <span :class="transferBadgeClass(d.transfer_status)">{{ transferStatusVN(d.transfer_status) }}</span>
                </td>
                <td class="py-2.5 px-3 text-center">
                  <span :class="documentBadgeClass(d.payslip_document)">{{ documentStatusLabel(d.payslip_document) }}</span>
                </td>
                <td class="py-2.5 pl-3 text-right">
                  <button
                    @click="openPayslip(d)"
                    class="text-primary text-xs font-semibold hover:underline whitespace-nowrap"
                    :data-testid="`button-payslip-${d.id}`"
                  >
                    Phiếu lương →
                  </button>
                  <button v-if="d.payslip_document?.generation_status === 'READY'" @click="viewOfficialPdf(d.id)" class="ml-2 text-primary text-xs font-semibold hover:underline">PDF</button>
                  <button v-if="d.payslip_document?.generation_status === 'READY'" @click="downloadOfficialPdf(d)" class="ml-2 text-primary text-xs font-semibold hover:underline">Tải</button>
                  <button v-if="d.payslip_document?.generation_status === 'READY' && d.payslip_document?.email_status !== 'SENT'" @click="emailOfficialPayslip(d.id)" class="ml-2 text-primary text-xs font-semibold hover:underline">Gửi</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </BaseCard>

    <!-- ═══ Phiếu lương của tôi (nhân viên) ═══ -->
    <BaseCard v-if="!isAdmin">
      <BaseSkeleton v-if="loading" type="block" />
      <template v-else>
        <div v-if="!myDetail" class="text-center py-10 text-muted-foreground">
          <p class="font-medium">Kỳ này chưa có phiếu lương của bạn</p>
          <p class="text-sm mt-1">Lương sẽ hiển thị sau khi phòng nhân sự chạy tính lương kỳ.</p>
        </div>
        <div v-else class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
          <div>
            <p class="font-semibold text-lg">{{ currentUser?.full_name }}</p>
            <p class="text-sm text-muted-foreground">Kỳ {{ selectedPeriod?.period_code }} · Thực lĩnh
              <span class="font-bold text-primary">{{ formatMoney(myDetail.net_salary) }}</span>
            </p>
          </div>
          <BaseButton @click="openPayslip(myDetail)">Xem phiếu lương chi tiết</BaseButton>
        </div>
      </template>
    </BaseCard>

    <!-- ═══ Lịch sử lương (nhân viên) ═══ -->
    <BaseCard v-if="!isAdmin && history.length" title="Lịch sử lương">
      <BaseTable :columns="historyColumns" :data="history" data-testid="table-salary-history">
        <template #cell-period="{ row }">
          <span class="font-medium">{{ row.period?.period_code || row.period_id }}</span>
        </template>
        <template #cell-gross_salary="{ value }">
          <span class="text-green-600 dark:text-green-400">{{ formatMoney(value) }}</span>
        </template>
        <template #cell-deduction="{ row }">
          <span class="text-red-600 dark:text-red-400">−{{ formatMoney(row.gross_salary - row.net_salary) }}</span>
        </template>
        <template #cell-net_salary="{ value }">
          <span class="font-semibold text-primary">{{ formatMoney(value) }}</span>
        </template>
      </BaseTable>
    </BaseCard>

    <!-- ═══ Modal phiếu lương chi tiết ═══ -->
    <BaseModal v-model="bonusOpen" title="Tạo thưởng đợt">
      <div class="space-y-4">
        <div class="rounded-lg border border-border bg-muted/30 p-3 text-sm">
          <p class="text-muted-foreground">Kỳ lương đang chọn</p>
          <p class="font-semibold">{{ selectedPeriod?.period_code || '—' }} · {{ periodStatusVN(selectedPeriod?.status) }}</p>
        </div>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <label class="text-sm font-medium">Từ ngày<input v-model="bonusForm.window_start" type="date" class="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2" /></label>
          <label class="text-sm font-medium">Đến ngày<input v-model="bonusForm.window_end" type="date" class="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2" /></label>
        </div>
        <label class="block text-sm font-medium">Tỷ lệ thưởng (%)<input v-model.number="bonusForm.rate_percent" type="number" min="1" max="300" step="1" class="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2" /></label>
        <p class="text-xs text-muted-foreground">Mặc định 50%. Chạy lại cùng khoảng thời gian sẽ thay batch cũ, không nhân đôi.</p>
        <div v-if="bonusResult" class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
          <p class="font-semibold">Đã tạo thưởng cho {{ bonusResult.employees || 0 }} nhân viên</p>
          <p class="mt-1">Tổng thưởng: <strong>{{ formatMoney(bonusResult.total) }}</strong></p>
          <p class="mt-2 text-xs">{{ bonusResult.note || 'Hãy chạy lại tính lương để cập nhật bảng lương.' }}</p>
        </div>
      </div>
      <template #footer>
        <BaseButton variant="outline" :disabled="bonusLoading" @click="bonusOpen = false">Đóng</BaseButton>
        <BaseButton :disabled="bonusLoading || periodLocked" @click="runBonus">{{ bonusLoading ? 'Đang tạo...' : 'Tạo thưởng đợt' }}</BaseButton>
      </template>
    </BaseModal>

    <BaseModal v-model="payslipOpen" :title="`Phiếu lương — ${payslip?.salary_detail?.employee?.full_name || ''}`" size="lg">
      <BaseSkeleton v-if="payslipLoading" type="block" />
      <div v-else-if="payslip" class="space-y-5">
        <!-- Header kiểu ADMS: công ty + tiêu đề config + kỳ từ→đến -->
        <div class="text-center border-b border-border pb-3">
          <p class="font-bold">{{ payslip.legal_entity?.name || '' }}</p>
          <p class="font-extrabold text-primary mt-0.5">{{ payslipConfig.title || 'PHIẾU LƯƠNG THÁNG' }} {{ payslip.salary_detail?.period?.period_code }}</p>
          <p class="text-xs text-muted-foreground" v-if="payslip.salary_detail?.period?.start_date">
            Từ {{ new Date(payslip.salary_detail.period.start_date).toLocaleDateString('vi-VN') }}
            đến {{ new Date(payslip.salary_detail.period.end_date).toLocaleDateString('vi-VN') }}
          </p>
        </div>
        <div class="flex flex-wrap items-center gap-2 text-sm">
          <span class="font-medium">{{ payslip.salary_detail?.employee?.full_name }}</span>
          <span class="text-muted-foreground">· Mã NV {{ payslip.salary_detail?.employee?.employee_code }}</span>
          <span class="text-muted-foreground" v-if="payslipMeta.base_salary">· Lương cơ bản {{ formatMoney(payslipMeta.base_salary) }}</span>
          <span v-if="payslipMeta.engine" class="ml-auto text-xs text-muted-foreground">engine: {{ payslipMeta.engine }}</span>
        </div>

        <!-- Công tháng -->
        <div v-if="payslip.attendance_summary" class="grid grid-cols-3 sm:grid-cols-5 gap-2 p-3 rounded-xl bg-muted/50 text-center">
          <div><p class="text-[11px] text-muted-foreground">Công chuẩn</p><p class="font-semibold">{{ num(payslip.attendance_summary.standard_days) }}</p></div>
          <div><p class="text-[11px] text-muted-foreground">Đi làm</p><p class="font-semibold">{{ num(payslip.attendance_summary.actual_working_days) }}</p></div>
          <div><p class="text-[11px] text-muted-foreground">Nghỉ phép</p><p class="font-semibold">{{ num(payslip.attendance_summary.paid_leave_days) }}</p></div>
          <div><p class="text-[11px] text-muted-foreground">Vắng</p><p class="font-semibold text-amber-600">{{ num(payslipMeta.proration_absent_days ?? summaryMeta.absent_days ?? 0) }}</p></div>
          <div><p class="text-[11px] text-muted-foreground">Tăng ca</p><p class="font-semibold">{{ num(payslip.attendance_summary.overtime_hours) }}h</p></div>
        </div>

        <!-- Thu nhập -->
        <div>
          <h4 class="text-xs font-bold uppercase text-muted-foreground mb-2">Thu nhập</h4>
          <div class="space-y-1.5">
            <div v-for="b in earningRows" :key="b.item_code" class="flex justify-between text-sm py-1 border-b border-border/50 last:border-0">
              <span>
                {{ b.item_name }}
                <span v-if="b.item_code === 'BASE' && payslipMeta.proration_factor < 1" class="ml-1 text-xs text-amber-600">({{ payslipMeta.proration }})</span>
              </span>
              <span class="font-medium text-green-600 dark:text-green-400">+{{ formatMoney(b.amount) }}</span>
            </div>
            <div v-if="Number(payslipMeta.overtime_premium_pay) > 0 && payslipMeta.overtime_premium_tax_exempt" class="flex justify-between text-xs text-muted-foreground pl-3">
              <span>↳ trong đó phụ trội tăng ca <span class="text-green-600 font-medium">miễn thuế TNCN</span> (luật 01/07/2026)</span>
              <span>{{ formatMoney(payslipMeta.overtime_premium_pay) }}</span>
            </div>
          </div>
        </div>

        <!-- Khấu trừ -->
        <div>
          <h4 class="text-xs font-bold uppercase text-muted-foreground mb-2">Khấu trừ</h4>
          <div class="space-y-1.5">
            <div v-for="b in deductionRows" :key="b.item_code" class="flex justify-between text-sm py-1 border-b border-border/50 last:border-0">
              <span>{{ b.item_name }}</span>
              <span class="font-medium text-red-600 dark:text-red-400">−{{ formatMoney(b.amount) }}</span>
            </div>
          </div>
        </div>

        <!-- Căn cứ thuế -->
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-2 p-3 rounded-xl bg-blue-50 dark:bg-blue-950/30 text-center">
          <div><p class="text-[11px] text-muted-foreground">Thu nhập chịu thuế</p><p class="font-semibold">{{ formatMoney(payslipMeta.taxable_income || 0) }}</p></div>
          <div><p class="text-[11px] text-muted-foreground">Giảm trừ gia cảnh</p><p class="font-semibold">{{ formatMoney(payslipMeta.personal_relief || 0) }}</p></div>
          <div><p class="text-[11px] text-muted-foreground">Người phụ thuộc</p><p class="font-semibold">{{ payslipMeta.dependents ?? 0 }}</p></div>
        </div>

        <!-- Nền BHXH (config) -->
        <div v-if="payslipConfig.show_insurance_base !== false && infoRow('INS_BASE')" class="flex justify-between text-xs text-muted-foreground px-1">
          <span>Nền đóng BHXH (lương + phụ cấp tính chất lương)</span>
          <span class="font-medium">{{ formatMoney(infoRow('INS_BASE').amount) }}</span>
        </div>

        <!-- NET -->
        <div class="flex justify-between items-center p-4 rounded-xl bg-primary/10 border border-primary/20">
          <span class="font-bold">THỰC LĨNH (NET)</span>
          <span class="text-2xl font-extrabold text-primary">{{ formatMoney(payslip.salary_detail?.net_salary) }}</span>
        </div>

        <!-- Lời cảm ơn custom theo công ty -->
        <p v-if="payslipConfig.footer" class="text-center text-xs italic text-blue-600 dark:text-blue-400">{{ payslipConfig.footer }}</p>

        <!-- Chi phí DN (config, mặc định ẩn) -->
        <details v-if="employerRows.length && payslipConfig.show_employer_cost" class="text-sm">
          <summary class="cursor-pointer text-xs font-semibold text-muted-foreground hover:text-foreground">Chi phí doanh nghiệp đóng (BHXH/BHYT/BHTN) ▾</summary>
          <div class="mt-2 space-y-1.5 pl-2">
            <div v-for="b in employerRows" :key="b.item_code" class="flex justify-between py-0.5 text-muted-foreground">
              <span>{{ b.item_name }}</span><span>{{ formatMoney(b.amount) }}</span>
            </div>
          </div>
        </details>
      </div>
      <template #footer>
        <BaseButton variant="outline" @click="exportPayslipCsv" :disabled="!payslip">Xuất CSV</BaseButton>
        <BaseButton v-if="payslip?.document?.generation_status === 'READY'" variant="outline" @click="viewOfficialPdf(payslip.salary_detail.id)">Xem PDF chính thức</BaseButton>
        <BaseButton v-else variant="outline" @click="exportPayslipPDF" :disabled="!payslip">In bản xem trước</BaseButton>
        <BaseButton v-if="payslip?.document?.generation_status === 'READY'" variant="outline" @click="emailOfficialPayslip(payslip.salary_detail.id)">Gửi về email</BaseButton>
        <BaseButton variant="ghost" @click="payslipOpen = false">Đóng</BaseButton>
      </template>
    </BaseModal>

    <BaseModal v-model="readinessOpen" title="Kiểm tra trước khi chốt kỳ" size="lg">
      <div v-if="readinessResult" class="space-y-4">
        <div class="grid grid-cols-3 gap-3 text-center">
          <div class="rounded-lg bg-muted/40 p-3"><p class="text-xs text-muted-foreground">Tổng nhân viên</p><p class="text-xl font-bold">{{ readinessResult.total_employees }}</p></div>
          <div class="rounded-lg bg-emerald-50 p-3 text-emerald-800"><p class="text-xs">Pass</p><p class="text-xl font-bold">{{ readinessResult.pass_count }}</p></div>
          <div class="rounded-lg bg-red-50 p-3 text-red-800"><p class="text-xs">Không pass</p><p class="text-xl font-bold">{{ readinessResult.fail_count }}</p></div>
        </div>
        <div v-if="readinessResult.issues?.length" class="max-h-80 overflow-y-auto rounded-lg border border-border">
          <table class="w-full text-sm"><thead class="sticky top-0 bg-card"><tr class="border-b border-border text-left"><th class="p-3">Nhân viên</th><th class="p-3">Lỗi</th><th class="p-3">Hướng xử lý</th></tr></thead>
            <tbody><tr v-for="issue in readinessResult.issues" :key="`${issue.employee_id}-${issue.issue_code}`" class="border-b border-border/60 last:border-0 align-top">
              <td class="p-3"><p class="font-medium">{{ issue.full_name }}</p><p class="text-xs text-muted-foreground">{{ issue.employee_code }} · {{ issue.department_name || '-' }}</p></td>
              <td class="p-3">
                <div class="flex flex-wrap items-center gap-2">
                  <p>{{ issue.message }}</p>
                  <span v-if="issue.can_override === false" class="rounded-full bg-red-100 px-2 py-0.5 text-[10px] font-bold uppercase text-red-700">Bắt buộc xử lý</span>
                </div>
                <p class="mt-1 text-[11px] text-muted-foreground">{{ issue.issue_code }}</p>
              </td>
              <td class="p-3 text-muted-foreground">{{ issue.resolution_hint }}</td>
            </tr></tbody>
          </table>
        </div>
        <p v-if="readinessResult.fail_count" class="text-sm text-amber-700">Nhân viên pass vẫn có thể nhận phiếu. Danh sách không pass sẽ được lưu để HR/Kế toán xử lý ở kỳ sau.</p>
        <div v-if="readinessResult.has_non_bypassable_issues" class="rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-800" data-testid="readiness-non-bypassable-warning">
          Review chấm công chưa xử lý, OT thiếu punch hoặc khấu trừ vượt thực nhận phải được sửa trước. Tùy chọn <code>allow_partial</code> không thể bỏ qua các lỗi này.
        </div>
      </div>
      <template #footer>
        <BaseButton variant="outline" @click="readinessOpen = false">Hủy</BaseButton>
        <BaseButton :disabled="readinessResult?.has_non_bypassable_issues" @click="confirmReadinessAction">
          {{ readinessResult?.fail_count ? 'Xác nhận với danh sách loại trừ' : 'Xác nhận' }}
        </BaseButton>
      </template>
    </BaseModal>
    </template>

    <SalaryPeriodsPanel
      v-else-if="activeSalaryTab === 'periods'"
      :periods="periods"
      @reload="loadPeriods"
    />
    <PayrollAdjustmentsPanel
      v-else-if="activeSalaryTab === 'adjustments'"
      :periods="periods"
    />
    <InsuranceClaimsPanel v-else-if="activeSalaryTab === 'insurance'" />
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import BaseCard from '../components/BaseCard.vue';
import BaseButton from '../components/BaseButton.vue';
import BaseSelect from '../components/BaseSelect.vue';
import BaseTable from '../components/BaseTable.vue';
import BaseModal from '../components/BaseModal.vue';
import BaseSkeleton from '../components/BaseSkeleton.vue';
import SalaryPeriodsPanel from '../components/SalaryPeriodsPanel.vue';
import PayrollAdjustmentsPanel from '../components/PayrollAdjustmentsPanel.vue';
import InsuranceClaimsPanel from '../components/InsuranceClaimsPanel.vue';
import { salaryService } from '../services/salaryService';
import { authService } from '../services/authService';
import { useNotificationStore } from '../stores/notificationStore';
import { downloadCsv } from '../utils/csv';

const notificationStore = useNotificationStore();
const isAdmin = computed(() => authService.isAdmin());
const canManagePayroll = computed(() => authService.canAccessModule('payroll'));
const issuesOnly = computed(() => isAdmin.value && !canManagePayroll.value && authService.hasCapability('payslip_issues.view'));
const currentUser = computed(() => authService.getUser());
// user lưu trong localStorage chính là bản ghi employee → id = employee_id
const selfEmployeeId = computed(() => currentUser.value?.employee_id || currentUser.value?.id || null);

// ── State ──
const periods = ref([]);
const selectedPeriodId = ref('');
const details = ref([]);        // salary_details của kỳ đang chọn (meta đã flatten)
const history = ref([]);        // NV: chi tiết lương của mình qua các kỳ
const loading = ref(false);
const runLoading = ref(false);
const runProgress = ref('');
const bonusOpen = ref(false);
const bonusLoading = ref(false);
const bonusResult = ref(null);
const bonusForm = ref({ window_start: '', window_end: '', rate_percent: 50 });

const payslipOpen = ref(false);
const payslipLoading = ref(false);
const payslip = ref(null);      // { salary_detail, breakdowns, attendance_summary }
const readinessOpen = ref(false);
const readinessResult = ref(null);
const readinessAction = ref('');
const publicationBusy = ref(false);
const publicationStatus = ref(null);
const payslipIssues = ref([]);
const issuesLoading = ref(false);
const activeSalaryTab = ref('payroll');

// Trạng thái kỳ khóa tính lại (đồng bộ với PayrollRunService::LOCKED_PERIOD_STATUSES)
const LOCKED_STATUSES = ['CLOSED', 'LOCKED', 'PAID', 'ĐÃ_ĐÓNG', 'DA_DONG', 'ĐÃ_TRẢ', 'DA_TRA', 'CHỜ_DUYỆT'];

const selectedPeriod = computed(() => periods.value.find(p => String(p.id) === String(selectedPeriodId.value)) || null);
const periodLocked = computed(() => selectedPeriod.value && LOCKED_STATUSES.includes(String(selectedPeriod.value.status)));
// Maker–checker: kỳ đã trình chốt, chờ admin duyệt.
const periodPendingClose = computed(() => String(selectedPeriod.value?.status) === 'CHỜ_DUYỆT');
const periodClosed = computed(() => ['CLOSED', 'LOCKED', 'PAID', 'ĐÃ_ĐÓNG', 'DA_DONG', 'ĐÃ_TRẢ', 'DA_TRA'].includes(String(selectedPeriod.value?.status)));
const isFullAdmin = computed(() => authService.getAccess().full === true);
const salaryTabs = computed(() => {
  const rows = [{ value: 'payroll', label: 'Bảng lương' }];
  if (authService.hasCapability('payroll.periods.manage')) rows.push({ value: 'periods', label: 'Kỳ lương' });
  if (authService.hasCapability('payroll.adjustments.create') || authService.hasCapability('payroll.adjustments.approve')) {
    rows.push({ value: 'adjustments', label: 'Điều chỉnh lương' });
  }
  if (authService.hasCapability('insurance.review') || authService.hasCapability('insurance.pay')) rows.push({ value: 'insurance', label: 'Claim bảo hiểm' });
  return rows;
});

const periodOptions = computed(() => {
  // Đa pháp nhân: cùng mã kỳ lặp theo pháp nhân → chỉ thêm tên pháp nhân khi có nhiều pháp nhân.
  const multiEntity = new Set(periods.value.map(p => p.legal_entity_id)).size > 1;
  return [
    { label: 'Chọn kỳ lương', value: '' },
    ...periods.value.map(p => ({
      label: `${p.period_code} — ${periodStatusVN(p.status)}`
        + (multiEntity && p.legal_entity_name ? ` · ${p.legal_entity_name}` : ''),
      value: String(p.id),
    })),
  ];
});

const stats = computed(() => {
  const rows = isAdmin.value ? details.value : (myDetail.value ? [myDetail.value] : []);
  const gross = rows.reduce((s, d) => s + Number(d.gross_salary || 0), 0);
  const net = rows.reduce((s, d) => s + Number(d.net_salary || 0), 0);
  return { gross, net, deductions: gross - net };
});

const myDetail = computed(() =>
  isAdmin.value ? null : details.value.find(d => Number(d.employee_id) === Number(selfEmployeeId.value)) || details.value[0] || null
);

const myWorkdays = computed(() => {
  const d = myDetail.value;
  if (!d) return '—';
  const std = Number(d.standard_days ?? 0);
  // meta flatten: proration_absent_days có sẵn trên item
  const absent = Number(d.proration_absent_days ?? 0);
  if (!std && !absent) return '—';
  return `${Math.max(0, std - absent)}/${std || '?'}`;
});

const historyColumns = [
  { key: 'period', label: 'Kỳ' },
  { key: 'gross_salary', label: 'Thu nhập' },
  { key: 'deduction', label: 'Khấu trừ' },
  { key: 'net_salary', label: 'Thực lĩnh' },
];

// ── Payslip computed (breakdowns từ engine) ──
const payslipMeta = computed(() => {
  let m = payslip.value?.salary_detail?.meta;
  if (typeof m === 'string') { try { m = JSON.parse(m); } catch { m = {}; } }
  return m || {};
});
const summaryMeta = computed(() => {
  let m = payslip.value?.attendance_summary?.meta;
  if (typeof m === 'string') { try { m = JSON.parse(m); } catch { m = {}; } }
  return m || {};
});
const breakdowns = computed(() => payslip.value?.breakdowns || []);
// Config phiếu lương theo công ty (Settings → payslip.*); INFO row theo mã.
const payslipConfig = computed(() => payslip.value?.config || {});
const infoRow = (code) => breakdowns.value.find(b => b.item_type === 'INFO' && b.item_code === code && Number(b.amount) > 0);
const earningRows = computed(() => breakdowns.value.filter(b => b.item_type === 'EARNING' && Number(b.amount) > 0));
const deductionRows = computed(() => breakdowns.value.filter(b => b.item_type === 'DEDUCTION' && Number(b.amount) > 0));
const employerRows = computed(() => breakdowns.value.filter(b => b.item_type === 'EMPLOYER_COST' && Number(b.amount) > 0));

// ── Helpers hiển thị ──
const formatMoney = (amount) =>
  new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND', maximumFractionDigits: 0 }).format(Number(amount) || 0);
const num = (v) => {
  const n = Number(v);
  return Number.isFinite(n) ? (Number.isInteger(n) ? n : n.toFixed(1)) : '—';
};

const periodStatusVN = (s) => ({
  OPEN: 'Đang mở', PAID: 'Đã trả lương', CLOSED: 'Đã chốt', LOCKED: 'Đã khóa',
  'CHỜ_DUYỆT': 'Chờ duyệt chốt',
}[String(s)] || s || '—');
const periodBadgeClass = (s) => {
  const base = 'inline-block px-2.5 py-1 rounded-full text-xs font-semibold ';
  if (String(s) === 'OPEN') return base + 'bg-green-50 text-green-700 border border-green-200';
  if (String(s) === 'PAID') return base + 'bg-blue-50 text-blue-700 border border-blue-200';
  return base + 'bg-gray-100 text-gray-600 border border-gray-200';
};
const transferStatusVN = (s) => ({ PENDING: 'Chờ chuyển', PAID: 'Đã chuyển', TRANSFERRED: 'Đã chuyển', FAILED: 'Lỗi' }[String(s)] || s || '—');
const transferBadgeClass = (s) => {
  const base = 'inline-block px-2 py-0.5 rounded-full text-[11px] font-medium ';
  if (['PAID', 'TRANSFERRED'].includes(String(s))) return base + 'bg-green-50 text-green-700';
  if (String(s) === 'FAILED') return base + 'bg-red-50 text-red-700';
  return base + 'bg-amber-50 text-amber-700';
};
const documentStatusLabel = (document) => {
  if (!document) return 'Chưa phát hành';
  if (document.generation_status === 'FAILED') return 'Lỗi PDF';
  if (['PENDING', 'PROCESSING'].includes(document.generation_status)) return 'Đang tạo';
  if (document.email_status === 'SENT') return 'Đã gửi';
  if (document.email_status === 'MISSING_RECIPIENT') return 'Thiếu email';
  if (document.email_status === 'FAILED') return 'Lỗi email';
  if (document.generation_status === 'READY') return 'PDF sẵn sàng';
  return 'Chưa phát hành';
};
const documentBadgeClass = (document) => {
  const base = 'inline-block px-2 py-0.5 rounded-full text-[11px] font-medium ';
  const label = documentStatusLabel(document);
  if (label === 'Đã gửi') return base + 'bg-emerald-50 text-emerald-700';
  if (['Lỗi PDF', 'Lỗi email'].includes(label)) return base + 'bg-red-50 text-red-700';
  if (['Thiếu email', 'Đang tạo'].includes(label)) return base + 'bg-amber-50 text-amber-700';
  if (label === 'PDF sẵn sàng') return base + 'bg-blue-50 text-blue-700';
  return base + 'bg-muted text-muted-foreground';
};
const issueStatusLabel = (status) => ({ OPEN: 'Cần xử lý', DEFERRED: 'Điều chỉnh kỳ sau', RESOLVED: 'Đã xử lý' }[status] || status);
const issueBadgeClass = (status) => {
  const base = 'inline-block px-2 py-0.5 rounded-full text-[11px] font-medium ';
  if (status === 'RESOLVED') return base + 'bg-emerald-50 text-emerald-700';
  if (status === 'DEFERRED') return base + 'bg-amber-50 text-amber-700';
  return base + 'bg-red-50 text-red-700';
};
// "PRORATED 22/26 (…)" → "22/26" ; đủ công → null
const prorationDays = (d) => {
  const label = d.proration || '';
  const m = /PRORATED\s+([\d.]+\/[\d.]+)/.exec(label);
  return m ? m[1] : null;
};

// ── Data loading ──
const loadPeriods = async () => {
  const res = await salaryService.getPeriods();
  const list = Array.isArray(res) ? res : (res?.items || res?.data || []);
  periods.value = list;
  // Mặc định chọn kỳ mở gần nhất, không có thì kỳ mới nhất
  if (!selectedPeriodId.value && list.length) {
    const open = list.find(p => String(p.status) === 'OPEN');
    selectedPeriodId.value = String((open || list[0]).id);
  }
};

const loadDetails = async () => {
  if (!selectedPeriodId.value) { details.value = []; return; }
  loading.value = true;
  try {
    const params = { period_id: selectedPeriodId.value, per_page: 100 };
    if (!isAdmin.value) params.employee_id = selfEmployeeId.value;
    const res = await salaryService.getDetails(params);
    details.value = Array.isArray(res) ? res : (res?.items || []);
    if (isAdmin.value && canManagePayroll.value && periodClosed.value) await loadPublicationStatus();
  } catch (err) {
    console.error('Error loading salary details:', err);
    details.value = [];
    notificationStore.addError('Không tải được bảng lương kỳ này');
  } finally {
    loading.value = false;
  }
};

const loadIssues = async () => {
  issuesLoading.value = true;
  try {
    const res = await salaryService.getPayslipIssues({ per_page: 100 });
    payslipIssues.value = res.items || [];
  } catch (err) {
    payslipIssues.value = [];
    notificationStore.addError(err.response?.data?.message || 'Không tải được danh sách phiếu cần xử lý');
  } finally {
    issuesLoading.value = false;
  }
};

const retryIssue = async (issue) => {
  try {
    await salaryService.retryPayslipIssue(issue.id);
    notificationStore.addSuccess('Đã đưa yêu cầu xử lý lại vào hàng đợi');
    await loadIssues();
  } catch (err) {
    notificationStore.addError(err.response?.data?.message || 'Không thể xử lý lại phiếu lương');
  }
};

const loadPublicationStatus = async () => {
  if (!selectedPeriodId.value || !periodClosed.value || !canManagePayroll.value) {
    publicationStatus.value = null;
    return;
  }
  try {
    publicationStatus.value = await salaryService.getPayslipPublicationStatus(Number(selectedPeriodId.value));
  } catch { publicationStatus.value = null; }
};

const loadHistory = async () => {
  if (isAdmin.value || !selfEmployeeId.value) return;
  try {
    const res = await salaryService.getDetails({ employee_id: selfEmployeeId.value, per_page: 24 });
    history.value = Array.isArray(res) ? res : (res?.items || []);
  } catch { history.value = []; }
};

// ── Actions ──
const openBonusModal = () => {
  if (!selectedPeriod.value || periodLocked.value) return;
  bonusResult.value = null;
  bonusForm.value = {
    window_start: String(selectedPeriod.value.start_date || '').slice(0, 10),
    window_end: String(selectedPeriod.value.end_date || '').slice(0, 10),
    rate_percent: 50
  };
  bonusOpen.value = true;
};

const runBonus = async () => {
  if (!selectedPeriodId.value || periodLocked.value || bonusLoading.value) return;
  if (!bonusForm.value.window_start || !bonusForm.value.window_end) return notificationStore.addError('Vui lòng chọn đủ khoảng thời gian thưởng');
  if (Number(bonusForm.value.rate_percent) < 1 || Number(bonusForm.value.rate_percent) > 300) return notificationStore.addError('Tỷ lệ thưởng phải từ 1% đến 300%');
  bonusLoading.value = true;
  try {
    bonusResult.value = await salaryService.runBonus({
      salary_period_id: Number(selectedPeriodId.value),
      ...bonusForm.value
    });
    notificationStore.addSuccess(`Đã tạo thưởng cho ${bonusResult.value?.employees || 0} nhân viên. Hãy chạy lại tính lương.`);
  } catch (err) {
    notificationStore.addError(err?.response?.data?.message || 'Không thể tạo thưởng đợt');
  } finally {
    bonusLoading.value = false;
  }
};

const runPayroll = async () => {
  if (!selectedPeriodId.value || periodLocked.value) return;
  const periodId = Number(selectedPeriodId.value);
  runLoading.value = true;
  runProgress.value = 'Đang đưa vào hàng đợi…';
  try {
    // Tính lương chạy NỀN (queue): API trả về ngay (PROCESSING), rồi poll đến khi
    // xong — tránh timeout khi số nhân viên lớn (công ty sản xuất).
    const res = await salaryService.runPayroll(periodId);
    const d = res?.data || res || {};
    let final = d;
    if ((d.run_status || 'PROCESSING') === 'PROCESSING') {
      final = await pollPayrollStatus(periodId, d.total);
    }
    if (final.run_status === 'FAILED') {
      notificationStore.addError('Tính lương thất bại: ' + (final.error || 'lỗi không xác định'));
    } else {
      notificationStore.addSuccess(`Đã tính lương cho ${final.processed ?? d.processed ?? '?'} nhân viên`);
      await loadDetails();
    }
  } catch (err) {
    notificationStore.addError(err.response?.data?.message || 'Không thể tính lương');
  } finally {
    runLoading.value = false;
    runProgress.value = '';
  }
};

// Poll trạng thái tính lương nền mỗi 2.5s, tối đa 6 phút.
const pollPayrollStatus = async (periodId, total) => {
  const started = Date.now();
  while (Date.now() - started < 6 * 60 * 1000) {
    await new Promise((r) => setTimeout(r, 2500));
    let d;
    try {
      const st = await salaryService.runStatus(periodId);
      d = st?.data || st || {};
    } catch {
      continue; // lỗi mạng tạm thời → thử lại
    }
    // Backend báo PROCESSING (chưa đếm dần) → hiện thông báo chờ, không show "0/N" gây hiểu nhầm đứng máy.
    runProgress.value = `Đang tính lương nền cho ${d.total ?? total ?? ''} nhân viên…`;
    if (d.run_status === 'DONE' || d.run_status === 'FAILED') return d;
  }
  return { run_status: 'FAILED', error: 'Quá thời gian chờ tính lương' };
};

// Maker–checker chốt kỳ: trình (kế toán) → duyệt & chốt (admin) / trả về.
const requestReadinessAction = async (action) => {
  if (!selectedPeriodId.value || periodLocked.value) return;
  try {
    readinessResult.value = await salaryService.getPayslipReadiness(Number(selectedPeriodId.value));
    readinessAction.value = action;
    readinessOpen.value = true;
  } catch (err) {
    notificationStore.addError(err.response?.data?.message || 'Không thể kiểm tra dữ liệu phiếu lương');
  }
};

const submitClose = () => requestReadinessAction('submit');

const approveClose = async () => {
  if (!selectedPeriodId.value) return;
  try {
    readinessResult.value = await salaryService.getPayslipReadiness(Number(selectedPeriodId.value));
    readinessAction.value = 'close';
    readinessOpen.value = true;
  } catch (err) {
    notificationStore.addError(err.response?.data?.message || 'Không thể kiểm tra dữ liệu phiếu lương');
  }
};

const confirmReadinessAction = async () => {
  if (readinessResult.value?.has_non_bypassable_issues) {
    notificationStore.addError('Cần xử lý hết review chấm công và lỗi OT bắt buộc trước khi trình/chốt kỳ.');
    return;
  }
  const action = readinessAction.value;
  const allowPartial = Number(readinessResult.value?.fail_count || 0) > 0;
  readinessOpen.value = false;
  try {
    if (action === 'submit') {
      await salaryService.submitPeriod(Number(selectedPeriodId.value), allowPartial);
      notificationStore.addSuccess('Đã trình chốt kỳ — chờ admin duyệt');
    } else {
      await salaryService.closePeriod(Number(selectedPeriodId.value), allowPartial);
      notificationStore.addSuccess('Đã duyệt và chốt kỳ lương');
    }
    await loadPeriods();
    await loadDetails();
  } catch (err) {
    const data = err.response?.data?.data;
    if (data?.readiness) {
      readinessResult.value = data.readiness;
      readinessOpen.value = true;
    }
    notificationStore.addError(data?.errors?.approver_id?.[0] || err.response?.data?.message || 'Không thể chốt kỳ');
  }
};

const publishPayslips = async () => {
  if (!selectedPeriodId.value || publicationBusy.value) return;
  if (!window.confirm(`Phát hành và gửi các phiếu hợp lệ của kỳ ${selectedPeriod.value?.period_code}?`)) return;
  publicationBusy.value = true;
  try {
    const result = await salaryService.publishPayslips(Number(selectedPeriodId.value));
    notificationStore.addSuccess(`Đã xếp hàng ${result?.queued || 0} phiếu; ${result?.fail_count || 0} nhân viên không pass.`);
    await pollPublicationStatus();
    await loadDetails();
  } catch (err) {
    notificationStore.addError(err.response?.data?.message || 'Không thể phát hành phiếu lương');
  } finally {
    publicationBusy.value = false;
  }
};

const pollPublicationStatus = async () => {
  const started = Date.now();
  while (Date.now() - started < 3 * 60 * 1000) {
    await loadPublicationStatus();
    const pending = Number(publicationStatus.value?.documents?.pending || 0);
    if (!pending) return;
    await new Promise(resolve => setTimeout(resolve, 2000));
  }
};

const reopenPeriodFn = async () => {
  if (!selectedPeriodId.value) return;
  if (!window.confirm(`Trả kỳ ${selectedPeriod.value?.period_code} về Đang mở?`)) return;
  try {
    await salaryService.reopenPeriod(Number(selectedPeriodId.value));
    notificationStore.addSuccess('Kỳ đã trả về Đang mở');
    await loadPeriods();
  } catch (err) {
    notificationStore.addError(err.response?.data?.message || 'Không thể trả kỳ về');
  }
};

const openPayslip = async (detail) => {
  payslipOpen.value = true;
  payslipLoading.value = true;
  payslip.value = null;
  try {
    const res = await salaryService.getPayslip(detail.id);
    payslip.value = res;
  } catch (err) {
    console.error('Error loading payslip:', err);
    notificationStore.addError('Không tải được phiếu lương');
    payslipOpen.value = false;
  } finally {
    payslipLoading.value = false;
  }
};

const openPdfBlob = (blob, filename = 'phieu-luong.pdf', download = false) => {
  const url = URL.createObjectURL(blob);
  if (download) {
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    link.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
    return;
  }
  const win = window.open(url, '_blank', 'noopener,noreferrer');
  if (!win) notificationStore.addError('Trình duyệt đang chặn cửa sổ xem PDF');
  setTimeout(() => URL.revokeObjectURL(url), 60_000);
};

const viewOfficialPdf = async (detailId) => {
  try {
    openPdfBlob(await salaryService.getPayslipPdf(detailId), `Phieu_luong_${detailId}.pdf`);
  } catch (err) {
    notificationStore.addError(err.response?.data?.message || 'Không mở được PDF phiếu lương');
  }
};

const downloadOfficialPdf = async (detail) => {
  try {
    const periodCode = detail.period?.period_code || selectedPeriod.value?.period_code || 'ky';
    const employeeCode = detail.employee?.employee_code || detail.employee_id || detail.id;
    openPdfBlob(
      await salaryService.getPayslipPdf(detail.id, true),
      `Phieu_luong_${employeeCode}_${periodCode}.pdf`,
      true
    );
  } catch (err) {
    notificationStore.addError(err.response?.data?.message || 'Không tải được PDF phiếu lương');
  }
};

const emailOfficialPayslip = async (detailId) => {
  try {
    await salaryService.emailPayslip(detailId);
    notificationStore.addSuccess('Đã đưa email phiếu lương vào hàng đợi');
    await loadPublicationStatus();
  } catch (err) {
    notificationStore.addError(err.response?.data?.message || 'Không gửi được phiếu lương');
  }
};

// ── Exports (từ dữ liệu engine thật) ──
const sanitizeFilename = (str) =>
  String(str).normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^\w\s.-]/g, '_').trim();

const exportPayslipCsv = () => {
  if (!payslip.value) return;
  const d = payslip.value.salary_detail;
  const name = d?.employee?.full_name || 'NV';
  const data = [
    ['PHIẾU LƯƠNG NHÂN VIÊN'],
    [],
    ['Họ tên', name],
    ['Mã NV', d?.employee?.employee_code || ''],
    ['Kỳ lương', d?.period?.period_code || ''],
    ['Ngày xuất', new Date().toLocaleDateString('vi-VN')],
    [],
    ['KHOẢN MỤC', 'LOẠI', 'SỐ TIỀN (VNĐ)'],
    ...earningRows.value.map(b => [b.item_name, 'Thu nhập', Number(b.amount)]),
    ...deductionRows.value.map(b => [b.item_name, 'Khấu trừ', -Number(b.amount)]),
    [],
    ['Thu nhập chịu thuế', '', Number(payslipMeta.value.taxable_income || 0)],
    ['Giảm trừ gia cảnh', '', Number(payslipMeta.value.personal_relief || 0)],
    ['THỰC LĨNH (NET)', '', Number(d?.net_salary || 0)],
  ];
  downloadCsv(data, `PhieuLuong_${sanitizeFilename(name)}_${d?.period?.period_code || ''}.csv`);
  notificationStore.addSuccess('Đã xuất CSV phiếu lương');
};

const payslipHTML = (d, rowsE, rowsD, meta, att) => {
  const fmt = (n) => Number(n || 0).toLocaleString('vi-VN') + ' ₫';
  const tr = (nm, amt, sign, color) =>
    `<tr><td style="padding:8px 12px;border-bottom:1px solid #e5e7eb">${nm}</td>
     <td style="padding:8px 12px;border-bottom:1px solid #e5e7eb;text-align:right;color:${color};font-weight:600">${sign}${fmt(amt)}</td></tr>`;
  const eRows = rowsE.map(b => tr(b.item_name, b.amount, '+', '#16a34a')).join('');
  const dRows = rowsD.map(b => tr(b.item_name, b.amount, '−', '#dc2626')).join('');
  const attLine = att
    ? `<div style="font-size:12px;color:#6b7280;margin-bottom:16px">Công chuẩn ${att.standard_days} · Đi làm ${att.actual_working_days} · Nghỉ phép ${att.paid_leave_days} · Tăng ca ${att.overtime_hours}h</div>`
    : '';
  return `
    <div class="page">
      <div class="header">
        <div><div class="company">CODEDENNGU</div><div style="font-size:12px;color:#6b7280;margin-top:4px">Hệ thống Quản lý Nhân sự</div></div>
        <div style="text-align:right"><div style="font-size:20px;font-weight:700">PHIẾU LƯƠNG</div>
        <div style="font-size:12px;color:#6b7280">Kỳ: ${d?.period?.period_code || ''} | Xuất: ${new Date().toLocaleDateString('vi-VN')}</div></div>
      </div>
      <div class="info-grid">
        <div class="info-item"><label>Họ và tên</label><span>${d?.employee?.full_name || ''}</span></div>
        <div class="info-item"><label>Mã nhân viên</label><span>${d?.employee?.employee_code || ''}</span></div>
      </div>
      ${attLine}
      <h4>Thu nhập</h4>
      <table><thead><tr><th>Khoản mục</th><th style="text-align:right">Số tiền</th></tr></thead><tbody>${eRows}</tbody></table>
      <h4>Khấu trừ</h4>
      <table><thead><tr><th>Khoản mục</th><th style="text-align:right">Số tiền</th></tr></thead><tbody>${dRows}</tbody></table>
      <div class="summary">
        <div class="summary-row"><span>Thu nhập chịu thuế</span><span>${fmt(meta.taxable_income)}</span></div>
        <div class="summary-row"><span>Giảm trừ gia cảnh (${meta.dependents ?? 0} người phụ thuộc)</span><span>${fmt(meta.personal_relief)}</span></div>
        <div class="summary-row net"><span>Lương thực lĩnh</span><span>${fmt(d?.net_salary)}</span></div>
      </div>
      <div class="footer"><p>Phiếu lương tạo tự động bởi engine lương (luật VN 2026) — ${new Date().toLocaleDateString('vi-VN')}</p></div>
    </div>`;
};

const PAYSLIP_CSS = `
  *{margin:0;padding:0;box-sizing:border-box}
  body{font-family:'Segoe UI',Arial,sans-serif;color:#111827;background:#fff}
  .page{max-width:700px;margin:0 auto;padding:40px 32px;page-break-after:always}
  .page:last-child{page-break-after:avoid}
  .header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:24px;border-bottom:3px solid #2563eb;padding-bottom:16px}
  .company{font-size:22px;font-weight:800;color:#2563eb}
  .info-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px 32px;margin-bottom:16px;padding:16px 20px;background:#f9fafb;border-radius:10px}
  .info-item label{font-size:11px;color:#6b7280;text-transform:uppercase;display:block;margin-bottom:2px}
  .info-item span{font-size:14px;font-weight:600}
  table{width:100%;border-collapse:collapse;margin-bottom:16px}
  thead th{background:#f3f4f6;padding:9px 12px;text-align:left;font-size:12px;text-transform:uppercase;color:#6b7280}
  thead th:last-child{text-align:right}
  h4{font-size:13px;font-weight:700;color:#374151;margin-bottom:8px;padding-bottom:4px;border-bottom:2px solid #e5e7eb}
  .summary{background:#eff6ff;border:2px solid #bfdbfe;border-radius:10px;padding:14px 20px}
  .summary-row{display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px;color:#374151}
  .summary-row.net{border-top:1px solid #93c5fd;margin-top:10px;padding-top:10px;font-size:16px;font-weight:800;color:#1d4ed8}
  .footer{text-align:center;margin-top:24px;font-size:11px;color:#9ca3af}
  @media print{.page{padding:20px}}`;

const openPrintWindow = (title, bodyHTML) => {
  const html = `<!DOCTYPE html><html lang="vi"><head><meta charset="UTF-8"><title>${title}</title><style>${PAYSLIP_CSS}</style></head>
<body>${bodyHTML}<scr` + `ipt>window.onload=()=>window.print();</scr` + `ipt></body></html>`;
  const blob = new Blob([html], { type: 'text/html;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const w = window.open(url, '_blank', 'width=800,height=900');
  if (w) w.addEventListener('unload', () => URL.revokeObjectURL(url));
};

const exportPayslipPDF = () => {
  if (!payslip.value) return;
  const d = payslip.value.salary_detail;
  openPrintWindow(
    `Phiếu lương - ${d?.employee?.full_name || ''}`,
    payslipHTML(d, earningRows.value, deductionRows.value, payslipMeta.value, payslip.value.attendance_summary)
  );
};

// Bảng lương cả kỳ — MỘT nguồn dữ liệu (salary_details), không gọi N+1 API
const bulkExportCsv = () => {
  if (!details.value.length) return;
  const data = [
    ['BẢNG LƯƠNG TỔNG HỢP — KỲ ' + (selectedPeriod.value?.period_code || '')],
    ['Ngày xuất', new Date().toLocaleDateString('vi-VN')],
    [],
    ['STT', 'Mã NV', 'Họ tên', 'Gross', 'Khấu trừ', 'Thực lĩnh', 'Công', 'Tăng ca (h)'],
    ...details.value.map((d, i) => [
      i + 1,
      d.employee?.employee_code || '',
      d.employee?.full_name || '',
      Number(d.gross_salary || 0),
      -Number((d.gross_salary || 0) - (d.net_salary || 0)),
      Number(d.net_salary || 0),
      prorationDays(d) || 'Đủ',
      Number(d.overtime_hours || 0),
    ]),
    [],
    ['', '', 'TỔNG CỘNG', stats.value.gross, -stats.value.deductions, stats.value.net, '', ''],
  ];
  downloadCsv(data, `BangLuong_${selectedPeriod.value?.period_code || 'ky'}.csv`);
  notificationStore.addSuccess('Đã xuất bảng lương cả kỳ');
};

// File chuyển khoản ngân hàng (bulk): kế toán upload lên internet banking để trả
// lương cả kỳ 1 lần. Cột theo mẫu chung VCB/BIDV/ACB; NV thiếu STK bị tách riêng
// cuối file để HR bổ sung (không âm thầm bỏ sót người).
const exportBankFile = async () => {
  if (!details.value.length) return;
  // Gom ĐỦ mọi trang của kỳ (bảng đang hiển thị chỉ là 1 trang) — file thiếu người
  // là tai nạn trả lương thật.
  let all = [];
  for (let page = 1; page < 100; page++) {
    const chunk = await salaryService.getDetails({ period_id: Number(selectedPeriodId.value), per_page: 100, page });
    const rows = Array.isArray(chunk) ? chunk : (chunk?.items || []);
    all = all.concat(rows);
    if (rows.length < 100) break;
  }
  const profileOf = (d) => {
    const p = d.employee?.profile;
    if (!p) return {};
    if (typeof p === 'string') { try { return JSON.parse(p); } catch { return {}; } }
    return p;
  };
  const withAcc = [], noAcc = [];
  all.forEach(d => (profileOf(d).bank_account ? withAcc : noAcc).push(d));
  const code = selectedPeriod.value?.period_code || '';
  const data = [
    ['STT', 'Số tài khoản', 'Tên người thụ hưởng', 'Ngân hàng', 'Số tiền', 'Diễn giải'],
    ...withAcc.map((d, i) => {
      const p = profileOf(d);
      return [i + 1, p.bank_account, d.employee?.full_name || '', p.bank_name || '', Math.round(Number(d.net_salary || 0)), `Luong ${code} ${d.employee?.employee_code || ''}`];
    }),
  ];
  if (noAcc.length) {
    data.push([], [`CHƯA CÓ SỐ TÀI KHOẢN (${noAcc.length} NV — bổ sung hồ sơ rồi xuất lại)`]);
    noAcc.forEach((d, i) => data.push([i + 1, '', d.employee?.full_name || '', '', Math.round(Number(d.net_salary || 0)), d.employee?.employee_code || '']));
  }
  downloadCsv(data, `ChuyenKhoan_${code || 'ky'}.csv`);
  notificationStore.addSuccess(`File ngân hàng: ${withAcc.length} NV có STK${noAcc.length ? `, ${noAcc.length} NV thiếu STK` : ''}`);
};

const bulkExportPDF = async () => {
  if (!selectedPeriodId.value || !periodClosed.value) return;
  try {
    const blob = await salaryService.getPayslipArchive(Number(selectedPeriodId.value));
    openPdfBlob(blob, `Phieu_luong_${selectedPeriod.value?.period_code || 'ky'}.zip`, true);
    notificationStore.addSuccess('Đã tải ZIP các phiếu lương chính thức');
  } catch (err) {
    notificationStore.addError(err.response?.data?.message || 'Chưa có PDF chính thức để tải');
  }
};

// ── Wiring ──
watch(selectedPeriodId, () => {
  if (!issuesOnly.value) loadDetails();
});

onMounted(async () => {
  if (issuesOnly.value) {
    await loadIssues();
    return;
  }
  loading.value = true;
  try {
    await loadPeriods();
    await loadDetails();
    await loadHistory();
  } catch (err) {
    console.error('Salary page init error:', err);
    notificationStore.addError('Không tải được dữ liệu lương');
  } finally {
    loading.value = false;
  }
});
</script>
