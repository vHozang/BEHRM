<template>
  <div class="space-y-6">
    <div class="flex gap-2 border-b border-border">
      <button v-for="tab in tabs" :key="tab.id" class="border-b-2 px-4 py-2 text-sm font-semibold" :class="activeTab === tab.id ? 'border-primary text-primary' : 'border-transparent text-muted-foreground'" @click="activeTab = tab.id">{{ tab.label }}</button>
    </div>

    <template v-if="activeTab === 'posts'">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <h1 class="text-3xl font-bold text-foreground">Tin Tuyển Dụng (JD)</h1>
        <p class="mt-1 text-muted-foreground">Tin ở trạng thái “Đăng công khai” sẽ xuất hiện ngay trên landing page.</p>
      </div>
      <div class="flex gap-2">
        <a href="/careers" target="_blank" class="inline-flex items-center rounded-lg border border-border bg-card px-4 py-2.5 text-sm font-medium text-foreground transition-colors hover:bg-muted">
          Xem landing page ↗
        </a>
        <BaseButton @click="openCreateModal">+ Đăng tin mới</BaseButton>
      </div>
    </div>

    <BaseCard>
      <BaseTable
        :columns="[
          { key: 'title', label: 'Tên vị trí / Chức danh' },
          { key: 'quantity', label: 'Số lượng' },
          { key: 'salary_range', label: 'Mức lương (VNĐ)' },
          { key: 'deadline', label: 'Hạn nộp hồ sơ' },
          { key: 'status', label: 'Hiển thị' }
        ]"
        :data="posts"
      >
        <template #cell-title="{ item }">
          <div class="font-medium text-foreground">{{ item.title }}</div>
          <div class="max-w-md line-clamp-1 text-xs text-muted-foreground">{{ item.summary || 'Chưa có mô tả ngắn' }}</div>
        </template>

        <template #cell-quantity="{ item }"><span class="font-semibold">{{ item.quantity || 1 }} người</span></template>
        <template #cell-salary_range="{ item }"><span class="font-medium text-primary">{{ item.salary_range || 'Thỏa thuận' }}</span></template>
        <template #cell-deadline="{ item }"><span>{{ formatDate(item.deadline) }}</span></template>
        <template #cell-status="{ item }">
          <BaseBadge :variant="item.status === 'PUBLISHED' && !isDeadlinePassed(item.deadline) ? 'success' : 'secondary'">
            {{ statusLabel(item) }}
          </BaseBadge>
        </template>

        <template #actions="{ item }">
          <div class="flex gap-1">
            <button class="rounded-md bg-muted px-2.5 py-1.5 text-xs font-medium text-foreground transition-colors hover:bg-muted/70" @click="previewItem(item)">Xem JD</button>
            <button class="rounded-md bg-primary/10 px-2.5 py-1.5 text-xs font-medium text-primary transition-colors hover:bg-primary/20" @click="editItem(item)">Sửa</button>
            <button class="rounded-md bg-destructive/10 px-2.5 py-1.5 text-xs font-medium text-destructive transition-colors hover:bg-destructive/20" @click="deleteItem(item.id)">Xóa</button>
          </div>
        </template>
      </BaseTable>
    </BaseCard>

    <BaseModal v-model="showModal" size="lg" :title="form.id ? 'Cập nhật tin tuyển dụng' : 'Đăng tin tuyển dụng mới'">
      <div class="space-y-5">
        <div class="rounded-lg border border-primary/20 bg-primary/5 px-4 py-3 text-sm text-foreground">
          Chọn <strong>Đăng công khai</strong> để bài được đồng bộ lên trang <code>/careers</code> ngay sau khi lưu.
        </div>

        <BaseInput v-model="form.title" label="Tiêu đề tin tuyển dụng" placeholder="Ví dụ: Senior Backend Developer" required />
        <BaseInput v-model="form.summary" label="Mô tả ngắn trên landing page" placeholder="Một câu ngắn giúp ứng viên hiểu điểm hấp dẫn của vị trí" required />

        <div class="grid gap-4 md:grid-cols-2">
          <BaseInput v-model.number="form.quantity" type="number" label="Số lượng cần tuyển" required />
          <BaseInput v-model="form.salary_range" label="Mức lương" placeholder="Ví dụ: 20 - 35 triệu VNĐ" />
          <BaseInput v-model="form.location" label="Địa điểm làm việc" placeholder="TP. Hồ Chí Minh / Hybrid" required />
          <BaseInput v-model="form.deadline" type="date" label="Hạn nộp hồ sơ" required />
        </div>

        <div class="grid gap-4 md:grid-cols-2">
          <label class="block text-sm font-medium text-foreground">
            Hình thức làm việc
            <select v-model="form.employment_type" class="mt-2 w-full rounded-lg border border-input bg-background px-4 py-2.5 text-foreground focus:outline-none focus:ring-2 focus:ring-ring">
              <option value="FULL_TIME">Toàn thời gian</option>
              <option value="PART_TIME">Bán thời gian</option>
              <option value="CONTRACT">Hợp đồng</option>
              <option value="REMOTE">Từ xa</option>
            </select>
          </label>
          <label class="block text-sm font-medium text-foreground">
            Trạng thái tin
            <select v-model="form.status" class="mt-2 w-full rounded-lg border border-input bg-background px-4 py-2.5 text-foreground focus:outline-none focus:ring-2 focus:ring-ring">
              <option value="PUBLISHED">Đăng công khai trên landing</option>
              <option value="DRAFT">Lưu nháp</option>
              <option value="CLOSED">Đóng tuyển dụng</option>
            </select>
          </label>
        </div>

        <label class="block text-sm font-medium text-foreground">
          Mô tả công việc chi tiết (JD) <span class="text-destructive">*</span>
          <textarea v-model="form.content" rows="8" class="mt-2 w-full rounded-lg border border-input bg-background px-4 py-3 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring" placeholder="Mô tả vai trò, trách nhiệm chính, đội ngũ và những gì ứng viên sẽ thực hiện..."></textarea>
        </label>

        <div class="grid gap-4 md:grid-cols-2">
          <label class="block text-sm font-medium text-foreground">
            Yêu cầu ứng viên <span class="text-xs font-normal text-muted-foreground">(mỗi dòng một ý)</span>
            <textarea v-model="form.requirements_text" rows="6" class="mt-2 w-full rounded-lg border border-input bg-background px-4 py-3 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring" placeholder="Tối thiểu 2 năm kinh nghiệm&#10;Sử dụng tốt SQL&#10;Giao tiếp rõ ràng"></textarea>
          </label>
          <label class="block text-sm font-medium text-foreground">
            Quyền lợi <span class="text-xs font-normal text-muted-foreground">(mỗi dòng một ý)</span>
            <textarea v-model="form.benefits_text" rows="6" class="mt-2 w-full rounded-lg border border-input bg-background px-4 py-3 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring" placeholder="Bảo hiểm sức khỏe mở rộng&#10;Ngân sách học tập&#10;Làm việc linh hoạt"></textarea>
          </label>
        </div>

        <BaseInput v-model="form.required_skills_text" label="Kỹ năng dùng để AI chấm CV" hint="Phân cách bằng dấu phẩy, ví dụ: Laravel, PostgreSQL, Docker" placeholder="Laravel, PostgreSQL, Docker" />
      </div>

      <template #footer>
        <BaseButton variant="outline" @click="showModal = false">Hủy</BaseButton>
        <BaseButton :disabled="saving" @click="submitForm">{{ saving ? 'Đang lưu...' : (form.status === 'PUBLISHED' ? 'Lưu & đăng công khai' : 'Lưu tin') }}</BaseButton>
      </template>
    </BaseModal>

    <BaseModal v-model="showPreview" size="lg" title="Xem trước nội dung JD">
      <article v-if="previewPost" class="space-y-6">
        <div>
          <div class="mb-3 flex flex-wrap gap-2 text-xs text-muted-foreground">
            <span class="rounded-full border border-border px-3 py-1">{{ employmentLabel(previewPost.employment_type) }}</span>
            <span class="rounded-full border border-border px-3 py-1">{{ previewPost.location || 'Chưa cập nhật địa điểm' }}</span>
            <span class="rounded-full border border-border px-3 py-1">{{ previewPost.salary_range || 'Thỏa thuận' }}</span>
          </div>
          <h2 class="text-3xl font-bold text-foreground">{{ previewPost.title }}</h2>
          <p class="mt-3 text-muted-foreground">{{ previewPost.summary }}</p>
        </div>
        <section><h3 class="mb-2 text-lg font-semibold text-foreground">Mô tả công việc</h3><p class="whitespace-pre-line leading-7 text-muted-foreground">{{ previewPost.content || 'Chưa có nội dung JD.' }}</p></section>
        <section v-if="listValue(previewPost.requirements).length"><h3 class="mb-2 text-lg font-semibold text-foreground">Yêu cầu ứng viên</h3><ul class="list-disc space-y-2 pl-5 text-muted-foreground"><li v-for="item in listValue(previewPost.requirements)" :key="item">{{ item }}</li></ul></section>
        <section v-if="listValue(previewPost.benefits).length"><h3 class="mb-2 text-lg font-semibold text-foreground">Quyền lợi</h3><ul class="list-disc space-y-2 pl-5 text-muted-foreground"><li v-for="item in listValue(previewPost.benefits)" :key="item">{{ item }}</li></ul></section>
      </article>
      <template #footer><BaseButton variant="outline" @click="showPreview = false">Đóng</BaseButton></template>
    </BaseModal>
    </template>

    <ResourceCrudPanel
      v-else
      resource="recruitment-positions"
      title="Vị trí tuyển dụng"
      description="Danh mục vị trí dùng để nhận ứng viên và liên kết với bài đăng tuyển dụng. Chuyển trạng thái để mở/đóng tuyển."
      :columns="positionColumns"
      :fields="positionFields"
      :defaults="{ employment_type: 'FULL_TIME', status: 'OPEN', required_skills_json: [] }"
    />
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import BaseBadge from '../components/BaseBadge.vue';
import BaseButton from '../components/BaseButton.vue';
import BaseCard from '../components/BaseCard.vue';
import BaseInput from '../components/BaseInput.vue';
import BaseModal from '../components/BaseModal.vue';
import BaseTable from '../components/BaseTable.vue';
import ResourceCrudPanel from '../components/ResourceCrudPanel.vue';
import { useToast } from '../composables/useToast';
import { recruitmentService } from '../services/recruitmentService';

const toast = useToast();
const activeTab = ref('posts');
const tabs = [{ id: 'posts', label: 'Bài đăng tuyển dụng' }, { id: 'positions', label: 'Vị trí tuyển dụng' }];
const positionColumns = [
  { key: 'position_name', label: 'Tên vị trí' },
  { key: 'department_name', label: 'Phòng ban' },
  { key: 'employment_type', label: 'Hình thức' },
  { key: 'status', label: 'Trạng thái' },
];
const positionFields = [
  { key: 'position_name', label: 'Tên vị trí', required: true, full: true },
  { key: 'department_id', label: 'Phòng ban', type: 'resource', resource: 'departments', labelKey: 'department_name', codeKey: 'department_code', cast: 'number', nullable: true },
  { key: 'employment_type', label: 'Hình thức', type: 'select', options: [{ value: 'FULL_TIME', label: 'Toàn thời gian' }, { value: 'PART_TIME', label: 'Bán thời gian' }, { value: 'CONTRACT', label: 'Hợp đồng' }, { value: 'REMOTE', label: 'Từ xa' }], required: true },
  { key: 'status', label: 'Trạng thái', type: 'select', options: [{ value: 'OPEN', label: 'Đang tuyển' }, { value: 'CLOSED', label: 'Đã đóng' }], required: true },
  { key: 'required_skills_json', label: 'Kỹ năng yêu cầu', type: 'textarea', cast: 'json-array', full: true, help: 'Nhập mỗi kỹ năng một dòng hoặc phân cách bằng dấu phẩy' },
];
const posts = ref([]);
const showModal = ref(false);
const showPreview = ref(false);
const previewPost = ref(null);
const saving = ref(false);

const emptyForm = () => ({
  id: null,
  recruitment_position_id: null,
  title: '',
  summary: '',
  content: '',
  quantity: 1,
  salary_range: '',
  location: '',
  deadline: '',
  employment_type: 'FULL_TIME',
  status: 'PUBLISHED',
  requirements_text: '',
  benefits_text: '',
  required_skills_text: ''
});
const form = ref(emptyForm());

const listValue = (value) => {
  if (Array.isArray(value)) return value;
  if (!value) return [];
  try {
    const parsed = JSON.parse(value);
    return Array.isArray(parsed) ? parsed : [];
  } catch {
    return String(value).split('\n').map(item => item.trim()).filter(Boolean);
  }
};
const lines = (value) => String(value || '').split('\n').map(item => item.trim()).filter(Boolean);
const skills = (value) => String(value || '').split(',').map(item => item.trim()).filter(Boolean);
const employmentLabel = (type) => ({ FULL_TIME: 'Toàn thời gian', PART_TIME: 'Bán thời gian', CONTRACT: 'Hợp đồng', REMOTE: 'Từ xa' }[type] || type || 'Toàn thời gian');

const loadData = async () => {
  try {
    const data = await recruitmentService.getRecruitmentPosts({ per_page: 100 });
    posts.value = Array.isArray(data) ? data : [];
  } catch (error) {
    console.error('Error loading recruitment posts:', error);
    posts.value = [];
    toast.error('Không thể tải danh sách tin tuyển dụng');
  }
};

const formatDate = (date) => date ? new Date(date).toLocaleDateString('vi-VN') : '-';
const isDeadlinePassed = (deadline) => deadline ? new Date(deadline) < new Date().setHours(0, 0, 0, 0) : false;
const statusLabel = (item) => {
  if (isDeadlinePassed(item.deadline)) return 'Hết hạn';
  return { PUBLISHED: 'Đang công khai', DRAFT: 'Bản nháp', CLOSED: 'Đã đóng', ARCHIVED: 'Lưu trữ' }[item.status] || item.status;
};

const openCreateModal = () => {
  form.value = emptyForm();
  showModal.value = true;
};

const editItem = (item) => {
  form.value = {
    ...emptyForm(),
    ...item,
    requirements_text: listValue(item.requirements).join('\n'),
    benefits_text: listValue(item.benefits).join('\n'),
    required_skills_text: listValue(item.required_skills || item.required_skills_json).join(', '),
    quantity: Number(item.quantity || 1)
  };
  showModal.value = true;
};

const previewItem = async (item) => {
  try {
    previewPost.value = await recruitmentService.getRecruitmentPost(item.id);
  } catch {
    previewPost.value = item;
  }
  showPreview.value = true;
};

const submitForm = async () => {
  if (!form.value.title || !form.value.summary || !form.value.content || !form.value.location || !form.value.deadline) {
    toast.error('Vui lòng điền tiêu đề, mô tả ngắn, JD, địa điểm và hạn nộp hồ sơ');
    return;
  }

  const payload = {
    title: form.value.title,
    summary: form.value.summary,
    content: form.value.content,
    location: form.value.location,
    salary_range: form.value.salary_range,
    deadline: form.value.deadline,
    employment_type: form.value.employment_type,
    status: form.value.status,
    requirements: lines(form.value.requirements_text),
    benefits: lines(form.value.benefits_text),
    meta: {
      quantity: Number(form.value.quantity || 1),
      required_skills: skills(form.value.required_skills_text)
    }
  };
  if (form.value.recruitment_position_id) payload.recruitment_position_id = form.value.recruitment_position_id;

  saving.value = true;
  try {
    if (form.value.id) {
      await recruitmentService.updateRecruitmentPost(form.value.id, payload);
      toast.success(form.value.status === 'PUBLISHED' ? 'Đã cập nhật và đồng bộ lên landing page' : 'Đã cập nhật tin tuyển dụng');
    } else {
      await recruitmentService.createRecruitmentPost(payload);
      toast.success(form.value.status === 'PUBLISHED' ? 'Đã đăng tin lên landing page' : 'Đã lưu bản nháp');
    }
    showModal.value = false;
    await loadData();
  } catch (error) {
    console.error('Error saving recruitment post:', error);
    toast.error(error.response?.data?.message || 'Có lỗi xảy ra khi lưu tin tuyển dụng');
  } finally {
    saving.value = false;
  }
};

const deleteItem = async (id) => {
  if (!confirm('Bạn có chắc chắn muốn xóa tin tuyển dụng này? Tin sẽ biến mất khỏi landing page.')) return;
  try {
    await recruitmentService.deleteRecruitmentPost(id);
    toast.success('Đã xóa tin tuyển dụng');
    await loadData();
  } catch (error) {
    console.error('Error deleting recruitment post:', error);
    toast.error('Không thể xóa tin tuyển dụng');
  }
};

onMounted(loadData);
</script>
