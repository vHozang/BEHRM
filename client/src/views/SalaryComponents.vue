<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-3xl font-bold text-foreground">Quản lý Thành phần lương</h1>
        <p class="text-muted-foreground mt-1">Danh sách các thành phần lương</p>
      </div>
      <BaseButton @click="showCreateModal = true">+ Thêm thành phần</BaseButton>
    </div>

    <BaseCard>
      <BaseTable
        :columns="[
          { key: 'code', label: 'Mã' },
          { key: 'name', label: 'Tên thành phần' },
          { key: 'type', label: 'Loại' },
          { key: 'category', label: 'Danh mục' },
          { key: 'is_active', label: 'Trạng thái' }
        ]"
        :data="salaryComponents"
      >
        <template #cell-name="{ item }">
          <span :class="{ 'text-muted-foreground line-through': !item.is_active }">{{ item.name }}</span>
        </template>
        <template #cell-type="{ item }">
          <span class="text-sm">{{ item.type === 'earning' ? 'Thu nhập' : 'Khấu trừ' }}</span>
        </template>
        <template #cell-category="{ item }">
          <span class="text-sm">{{ getCategoryText(item.category) }}</span>
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

    <BaseModal v-model="showCreateModal" :title="form.id ? 'Chỉnh sửa thành phần lương' : 'Thêm thành phần lương'">
      <div class="space-y-4">
        <BaseInput v-model="form.code" label="Mã" required />
        <BaseInput v-model="form.name" label="Tên thành phần" required />
        <BaseSelect
          v-model="form.type"
          label="Loại"
          :options="[
            { value: 'earning', label: 'Thu nhập' },
            { value: 'deduction', label: 'Khấu trừ' }
          ]"
          required
        />
        <BaseSelect
          v-model="form.category"
          label="Danh mục"
          :options="[
            { value: 'basic', label: 'Lương cơ bản' },
            { value: 'allowance', label: 'Phụ cấp' },
            { value: 'bonus', label: 'Thưởng' },
            { value: 'tax', label: 'Thuế' },
            { value: 'insurance', label: 'Bảo hiểm' },
            { value: 'other', label: 'Khác' }
          ]"
          required
        />
        <div class="flex items-center gap-2">
          <input type="checkbox" v-model="form.is_taxable" class="rounded" />
          <label>Tính thuế</label>
        </div>
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
import { salaryService } from '../services/salaryService';
import { useToast } from '../composables/useToast';

const toast = useToast();
const salaryComponents = ref([]);
const showCreateModal = ref(false);
const form = ref({
  code: '',
  name: '',
  type: '',
  category: '',
  is_taxable: false,
  is_active: true
});

const getCategoryText = (category) => {
  const categories = {
    basic: 'Lương cơ bản',
    allowance: 'Phụ cấp',
    bonus: 'Thưởng',
    tax: 'Thuế',
    insurance: 'Bảo hiểm',
    other: 'Khác'
  };
  return categories[category] || category;
};

const loadData = async () => {
  try {
    const response = await salaryService.getComponents();
    salaryComponents.value = response?.data || response || [];
  } catch (err) {
    console.error('Error loading salary components:', err);
    salaryComponents.value = [];
  }
};

const saveItem = async () => {
  try {
    if (form.value.id) {
      await salaryService.updateComponent(form.value.id, form.value);
      toast.success('Cập nhật thành phần lương thành công!');
    } else {
      await salaryService.createComponent(form.value);
      toast.success('Thêm thành phần lương thành công!');
    }
    showCreateModal.value = false;
    form.value = {
      code: '',
      name: '',
      type: '',
      category: '',
      is_taxable: false,
      is_active: true
    };
    await loadData();
  } catch (err) {
    console.error('Error saving salary component:', err);
    toast.error('Có lỗi xảy ra khi lưu thành phần lương');
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
      type: item.type,
      category: item.category,
      is_taxable: item.is_taxable ? 1 : 0,
      is_active: 0
    };
    await salaryService.updateComponent(item.id, payload);
    toast.success('Đã tạm ngưng thành phần lương');
    await loadData();
  } catch (err) {
    console.error('Error deactivating salary component:', err);
    toast.error('Có lỗi xảy ra khi tạm ngưng thành phần lương');
  }
};

const activateItem = async (item) => {
  try {
    const payload = {
      code: item.code,
      name: item.name,
      type: item.type,
      category: item.category,
      is_taxable: item.is_taxable ? 1 : 0,
      is_active: 1
    };
    await salaryService.updateComponent(item.id, payload);
    toast.success('Đã kích hoạt thành phần lương');
    await loadData();
  } catch (err) {
    console.error('Error activating salary component:', err);
    toast.error('Có lỗi xảy ra khi kích hoạt thành phần lương');
  }
};

onMounted(async () => {
  await loadData();
});
</script>
