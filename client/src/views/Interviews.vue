<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-3xl font-bold text-foreground">Lịch Phỏng Vấn</h1>
        <p class="text-muted-foreground mt-1">Quản lý các buổi phỏng vấn và đánh giá năng lực ứng viên</p>
      </div>
      <BaseButton @click="openCreateModal">+ Lên lịch phỏng vấn</BaseButton>
    </div>

    <!-- Table Card -->
    <BaseCard>
      <BaseTable
        :columns="[
          { key: 'candidate', label: 'Ứng viên' },
          { key: 'interview_date', label: 'Thời gian' },
          { key: 'interviewer', label: 'Hội đồng phỏng vấn' },
          { key: 'location', label: 'Địa điểm / Link' },
          { key: 'result', label: 'Đánh giá / Trạng thái' }
        ]"
        :data="interviews"
      >
        <template #cell-candidate="{ item }">
          <div class="font-medium text-foreground">{{ item.candidate_name || item.candidate?.full_name || `Ứng viên #${item.candidate_id}` }}</div>
          <div class="text-xs text-muted-foreground">{{ item.position_title || item.candidate?.position?.position_name || 'Vị trí ứng tuyển' }}</div>
        </template>

        <template #cell-interview_date="{ item }">
          <div class="font-semibold text-primary text-sm">{{ formatDateTime(item) }}</div>
        </template>

        <template #cell-interviewer="{ item }">
          <span>{{ item.interviewer_name || item.interviewer || '-' }}</span>
        </template>

        <template #cell-location="{ item }">
          <div class="flex flex-col gap-1">
            <span v-if="item.location && !isLink(item.location)" class="text-xs text-muted-foreground">{{ item.location }}</span>
            <a v-if="meetingUrl(item)" :href="meetingUrl(item)" target="_blank" rel="noopener noreferrer" class="text-blue-500 hover:underline flex items-center gap-1 text-xs">
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" /></svg>
              Mở phòng họp trực tuyến
            </a>
            <span v-else-if="['ONLINE', 'HYBRID'].includes(item.interview_mode)" class="text-xs text-amber-500">Chưa có link phòng hợp lệ</span>
            <span v-else-if="!item.location" class="text-xs text-muted-foreground">-</span>
          </div>
        </template>

        <template #cell-result="{ item }">
          <div class="flex flex-col gap-1 items-start">
            <BaseBadge :variant="item.status === 'passed' ? 'success' : (item.status === 'failed' ? 'destructive' : 'warning')">
              {{ item.status === 'passed' ? 'Đạt phỏng vấn' : (item.status === 'failed' ? 'Không đạt' : 'Chờ phỏng vấn') }}
            </BaseBadge>
            <span v-if="item.result_note" class="text-[10px] text-muted-foreground line-clamp-1 max-w-[150px]" :title="item.result_note">
              {{ item.result_note }}
            </span>
          </div>
        </template>

        <template #actions="{ item }">
          <div class="flex gap-1">
            <button
              @click="editItem(item)"
              class="px-2.5 py-1.5 text-xs font-medium rounded-md bg-primary/10 text-primary hover:bg-primary/20 transition-colors"
            >
              Sửa / Đánh giá
            </button>
            <button
              @click="openManagerReview(item)"
              class="px-2.5 py-1.5 text-xs font-medium rounded-md bg-accent/40 text-foreground hover:bg-accent/60 transition-colors"
            >
              Duyệt quản lý
            </button>
            <button 
              @click="deleteItem(item.id)" 
              class="px-2.5 py-1.5 text-xs font-medium rounded-md bg-destructive/10 text-destructive hover:bg-destructive/20 transition-colors"
            >
              Xóa
            </button>
          </div>
        </template>
      </BaseTable>
    </BaseCard>

    <!-- Create/Edit Modal -->
    <BaseModal v-model="showModal" :title="form.id ? 'Đánh giá & cập nhật lịch phỏng vấn' : 'Lên lịch phỏng vấn mới'">
      <div class="space-y-4">
        <div v-if="!form.id">
          <label class="block text-sm font-medium text-foreground mb-1">Ứng viên <span class="text-destructive">*</span></label>
          <select 
            v-model="form.candidate_id" 
            class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground text-sm focus:outline-none focus:ring-2 focus:ring-ring"
            required
          >
            <option value="">-- Chọn ứng viên --</option>
            <option v-for="c in candidates" :key="c.id" :value="c.id">{{ c.full_name }} ({{ c.recruitment_position_title || 'JD' }})</option>
          </select>
        </div>
        <div v-else class="p-3 bg-muted rounded-lg">
          <p class="text-xs text-muted-foreground">Ứng viên</p>
          <p class="font-bold text-foreground">{{ form.candidate_name || 'Ứng viên' }}</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <BaseInput v-model="form.interview_date" type="datetime-local" label="Thời gian phỏng vấn" required />
          <BaseInput v-model="form.interviewer" label="Người phỏng vấn / Hội đồng" placeholder="Ví dụ: Nguyễn Văn A (HR), Trần Văn B (Tech Lead)" required />
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-foreground mb-1">Hình thức phỏng vấn</label>
            <select
              v-model="form.interview_mode"
              class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground text-sm focus:outline-none focus:ring-2 focus:ring-ring"
            >
              <option value="ONSITE">Trực tiếp</option>
              <option value="ONLINE">Trực tuyến</option>
              <option value="HYBRID">Linh hoạt</option>
            </select>
          </div>
          <BaseInput v-model="form.duration_minutes" type="number" label="Thời lượng (phút)" />
        </div>

        <BaseInput v-model="form.location" label="Địa điểm phỏng vấn" placeholder="Phòng họp A02 hoặc địa chỉ văn phòng..." />
        <BaseInput
          v-if="form.interview_mode !== 'ONSITE'"
          v-model="form.meeting_link"
          type="url"
          label="Link Google Meet / Zoom"
          placeholder="https://meet.google.com/abc-defg-hij"
          :disabled="form.auto_create_meeting"
        />
        <label v-if="form.interview_mode !== 'ONSITE'" class="flex items-start gap-3 rounded-lg border border-border bg-muted/30 p-3 text-sm">
          <input v-model="form.auto_create_meeting" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-input" @change="handleAutoMeetingChange" />
          <span>
            <strong class="block text-foreground">Tự động tạo phòng Google Meet</strong>
            <span class="text-xs text-muted-foreground">Tạo sự kiện Google Calendar và chèn link phòng thật vào email mời.</span>
          </span>
        </label>
        <BaseInput v-model="form.confirmation_deadline" type="date" label="Hạn ứng viên xác nhận tham dự" />

        <div>
          <label class="block text-sm font-medium text-foreground mb-1">Trạng thái buổi phỏng vấn</label>
          <select 
            v-model="form.status" 
            class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground text-sm focus:outline-none focus:ring-2 focus:ring-ring"
          >
            <option value="pending">Chờ phỏng vấn (Pending)</option>
            <option value="passed">Đạt yêu cầu (Passed)</option>
            <option value="failed">Không đạt yêu cầu (Failed)</option>
          </select>
        </div>

        <div>
          <label class="block text-sm font-medium text-foreground mb-1">Nhận xét & Đánh giá chi tiết</label>
          <textarea 
            v-model="form.result_note" 
            class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground text-sm focus:outline-none focus:ring-2 focus:ring-ring" 
            rows="3" 
            placeholder="Nhập ghi chú đánh giá của hội đồng phỏng vấn..."
          ></textarea>
        </div>
      </div>
      <template #footer>
        <BaseButton variant="outline" @click="showModal = false">Hủy</BaseButton>
        <BaseButton @click="submitForm">Lưu</BaseButton>
      </template>
    </BaseModal>

    <!-- Manager Review Modal -->
    <BaseModal v-model="showReviewModal" title="Đánh giá của quản lý">
      <div class="space-y-4">
        <div class="p-3 bg-muted rounded-lg">
          <p class="text-xs text-muted-foreground">Ứng viên</p>
          <p class="font-bold text-foreground">{{ reviewForm.candidate_name || `Ứng viên #${reviewForm.candidate_id}` }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-foreground mb-1">Quyết định <span class="text-destructive">*</span></label>
          <select
            v-model="reviewForm.decision"
            class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground text-sm focus:outline-none focus:ring-2 focus:ring-ring"
          >
            <option value="approved">Đồng ý</option>
            <option value="rejected">Từ chối</option>
          </select>
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
      </div>
      <template #footer>
        <BaseButton variant="outline" @click="showReviewModal = false">Hủy</BaseButton>
        <BaseButton :disabled="reviewLoading" @click="submitManagerReview">Gửi đánh giá</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>

<script setup>
import { ref, onMounted, watch } from 'vue';
import BaseButton from '../components/BaseButton.vue';
import BaseCard from '../components/BaseCard.vue';
import BaseTable from '../components/BaseTable.vue';
import BaseModal from '../components/BaseModal.vue';
import BaseInput from '../components/BaseInput.vue';
import BaseBadge from '../components/BaseBadge.vue';
import { recruitmentService } from '../services/recruitmentService';
import { useToast } from '../composables/useToast';
import {
  formatInterviewDateTime,
  interviewDateOnly,
  isLink,
  isUsableMeetingLink,
  meetingUrl
} from '../utils/interview';

const toast = useToast();
const interviews = ref([]);
const candidates = ref([]);
const showModal = ref(false);
const showReviewModal = ref(false);
const reviewLoading = ref(false);

const reviewForm = ref({
  id: '',
  candidate_id: '',
  candidate_name: '',
  decision: 'approved',
  note: ''
});

const form = ref({
  candidate_id: '',
  interview_date: '',
  interviewer: '',
  interview_mode: 'ONSITE',
  location: '',
  meeting_link: '',
  auto_create_meeting: false,
  duration_minutes: 60,
  confirmation_deadline: '',
  status: 'pending',
  result_note: ''
});

const interviewDisplayStatus = (item) => {
  if (item.status === 'SCHEDULED') return 'pending';
  if (item.status === 'CANCELLED') return 'failed';

  const outcome = String(item.manager_decision || item.result || '').toUpperCase();
  if (item.status === 'COMPLETED') {
    return ['REJECTED', 'FAILED'].includes(outcome) ? 'failed' : 'passed';
  }

  return item.status;
};

const loadData = async () => {
  try {
    const [intRes, candRes] = await Promise.all([
      recruitmentService.getAllInterviews(),
      recruitmentService.getAllCandidates()
    ]);
    interviews.value = (intRes?.data || intRes || []).map((item) => ({
      ...item,
      status: interviewDisplayStatus(item),
      result_note: item.result_note
        || item.meta?.result_note
        || (!['PASSED', 'FAILED'].includes(String(item.result || '').toUpperCase()) ? item.result : '')
    }));
    candidates.value = (candRes?.data || candRes || []).filter(c => c.status === 'applied' || c.status === 'shortlisted' || c.status === 'interviewing');
  } catch (err) {
    console.error('Error loading interviews:', err);
  }
};

const formatDateTime = formatInterviewDateTime;

const openCreateModal = () => {
  form.value = {
    candidate_id: '',
    interview_date: '',
    interviewer: '',
    interview_mode: 'ONSITE',
    location: '',
    meeting_link: '',
    auto_create_meeting: false,
    duration_minutes: 60,
    confirmation_deadline: '',
    status: 'pending',
    result_note: ''
  };
  showModal.value = true;
};

const editItem = (item) => {
  const existingMeetingLink = meetingUrl(item);
  form.value = { 
    ...item,
    interview_date: item.interview_date
      ? `${interviewDateOnly(item.interview_date)}T${String(item.interview_time || '09:00').slice(0, 5)}`
      : '',
    interview_mode: item.interview_mode || 'ONSITE',
    meeting_link: existingMeetingLink,
    auto_create_meeting: !existingMeetingLink && ['ONLINE', 'HYBRID'].includes(item.interview_mode),
    duration_minutes: item.duration_minutes || 60,
    confirmation_deadline: item.confirmation_deadline || ''
  };
  showModal.value = true;
};

const submitForm = async () => {
  if (!form.value.interview_date || !form.value.interviewer || (!form.value.id && !form.value.candidate_id)) {
    toast.error('Vui lòng điền đầy đủ các thông tin bắt buộc');
    return;
  }
  if (form.value.interview_mode !== 'ONSITE'
    && !form.value.auto_create_meeting
    && !isUsableMeetingLink(form.value.meeting_link)) {
    toast.error('Hãy nhập link phòng họp cụ thể hoặc bật tạo Google Meet tự động');
    return;
  }
  try {
    if (form.value.id) {
      await recruitmentService.updateInterview(form.value.id, form.value);
      toast.success('Cập nhật lịch phỏng vấn thành công');
    } else {
      await recruitmentService.createInterview(form.value);
      toast.success('Lên lịch phỏng vấn thành công');
    }
    showModal.value = false;
    await loadData();
  } catch (err) {
    console.error('Error saving interview:', err);
    const meetingError = err.response?.data?.data?.errors?.meeting_link?.[0];
    toast.error(meetingError || 'Có lỗi xảy ra khi lưu lịch phỏng vấn');
  }
};

const handleAutoMeetingChange = () => {
  if (form.value.auto_create_meeting) form.value.meeting_link = '';
};

watch(() => form.value.interview_mode, (mode) => {
  if (mode === 'ONSITE') {
    form.value.auto_create_meeting = false;
    return;
  }
  if (!form.value.id && !form.value.meeting_link) {
    form.value.auto_create_meeting = true;
  }
});

const openManagerReview = (item) => {
  reviewForm.value = {
    id: item.id,
    candidate_id: item.candidate_id,
    candidate_name: item.candidate_name,
    decision: 'approved',
    note: ''
  };
  showReviewModal.value = true;
};

const submitManagerReview = async () => {
  if (!reviewForm.value.id) return;
  reviewLoading.value = true;
  try {
    await recruitmentService.interviewManagerReview(reviewForm.value.id, {
      decision: reviewForm.value.decision,
      note: reviewForm.value.note
    });
    toast.success('Đã gửi đánh giá của quản lý');
    showReviewModal.value = false;
    await loadData();
  } catch (err) {
    console.error('Error submitting manager review:', err);
    toast.error('Có lỗi xảy ra khi gửi đánh giá của quản lý');
  } finally {
    reviewLoading.value = false;
  }
};

const deleteItem = async (id) => {
  if (!confirm('Bạn có chắc chắn muốn xóa lịch phỏng vấn này?')) return;
  try {
    await recruitmentService.deleteInterview(id);
    toast.success('Xóa lịch phỏng vấn thành công');
    await loadData();
  } catch (err) {
    console.error('Error deleting interview:', err);
    toast.error('Có lỗi xảy ra khi xóa lịch phỏng vấn');
  }
};

onMounted(async () => {
  await loadData();
});
</script>
