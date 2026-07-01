<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-3xl font-bold text-foreground">Tin Tuyển Dụng (JD)</h1>
        <p class="text-muted-foreground mt-1">Danh mục các vị trí và cơ hội nghề nghiệp đang mở tuyển</p>
      </div>
      <BaseButton @click="openCreateModal">+ Thêm vị trí tuyển</BaseButton>
    </div>

    <!-- Table Card -->
    <BaseCard>
      <BaseTable
        :columns="[
          { key: 'title', label: 'Tên vị trí / Chức danh' },
          { key: 'quantity', label: 'Số lượng' },
          { key: 'salary_range', label: 'Mức lương (VNĐ)' },
          { key: 'deadline', label: 'Hạn nộp hồ sơ' },
          { key: 'status', label: 'Trạng thái' }
        ]"
        :data="positions"
      >
        <template #cell-title="{ item }">
          <div class="font-medium text-foreground">
            {{ item.title }}
          </div>
          <div class="text-xs text-muted-foreground line-clamp-1">
            {{ item.description || 'Không có mô tả' }}
          </div>
        </template>

        <template #cell-quantity="{ item }">
          <span class="font-semibold">{{ item.quantity || 1 }} người</span>
        </template>

        <template #cell-salary_range="{ item }">
          <span class="text-primary font-medium">{{ item.salary_range || 'Thỏa thuận' }}</span>
        </template>

        <template #cell-deadline="{ item }">
          <span>{{ formatDate(item.deadline) }}</span>
        </template>

        <template #cell-status="{ item }">
          <BaseBadge :variant="isDeadlinePassed(item.deadline) || item.status === 'closed' ? 'secondary' : 'success'">
            {{ item.status === 'closed' || isDeadlinePassed(item.deadline) ? 'Đã đóng' : 'Đang tuyển' }}
          </BaseBadge>
        </template>

        <template #actions="{ item }">
          <div class="flex gap-1">
            <button 
              @click="editItem(item)" 
              class="px-2.5 py-1.5 text-xs font-medium rounded-md bg-primary/10 text-primary hover:bg-primary/20 transition-colors"
            >
              Sửa
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
    <BaseModal v-model="showModal" :title="form.id ? 'Cập nhật tin tuyển dụng' : 'Thêm tin tuyển dụng mới'">
      <div class="space-y-4">
        <BaseInput v-model="form.title" label="Tiêu đề tin tuyển dụng" required />
        <div class="grid grid-cols-2 gap-4">
          <BaseInput v-model.number="form.quantity" type="number" label="Số lượng cần tuyển" required />
          <BaseInput v-model="form.salary_range" label="Mức lương (Ví dụ: 15-20 triệu)" />
        </div>
        <BaseInput v-model="form.deadline" type="date" label="Hạn nộp hồ sơ" required />
        
        <div>
          <label class="block text-sm font-medium text-foreground mb-1">Mô tả công việc (JD) & Yêu cầu</label>
          <textarea 
            v-model="form.description" 
            class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground text-sm focus:outline-none focus:ring-2 focus:ring-ring" 
            rows="5" 
            placeholder="Nhập mô tả vị trí và các yêu cầu tuyển dụng..."
          ></textarea>
        </div>

        <div>
          <label class="block text-sm font-medium text-foreground mb-1">Trạng thái tin</label>
          <select 
            v-model="form.status" 
            class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground text-sm focus:outline-none focus:ring-2 focus:ring-ring"
          >
            <option value="open">Mở tuyển dụng (Open)</option>
            <option value="closed">Đóng tuyển dụng (Closed)</option>
          </select>
        </div>
      </div>
      <template #footer>
        <BaseButton variant="outline" @click="showModal = false">Hủy</BaseButton>
        <BaseButton @click="submitForm">Lưu</BaseButton>
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
import { recruitmentService } from '../services/recruitmentService';
import { useToast } from '../composables/useToast';

const toast = useToast();
const positions = ref([]);
const showModal = ref(false);

const form = ref({
  title: '',
  quantity: 1,
  salary_range: '',
  deadline: '',
  description: '',
  status: 'open'
});

const loadData = async () => {
  try {
    const res = await recruitmentService.getAllPositions();
    positions.value = res?.data || res || [];
  } catch (err) {
    console.error('Error loading positions:', err);
    positions.value = [];
  }
};

const formatDate = (date) => {
  if (!date) return '-';
  return new Date(date).toLocaleDateString('vi-VN');
};

const isDeadlinePassed = (deadline) => {
  if (!deadline) return false;
  return new Date(deadline) < new Date().setHours(0,0,0,0);
};

const openCreateModal = () => {
  form.value = {
    title: '',
    quantity: 1,
    salary_range: '',
    deadline: '',
    description: '',
    status: 'open'
  };
  showModal.value = true;
};

const editItem = (item) => {
  form.value = { ...item };
  showModal.value = true;
};

const submitForm = async () => {
  if (!form.value.title || !form.value.deadline) {
    toast.error('Vui lòng điền đầy đủ các thông tin bắt buộc');
    return;
  }
  try {
    if (form.value.id) {
      await recruitmentService.updatePosition(form.value.id, form.value);
      toast.success('Cập nhật tin tuyển dụng thành công');
    } else {
      await recruitmentService.createPosition(form.value);
      toast.success('Đăng tuyển dụng mới thành công');
    }
    showModal.value = false;
    await loadData();
  } catch (err) {
    console.error('Error saving position:', err);
    toast.error('Có lỗi xảy ra khi lưu thông tin');
  }
};

const deleteItem = async (id) => {
  if (!confirm('Bạn có chắc chắn muốn xóa tin tuyển dụng này?')) return;
  try {
    await recruitmentService.deletePosition(id);
    toast.success('Xóa tin tuyển dụng thành công');
    await loadData();
  } catch (err) {
    console.error('Error deleting position:', err);
    toast.error('Có lỗi xảy ra khi xóa tin tuyển dụng');
  }
};

onMounted(async () => {
  await loadData();
});
</script>
