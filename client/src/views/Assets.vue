<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-3xl font-bold text-foreground">Danh Mục Tài Sản</h1>
        <p class="text-muted-foreground mt-1">Danh sách thiết bị máy tính, điện thoại, nội thất bàn giao cho nhân viên</p>
      </div>
      <BaseButton @click="openCreateModal">+ Thêm tài sản</BaseButton>
    </div>

    <!-- Summary Widgets -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
      <div v-for="stat in assetStats" :key="stat.label" class="bg-card p-4 rounded-xl border border-border flex items-center gap-4">
        <div class="p-3 rounded-full" :class="stat.bgColorClass">
          <svg class="w-5 h-5" :class="stat.iconColorClass" fill="none" stroke="currentColor" viewBox="0 0 24 24" v-html="stat.iconSvg"></svg>
        </div>
        <div>
          <p class="text-xs text-muted-foreground font-semibold">{{ stat.label }}</p>
          <p class="text-xl font-bold text-foreground">{{ stat.count }}</p>
        </div>
      </div>
    </div>

    <!-- Table Card -->
    <BaseCard>
      <BaseTable
        :columns="[
          { key: 'asset_code', label: 'Mã tài sản' },
          { key: 'name', label: 'Tên tài sản' },
          { key: 'category', label: 'Loại tài sản' },
          { key: 'purchase_date', label: 'Ngày mua' },
          { key: 'price', label: 'Trị giá' },
          { key: 'status', label: 'Trạng thái' }
        ]"
        :data="assets"
      >
        <template #cell-asset_code="{ item }">
          <span class="font-bold text-foreground">{{ item.asset_code }}</span>
        </template>

        <template #cell-name="{ item }">
          <span class="font-medium">{{ item.name }}</span>
        </template>

        <template #cell-price="{ item }">
          <span class="font-medium text-primary">{{ formatCurrency(item.price || item.purchase_cost) }}</span>
        </template>

        <template #cell-purchase_date="{ item }">
          <span>{{ formatDate(item.purchase_date) }}</span>
        </template>

        <template #cell-status="{ item }">
          <BaseBadge :variant="getStatusVariant(item.status)">
            {{ getStatusLabel(item.status) }}
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
    <BaseModal v-model="showModal" :title="form.id ? 'Cập nhật tài sản thiết bị' : 'Đăng ký tài sản mới'">
      <div class="space-y-4">
        <BaseInput v-model="form.asset_code" label="Mã tài sản (Ví dụ: LAP-024, MON-012)" required />
        <BaseInput v-model="form.name" label="Tên tài sản (Ví dụ: Laptop Dell Latitude 5420)" required />
        
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-foreground mb-1">Nhóm thiết bị <span class="text-destructive">*</span></label>
            <select 
              v-model="form.category" 
              class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground text-sm focus:outline-none focus:ring-2 focus:ring-ring"
              required
            >
              <option value="Laptop/PC">Laptop / Máy tính</option>
              <option value="Monitor">Màn hình</option>
              <option value="Office Equipment">Thiết bị văn phòng</option>
              <option value="Others">Khác</option>
            </select>
          </div>

          <BaseInput v-model="form.purchase_date" type="date" label="Ngày mua" required />
        </div>

        <div class="grid grid-cols-2 gap-4">
          <BaseMoneyInput v-model="form.price" label="Trị giá mua (VNĐ)" required />
          <div>
            <label class="block text-sm font-medium text-foreground mb-1">Trạng thái thiết bị</label>
            <select 
              v-model="form.status" 
              class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground text-sm focus:outline-none focus:ring-2 focus:ring-ring"
            >
              <option value="available">Sẵn dùng (Available)</option>
              <option value="assigned">Đang cấp phát (Assigned)</option>
              <option value="maintenance">Đang sửa chữa (Maintenance)</option>
              <option value="lost_broken">Hỏng / Mất (Broken)</option>
            </select>
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium text-foreground mb-1">Chi tiết cấu hình / Mô tả</label>
          <textarea 
            v-model="form.description" 
            class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground text-sm focus:outline-none focus:ring-2 focus:ring-ring" 
            rows="3" 
            placeholder="Nhập thông tin số serial, RAM, CPU..."
          ></textarea>
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
import { ref, computed, onMounted } from 'vue';
import BaseButton from '../components/BaseButton.vue';
import BaseCard from '../components/BaseCard.vue';
import BaseTable from '../components/BaseTable.vue';
import BaseModal from '../components/BaseModal.vue';
import BaseInput from '../components/BaseInput.vue';
import BaseMoneyInput from '../components/BaseMoneyInput.vue';
import BaseBadge from '../components/BaseBadge.vue';
import { assetService } from '../services/assetService';
import { useToast } from '../composables/useToast';

const toast = useToast();
const assets = ref([]);
const showModal = ref(false);

const form = ref({
  asset_code: '',
  name: '',
  category: 'Laptop/PC',
  purchase_date: '',
  price: 0,
  description: '',
  status: 'available'
});

const loadData = async () => {
  try {
    const res = await assetService.getAll();
    assets.value = res?.data || res || [];
  } catch (err) {
    console.error('Error loading assets:', err);
    assets.value = [];
  }
};

const formatDate = (date) => {
  if (!date) return '-';
  return new Date(date).toLocaleDateString('vi-VN');
};

const formatCurrency = (value) => {
  return new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(value || 0);
};

const openCreateModal = () => {
  form.value = {
    asset_code: '',
    name: '',
    category: 'Laptop/PC',
    purchase_date: new Date().toISOString().substring(0, 10),
    price: 0,
    description: '',
    status: 'available'
  };
  showModal.value = true;
};

const editItem = (item) => {
  form.value = {
    ...item,
    price: item.price || item.purchase_cost || 0,
    purchase_date: item.purchase_date ? item.purchase_date.substring(0, 10) : ''
  };
  showModal.value = true;
};

const submitForm = async () => {
  if (!form.value.asset_code || !form.value.name || !form.value.purchase_date) {
    toast.error('Vui lòng điền đầy đủ các thông tin bắt buộc');
    return;
  }
  try {
    const data = {
      ...form.value,
      purchase_cost: form.value.price
    };
    if (form.value.id) {
      await assetService.update(form.value.id, data);
      toast.success('Cập nhật tài sản thành công');
    } else {
      await assetService.create(data);
      toast.success('Thêm tài sản mới thành công');
    }
    showModal.value = false;
    await loadData();
  } catch (err) {
    console.error('Error saving asset:', err);
    toast.error('Có lỗi xảy ra khi lưu tài sản');
  }
};

const deleteItem = async (id) => {
  if (!confirm('Bạn có chắc chắn muốn xóa tài sản này?')) return;
  try {
    await assetService.delete(id);
    toast.success('Xóa tài sản thành công');
    await loadData();
  } catch (err) {
    console.error('Error deleting asset:', err);
    toast.error('Có lỗi xảy ra khi xóa tài sản');
  }
};

// --- Statistics ---
const assetStats = computed(() => {
  const list = assets.value;
  return [
    {
      label: 'Tổng tài sản',
      count: list.length,
      bgColorClass: 'bg-blue-100 dark:bg-blue-900/30',
      iconColorClass: 'text-blue-600',
      iconSvg: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />'
    },
    {
      label: 'Đang cấp phát',
      count: list.filter(a => a.status === 'assigned').length,
      bgColorClass: 'bg-green-100 dark:bg-green-900/30',
      iconColorClass: 'text-green-600',
      iconSvg: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />'
    },
    {
      label: 'Kho sẵn sàng',
      count: list.filter(a => a.status === 'available').length,
      bgColorClass: 'bg-amber-100 dark:bg-amber-900/30',
      iconColorClass: 'text-amber-600',
      iconSvg: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />'
    },
    {
      label: 'Hỏng hóc / Sửa',
      count: list.filter(a => a.status === 'maintenance' || a.status === 'lost_broken').length,
      bgColorClass: 'bg-red-100 dark:bg-red-900/30',
      iconColorClass: 'text-red-600',
      iconSvg: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />'
    }
  ];
});

// --- Label Helpers ---
const getStatusLabel = (status) => {
  switch (status) {
    case 'available': return 'Sẵn dùng';
    case 'assigned': return 'Đang cấp phát';
    case 'maintenance': return 'Bảo trì';
    case 'lost_broken': return 'Hỏng hóc/Mất';
    default: return 'Khác';
  }
};

const getStatusVariant = (status) => {
  switch (status) {
    case 'available': return 'secondary';
    case 'assigned': return 'success';
    case 'maintenance': return 'warning';
    case 'lost_broken': return 'destructive';
    default: return 'secondary';
  }
};

onMounted(async () => {
  await loadData();
});
</script>
