<template>
  <div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
      <div>
        <h1 class="text-3xl font-bold text-foreground">Ứng viên Tuyển dụng</h1>
        <p class="text-muted-foreground mt-1">Quản lý quy trình ứng tuyển và đánh giá CV bằng AI</p>
      </div>
      <div class="flex gap-2">
        <BaseButton @click="openCreateModal">+ Thêm ứng viên</BaseButton>
      </div>
    </div>

    <!-- Filters & Summary -->
    <div class="grid grid-cols-1 lg:grid-cols-4 gap-4">
      <!-- Search & Filters -->
      <BaseCard class="lg:col-span-3">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <div>
            <label class="block text-xs font-medium text-muted-foreground mb-1">Tìm kiếm tên/email</label>
            <input 
              v-model="filters.search" 
              type="text" 
              placeholder="Nhập từ khóa..." 
              class="w-full px-3 py-1.5 rounded-lg border border-input bg-background text-foreground text-sm focus:outline-none focus:ring-2 focus:ring-ring"
              @input="loadCandidates"
            />
          </div>
          <div>
            <label class="block text-xs font-medium text-muted-foreground mb-1">Vị trí ứng tuyển</label>
            <select 
              v-model="filters.position_id" 
              class="w-full px-3 py-1.5 rounded-lg border border-input bg-background text-foreground text-sm focus:outline-none focus:ring-2 focus:ring-ring"
              @change="loadCandidates"
            >
              <option value="">-- Tất cả vị trí --</option>
              <option v-for="p in positions" :key="p.id" :value="p.id">{{ p.title }}</option>
            </select>
          </div>
          <div class="flex items-end">
            <BaseButton variant="outline" @click="resetFilters" class="w-full text-xs py-1.5">Xóa bộ lọc</BaseButton>
          </div>
        </div>
      </BaseCard>

      <!-- AI stats -->
      <BaseCard class="bg-gradient-to-br from-primary/10 to-accent/5 border-primary/20">
        <div class="flex items-center gap-4 h-full">
          <div class="p-3 rounded-full bg-primary/20 text-primary">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z" />
            </svg>
          </div>
          <div>
            <h3 class="text-sm font-semibold text-foreground">Phân tích AI CV</h3>
            <p class="text-2xl font-bold text-primary">{{ aiProcessedCount }} / {{ candidates.length }}</p>
            <p class="text-xs text-muted-foreground">hồ sơ đã chấm điểm AI</p>
          </div>
        </div>
      </BaseCard>
    </div>

    <!-- Kanban Board Layout -->
    <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-5 gap-4 overflow-x-auto pb-4">
      <div v-for="column in columns" :key="column.id" class="flex-shrink-0 min-w-[250px] bg-muted/30 rounded-xl p-3 border border-border/40">
        <div class="flex items-center justify-between mb-3 px-1">
          <div class="flex items-center gap-2">
            <span class="w-2.5 h-2.5 rounded-full" :class="column.colorClass"></span>
            <h3 class="font-bold text-sm text-foreground">{{ column.label }}</h3>
          </div>
          <BaseBadge variant="secondary" class="text-xs px-2 py-0.5">{{ getCandidatesByStatus(column.id).length }}</BaseBadge>
        </div>

        <div class="space-y-3 min-h-[500px]">
          <div 
            v-for="cand in getCandidatesByStatus(column.id)" 
            :key="cand.id"
            class="bg-card hover:shadow-md border border-card-border hover:border-primary/30 rounded-lg p-3 cursor-pointer transition-all duration-200 group"
            @click="viewDetail(cand)"
          >
            <div class="flex items-start justify-between gap-2">
              <h4 class="font-semibold text-sm text-foreground group-hover:text-primary transition-colors">{{ cand.full_name }}</h4>
              
              <!-- AI Compatibility Score Badge -->
              <div v-if="cand.ai_score !== null && cand.manager_review" class="flex-shrink-0" :title="'Đánh giá độ phù hợp AI: ' + cand.ai_score + '%'">
                <span 
                  class="text-[10px] font-bold px-1.5 py-0.5 rounded-full"
                  :class="getAiScoreClass(cand.ai_score)"
                >
                  AI: {{ cand.ai_score }}%
                </span>
              </div>
              <span
                v-else-if="cand.ai_score !== null"
                class="flex-shrink-0 rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] font-bold text-slate-600"
                title="Điểm AI được ẩn cho tới khi hoàn thành đánh giá độc lập"
              >
                AI: Đang ẩn
              </span>
            </div>

            <p class="text-xs text-muted-foreground mt-1">{{ cand.position?.position_name || cand.job_title_name || cand.recruitment_position_title || 'Vị trí chưa rõ' }}</p>
            
            <div class="flex items-center justify-between mt-3 text-[11px] text-muted-foreground">
              <span class="truncate max-w-[130px]">{{ cand.email }}</span>
              <span v-if="cand.cv_path" class="text-green-600 flex items-center gap-0.5">
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                CV
              </span>
              <span v-else class="text-amber-500">Thiếu CV</span>
            </div>
          </div>
          
          <div v-if="getCandidatesByStatus(column.id).length === 0" class="flex flex-col items-center justify-center py-8 text-xs text-muted-foreground border border-dashed border-border rounded-lg">
            Thả hồ sơ vào đây
          </div>
        </div>
      </div>
    </div>

    <!-- Candidate Detail Modal -->
    <BaseModal v-model="showDetailModal" title="Chi tiết ứng viên" size="lg">
      <div v-if="selectedCandidate" class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Cột thông tin cơ bản -->
        <div class="space-y-4">
          <div class="bg-muted/30 p-4 rounded-xl space-y-3">
            <h3 class="font-bold text-foreground border-b pb-2">Hồ sơ cá nhân</h3>
            <div>
              <label class="text-xs text-muted-foreground">Họ tên ứng viên</label>
              <p class="font-semibold">{{ selectedCandidate.full_name }}</p>
            </div>
            <div>
              <label class="text-xs text-muted-foreground">Email</label>
              <p class="font-semibold">{{ selectedCandidate.email }}</p>
            </div>
            <div>
              <label class="text-xs text-muted-foreground">Số điện thoại</label>
              <p class="font-semibold">{{ selectedCandidate.phone || selectedCandidate.phone_number || '-' }}</p>
            </div>
            <div>
              <label class="text-xs text-muted-foreground">Vị trí ứng tuyển</label>
              <p class="font-semibold text-primary">{{ selectedCandidate.position?.position_name || selectedCandidate.job_title_name || selectedCandidate.recruitment_position_title || '—' }}</p>
            </div>
            <div>
              <label class="text-xs text-muted-foreground">Trạng thái tuyển dụng</label>
              <div class="mt-1">
                <select 
                  v-model="selectedCandidate.status" 
                  class="px-2 py-1 rounded border border-input bg-background text-sm focus:outline-none focus:ring-1 focus:ring-ring"
                  @change="updateCandidateStatus"
                >
                  <option v-for="col in columns" :key="col.id" :value="col.id">{{ col.label }}</option>
                </select>
              </div>
            </div>
          </div>

          <!-- Tài liệu CV -->
          <div class="bg-muted/30 p-4 rounded-xl space-y-3">
            <h3 class="font-bold text-foreground border-b pb-2">Hồ sơ CV</h3>
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
              <button
                v-if="selectedCandidate.cv_path"
                type="button"
                class="group flex min-w-0 items-center gap-2 text-left text-sm font-semibold text-primary hover:underline"
                title="Nhấn để xem CV"
                @click="openCvPreview"
              >
                <svg class="h-4 w-4 shrink-0 transition-transform group-hover:scale-110" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12H9m6 4H9m8 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                <span class="truncate">{{ cvFilename(selectedCandidate) }}</span>
              </button>
              <span v-else class="text-sm text-amber-500 font-medium">Chưa có tệp CV nào được tải lên.</span>
              
              <div class="flex shrink-0 gap-2">
                <input 
                  type="file" 
                  ref="cvFileInput" 
                  class="hidden" 
                  accept=".pdf,.doc,.docx"
                  @change="handleCvUpload"
                />
                <BaseButton v-if="selectedCandidate.cv_path" variant="outline" size="sm" @click="openCvPreview">
                  Xem CV
                </BaseButton>
                <BaseButton variant="outline" size="sm" @click="triggerCvUpload">
                  {{ selectedCandidate.cv_path ? 'Thay đổi CV' : 'Tải lên CV' }}
                </BaseButton>
              </div>
            </div>
          </div>
        </div>

        <!-- Cột Phân tích đánh giá AI -->
        <div class="space-y-4">
          <div class="bg-primary/5 border border-primary/20 p-5 rounded-xl space-y-4">
            <div class="flex items-center justify-between">
              <h3 class="font-extrabold text-primary flex items-center gap-1.5">
                <svg class="w-5 h-5 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                </svg>
                Trợ lý AI Đánh Giá CV
              </h3>
              <BaseButton 
                variant="outline" 
                size="sm" 
                :disabled="aiLoading" 
                @click="runAiScoring"
              >
                {{ aiLoading ? 'AI đang chấm...' : 'Chạy lại AI' }}
              </BaseButton>
            </div>

            <!-- Vòng tròn hiển thị điểm AI -->
            <div v-if="humanReviewCompleted" class="flex flex-col items-center justify-center py-4 space-y-2">
              <div class="relative w-24 h-24 flex items-center justify-center rounded-full bg-background border-4" :class="getAiScoreBorderClass(selectedCandidate.ai_score)">
                <span class="text-2xl font-black text-foreground">{{ selectedCandidate.ai_score !== null ? selectedCandidate.ai_score + '%' : '--' }}</span>
              </div>
              <p class="text-xs text-muted-foreground font-medium text-center">Mức độ tương thích giữa CV ứng viên và mô tả công việc (JD)</p>
            </div>
            <div v-else class="rounded-xl border border-dashed border-primary/30 bg-background px-4 py-6 text-center">
              <p class="text-sm font-bold text-foreground">Điểm AI đang được ẩn</p>
              <p class="mt-1 text-xs text-muted-foreground">Hãy hoàn thành đánh giá độc lập để tránh bị ảnh hưởng bởi điểm của AI.</p>
            </div>

            <!-- Hành động quy trình tuyển dụng -->
            <div class="grid grid-cols-2 gap-2">
              <BaseButton
                variant="outline"
                size="sm"
                :disabled="actionLoading"
                @click="advanceCandidate"
              >
                Chuyển giai đoạn
              </BaseButton>
              <BaseButton
                variant="outline"
                size="sm"
                :disabled="actionLoading"
                @click="openManagerReview"
              >
                Đánh giá quản lý
              </BaseButton>
              <BaseButton
                size="sm"
                :disabled="actionLoading"
                @click="hireCandidate"
              >
                Tuyển
              </BaseButton>
              <BaseButton
                variant="destructive"
                size="sm"
                :disabled="actionLoading"
                @click="rejectCandidate"
              >
                Từ chối
              </BaseButton>
            </div>

            <!-- Báo cáo AI chi tiết (mocked or from BE) -->
            <div v-if="humanReviewCompleted && aiAssessment" class="space-y-2.5 rounded-lg border bg-background p-3 text-xs">
              <div class="flex flex-wrap gap-2 text-muted-foreground">
                <span>Model: <strong class="text-foreground">{{ aiAssessment.model_version }}</strong></span>
                <span>Độ tin cậy: <strong class="text-foreground">{{ Math.round(Number(aiAssessment.confidence || 0) * 100) }}%</strong></span>
                <span>Parser: <strong class="text-foreground">{{ aiAssessment.parser?.parser_name || 'fallback' }}</strong></span>
              </div>
              <div v-for="criterion in aiAssessment.criteria || []" :key="criterion.id" class="rounded-lg border border-border/70 p-2.5">
                <div class="flex items-start justify-between gap-3">
                  <span class="font-bold text-foreground">{{ criterion.criterion }}</span>
                  <span class="whitespace-nowrap font-bold">{{ criterion.score }}/{{ criterion.max_score }}</span>
                </div>
                <p class="mt-1 text-muted-foreground">{{ criterion.evidence }}</p>
                <p v-if="criterion.source_page" class="mt-1 text-[10px] text-primary">Nguồn: trang {{ criterion.source_page }}</p>
              </div>
            </div>
            <div v-else-if="selectedCandidate.ai_score === null" class="text-xs text-center py-6 text-muted-foreground bg-background rounded-lg border">
              Chưa có dữ liệu đánh giá AI. Vui lòng tải lên CV và nhấn "Chạy lại AI" để phân tích.
            </div>
          </div>
        </div>
      </div>
      <template #footer>
        <BaseButton variant="outline" @click="showDetailModal = false">Đóng</BaseButton>
        <BaseButton variant="destructive" @click="deleteCandidate(selectedCandidate.id)" class="mr-auto">Xóa hồ sơ</BaseButton>
      </template>
    </BaseModal>

    <BaseModal v-model="showCvPreviewModal" :title="`CV · ${cvPreviewName}`" size="xl">
      <div class="min-h-[55vh]">
        <div v-if="cvPreviewLoading" class="flex min-h-[55vh] flex-col items-center justify-center gap-3 text-muted-foreground">
          <span class="h-9 w-9 animate-spin rounded-full border-4 border-primary/20 border-t-primary"></span>
          <p class="text-sm">Đang tải CV...</p>
        </div>

        <div v-else-if="cvPreviewError" class="flex min-h-[45vh] flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-border bg-muted/20 p-8 text-center">
          <svg class="h-10 w-10 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4c-.77-1.33-2.69-1.33-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z" />
          </svg>
          <p class="max-w-lg text-sm text-muted-foreground">{{ cvPreviewError }}</p>
        </div>

        <iframe
          v-else-if="cvPreviewType === 'pdf' && cvPreviewUrl"
          :src="cvPreviewUrl"
          :title="`Xem trước ${cvPreviewName}`"
          class="h-[68vh] w-full rounded-lg border border-border bg-white"
        ></iframe>

        <div v-show="!cvPreviewLoading && cvPreviewType === 'docx'" class="max-h-[68vh] overflow-auto rounded-lg bg-slate-200 p-2 sm:p-4">
          <div ref="cvPreviewStyle"></div>
          <div ref="cvPreviewBody"></div>
        </div>
      </div>
      <template #footer>
        <BaseButton variant="outline" @click="showCvPreviewModal = false">Đóng</BaseButton>
        <BaseButton :disabled="!cvPreviewCanDownload" @click="downloadCurrentCv">Tải CV xuống</BaseButton>
      </template>
    </BaseModal>

    <!-- Create Candidate Modal -->
    <BaseModal v-model="showCreateModal" title="Thêm ứng viên mới">
      <div class="space-y-4">
        <BaseInput v-model="createForm.full_name" label="Họ tên ứng viên" required />
        <BaseInput v-model="createForm.email" type="email" label="Email" required />
        <BaseInput v-model="createForm.phone" label="Số điện thoại" />
        <div>
          <label class="block text-sm font-medium text-foreground mb-1">Vị trí ứng tuyển <span class="text-destructive">*</span></label>
          <select 
            v-model="createForm.recruitment_position_id" 
            class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground text-sm focus:outline-none focus:ring-2 focus:ring-ring"
            required
          >
            <option value="">-- Chọn vị trí --</option>
            <option v-for="p in positions" :key="p.id" :value="p.id">{{ p.title }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-foreground mb-1">Trạng thái khởi tạo</label>
          <select 
            v-model="createForm.status" 
            class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground text-sm focus:outline-none focus:ring-2 focus:ring-ring"
          >
            <option v-for="col in columns" :key="col.id" :value="col.id">{{ col.label }}</option>
          </select>
        </div>
      </div>
      <template #footer>
        <BaseButton variant="outline" @click="showCreateModal = false">Hủy</BaseButton>
        <BaseButton @click="submitCreateCandidate">Lưu</BaseButton>
      </template>
    </BaseModal>

    <!-- Manager Review Modal -->
    <BaseModal v-model="showReviewModal" title="Đánh giá độc lập của HR / Trưởng phòng" size="lg">
      <div class="space-y-4">
        <div class="rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm text-blue-900">
          Điểm và nhận xét AI được ẩn trong bước này. Hãy chấm dựa trên CV, JD và bằng chứng chuyên môn của bạn.
        </div>
        <div>
          <label class="block text-sm font-medium text-foreground mb-1">Quyết định <span class="text-destructive">*</span></label>
          <select
            v-model="reviewForm.decision"
            class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground text-sm focus:outline-none focus:ring-2 focus:ring-ring"
          >
            <option value="approved">Đồng ý</option>
            <option value="needs_review">Cần xem xét thêm</option>
            <option value="rejected">Từ chối</option>
          </select>
        </div>
        <div class="space-y-3">
          <div class="flex items-center justify-between">
            <label class="text-sm font-bold text-foreground">Chấm theo tiêu chí JD</label>
            <span class="rounded-full bg-primary/10 px-3 py-1 text-sm font-black text-primary">{{ calculatedHumanScore }}%</span>
          </div>
          <div v-for="criterion in reviewForm.criteria" :key="criterion.criterion_id" class="rounded-xl border border-border p-3">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
              <div>
                <p class="text-sm font-bold text-foreground">{{ criterion.criterion }}</p>
                <p class="text-xs text-muted-foreground">Trọng số {{ Number(criterion.weight || 0).toFixed(1) }}%</p>
              </div>
              <select v-model.number="criterion.score" class="rounded-lg border border-input bg-background px-3 py-2 text-sm" required>
                <option :value="null" disabled>Chọn điểm</option>
                <option v-for="score in [0, 1, 2, 3, 4, 5]" :key="score" :value="score">{{ score }}/5</option>
              </select>
            </div>
            <textarea
              v-model="criterion.reason"
              class="mt-3 w-full rounded-lg border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring"
              rows="2"
              placeholder="Bằng chứng hoặc lý do cho điểm này..."
            ></textarea>
          </div>
        </div>
        <div>
          <label class="block text-sm font-medium text-foreground mb-1">Nhận xét của quản lý</label>
          <textarea
            v-model="reviewForm.note"
            class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground text-sm focus:outline-none focus:ring-2 focus:ring-ring"
            rows="3"
            placeholder="Nhập nhận xét đánh giá..."
          ></textarea>
        </div>
        <label class="flex items-start gap-2 rounded-lg border border-border p-3 text-sm">
          <input v-model="reviewForm.eligible_for_training" type="checkbox" class="mt-1 h-4 w-4" />
          <span>
            <strong class="block text-foreground">Cho phép dùng đánh giá này trong đợt huấn luyện sau</strong>
            <span class="text-xs text-muted-foreground">Hệ thống không học ngay; dữ liệu chỉ được đưa vào dataset sau khi đủ điều kiện chất lượng.</span>
          </span>
        </label>
      </div>
      <template #footer>
        <BaseButton variant="outline" @click="showReviewModal = false">Hủy</BaseButton>
        <BaseButton :disabled="actionLoading" @click="submitManagerReview">Gửi đánh giá</BaseButton>
      </template>
    </BaseModal>

    <BaseModal v-model="showHireModal" title="Xác nhận tuyển dụng và gửi thư nhận việc">
      <div class="space-y-4">
        <p class="text-sm text-muted-foreground">
          Email nhận việc sẽ được gửi tới <strong class="text-foreground">{{ selectedCandidate?.email }}</strong> ngay sau khi tuyển.
        </p>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <BaseInput v-model="hireForm.start_date" type="date" label="Ngày bắt đầu làm việc" required />
          <BaseInput v-model="hireForm.arrival_time" type="time" label="Thời gian có mặt" required />
        </div>
        <BaseInput v-model="hireForm.work_location" label="Địa điểm làm việc" placeholder="Văn phòng / chi nhánh làm việc" />
        <div>
          <label class="block text-sm font-medium text-foreground mb-1">Ghi chú trong thư nhận việc</label>
          <textarea
            v-model="hireForm.offer_note"
            class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground text-sm focus:outline-none focus:ring-2 focus:ring-ring"
            rows="3"
            placeholder="Hồ sơ cần chuẩn bị, người liên hệ hoặc lưu ý ngày đầu đi làm..."
          ></textarea>
        </div>
      </div>
      <template #footer>
        <BaseButton variant="outline" @click="showHireModal = false">Hủy</BaseButton>
        <BaseButton :disabled="actionLoading" @click="submitHireCandidate">Tuyển và gửi email</BaseButton>
      </template>
    </BaseModal>

    <BaseModal v-model="showRejectModal" title="Từ chối ứng viên và gửi email phản hồi">
      <div class="space-y-4">
        <p class="text-sm text-muted-foreground">
          Email phản hồi lịch sự sẽ được gửi tới <strong class="text-foreground">{{ selectedCandidate?.email }}</strong>.
        </p>
        <div>
          <label class="block text-sm font-medium text-foreground mb-1">Lý do / phản hồi cho ứng viên</label>
          <textarea
            v-model="rejectForm.reason"
            class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground text-sm focus:outline-none focus:ring-2 focus:ring-ring"
            rows="4"
            placeholder="Ví dụ: Kinh nghiệm hiện tại chưa hoàn toàn phù hợp với yêu cầu của vị trí..."
          ></textarea>
        </div>
      </div>
      <template #footer>
        <BaseButton variant="outline" @click="showRejectModal = false">Hủy</BaseButton>
        <BaseButton variant="destructive" :disabled="actionLoading" @click="submitRejectCandidate">Từ chối và gửi email</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>

<script setup>
import { ref, computed, nextTick, onBeforeUnmount, onMounted, watch } from 'vue';
import BaseButton from '../components/BaseButton.vue';
import BaseCard from '../components/BaseCard.vue';
import BaseModal from '../components/BaseModal.vue';
import BaseInput from '../components/BaseInput.vue';
import BaseBadge from '../components/BaseBadge.vue';
import { recruitmentService } from '../services/recruitmentService';
import { useToast } from '../composables/useToast';
import { cvFilename, cvPreviewKind } from '../utils/cvPreview';
import { buildIndependentReviewRubric, calculateWeightedReviewScore } from '../utils/recruitmentReview';

const toast = useToast();

const candidates = ref([]);
const positions = ref([]);
const cvFileInput = ref(null);
const showCvPreviewModal = ref(false);
const cvPreviewLoading = ref(false);
const cvPreviewError = ref('');
const cvPreviewName = ref('CV ứng viên');
const cvPreviewType = ref('');
const cvPreviewUrl = ref('');
const cvPreviewCanDownload = ref(false);
const cvPreviewStyle = ref(null);
const cvPreviewBody = ref(null);
let cvPreviewBlob = null;
let cvPreviewRequestId = 0;

const filters = ref({
  search: '',
  position_id: ''
});

const columns = [
  { id: 'applied', label: 'Nộp hồ sơ', colorClass: 'bg-blue-500' },
  { id: 'shortlisted', label: 'Duyệt hồ sơ', colorClass: 'bg-purple-500' },
  { id: 'interviewing', label: 'Phỏng vấn', colorClass: 'bg-amber-500' },
  { id: 'offered', label: 'Đề nghị nhận việc', colorClass: 'bg-green-500' },
  { id: 'rejected', label: 'Từ chối / Đóng', colorClass: 'bg-red-500' }
];

// Modals & Forms
const showDetailModal = ref(false);
const showCreateModal = ref(false);
const showReviewModal = ref(false);
const showHireModal = ref(false);
const showRejectModal = ref(false);
const selectedCandidate = ref(null);
const aiLoading = ref(false);
const actionLoading = ref(false);

const reviewForm = ref({
  decision: 'approved',
  note: '',
  criteria: [],
  eligible_for_training: false
});

const aiAssessment = computed(() => selectedCandidate.value?.meta?.ai_assessment || null);
const humanReviewCompleted = computed(() => Boolean(selectedCandidate.value?.manager_review));
const calculatedHumanScore = computed(() => {
  return calculateWeightedReviewScore(reviewForm.value.criteria || []);
});

const hireForm = ref({
  start_date: '',
  arrival_time: '08:30',
  work_location: '',
  offer_note: ''
});

const rejectForm = ref({ reason: '' });

const createForm = ref({
  full_name: '',
  email: '',
  phone: '',
  recruitment_position_id: '',
  status: 'applied'
});

const loadPositions = async () => {
  try {
    const res = await recruitmentService.getAllPositions();
    positions.value = res?.data || res || [];
  } catch (err) {
    console.error('Error loading positions:', err);
  }
};

const loadCandidates = async () => {
  try {
    const params = {};
    if (filters.value.search) params.search = filters.value.search;
    if (filters.value.position_id) params.recruitment_position_id = filters.value.position_id;
    
    const res = await recruitmentService.getAllCandidates(params);
    candidates.value = res?.data || res || [];
  } catch (err) {
    console.error('Error loading candidates:', err);
  }
};

const getCandidatesByStatus = (status) => {
  return candidates.value.filter(c => c.status === status);
};

const aiProcessedCount = computed(() => {
  return candidates.value.filter(c => c.ai_score !== null).length;
});

const resetFilters = () => {
  filters.value.search = '';
  filters.value.position_id = '';
  loadCandidates();
};

// --- Actions ---
const viewDetail = async (cand) => {
  selectedCandidate.value = { ...cand };
  showDetailModal.value = true;
  try {
    selectedCandidate.value = await recruitmentService.getCandidateById(cand.id);
  } catch (err) {
    console.error('Error loading candidate detail:', err);
    toast.error('Không thể tải đầy đủ chi tiết ứng viên');
  }
};

const updateCandidateStatus = async () => {
  try {
    await recruitmentService.updateCandidate(selectedCandidate.value.id, {
      status: selectedCandidate.value.status
    });
    toast.success('Đã cập nhật trạng thái ứng viên');
    await loadCandidates();
  } catch (err) {
    console.error('Error updating status:', err);
    toast.error('Lỗi khi cập nhật trạng thái');
  }
};

const advanceCandidate = async () => {
  if (!selectedCandidate.value?.id) return;
  actionLoading.value = true;
  try {
    const updated = await recruitmentService.advance(selectedCandidate.value.id);
    if (updated && updated.status) selectedCandidate.value.status = updated.status;
    toast.success('Đã chuyển ứng viên sang giai đoạn tiếp theo');
    await loadCandidates();
  } catch (err) {
    console.error('Error advancing candidate:', err);
    toast.error('Lỗi khi chuyển giai đoạn ứng viên');
  } finally {
    actionLoading.value = false;
  }
};

const hireCandidate = () => {
  if (!selectedCandidate.value?.id) return;
  if (!['interviewing', 'offered'].includes(selectedCandidate.value.status)) {
    toast.error('Chỉ có thể tuyển ứng viên đang phỏng vấn hoặc đã được đề nghị nhận việc');
    return;
  }
  const start = new Date();
  start.setDate(start.getDate() + 1);
  hireForm.value = {
    start_date: start.toISOString().slice(0, 10),
    arrival_time: '08:30',
    work_location: '',
    offer_note: ''
  };
  showHireModal.value = true;
};

const submitHireCandidate = async () => {
  if (!selectedCandidate.value?.id || !hireForm.value.start_date || !hireForm.value.arrival_time) return;
  actionLoading.value = true;
  try {
    const result = await recruitmentService.hire(selectedCandidate.value.id, hireForm.value);
    toast.success(result?.notification_email_sent
      ? 'Đã tuyển dụng và gửi email nhận việc'
      : 'Đã tuyển dụng; email chưa gửi được, vui lòng kiểm tra cấu hình SMTP');
    showHireModal.value = false;
    showDetailModal.value = false;
    await loadCandidates();
  } catch (err) {
    console.error('Error hiring candidate:', err);
    toast.error('Lỗi khi tuyển dụng ứng viên');
  } finally {
    actionLoading.value = false;
  }
};

const rejectCandidate = () => {
  if (!selectedCandidate.value?.id) return;
  rejectForm.value = { reason: '' };
  showRejectModal.value = true;
};

const submitRejectCandidate = async () => {
  if (!selectedCandidate.value?.id) return;
  actionLoading.value = true;
  try {
    const result = await recruitmentService.reject(selectedCandidate.value.id, rejectForm.value);
    toast.success(result?.notification_email_sent
      ? 'Đã từ chối và gửi email phản hồi'
      : 'Đã từ chối; email chưa gửi được, vui lòng kiểm tra cấu hình SMTP');
    showRejectModal.value = false;
    showDetailModal.value = false;
    await loadCandidates();
  } catch (err) {
    console.error('Error rejecting candidate:', err);
    toast.error('Lỗi khi từ chối ứng viên');
  } finally {
    actionLoading.value = false;
  }
};

const openManagerReview = () => {
  reviewForm.value = {
    decision: 'approved',
    note: '',
    eligible_for_training: false,
    criteria: buildIndependentReviewRubric(selectedCandidate.value)
  };
  showReviewModal.value = true;
};

const submitManagerReview = async () => {
  if (!selectedCandidate.value?.id) return;
  if (!reviewForm.value.criteria.length) {
    toast.error('Chưa có rubric JD để chấm ứng viên');
    return;
  }
  if (reviewForm.value.criteria.some(item => item.score === null || !item.reason.trim())) {
    toast.error('Vui lòng chấm điểm và nhập lý do cho tất cả tiêu chí');
    return;
  }
  actionLoading.value = true;
  try {
    await recruitmentService.managerReview(selectedCandidate.value.id, {
      decision: reviewForm.value.decision,
      note: reviewForm.value.note,
      manager_score: calculatedHumanScore.value,
      blind_review: true,
      eligible_for_training: reviewForm.value.eligible_for_training,
      criteria: reviewForm.value.criteria.map(item => ({
        criterion_id: item.criterion_id,
        score: item.score,
        reason: item.reason,
        evidence: item.evidence
      }))
    });
    toast.success('Đã gửi đánh giá của quản lý');
    showReviewModal.value = false;
    await loadCandidates();
    selectedCandidate.value = await recruitmentService.getCandidateById(selectedCandidate.value.id);
  } catch (err) {
    console.error('Error submitting manager review:', err);
    toast.error('Lỗi khi gửi đánh giá của quản lý');
  } finally {
    actionLoading.value = false;
  }
};

const triggerCvUpload = () => {
  if (cvFileInput.value) {
    cvFileInput.value.click();
  }
};

const clearCvPreview = () => {
  cvPreviewRequestId += 1;
  if (cvPreviewUrl.value) URL.revokeObjectURL(cvPreviewUrl.value);
  cvPreviewUrl.value = '';
  cvPreviewBlob = null;
  cvPreviewCanDownload.value = false;
  cvPreviewType.value = '';
  cvPreviewError.value = '';
  if (cvPreviewStyle.value) cvPreviewStyle.value.innerHTML = '';
  if (cvPreviewBody.value) cvPreviewBody.value.innerHTML = '';
};

const openCvPreview = async () => {
  const candidate = selectedCandidate.value;
  if (!candidate?.id || !candidate.cv_path) return;

  clearCvPreview();
  const requestId = cvPreviewRequestId;
  cvPreviewName.value = cvFilename(candidate);
  cvPreviewType.value = cvPreviewKind(cvPreviewName.value, candidate.cv?.mime_type);
  cvPreviewLoading.value = true;
  showCvPreviewModal.value = true;

  try {
    const blob = await recruitmentService.downloadCv(candidate.id);
    if (requestId !== cvPreviewRequestId) return;
    cvPreviewBlob = blob;
    cvPreviewCanDownload.value = true;

    if (cvPreviewType.value === 'pdf') {
      const pdfBlob = blob.type === 'application/pdf'
        ? blob
        : new Blob([blob], { type: 'application/pdf' });
      cvPreviewUrl.value = URL.createObjectURL(pdfBlob);
      return;
    }

    if (cvPreviewType.value === 'docx') {
      await nextTick();
      const { renderAsync } = await import('docx-preview');
      if (requestId !== cvPreviewRequestId || !cvPreviewBody.value || !cvPreviewStyle.value) return;
      await renderAsync(await blob.arrayBuffer(), cvPreviewBody.value, cvPreviewStyle.value, {
        inWrapper: true,
        ignoreWidth: false,
        ignoreHeight: false,
        breakPages: true,
        ignoreLastRenderedPageBreak: false,
        experimental: true
      });
      return;
    }

    cvPreviewError.value = 'Định dạng DOC cũ chưa thể xem trực tiếp trên trình duyệt. Bạn có thể tải tệp xuống để mở bằng Microsoft Word hoặc LibreOffice.';
  } catch (err) {
    console.error('Error previewing CV:', err);
    cvPreviewError.value = 'Không thể tải nội dung CV. Vui lòng thử lại hoặc tải tệp xuống.';
    toast.error('Không thể mở CV ứng viên');
  } finally {
    cvPreviewLoading.value = false;
  }
};

const downloadCurrentCv = async () => {
  try {
    const blob = cvPreviewBlob || await recruitmentService.downloadCv(selectedCandidate.value.id);
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = cvPreviewName.value || cvFilename(selectedCandidate.value);
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
  } catch (err) {
    console.error('Error downloading CV:', err);
    toast.error('Không thể tải CV ứng viên');
  }
};

const handleCvUpload = async (event) => {
  const file = event.target.files[0];
  if (!file) return;
  
  try {
    const res = await recruitmentService.uploadCv(selectedCandidate.value.id, file);
    toast.success('Tải lên CV thành công!');
    const cv = res?.data || res || {};
    selectedCandidate.value.cv = cv;
    selectedCandidate.value.cv_path = cv.storage_path || cv.cv_path || 'cv_uploaded.pdf';
    selectedCandidate.value.cv_original_filename = cv.original_filename || file.name;
    
    // Auto score after upload
    await runAiScoring();
    await loadCandidates();
  } catch (err) {
    console.error('Error uploading CV:', err);
    toast.error('Lỗi khi tải lên CV');
  }
};

const runAiScoring = async () => {
  if (!selectedCandidate.value.id) return;
  aiLoading.value = true;
  try {
    await recruitmentService.retryAiScore(selectedCandidate.value.id);
    toast.success('Đã đưa hồ sơ vào hàng chờ chấm điểm');
    selectedCandidate.value.ai_scoring_status = 'PENDING';
    await loadCandidates();
  } catch (err) {
    console.error('Error running AI scoring:', err);
    toast.error('AI chấm điểm thất bại hoặc chưa cấu hình API key');
  } finally {
    aiLoading.value = false;
  }
};

const deleteCandidate = async (id) => {
  if (!confirm('Bạn có chắc chắn muốn xóa hồ sơ ứng viên này?')) return;
  try {
    await recruitmentService.deleteCandidate(id);
    toast.success('Xóa ứng viên thành công!');
    showDetailModal.value = false;
    await loadCandidates();
  } catch (err) {
    console.error('Error deleting candidate:', err);
    toast.error('Có lỗi xảy ra khi xóa ứng viên');
  }
};

const openCreateModal = () => {
  createForm.value = {
    full_name: '',
    email: '',
    phone: '',
    recruitment_position_id: '',
    status: 'applied'
  };
  showCreateModal.value = true;
};

const submitCreateCandidate = async () => {
  if (!createForm.value.full_name || !createForm.value.email || !createForm.value.recruitment_position_id) {
    toast.error('Vui lòng nhập đầy đủ thông tin bắt buộc');
    return;
  }
  try {
    await recruitmentService.createCandidate(createForm.value);
    toast.success('Thêm ứng viên thành công');
    showCreateModal.value = false;
    await loadCandidates();
  } catch (err) {
    console.error('Error creating candidate:', err);
    toast.error('Có lỗi xảy ra khi thêm ứng viên');
  }
};

// --- Style Helpers ---
const getAiScoreClass = (score) => {
  if (score >= 80) return 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400';
  if (score >= 60) return 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400';
  return 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400';
};

const getAiScoreBorderClass = (score) => {
  if (score === null) return 'border-border';
  if (score >= 80) return 'border-green-500';
  if (score >= 60) return 'border-yellow-500';
  return 'border-red-500';
};

watch(showCvPreviewModal, (visible) => {
  if (!visible) clearCvPreview();
});

onBeforeUnmount(clearCvPreview);

onMounted(async () => {
  await Promise.all([loadPositions(), loadCandidates()]);
});
</script>
