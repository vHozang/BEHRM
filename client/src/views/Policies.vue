<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-3xl font-bold text-foreground">Chính Sách & Quy Định</h1>
        <p class="text-muted-foreground mt-1">Các quy chế hoạt động, nội quy công ty và chính sách phúc lợi chính thức</p>
      </div>
      <BaseButton v-if="isAdmin" @click="openCreateModal">+ Thêm chính sách</BaseButton>
    </div>

    <!-- Policy List Table -->
    <BaseCard>
      <BaseTable
        :columns="[
          { key: 'title', label: 'Tên chính sách' },
          { key: 'version', label: 'Phiên bản' },
          { key: 'published_date', label: 'Ngày ban hành' },
          { key: 'acknowledgment', label: 'Xác nhận của bạn' }
        ]"
        :data="policies"
      >
        <template #cell-title="{ item }">
          <div class="font-semibold text-foreground flex items-center gap-2">
            <svg class="w-4 h-4 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" /></svg>
            {{ item.title }}
          </div>
          <div class="text-xs text-muted-foreground line-clamp-1 mt-0.5">{{ item.description || 'Quy định nội bộ' }}</div>
        </template>

        <template #cell-version="{ item }">
          <span class="font-mono text-xs px-2 py-0.5 bg-muted border rounded">v{{ item.version || '1.0' }}</span>
        </template>

        <template #cell-published_date="{ item }">
          <span>{{ formatDate(item.created_at || item.published_date) }}</span>
        </template>

        <template #cell-acknowledgment="{ item }">
          <!-- Acknowledgment status for employee -->
          <div v-if="!isAdmin">
            <BaseBadge v-if="item.is_acknowledged || item.acknowledged" variant="success" class="flex items-center gap-1 w-max">
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
              Đã ký xác nhận
            </BaseBadge>
            <BaseBadge v-else variant="warning" class="w-max">Chưa xác nhận</BaseBadge>
          </div>
          <div v-else>
            <!-- Acknowledgment count for admin -->
            <span class="text-xs font-semibold text-primary">
              {{ item.acknowledgments_count || 0 }} lượt xác nhận
            </span>
          </div>
        </template>

        <template #actions="{ item }">
          <div class="flex gap-2">
            <button 
              @click="readPolicy(item)" 
              class="px-2.5 py-1.5 text-xs font-medium rounded-md bg-primary/10 text-primary hover:bg-primary/20 transition-colors"
            >
              Xem nội dung
            </button>
            <button 
              v-if="isAdmin" 
              @click="deleteItem(item.id)" 
              class="px-2.5 py-1.5 text-xs font-medium rounded-md bg-destructive/10 text-destructive hover:bg-destructive/20 transition-colors"
            >
              Xóa
            </button>
          </div>
        </template>
      </BaseTable>
    </BaseCard>

    <!-- Read Detail & Sign Modal -->
    <BaseModal v-model="showReadModal" :title="currentPolicy?.title || 'Xem văn bản'" size="lg">
      <div v-if="currentPolicy" class="space-y-6">
        <div class="flex items-center justify-between text-xs text-muted-foreground border-b pb-3">
          <span>Ban hành: {{ formatDate(currentPolicy.created_at || currentPolicy.published_date) }}</span>
          <span>Mã số: v{{ currentPolicy.version || '1.0' }}</span>
        </div>
        
        <div class="bg-card p-4 rounded-xl border max-h-[300px] overflow-y-auto whitespace-pre-wrap text-sm leading-relaxed">
          {{ currentPolicy.content || 'Nội dung điều khoản đang cập nhật...' }}
        </div>

        <!-- Acknowledgment Action Box -->
        <div 
          v-if="!isAdmin && !(currentPolicy.is_acknowledged || currentPolicy.acknowledged)" 
          class="p-4 bg-primary/5 rounded-xl border border-primary/20 space-y-4"
        >
          <h4 class="font-bold text-primary flex items-center gap-1.5 text-sm">
            <svg class="w-4 h-4 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" /></svg>
            Ký Xác Nhận Nội Bộ
          </h4>
          <p class="text-xs text-muted-foreground leading-normal">Vui lòng vẽ chữ ký tay của bạn vào khung bên dưới để cam kết tuân thủ chính sách mới của công ty.</p>
          
          <SignaturePad @save="signPolicy" />
        </div>
        <div v-else-if="!isAdmin" class="flex flex-col gap-3 p-4 bg-green-50 dark:bg-emerald-950/20 border border-green-200 dark:border-emerald-900/50 rounded-xl">
          <div class="flex items-center gap-2 text-green-700 dark:text-emerald-400 text-sm font-semibold">
            <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            Bạn đã ký số xác nhận đồng ý với chính sách này vào ngày {{ formatDate(currentPolicy.acknowledged_at || new Date()) }}.
          </div>
          <div v-if="currentPolicy.signature" class="bg-white p-2 rounded-xl border border-border w-max">
            <img :src="currentPolicy.signature" alt="Chữ ký đã xác nhận" class="h-16 object-contain" />
          </div>
        </div>
      </div>
      <template #footer>
        <BaseButton variant="outline" @click="showReadModal = false">Đóng</BaseButton>
      </template>
    </BaseModal>

    <!-- Create Policy Modal (Admin only) -->
    <BaseModal v-model="showCreateModal" title="Thêm chính sách mới">
      <div class="space-y-4">
        <BaseInput v-model="form.title" label="Tiêu đề chính sách" required />
        <BaseInput v-model="form.version" label="Phiên bản (Ví dụ: 1.0, 1.2)" placeholder="1.0" required />
        <BaseInput v-model="form.description" label="Tóm tắt ngắn gọn" />
        
        <div>
          <label class="block text-sm font-medium text-foreground mb-1">Nội dung văn bản chính sách <span class="text-destructive">*</span></label>
          <textarea 
            v-model="form.content" 
            class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground text-sm focus:outline-none focus:ring-2 focus:ring-ring" 
            rows="8" 
            placeholder="Nhập toàn bộ nội dung văn bản chính thức..."
            required
          ></textarea>
        </div>
      </div>
      <template #footer>
        <BaseButton variant="outline" @click="showCreateModal = false">Hủy</BaseButton>
        <BaseButton @click="submitForm">Lưu & Ban hành</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import BaseButton from '../components/BaseButton.vue';
import BaseCard from '../components/BaseCard.vue';
import BaseTable from '../components/BaseTable.vue';
import BaseModal from '../components/BaseModal.vue';
import BaseInput from '../components/BaseInput.vue';
import BaseBadge from '../components/BaseBadge.vue';
import SignaturePad from '../components/SignaturePad.vue';
import { communicationService } from '../services/communicationService';
import { useToast } from '../composables/useToast';

const toast = useToast();
const policies = ref([]);
const isAdmin = ref(false);

const showReadModal = ref(false);
const showCreateModal = ref(false);
const currentPolicy = ref(null);
const signLoading = ref(false);

const form = ref({
  title: '',
  version: '1.0',
  description: '',
  content: ''
});

const loadData = async () => {
  try {
    const res = await communicationService.getAllPolicies();
    policies.value = res?.data || res || [];
  } catch (err) {
    console.error('Error loading policies:', err);
    policies.value = [];
  }
};

const formatDate = (date) => {
  if (!date) return '-';
  return new Date(date).toLocaleDateString('vi-VN');
};

const readPolicy = (item) => {
  currentPolicy.value = item;
  showReadModal.value = true;
};

const signPolicy = async (signatureBase64) => {
  if (!currentPolicy.value.id) return;
  signLoading.value = true;
  try {
    await communicationService.acknowledge(currentPolicy.value.id, { signature: signatureBase64 });
    toast.success('Ký xác nhận chính sách thành công!');
    currentPolicy.value.is_acknowledged = true;
    currentPolicy.value.acknowledged = true;
    currentPolicy.value.acknowledged_at = new Date().toISOString();
    currentPolicy.value.signature = signatureBase64;
    await loadData();
  } catch (err) {
    console.error('Error signing policy:', err);
    toast.error('Có lỗi xảy ra khi ký xác nhận chính sách');
  } finally {
    signLoading.value = false;
  }
};

const openCreateModal = () => {
  form.value = {
    title: '',
    version: '1.0',
    description: '',
    content: ''
  };
  showCreateModal.value = true;
};

const submitForm = async () => {
  if (!form.value.title || !form.value.content || !form.value.version) {
    toast.error('Vui lòng nhập đầy đủ tiêu đề, phiên bản và nội dung');
    return;
  }
  try {
    await communicationService.createPolicy(form.value);
    toast.success('Ban hành chính sách mới thành công');
    showCreateModal.value = false;
    await loadData();
  } catch (err) {
    console.error('Error creating policy:', err);
    toast.error('Có lỗi xảy ra khi lưu chính sách');
  }
};

const deleteItem = async (id) => {
  if (!confirm('Bạn có chắc chắn muốn xóa văn bản chính sách này?')) return;
  try {
    await communicationService.deletePolicy(id);
    toast.success('Xóa chính sách thành công');
    await loadData();
  } catch (err) {
    console.error('Error deleting policy:', err);
    toast.error('Có lỗi xảy ra khi xóa');
  }
};

onMounted(async () => {
  try {
    const user = JSON.parse(localStorage.getItem('user') || '{}');
    isAdmin.value = user && user.is_admin;
  } catch (e) {
    isAdmin.value = false;
  }
  await loadData();
});
</script>
