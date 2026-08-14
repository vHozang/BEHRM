<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-3xl font-bold text-foreground">Tin Tức Nội Bộ</h1>
        <p class="text-muted-foreground mt-1">Thông báo, tin tức và hoạt động nội bộ của công ty</p>
      </div>
      <BaseButton v-if="isAdmin && activeTab === 'news'" @click="openCreateModal">+ Đăng tin tức</BaseButton>
    </div>

    <div v-if="isAdmin" class="flex gap-2 rounded-xl border border-border bg-card p-2"><button class="rounded-lg px-3 py-2 text-sm font-semibold" :class="activeTab === 'news' ? 'bg-primary text-primary-foreground' : 'text-muted-foreground'" @click="activeTab = 'news'">Tin tức</button><button class="rounded-lg px-3 py-2 text-sm font-semibold" :class="activeTab === 'categories' ? 'bg-primary text-primary-foreground' : 'text-muted-foreground'" @click="activeTab = 'categories'">Danh mục tin</button></div>

    <!-- Articles Grid -->
    <div v-if="activeTab === 'news'" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
      <BaseCard 
        v-for="item in newsList" 
        :key="item.id" 
        class="flex flex-col justify-between hover-elevate transition-all duration-200"
      >
        <div class="space-y-3">
          <div class="flex items-center justify-between">
            <span class="text-xs font-semibold text-primary uppercase tracking-wider">Tin nội bộ</span>
            <BaseBadge v-if="isAdmin" :variant="item.status === 'published' ? 'success' : 'secondary'">
              {{ item.status === 'published' ? 'Đã đăng' : 'Bản nháp' }}
            </BaseBadge>
          </div>
          
          <h2 class="text-lg font-bold text-foreground line-clamp-2 leading-snug group-hover:text-primary">{{ item.title }}</h2>
          <p class="text-sm text-muted-foreground line-clamp-4 leading-relaxed">{{ getPlainText(item.content) }}</p>
        </div>

        <div class="mt-6 pt-4 border-t border-border flex items-center justify-between text-xs text-muted-foreground">
          <div class="flex items-center gap-1">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
            {{ formatDate(item.created_at) }}
          </div>
          
          <div class="flex gap-2">
            <button 
              @click="readMore(item)" 
              class="text-primary font-bold hover:underline"
            >
              Đọc tiếp
            </button>
            <button 
              v-if="isAdmin" 
              @click="editItem(item)" 
              class="text-amber-600 font-medium hover:underline"
            >
              Sửa
            </button>
            <button 
              v-if="isAdmin" 
              @click="deleteItem(item.id)" 
              class="text-destructive font-medium hover:underline"
            >
              Xóa
            </button>
          </div>
        </div>
      </BaseCard>

      <div v-if="newsList.length === 0" class="col-span-full text-center py-12 text-muted-foreground bg-card border rounded-xl">
        Chưa có tin tức nội bộ nào được đăng.
      </div>
    </div>
    <ResourceCrudPanel v-else resource="news-categories" title="Danh mục tin tức" :columns="categoryColumns" :fields="categoryFields" :defaults="{ status: 'ACTIVE' }" />

    <!-- Read Detail Modal -->
    <BaseModal v-model="showReadModal" :title="currentArticle?.title || 'Chi tiết thông báo'" size="lg">
      <div v-if="currentArticle" class="space-y-4">
        <div class="flex items-center gap-4 text-xs text-muted-foreground border-b pb-3">
          <span>Tác giả: Ban nhân sự</span>
          <span>•</span>
          <span>Ngày đăng: {{ formatDate(currentArticle.created_at) }}</span>
        </div>
        <div class="prose dark:prose-invert max-w-none text-foreground leading-relaxed whitespace-pre-wrap">
          {{ currentArticle.content }}
        </div>
      </div>
      <template #footer>
        <BaseButton @click="showReadModal = false">Đóng</BaseButton>
      </template>
    </BaseModal>

    <!-- Create/Edit Modal (Admin only) -->
    <BaseModal v-model="showEditModal" :title="form.id ? 'Sửa tin tức' : 'Đăng tin tức mới'" size="lg">
      <div class="space-y-4">
        <BaseInput v-model="form.title" label="Tiêu đề thông báo / Tin tức" required />
        <label class="block text-sm font-medium">Danh mục<ResourceSelect v-model="form.category_id" resource="news-categories" label-key="category_name" code-key="category_code" /></label>
        
        <div>
          <label class="block text-sm font-medium text-foreground mb-1">Nội dung tin tức <span class="text-destructive">*</span></label>
          <textarea 
            v-model="form.content" 
            class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground text-sm focus:outline-none focus:ring-2 focus:ring-ring" 
            rows="8" 
            placeholder="Viết nội dung thông báo chi tiết..."
            required
          ></textarea>
        </div>

        <div>
          <label class="block text-sm font-medium text-foreground mb-1">Trạng thái phát hành</label>
          <select 
            v-model="form.status" 
            class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground text-sm focus:outline-none focus:ring-2 focus:ring-ring"
          >
            <option value="published">Xuất bản ngay (Published)</option>
            <option value="draft">Bản nháp (Draft)</option>
          </select>
        </div>
      </div>
      <template #footer>
        <BaseButton variant="outline" @click="showEditModal = false">Hủy</BaseButton>
        <BaseButton @click="submitForm">Lưu</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { authService } from '../services/authService';
import BaseButton from '../components/BaseButton.vue';
import BaseCard from '../components/BaseCard.vue';
import BaseModal from '../components/BaseModal.vue';
import BaseInput from '../components/BaseInput.vue';
import BaseBadge from '../components/BaseBadge.vue';
import ResourceCrudPanel from '../components/ResourceCrudPanel.vue';
import ResourceSelect from '../components/ResourceSelect.vue';
import { communicationService } from '../services/communicationService';
import { useToast } from '../composables/useToast';

const toast = useToast();
const newsList = ref([]);
// isAdmin phải theo authService (user_role) — user.is_admin KHÔNG tồn tại trên
// bản ghi employee (chỉ có is_super_admin) nên admin không bao giờ thấy nút quản lý.
const isAdmin = computed(() => authService.hasCapability('communications.manage'));
const activeTab = ref('news');
const categoryColumns = [{ key: 'category_code', label: 'Mã', mono: true }, { key: 'category_name', label: 'Tên danh mục' }, { key: 'description', label: 'Mô tả' }, { key: 'status', label: 'Trạng thái' }];
const categoryFields = [{ key: 'category_code', label: 'Mã', required: true }, { key: 'category_name', label: 'Tên danh mục', required: true }, { key: 'description', label: 'Mô tả', type: 'textarea', full: true, nullable: true }, { key: 'status', label: 'Trạng thái', type: 'select', options: [{ value: 'ACTIVE', label: 'Hoạt động' }, { value: 'INACTIVE', label: 'Ngừng dùng' }] }];

const showReadModal = ref(false);
const showEditModal = ref(false);
const currentArticle = ref(null);

const form = ref({
  title: '',
  category_id: '',
  content: '',
  status: 'published'
});

const loadData = async () => {
  try {
    const res = await communicationService.getAllNews();
    newsList.value = res?.data || res || [];
  } catch (err) {
    console.error('Error loading news:', err);
    newsList.value = [];
  }
};

const formatDate = (date) => {
  if (!date) return '-';
  return new Date(date).toLocaleDateString('vi-VN');
};

const getPlainText = (html) => {
  if (!html) return '';
  return html.replace(/<[^>]*>/g, '');
};

const readMore = async (item) => {
  currentArticle.value = item;
  showReadModal.value = true;
  // Mark the news item as read server-side when the user opens it
  if (item && item.id && !item.is_read) {
    try {
      await communicationService.markRead(item.id);
      item.is_read = true;
    } catch (err) {
      console.error('Error marking news as read:', err);
    }
  }
};

const openCreateModal = () => {
  form.value = {
    title: '',
    category_id: '',
    content: '',
    status: 'published'
  };
  showEditModal.value = true;
};

const editItem = (item) => {
  form.value = { ...item };
  showEditModal.value = true;
};

const submitForm = async () => {
  if (!form.value.title || !form.value.content) {
    toast.error('Vui lòng nhập đầy đủ tiêu đề và nội dung');
    return;
  }
  try {
    if (form.value.id) {
      await communicationService.updateNews(form.value.id, form.value);
      toast.success('Cập nhật tin tức thành công');
    } else {
      await communicationService.createNews(form.value);
      toast.success('Đăng tin tức thành công');
    }
    showEditModal.value = false;
    await loadData();
  } catch (err) {
    console.error('Error saving news:', err);
    toast.error('Có lỗi xảy ra khi lưu tin tức');
  }
};

const deleteItem = async (id) => {
  if (!confirm('Bạn có chắc chắn muốn xóa tin tức này?')) return;
  try {
    await communicationService.deleteNews(id);
    toast.success('Xóa tin tức thành công');
    await loadData();
  } catch (err) {
    console.error('Error deleting news:', err);
    toast.error('Có lỗi xảy ra khi xóa tin tức');
  }
};

onMounted(loadData);
</script>
