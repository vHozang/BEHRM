<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-3xl font-bold text-foreground">Quản lý Chức danh</h1>
        <p class="text-muted-foreground mt-1">Danh sách các chức danh công việc</p>
      </div>
      <BaseButton @click="showCreateModal = true">+ Thêm chức danh</BaseButton>
    </div>

    <BaseCard>
      <BaseTable
        :columns="[
          { key: 'code', label: 'Mã' },
          { key: 'name', label: 'Tên chức danh' },
          { key: 'job_level', label: 'Cấp độ' },
          { key: 'is_active', label: 'Trạng thái' }
        ]"
        :data="jobTitles"
      >
        <template #cell-job_level="{ item }">
          <span class="text-sm">{{ getLevelText(item.job_level) }}</span>
        </template>
        <template #cell-name="{ item }">
          <span :class="{ 'text-muted-foreground line-through': !item.is_active }">{{ item.name }}</span>
        </template>
        <template #cell-is_active="{ item }">
          <BaseBadge :variant="item.is_active ? 'success' : 'secondary'">
            {{ item.is_active ? 'Hoạt động' : 'Không hoạt động' }}
          </BaseBadge>
        </template>
        <template #actions="{ item }">
          <div class="flex gap-1">
            <button 
              @click="editItem(item)" 
              class="px-3 py-1.5 text-xs font-medium rounded-md bg-primary/10 text-primary hover:bg-primary/20 transition-colors"
              title="Chỉnh sửa"
            >
              Chi tiết
            </button>
            <button 
              v-if="item.is_active"
              @click="deactivateItem(item)" 
              class="px-3 py-1.5 text-xs font-medium rounded-md bg-amber-100 text-amber-700 hover:bg-amber-200 dark:bg-amber-900/30 dark:text-amber-400 dark:hover:bg-amber-900/50 transition-colors"
              title="Tạm ngưng"
            >
              Tạm ngưng
            </button>
            <button 
              v-else
              @click="activateItem(item)" 
              class="px-3 py-1.5 text-xs font-medium rounded-md bg-green-100 text-green-700 hover:bg-green-200 dark:bg-green-900/30 dark:text-green-400 dark:hover:bg-green-900/50 transition-colors"
              title="Kích hoạt"
            >
              Kích hoạt
            </button>
          </div>
        </template>
      </BaseTable>
    </BaseCard>

    <BaseModal v-model="showCreateModal" :title="form.id ? 'Chỉnh sửa chức danh' : 'Thêm chức danh'">
      <div class="space-y-4">
        <BaseInput v-model="form.code" label="Mã chức danh" required />
        <BaseInput v-model="form.name" label="Tên chức danh" required />
        <BaseSelect
          v-model="form.job_level"
          label="Cấp độ"
          :options="[
            { value: 'entry', label: 'Mới vào' },
            { value: 'junior', label: 'Junior' },
            { value: 'senior', label: 'Senior' },
            { value: 'lead', label: 'Lead' },
            { value: 'manager', label: 'Manager' },
            { value: 'director', label: 'Director' },
            { value: 'executive', label: 'Executive' }
          ]"
          required
        />
        <div class="flex items-center gap-2">
          <input type="checkbox" v-model="form.is_active" class="rounded" />
          <label>Hoạt động</label>
        </div>
        <BaseButton @click="saveItem" class="w-full">Lưu</BaseButton>
      </div>
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
import BaseSelect from '../components/BaseSelect.vue';
import BaseBadge from '../components/BaseBadge.vue';
import { jobTitleService } from '../services/jobTitleService';
import { useToast } from '../composables/useToast';

const toast = useToast();
const jobTitles = ref([]);
const showCreateModal = ref(false);
const form = ref({
  code: '',
  name: '',
  job_level: '',
  is_active: true
});

const getLevelText = (level) => {
  const levels = {
    entry: 'Mới vào',
    junior: 'Junior',
    senior: 'Senior',
    lead: 'Lead',
    manager: 'Manager',
    director: 'Director',
    executive: 'Executive'
  };
  return levels[level] || level;
};

const loadData = async () => {
  try {
    const response = await jobTitleService.getAll();
    jobTitles.value = response?.data || response || [];
  } catch (err) {
    console.error('Error loading job titles:', err);
    jobTitles.value = [];
  }
};

const saveItem = async () => {
  try {
    if (form.value.id) {
      await jobTitleService.update(form.value.id, form.value);
      toast.success('Cập nhật chức danh thành công!');
    } else {
      await jobTitleService.create(form.value);
      toast.success('Thêm chức danh thành công!');
    }
    showCreateModal.value = false;
    form.value = { code: '', name: '', job_level: '', is_active: true };
    await loadData();
  } catch (err) {
    console.error('Error saving job title:', err);
    toast.error('Có lỗi xảy ra khi lưu chức danh');
  }
};

const editItem = (item) => {
  form.value = { ...item };
  showCreateModal.value = true;
};

const deactivateItem = async (item) => {
  try {
    const payload = {
      code: item.code,
      name: item.name,
      job_level: item.job_level,
      job_family_id: item.job_family_id,
      is_active: 0
    };
    await jobTitleService.update(item.id, payload);
    toast.success('Đã tạm ngưng chức danh');
    await loadData();
  } catch (err) {
    console.error('Error deactivating job title:', err);
    toast.error('Có lỗi xảy ra khi tạm ngưng chức danh');
  }
};

const activateItem = async (item) => {
  try {
    const payload = {
      code: item.code,
      name: item.name,
      job_level: item.job_level,
      job_family_id: item.job_family_id,
      is_active: 1
    };
    await jobTitleService.update(item.id, payload);
    toast.success('Đã kích hoạt chức danh');
    await loadData();
  } catch (err) {
    console.error('Error activating job title:', err);
    toast.error('Có lỗi xảy ra khi kích hoạt chức danh');
  }
};

onMounted(async () => {
  await loadData();
});
</script>
