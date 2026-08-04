<template>
  <div class="space-y-6">
    <div v-if="loading" class="text-center py-8">
      <p class="text-muted-foreground">Đang tải thông tin nhân viên...</p>
    </div>

    <div v-else-if="error" class="bg-destructive/10 border border-destructive/20 rounded-lg p-4">
      <p class="text-destructive font-medium">Lỗi:</p>
      <p class="text-destructive/80 text-sm mt-1">{{ error }}</p>
    </div>

    <template v-else>
      <div class="flex items-center gap-4">
        <button
          @click="$router.push('/employees')"
          class="p-2 rounded-lg hover:bg-muted"
          data-testid="button-back"
        >
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </button>
        <div class="flex-1">
          <h1 class="text-3xl font-bold text-foreground">{{ employee?.full_name }}</h1>
          <p class="text-muted-foreground mt-1">{{ employee?.code || employee?.employee_code }}</p>
        </div>
        <BaseButton @click="openEditModal" data-testid="button-edit-profile">Chỉnh sửa hồ sơ</BaseButton>
      </div>
      
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <BaseCard title="Thông tin cá nhân">
          <div class="space-y-4">
            <div class="flex justify-center">
              <div class="w-24 h-24 rounded-full bg-primary text-primary-foreground flex items-center justify-center text-3xl font-bold">
                {{ getInitials(employee?.full_name || '') }}
              </div>
            </div>
            
            <div class="space-y-3">
              <div>
                <p class="text-sm text-muted-foreground">Email</p>
                <p class="font-medium">{{ employee?.personal_email || employee?.email || 'Chưa có' }}</p>
              </div>
              <div>
                <p class="text-sm text-muted-foreground">Điện thoại</p>
                <p class="font-medium">{{ employee?.personal_phone || employee?.phone || 'Chưa có' }}</p>
              </div>
              <div>
                <p class="text-sm text-muted-foreground">Ngày sinh</p>
                <p class="font-medium">{{ formatDate(employee?.dob || employee?.date_of_birth) }}</p>
              </div>
              <div>
                <p class="text-sm text-muted-foreground">Giới tính</p>
                <p class="font-medium">{{ getGender(employee?.gender) }}</p>
              </div>
              <div>
                <p class="text-sm text-muted-foreground">Địa chỉ</p>
                <p class="font-medium">{{ employee?.address || 'Chưa có' }}</p>
              </div>
            </div>
          </div>
        </BaseCard>
        
        <BaseCard title="Thông tin công việc" class="lg:col-span-2">
          <div class="grid grid-cols-2 gap-4">
            <div>
              <p class="text-sm text-muted-foreground">Phòng ban</p>
              <p class="font-medium">{{ employee?.department || employee?.department_name || 'Chưa phân công' }} <span class="text-xs text-muted-foreground">(chính)</span></p>
              <div v-if="secondaryDepartments.length" class="mt-1.5 flex flex-wrap items-center gap-1">
                <span class="text-[11px] text-muted-foreground">Kiêm nhiệm:</span>
                <span
                  v-for="d in secondaryDepartments"
                  :key="d.id"
                  class="text-[11px] px-2 py-0.5 rounded-full border border-dashed border-indigo-400 text-indigo-600 bg-indigo-50"
                >{{ d.department_name }}<span v-if="d.role_in_dept"> · {{ d.role_in_dept }}</span></span>
              </div>
            </div>
            <div>
              <p class="text-sm text-muted-foreground">Chức danh</p>
              <p class="font-medium">{{ employee?.job_title || employee?.job_title_name || 'Chưa phân công' }}</p>
            </div>
            <div>
              <p class="text-sm text-muted-foreground">Loại hợp đồng</p>
              <p class="font-medium">{{ employeeContract?.contract_type_name || 'Chưa có hợp đồng' }}</p>
              <p v-if="employeeContract" class="text-xs text-muted-foreground">
                {{ employeeContract.contract_code }} · {{ employeeContract.start_date ? formatDate(employeeContract.start_date) : '' }}<span v-if="employeeContract.end_date"> → {{ formatDate(employeeContract.end_date) }}</span>
              </p>
            </div>
            <div>
              <p class="text-sm text-muted-foreground">Trạng thái làm việc</p>
              <BaseBadge :variant="getStatusVariant(employee?.employment_status)">
                {{ getStatusText(employee?.employment_status) }}
              </BaseBadge>
            </div>
            <div>
              <p class="text-sm text-muted-foreground">Ngày vào làm</p>
              <p class="font-medium">{{ formatDate(employee?.start_date || employee?.hire_date) }}</p>
            </div>
          </div>

          <div class="border-t border-border mt-6 pt-6" v-if="employee?.employment_status === 'inactive' || employee?.employment_status === 'resigned' || employee?.employment_status === 'terminated'">
            <h4 class="font-semibold text-destructive mb-3">Thông tin nghỉ việc</h4>
            <div class="grid grid-cols-2 gap-4">
              <div>
                <p class="text-sm text-muted-foreground">Ngày nghỉ việc</p>
                <p class="font-medium">{{ formatDate(employee?.end_date) }}</p>
              </div>
              <div>
                <p class="text-sm text-muted-foreground">Lý do</p>
                <p class="font-medium">{{ employee?.termination_reason || 'Không ghi nhận' }}</p>
              </div>
            </div>
          </div>
        </BaseCard>
      </div>
      
      <BaseCard>
        <div class="border-b border-border mb-6">
          <div class="flex gap-4">
            <button
              v-for="tab in tabs"
              :key="tab.id"
              @click="activeTab = tab.id"
              :class="['px-4 py-2 border-b-2 transition-colors',
                       activeTab === tab.id 
                         ? 'border-primary text-primary font-medium' 
                         : 'border-transparent text-muted-foreground hover:text-foreground']"
              :data-testid="`tab-${tab.id}`"
            >
              {{ tab.label }}
            </button>
          </div>
        </div>
        
        <div v-if="activeTab === 'personal'">
          <h4 class="font-semibold mb-4">Giấy tờ tùy thân</h4>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <p class="text-sm text-muted-foreground">CMND/CCCD</p>
              <p class="font-medium">{{ employee?.id_number || 'Chưa có' }}</p>
            </div>
            <div>
              <p class="text-sm text-muted-foreground">Ngày cấp</p>
              <p class="font-medium">{{ formatDate(employee?.id_issue_date) }}</p>
            </div>
            <div>
              <p class="text-sm text-muted-foreground">Nơi cấp</p>
              <p class="font-medium">{{ employee?.id_issue_place || 'Chưa có' }}</p>
            </div>
            <div>
              <p class="text-sm text-muted-foreground">Mã số thuế cá nhân</p>
              <p class="font-medium">{{ employee?.tax_number || 'Chưa có' }}</p>
            </div>
            <div>
              <p class="text-sm text-muted-foreground">Số sổ BHXH</p>
              <p class="font-medium">{{ employee?.insurance_number || 'Chưa có' }}</p>
            </div>
            <div>
              <p class="text-sm text-muted-foreground">Số tài khoản ngân hàng</p>
              <p class="font-medium">{{ employee?.bank_account || 'Chưa có' }}</p>
            </div>
            <div>
              <p class="text-sm text-muted-foreground">Ngân hàng</p>
              <p class="font-medium">{{ employee?.bank_name || 'Chưa có' }}</p>
            </div>
          </div>

          <h4 class="font-semibold mt-6 mb-4">Thông tin bổ sung</h4>
          <div class="grid grid-cols-2 gap-4">
            <div><p class="text-sm text-muted-foreground">Dân tộc</p><p class="font-medium">{{ employee?.ethnicity || 'Chưa có' }}</p></div>
            <div><p class="text-sm text-muted-foreground">Tôn giáo</p><p class="font-medium">{{ employee?.religion || 'Chưa có' }}</p></div>
            <div><p class="text-sm text-muted-foreground">Tình trạng hôn nhân</p><p class="font-medium">{{ maritalText(employee?.marital_status) }}</p></div>
            <div><p class="text-sm text-muted-foreground">Quốc tịch</p><p class="font-medium">{{ employee?.nationality_name || 'Chưa có' }}</p></div>
            <div><p class="text-sm text-muted-foreground">Quê quán</p><p class="font-medium">{{ employee?.hometown || 'Chưa có' }}</p></div>
            <div><p class="text-sm text-muted-foreground">Địa chỉ thường trú</p><p class="font-medium">{{ employee?.permanent_address || 'Chưa có' }}</p></div>
            <div><p class="text-sm text-muted-foreground">Trình độ học vấn</p><p class="font-medium">{{ employee?.education_level || 'Chưa có' }}</p></div>
            <div><p class="text-sm text-muted-foreground">Ngày hết thử việc</p><p class="font-medium">{{ formatDate(employee?.probation_end_date) }}</p></div>
          </div>

          <template v-if="customFields.length">
            <h4 class="font-semibold mt-6 mb-4">Thông tin tùy biến</h4>
            <div class="grid grid-cols-2 gap-4">
              <div v-for="f in customFields" :key="f.key">
                <p class="text-sm text-muted-foreground">{{ f.label || f.key }}</p>
                <p class="font-medium">{{ employee?.[f.key] || 'Chưa có' }}</p>
              </div>
            </div>
          </template>
        </div>

        <div v-if="activeTab === 'emergency'">
          <h4 class="font-semibold mb-4">Thông tin liên hệ khẩn cấp</h4>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <p class="text-sm text-muted-foreground">Họ tên người liên hệ</p>
              <p class="font-medium">{{ employee?.emergency_contact_name || 'Chưa có' }}</p>
            </div>
            <div>
              <p class="text-sm text-muted-foreground">Mối quan hệ</p>
              <p class="font-medium">{{ employee?.relationship || employee?.emergency_contact_relationship || 'Chưa có' }}</p>
            </div>
            <div>
              <p class="text-sm text-muted-foreground">Số điện thoại</p>
              <p class="font-medium">{{ employee?.emergency_contact_phone || 'Chưa có' }}</p>
            </div>
          </div>
        </div>
        
        <div v-if="activeTab === 'history'">
          <h4 class="font-semibold mb-4">Lịch sử công tác</h4>
          <div v-if="employmentHistory.length === 0" class="text-center py-8 text-muted-foreground">
            Chưa có lịch sử công tác
          </div>
          <div v-else class="space-y-3">
            <div v-for="history in employmentHistory" :key="history.id" class="border border-border rounded-lg p-4">
              <div class="flex justify-between items-start">
                <div>
                  <p class="font-medium">{{ history.job_title || 'Chưa có chức danh' }}</p>
                  <p class="text-sm text-muted-foreground">{{ history.department || 'Chưa có phòng ban' }}</p>
                </div>
                <div class="flex items-center gap-2">
                  <BaseBadge size="sm" :variant="getHistoryStatusVariant(history.employment_status)">
                    {{ getHistoryStatusLabel(history.employment_status) }}
                  </BaseBadge>
                  <BaseBadge size="sm" variant="outline">
                    {{ formatDate(history.start_date) }} - {{ history.end_date ? formatDate(history.end_date) : 'Hiện tại' }}
                  </BaseBadge>
                </div>
              </div>
            </div>
          </div>
        </div>
        
        <div v-if="activeTab === 'salary'">
          <h4 class="font-semibold mb-4">Thông tin lương<span v-if="salaryPeriodLabel" class="text-sm font-normal text-muted-foreground"> — Kỳ {{ salaryPeriodLabel }}</span></h4>
          <!-- RBAC: người xem không có module payroll bị 403 — nói rõ thay vì "chưa có dữ liệu". -->
          <div v-if="salaryDenied" class="text-center py-8 text-muted-foreground">
            Bạn không có quyền xem thông tin lương (cần quyền module Lương).
          </div>
          <div v-else-if="salaryInfo.length === 0" class="text-center py-8 text-muted-foreground">
            Chưa có thông tin lương
          </div>
          <div v-else class="space-y-3">
            <div v-for="salary in salaryInfo" :key="salary.id" class="flex justify-between items-center p-3 border border-border rounded-lg">
              <div>
                <p class="font-medium">{{ salary.component_name }}</p>
                <p class="text-sm text-muted-foreground">{{ salary.type === 'earning' ? 'Thu nhập' : 'Khấu trừ' }}</p>
              </div>
              <p class="font-semibold" :class="salary.type === 'earning' ? 'text-green-600' : 'text-red-600'">
                {{ formatCurrency(salary.amount) }}
              </p>
            </div>
          </div>
        </div>

        <div v-if="activeTab === 'dependents'">
          <div class="flex items-center justify-between mb-4">
            <h4 class="font-semibold">Người phụ thuộc (giảm trừ gia cảnh)</h4>
            <BaseButton size="sm" @click="openDepModal()" data-testid="button-add-dependent">+ Thêm</BaseButton>
          </div>
          <div v-if="dependents.length === 0" class="text-center py-8 text-muted-foreground">Chưa đăng ký người phụ thuộc</div>
          <div v-else>
            <p class="text-sm text-muted-foreground mb-3">
              Đang giảm trừ: <span class="font-medium text-foreground">{{ activeDependentCount }}</span> người
              ({{ formatCurrency(activeDependentCount * perDependent) }}/tháng)
            </p>
            <div class="space-y-2">
              <div v-for="dep in dependents" :key="dep.id" class="flex justify-between items-center p-3 border border-border rounded-lg">
                <div>
                  <p class="font-medium">{{ dep.full_name }}</p>
                  <p class="text-sm text-muted-foreground">{{ dep.relationship }}<span v-if="dep.start_date"> · từ {{ formatDate(dep.start_date) }}</span></p>
                </div>
                <div class="flex items-center gap-2">
                  <BaseBadge size="sm" :variant="isDependentActive(dep) ? 'success' : 'default'">
                    {{ isDependentActive(dep) ? 'Đang giảm trừ' : 'Ngừng' }}
                  </BaseBadge>
                  <button @click="openDepModal(dep)" class="p-1 rounded hover:bg-accent text-muted-foreground" title="Sửa">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                  </button>
                  <button @click="deleteDependent(dep)" class="p-1 rounded hover:bg-red-100 dark:hover:bg-red-900 text-red-600 dark:text-red-400" title="Xóa">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div v-if="activeTab === 'onboarding'">
          <div class="flex items-center justify-between mb-4">
            <h4 class="font-semibold">Tiến độ hội nhập / nghỉ việc</h4>
            <router-link to="/onboarding" class="text-sm text-primary hover:underline">Quản lý →</router-link>
          </div>
          <div v-if="onboardingChecklists.length === 0" class="text-center py-8 text-muted-foreground">Chưa có checklist nào cho nhân viên này</div>
          <div v-else class="space-y-4">
            <div v-for="cl in onboardingChecklists" :key="cl.id" class="p-4 border border-border rounded-lg">
              <div class="flex items-center justify-between mb-2">
                <span class="font-medium">{{ cl.type === 'OFFBOARDING' ? 'Thủ tục nghỉ việc' : 'Hội nhập nhân viên mới' }}</span>
                <BaseBadge size="sm" :variant="cl.status === 'COMPLETED' ? 'success' : (cl.status === 'CANCELLED' ? 'default' : 'warning')">{{ onboardingStatusText(cl.status) }}</BaseBadge>
              </div>
              <div class="flex items-center gap-3 mb-3">
                <div class="flex-1 h-2 rounded-full bg-muted overflow-hidden">
                  <div class="h-full bg-primary transition-all" :style="{ width: onboardingPct(cl) + '%' }"></div>
                </div>
                <span class="text-xs text-muted-foreground whitespace-nowrap">{{ doneCount(cl) }}/{{ (cl.tasks || []).length }} việc</span>
              </div>

              <ul v-if="(cl.tasks || []).length" class="space-y-2">
                <li
                  v-for="task in cl.tasks"
                  :key="task.id"
                  class="flex items-start gap-3 p-2.5 rounded-lg border border-border/70"
                  :class="isTaskDone(task) ? 'bg-green-50/60 dark:bg-green-950/20' : 'bg-card'"
                >
                  <input
                    type="checkbox"
                    :checked="isTaskDone(task)"
                    :disabled="onboardingBusy || cl.status === 'CANCELLED'"
                    @change="toggleOnboardingTask(cl, task)"
                    class="mt-0.5 h-4 w-4 rounded border-input text-primary focus:ring-ring"
                  />
                  <div class="flex-1 min-w-0">
                    <p :class="['text-sm', isTaskDone(task) ? 'line-through text-muted-foreground' : 'text-foreground font-medium']">{{ task.title }}</p>
                    <p v-if="task.description" class="text-xs text-muted-foreground mt-0.5">{{ task.description }}</p>
                  </div>
                  <div class="flex flex-col items-end gap-0.5 flex-shrink-0">
                    <span v-if="task.due_date" class="text-xs text-muted-foreground">Hạn: {{ formatDate(task.due_date) }}</span>
                    <BaseBadge size="sm" :variant="isTaskDone(task) ? 'success' : 'warning'">{{ isTaskDone(task) ? 'Đã xong' : 'Chưa làm' }}</BaseBadge>
                  </div>
                </li>
              </ul>
              <p v-else class="text-sm text-muted-foreground">Checklist chưa có công việc nào.</p>
            </div>
          </div>
        </div>

        <div v-if="activeTab === 'credentials'">
          <h4 class="font-semibold mb-4">Bằng cấp</h4>
          <div v-if="qualifications.length === 0" class="text-sm text-muted-foreground mb-6">Chưa có bằng cấp</div>
          <div v-else class="space-y-2 mb-6">
            <div v-for="q in qualifications" :key="q.id" class="p-3 border border-border rounded-lg">
              <p class="font-medium">{{ q.qualification_name || q.major || 'Bằng cấp' }}</p>
              <p class="text-sm text-muted-foreground">
                {{ [q.major, q.school_name].filter(Boolean).join(' · ') }}{{ q.graduation_year ? ` · ${q.graduation_year}` : '' }}
              </p>
            </div>
          </div>
          <div class="mb-4 flex items-center justify-between gap-3">
            <h4 class="font-semibold">Chứng chỉ</h4>
            <BaseButton size="sm" @click="openCertificateModal">+ Thêm chứng chỉ</BaseButton>
          </div>
          <div v-if="certificates.length === 0" class="text-sm text-muted-foreground">Chưa có chứng chỉ</div>
          <div v-else class="space-y-2">
            <div v-for="c in certificates" :key="c.id" class="rounded-lg border border-border p-3">
              <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
                <div>
                  <div class="flex flex-wrap items-center gap-2">
                    <p class="font-medium">{{ c.certificate_name || 'Chứng chỉ' }}</p>
                    <BaseBadge size="sm" :variant="certificateStatus(c).variant">{{ certificateStatus(c).label }}</BaseBadge>
                  </div>
                  <p class="mt-1 text-sm text-muted-foreground">{{ c.issued_by || 'Chưa có đơn vị cấp' }}{{ c.issued_date ? ` · cấp ${formatDate(c.issued_date)}` : '' }}{{ c.expiry_date ? ` · hết hạn ${formatDate(c.expiry_date)}` : '' }}</p>
                  <p v-if="c.certificate_number || c.score" class="mt-1 text-xs text-muted-foreground">{{ c.certificate_number ? `Số: ${c.certificate_number}` : '' }}{{ c.score ? ` · Điểm: ${c.score}` : '' }}</p>
                  <a v-if="c.file_url" :href="c.file_url" target="_blank" rel="noopener noreferrer" class="mt-2 inline-block text-sm font-medium text-primary hover:underline">Mở tài liệu</a>
                </div>
                <BaseButton variant="destructive" size="sm" :disabled="certificateSaving" @click="deleteCertificate(c)">Xóa</BaseButton>
              </div>
            </div>
          </div>
        </div>
      </BaseCard>

      <BaseModal v-model="showCertificateModal" title="Thêm chứng chỉ" size="lg">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <BaseInput v-model="certificateForm.certificate_name" label="Tên chứng chỉ" required />
          <label class="text-sm font-medium">
            Loại chứng chỉ
            <select v-model="certificateForm.certificate_type_id" class="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2 font-normal">
              <option value="">-- Chưa phân loại --</option>
              <option v-for="type in certificateTypes" :key="type.id" :value="type.id">{{ type.certificate_type_name || type.name || type.certificate_type_code }}</option>
            </select>
          </label>
          <BaseInput v-model="certificateForm.issued_by" label="Đơn vị cấp" />
          <BaseInput v-model="certificateForm.certificate_number" label="Số chứng chỉ" />
          <BaseInput v-model="certificateForm.issued_date" type="date" label="Ngày cấp" />
          <BaseInput v-model="certificateForm.expiry_date" type="date" label="Ngày hết hạn" />
          <label class="text-sm font-medium">Điểm<input v-model="certificateForm.score" type="number" min="0" max="100" step="0.01" class="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2 font-normal" /></label>
          <BaseInput v-model="certificateForm.file_url" type="url" label="URL tài liệu" placeholder="https://..." />
        </div>
        <p class="mt-3 text-xs text-muted-foreground">Đợt này chỉ lưu URL tài liệu, chưa upload file trực tiếp.</p>
        <template #footer>
          <BaseButton variant="outline" :disabled="certificateSaving" @click="showCertificateModal = false">Hủy</BaseButton>
          <BaseButton :disabled="certificateSaving" @click="saveCertificate">{{ certificateSaving ? 'Đang lưu...' : 'Thêm chứng chỉ' }}</BaseButton>
        </template>
      </BaseModal>

      <!-- Edit profile modal -->
      <BaseModal v-model="showEditModal" title="Chỉnh sửa hồ sơ nhân viên" size="lg">
        <div class="space-y-4">
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <BaseInput v-model="editForm.full_name" label="Họ tên" />
            <div>
              <label class="block text-sm font-medium text-foreground mb-1">Giới tính</label>
              <select v-model="editForm.gender" class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground text-sm focus:outline-none focus:ring-2 focus:ring-ring">
                <option value="">--</option>
                <option value="MALE">Nam</option>
                <option value="FEMALE">Nữ</option>
                <option value="OTHER">Khác</option>
              </select>
            </div>
            <BaseInput v-model="editForm.date_of_birth" type="date" label="Ngày sinh" />
            <BaseInput v-model="editForm.personal_phone" label="Điện thoại" />
            <BaseInput v-model="editForm.personal_email" label="Email cá nhân" />
            <BaseInput v-model="editForm.address" label="Địa chỉ" />
            <div>
              <label class="block text-sm font-medium text-foreground mb-1">Quản lý trực tiếp</label>
              <select v-model="editForm.manager_id" class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground text-sm focus:outline-none focus:ring-2 focus:ring-ring">
                <option value="">-- Không có --</option>
                <option v-for="m in managerOptions" :key="m.id" :value="m.id">{{ m.full_name }}{{ m.employee_code ? ` (${m.employee_code})` : '' }}</option>
              </select>
              <p class="text-[11px] text-muted-foreground mt-1">Dùng để dựng sơ đồ tổ chức.</p>
            </div>
          </div>

          <div class="border-t border-border pt-4">
            <p class="text-sm font-semibold mb-3">Thông tin bổ sung</p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <BaseInput v-model="editForm.ethnicity" label="Dân tộc" />
              <BaseInput v-model="editForm.religion" label="Tôn giáo" />
              <div>
                <label class="block text-sm font-medium text-foreground mb-1">Tình trạng hôn nhân</label>
                <select v-model="editForm.marital_status" class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground text-sm focus:outline-none focus:ring-2 focus:ring-ring">
                  <option value="">--</option>
                  <option value="SINGLE">Độc thân</option>
                  <option value="MARRIED">Đã kết hôn</option>
                  <option value="OTHER">Khác</option>
                </select>
              </div>
              <BaseInput v-model="editForm.nationality_name" label="Quốc tịch" />
              <BaseInput v-model="editForm.hometown" label="Quê quán" />
              <BaseInput v-model="editForm.permanent_address" label="Địa chỉ thường trú" />
              <BaseInput v-model="editForm.education_level" label="Trình độ học vấn" />
              <BaseInput v-model="editForm.probation_end_date" type="date" label="Ngày hết thử việc" />
            </div>
          </div>

          <div v-if="customFields.length" class="border-t border-border pt-4">
            <p class="text-sm font-semibold mb-3">Trường tùy biến của công ty</p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <BaseInput v-for="f in customFields" :key="f.key" v-model="editForm._custom[f.key]" :label="f.label || f.key" />
            </div>
          </div>

          <div class="border-t border-border pt-4">
            <p class="text-sm font-semibold mb-3">Giấy tờ tùy thân & thuế/BHXH</p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <BaseInput v-model="editForm.id_number" label="CMND/CCCD" />
              <BaseInput v-model="editForm.id_issue_date" type="date" label="Ngày cấp" />
              <BaseInput v-model="editForm.id_issue_place" label="Nơi cấp" />
              <BaseInput v-model="editForm.tax_number" label="Mã số thuế cá nhân" />
              <BaseInput v-model="editForm.insurance_number" label="Số sổ BHXH" />
              <BaseInput v-model="editForm.bank_account" label="Số tài khoản ngân hàng" />
              <BaseInput v-model="editForm.bank_name" label="Ngân hàng" />
            </div>
          </div>

          <div class="border-t border-border pt-4">
            <p class="text-sm font-semibold mb-3">Liên hệ khẩn cấp</p>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <BaseInput v-model="editForm.emergency_contact_name" label="Họ tên" />
              <BaseInput v-model="editForm.emergency_contact_relationship" label="Mối quan hệ" />
              <BaseInput v-model="editForm.emergency_contact_phone" label="Số điện thoại" />
            </div>
          </div>
        </div>
        <template #footer>
          <BaseButton variant="outline" @click="showEditModal = false" :disabled="saving">Hủy</BaseButton>
          <BaseButton @click="saveProfile" :disabled="saving">{{ saving ? 'Đang lưu...' : 'Lưu' }}</BaseButton>
        </template>
      </BaseModal>

      <!-- Dependent modal -->
      <BaseModal v-model="showDepModal" :title="depForm.id ? 'Sửa người phụ thuộc' : 'Thêm người phụ thuộc'" size="md">
        <div class="space-y-4">
          <BaseInput v-model="depForm.full_name" label="Họ tên" />
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-foreground mb-1">Quan hệ</label>
              <select v-model="depForm.relationship" class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground text-sm focus:outline-none focus:ring-2 focus:ring-ring">
                <option v-for="r in ['Con','Vợ','Chồng','Bố','Mẹ','Khác']" :key="r" :value="r">{{ r }}</option>
              </select>
            </div>
            <BaseInput v-model="depForm.date_of_birth" type="date" label="Ngày sinh" />
          </div>
          <BaseInput v-model="depForm.tax_code" label="Mã số thuế NPT (nếu có)" />
          <div class="grid grid-cols-2 gap-4">
            <BaseInput v-model="depForm.start_date" type="date" label="Bắt đầu giảm trừ" />
            <BaseInput v-model="depForm.end_date" type="date" label="Kết thúc (nếu có)" />
          </div>
          <div class="flex items-center gap-2">
            <input id="dep_active" v-model="depForm.active" type="checkbox" class="h-4 w-4 rounded border-input text-primary focus:ring-ring" />
            <label for="dep_active" class="text-sm text-foreground">Đang hiệu lực (được tính giảm trừ)</label>
          </div>
        </div>
        <template #footer>
          <BaseButton variant="outline" @click="showDepModal = false" :disabled="depSaving">Hủy</BaseButton>
          <BaseButton @click="saveDependent" :disabled="depSaving">{{ depSaving ? 'Đang lưu...' : 'Lưu' }}</BaseButton>
        </template>
      </BaseModal>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import BaseCard from '../components/BaseCard.vue';
import BaseBadge from '../components/BaseBadge.vue';
import BaseModal from '../components/BaseModal.vue';
import BaseInput from '../components/BaseInput.vue';
import BaseButton from '../components/BaseButton.vue';
import { employeeService } from '../services/employeeService';
import { dependentService } from '../services/dependentService';
import { settingsService } from '../services/settingsService';
import { onboardingService } from '../services/onboardingService';
import { contractService } from '../services/contractService';
import { useToast } from '../composables/useToast';
import { certificateExpiryStatus } from '../utils/managementUi';

const route = useRoute();
const toast = useToast();

const employee = ref(null);
const employeeContract = ref(null); // HĐ đang hiệu lực (hoặc mới nhất) của NV
const secondaryDepartments = ref([]); // phòng ban kiêm nhiệm (ma trận)
const employmentHistory = ref([]);
const salaryInfo = ref([]);
const salaryDenied = ref(false);     // 403 module payroll — phân biệt với "chưa có dữ liệu"
const salaryPeriodLabel = ref('');   // mã kỳ của phiếu lương đang xem
const dependents = ref([]);
const qualifications = ref([]);
const certificates = ref([]);
const certificateTypes = ref([]);
const showCertificateModal = ref(false);
const certificateSaving = ref(false);
const certificateForm = ref({});
const loading = ref(true);
const error = ref('');
const activeTab = ref('personal');

const emptyCertificateForm = () => ({
  certificate_name: '',
  certificate_type_id: '',
  issued_by: '',
  issued_date: '',
  expiry_date: '',
  certificate_number: '',
  score: '',
  file_url: ''
});

const loadCertificates = async () => {
  certificates.value = await employeeService.getCertificates(route.params.id);
};

const openCertificateModal = () => {
  certificateForm.value = emptyCertificateForm();
  showCertificateModal.value = true;
};

const certificateStatus = (certificate) => certificateExpiryStatus(certificate?.expiry_date);

const saveCertificate = async () => {
  if (!certificateForm.value.certificate_name.trim()) return toast.error('Vui lòng nhập tên chứng chỉ');
  certificateSaving.value = true;
  try {
    const payload = Object.fromEntries(Object.entries(certificateForm.value).filter(([, value]) => value !== ''));
    if (payload.certificate_type_id) payload.certificate_type_id = Number(payload.certificate_type_id);
    await employeeService.addCertificate(route.params.id, payload);
    await loadCertificates();
    showCertificateModal.value = false;
    toast.success('Đã thêm chứng chỉ');
  } catch (err) {
    toast.error(err?.response?.data?.message || 'Không thể thêm chứng chỉ');
  } finally {
    certificateSaving.value = false;
  }
};

const deleteCertificate = async (certificate) => {
  if (!window.confirm(`Xóa chứng chỉ "${certificate.certificate_name || 'Chứng chỉ'}"?`)) return;
  certificateSaving.value = true;
  try {
    await employeeService.deleteCertificate(route.params.id, certificate.id);
    await loadCertificates();
    toast.success('Đã xóa chứng chỉ');
  } catch (err) {
    toast.error(err?.response?.data?.message || 'Không thể xóa chứng chỉ');
  } finally {
    certificateSaving.value = false;
  }
};

const showEditModal = ref(false);
const saving = ref(false);
const editForm = ref({ _custom: {} });
const allEmployees = ref([]);
// Company-defined custom employee fields (configured in Cấu hình → Hồ sơ nhân viên).
const customFields = ref([]);

// Manager choices: everyone except this employee (avoid self-management).
const managerOptions = computed(() =>
  allEmployees.value.filter((e) => String(e.id) !== String(route.params.id))
);

const tabs = [
  { id: 'personal', label: 'Giấy tờ tùy thân' },
  { id: 'emergency', label: 'Liên hệ khẩn cấp' },
  { id: 'dependents', label: 'Người phụ thuộc' },
  { id: 'credentials', label: 'Bằng cấp & Chứng chỉ' },
  { id: 'onboarding', label: 'Hội nhập / Nghỉ việc' },
  { id: 'history', label: 'Lịch sử công tác' },
  { id: 'salary', label: 'Thông tin lương' },
];

const onboardingChecklists = ref([]);
const onboardingBusy = ref(false);
const isTaskDone = (t) => t?.is_done === true || t?.is_done === 't' || t?.is_done === 1;
const doneCount = (cl) => (cl.tasks || []).filter(isTaskDone).length;
const onboardingPct = (cl) => {
  const total = (cl.tasks || []).length;
  return total ? Math.round((doneCount(cl) / total) * 100) : 0;
};
const onboardingStatusText = (s) => ({ COMPLETED: 'Hoàn tất', CANCELLED: 'Đã hủy', IN_PROGRESS: 'Đang thực hiện' }[s] || s);

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
    console.error('Không thể cập nhật công việc onboarding', e);
  } finally {
    onboardingBusy.value = false;
  }
};
const maritalText = (s) => ({ SINGLE: 'Độc thân', MARRIED: 'Đã kết hôn', OTHER: 'Khác' }[String(s || '').toUpperCase()] || 'Chưa có');

const DEP_INACTIVE = ['false', '0', 'inactive', 'expired', 'deleted'];
const isDependentActive = (dep) => {
  const s = dep?.status;
  if (s === null || s === undefined || s === '') return true;
  return !DEP_INACTIVE.includes(String(s).toLowerCase());
};
const activeDependentCount = computed(() => dependents.value.filter(isDependentActive).length);
const perDependent = ref(4400000);

// ── Dependent CRUD (managed inside the employee profile) ──
const showDepModal = ref(false);
const depSaving = ref(false);
const depForm = ref({});

const openDepModal = (dep = null) => {
  depForm.value = dep
    ? {
        id: dep.id,
        full_name: dep.full_name || '',
        relationship: dep.relationship || 'Con',
        date_of_birth: String(dep.date_of_birth || '').slice(0, 10),
        tax_code: dep.tax_code || '',
        start_date: String(dep.start_date || '').slice(0, 10),
        end_date: String(dep.end_date || '').slice(0, 10),
        active: isDependentActive(dep)
      }
    : { full_name: '', relationship: 'Con', date_of_birth: '', tax_code: '', start_date: '', end_date: '', active: true };
  showDepModal.value = true;
};

const reloadDependents = async () => {
  try {
    dependents.value = await dependentService.getAll({ employee_id: route.params.id });
  } catch (e) {
    console.log('Could not reload dependents');
  }
};

const saveDependent = async () => {
  if (!depForm.value.full_name?.trim()) { toast.error('Vui lòng nhập họ tên'); return; }
  depSaving.value = true;
  try {
    const payload = {
      employee_id: parseInt(route.params.id, 10),
      full_name: depForm.value.full_name.trim(),
      relationship: depForm.value.relationship,
      date_of_birth: depForm.value.date_of_birth || null,
      tax_code: depForm.value.tax_code?.trim() || null,
      deduction_percent: 100,
      start_date: depForm.value.start_date || null,
      end_date: depForm.value.end_date || null,
      status: depForm.value.active ? 'ACTIVE' : 'INACTIVE'
    };
    if (depForm.value.id) {
      await dependentService.update(depForm.value.id, payload);
      toast.success('Đã cập nhật người phụ thuộc');
    } else {
      await dependentService.create(payload);
      toast.success('Đã thêm người phụ thuộc');
    }
    showDepModal.value = false;
    await reloadDependents();
  } catch (err) {
    console.error('Error saving dependent:', err);
    toast.error(err?.response?.data?.message || 'Có lỗi khi lưu người phụ thuộc');
  } finally {
    depSaving.value = false;
  }
};

const deleteDependent = async (dep) => {
  if (!confirm(`Xóa người phụ thuộc "${dep.full_name}"?`)) return;
  try {
    await dependentService.remove(dep.id);
    toast.success('Đã xóa người phụ thuộc');
    await reloadDependents();
  } catch (err) {
    console.error('Error deleting dependent:', err);
    toast.error('Có lỗi khi xóa người phụ thuộc');
  }
};

const openEditModal = () => {
  const e = employee.value || {};
  editForm.value = {
    full_name: e.full_name || '',
    gender: (e.gender || '').toUpperCase(),
    date_of_birth: (e.date_of_birth || e.dob || '').substring(0, 10),
    personal_phone: e.personal_phone || e.phone || '',
    personal_email: e.personal_email || '',
    address: e.address || '',
    id_number: e.id_number || '',
    id_issue_date: (e.id_issue_date || '').substring(0, 10),
    id_issue_place: e.id_issue_place || '',
    tax_number: e.tax_number || '',
    insurance_number: e.insurance_number || '',
    bank_account: e.bank_account || '',
    bank_name: e.bank_name || '',
    emergency_contact_name: e.emergency_contact_name || '',
    emergency_contact_relationship: e.emergency_contact_relationship || '',
    emergency_contact_phone: e.emergency_contact_phone || '',
    manager_id: e.manager_id ? String(e.manager_id) : '',
    // Extended VN fields
    ethnicity: e.ethnicity || '',
    religion: e.religion || '',
    marital_status: (e.marital_status || '').toUpperCase(),
    nationality_name: e.nationality_name || '',
    hometown: e.hometown || '',
    permanent_address: e.permanent_address || '',
    education_level: e.education_level || '',
    probation_end_date: (e.probation_end_date || '').substring(0, 10),
    // Custom fields → current values from profile
    _custom: Object.fromEntries(customFields.value.map((f) => [f.key, e[f.key] ?? '']))
  };
  showEditModal.value = true;
};

const saveProfile = async () => {
  saving.value = true;
  try {
    // Custom-field values go into the profile JSONB (merged by employeeService.update).
    const payload = { ...editForm.value, profile: { ...(editForm.value._custom || {}) } };
    delete payload._custom;
    await employeeService.update(route.params.id, payload);
    toast.success('Đã cập nhật hồ sơ');
    showEditModal.value = false;
    const response = await employeeService.getById(route.params.id);
    employee.value = response?.data || response;
  } catch (err) {
    console.error('Error saving profile:', err);
    toast.error(err?.response?.data?.message || 'Có lỗi khi lưu hồ sơ');
  } finally {
    saving.value = false;
  }
};

const getInitials = (name) => {
  if (!name) return '';
  return name.split(' ').map(w => w[0]).join('').toUpperCase().slice(0, 2);
};

const formatDate = (date) => {
  if (!date) return 'Chưa có';
  return new Date(date).toLocaleDateString('vi-VN');
};

const formatCurrency = (amount) => {
  if (!amount) return '0 đ';
  return new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(amount);
};

const getGender = (gender) => {
  const g = String(gender || '').toUpperCase();
  const map = {
    M: 'Nam', MALE: 'Nam', NAM: 'Nam',
    F: 'Nữ', FEMALE: 'Nữ', 'NỮ': 'Nữ', NU: 'Nữ',
    O: 'Khác', OTHER: 'Khác', 'KHÁC': 'Khác',
  };
  return map[g] || 'Chưa có';
};

const getEmploymentType = (type) => {
  const types = {
    fulltime: 'Toàn thời gian',
    full_time: 'Toàn thời gian',
    parttime: 'Bán thời gian',
    part_time: 'Bán thời gian',
    contract: 'Hợp đồng',
    intern: 'Thực tập'
  };
  return types[type || ''] || 'Chưa có';
};

const getWorkLocation = (location) => {
  const locations = {
    head_office: 'Văn phòng chính',
    branch_1: 'Chi nhánh 1',
    branch_2: 'Chi nhánh 2',
    remote: 'Làm việc từ xa'
  };
  return locations[location || ''] || location || 'Chưa có';
};

const getStatusVariant = (status) => {
  const variants = {
    active: 'success',
    probation: 'warning',
    on_leave: 'warning',
    suspended: 'error',
    inactive: 'default',
    resigned: 'default',
    terminated: 'destructive'
  };
  return variants[status || 'inactive'] || 'default';
};

const getStatusText = (status) => {
  const texts = {
    active: 'Đang làm việc',
    probation: 'Thử việc',
    on_leave: 'Đang nghỉ phép',
    suspended: 'Tạm ngưng',
    inactive: 'Nghỉ việc',
    resigned: 'Đã nghỉ việc',
    terminated: 'Đã nghỉ việc'
  };
  return texts[status || 'inactive'] || 'Không xác định';
};

const getContractType = (type) => {
  const types = {
    permanent: 'Không xác định thời hạn',
    fixed_term: 'Xác định thời hạn',
    seasonal: 'Thời vụ',
    probation: 'Thử việc',
    freelance: 'Tự do'
  };
  return types[type || ''] || type || 'Chưa có';
};

const getHistoryStatusLabel = (status) => {
  const labels = {
    active: 'Chính thức',
    probation: 'Thử việc',
    promoted: 'Thăng chức',
    demoted: 'Giáng chức',
    transferred: 'Điều chuyển',
    suspended: 'Tạm ngưng',
    inactive: 'Nghỉ việc',
    resigned: 'Đã nghỉ',
    terminated: 'Chấm dứt HĐ'
  };
  return labels[status || ''] || status || 'Không xác định';
};

const getHistoryStatusVariant = (status) => {
  const variants = {
    active: 'success',
    probation: 'warning',
    promoted: 'success',
    demoted: 'error',
    transferred: 'default',
    suspended: 'error',
    inactive: 'default',
    resigned: 'default',
    terminated: 'default'
  };
  return variants[status || ''] || 'default';
};

onMounted(async () => {
  const employeeId = route.params.id;
  try {
    loading.value = true;
    error.value = '';
    
    const response = await employeeService.getById(employeeId);
    employee.value = response?.data || response;
    
    try {
      const historyRes = await employeeService.getHistories(employeeId);
      employmentHistory.value = historyRes?.data || historyRes || [];
    } catch (e) {
      console.log('Could not load employment history');
    }
    
    try {
      // /salary-details trả DANH SÁCH KỲ (không phải thành phần lương) — lấy kỳ mới
      // nhất rồi gọi payslip để có breakdown thật (cùng pattern EmployeePortal).
      const salaryRes = await employeeService.getSalaries(employeeId);
      const rows = Array.isArray(salaryRes) ? salaryRes : (salaryRes?.data || salaryRes?.items || []);
      if (rows.length) {
        const latest = rows[0];
        salaryPeriodLabel.value = latest.period?.period_code || '';
        const payslip = await employeeService.getPayslip(latest.id);
        const breakdowns = payslip?.breakdowns || payslip?.data?.breakdowns || [];
        salaryInfo.value = breakdowns
          .filter(b => ['EARNING', 'DEDUCTION', 'NET'].includes(b.item_type) && Number(b.amount) !== 0)
          .map(b => ({
            id: b.id,
            component_name: b.item_name,
            type: b.item_type === 'DEDUCTION' ? 'deduction' : 'earning',
            amount: Number(b.amount) || 0,
          }));
      }
    } catch (e) {
      salaryDenied.value = e?.response?.status === 403;
      console.log('Could not load salary info');
    }

    try {
      const profileRes = await employeeService.getProfile(employeeId);
      const p = profileRes?.data || profileRes || {};
      dependents.value = Array.isArray(p.dependents) ? p.dependents : [];
      qualifications.value = Array.isArray(p.qualifications) ? p.qualifications : [];
    } catch (e) {
      console.log('Could not load extended profile');
    }

    try {
      await loadCertificates();
    } catch (e) {
      console.log('Could not load employee certificates');
    }

    try {
      certificateTypes.value = await employeeService.getCertificateTypes();
    } catch (e) {
      console.log('Could not load certificate types');
    }

    try {
      const emps = await employeeService.getLookup();
      allEmployees.value = Array.isArray(emps) ? emps : (emps?.items || emps?.data || []);
    } catch (e) {
      console.log('Could not load employees for manager picker');
    }

    try {
      const cons = await contractService.getAll({ employee_id: employeeId });
      const list = Array.isArray(cons) ? cons : (cons?.items || cons?.data || []);
      // Ưu tiên HĐ đang hiệu lực, nếu không lấy HĐ mới nhất.
      const active = ['ACTIVE', 'CÓ_HIỆU_LỰC', 'ĐANG_HIỆU_LỰC'];
      employeeContract.value = list.find((c) => active.includes(String(c.status || '').toUpperCase())) || list[0] || null;
    } catch (e) {
      console.log('Could not load employee contract');
    }

    try {
      const depRes = await employeeService.getDepartments(employeeId);
      secondaryDepartments.value = Array.isArray(depRes?.secondary) ? depRes.secondary : [];
    } catch (e) {
      console.log('Could not load employee departments');
    }

    try {
      const map = await settingsService.getEffectiveMap();
      const v = Number(map['payroll.dependent_deduction']);
      if (!Number.isNaN(v) && v > 0) perDependent.value = v;
      const cf = map['employee.custom_fields'];
      customFields.value = Array.isArray(cf) ? cf.filter((f) => f && f.key) : [];
    } catch (e) { /* keep default */ }

    try {
      const { items } = await onboardingService.getAll({ employee_id: employeeId });
      // Fetch each checklist's tasks so the admin sees the full tickable list.
      const detailed = await Promise.all((items || []).map((c) => onboardingService.get(c.id).catch(() => null)));
      onboardingChecklists.value = detailed.filter(Boolean).map((d) => d?.data || d);
    } catch (e) { console.log('Could not load onboarding checklists'); }

  } catch (err) {
    console.error('Employee API Error:', err);
    error.value = err.response?.data?.error || err.message || 'Không thể tải thông tin nhân viên';
  } finally {
    loading.value = false;
  }
});
</script>
