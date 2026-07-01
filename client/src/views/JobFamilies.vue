<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-3xl font-bold text-foreground">Quản lý Nhóm chức danh</h1>
        <p class="text-muted-foreground mt-1">Danh sách các nhóm chức danh</p>
      </div>
      <BaseButton @click="showCreateModal = true">+ Thêm nhóm</BaseButton>
    </div>

    <BaseCard>
      <BaseTable
        :columns="[
          { key: 'code', label: 'Mã' },
          { key: 'name', label: 'Tên nhóm' },
          { key: 'description', label: 'Mô tả' },
          { key: 'is_active', label: 'Trạng thái' }
        ]"
        :data="jobFamilies"
      >
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

    <BaseModal v-model="showCreateModal" :title="form.id ? 'Chỉnh sửa nhóm chức danh' : 'Thêm nhóm chức danh'">
      <div class="space-y-4">
        <BaseInput v-model="form.code" label="Mã nhóm" required />
        <BaseInput v-model="form.name" label="Tên nhóm" required />
        <BaseInput v-model="form.description" label="Mô tả" />
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
import BaseBadge from '../components/BaseBadge.vue';
import { jobFamilyService } from '../services/jobFamilyService';
import { useToast } from '../composables/useToast';

const toast = useToast();
const jobFamilies = ref([]);
const showCreateModal = ref(false);
const form = ref({
  code: '',
  name: '',
  description: '',
  is_active: true
});

const loadData = async () => {
  try {
    const response = await jobFamilyService.getAll();
    jobFamilies.value = response?.data || response || [];
  } catch (err) {
    console.error('Error loading job families:', err);
    jobFamilies.value = [];
  }
};

const saveItem = async () => {
  try {
    if (form.value.id) {
      await jobFamilyService.update(form.value.id, form.value);
      toast.success('Cập nhật nhóm chức danh thành công!');
    } else {
      await jobFamilyService.create(form.value);
      toast.success('Thêm nhóm chức danh thành công!');
    }
    showCreateModal.value = false;
    form.value = { code: '', name: '', description: '', is_active: true };
    await loadData();
  } catch (err) {
    console.error('Error saving job family:', err);
    toast.error('Có lỗi xảy ra khi lưu nhóm chức danh');
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
      description: item.description,
      is_active: 0
    };
    await jobFamilyService.update(item.id, payload);
    toast.success('Đã tạm ngưng nhóm chức danh');
    await loadData();
  } catch (err) {
    console.error('Error deactivating job family:', err);
    toast.error('Có lỗi xảy ra khi tạm ngưng nhóm chức danh');
  }
};

const activateItem = async (item) => {
  try {
    const payload = {
      code: item.code,
      name: item.name,
      description: item.description,
      is_active: 1
    };
    await jobFamilyService.update(item.id, payload);
    toast.success('Đã kích hoạt nhóm chức danh');
    await loadData();
  } catch (err) {
    console.error('Error activating job family:', err);
    toast.error('Có lỗi xảy ra khi kích hoạt nhóm chức danh');
  }
};

onMounted(async () => {
  await loadData();
});
</script>
