<template>
  <div class="space-y-6">
    <div>
      <p class="text-xs font-bold uppercase tracking-[0.22em] text-amber-600">Asset control room</p>
      <h1 class="mt-1 text-3xl font-bold text-foreground">Tài sản & vòng đời thiết bị</h1>
      <p class="mt-1 text-muted-foreground">Dữ liệu tài sản dùng danh mục chuẩn; sự cố và bảo trì được lưu riêng để giữ lịch sử.</p>
    </div>

    <div class="flex gap-2 overflow-x-auto rounded-2xl border border-border bg-gradient-to-r from-amber-50 via-card to-sky-50 p-2 dark:from-amber-950/20 dark:to-sky-950/20">
      <button v-for="tab in tabs" :key="tab.value" class="whitespace-nowrap rounded-xl px-3 py-2 text-sm font-semibold transition-colors" :class="activeTab === tab.value ? 'bg-foreground text-background shadow-sm' : 'text-muted-foreground hover:bg-card'" @click="activeTab = tab.value">{{ tab.label }}</button>
    </div>

    <ResourceCrudPanel
      v-if="activeTab === 'assets'"
      resource="assets"
      title="Tài sản"
      description="Mã tài sản, danh mục, vị trí và nhà cung cấp đều được kiểm tra cùng tenant/pháp nhân."
      :columns="assetColumns"
      :fields="assetFields"
      :defaults="{ status: 'AVAILABLE' }"
    />
    <ResourceCrudPanel v-else-if="activeTab === 'categories'" resource="asset-categories" title="Danh mục tài sản" :columns="categoryColumns" :fields="categoryFields" :defaults="{ status: 'ACTIVE' }" />
    <ResourceCrudPanel v-else-if="activeTab === 'locations'" resource="asset-locations" title="Vị trí tài sản" :columns="locationColumns" :fields="locationFields" :defaults="{ status: 'ACTIVE' }" />
    <ResourceCrudPanel v-else-if="activeTab === 'suppliers'" resource="suppliers" title="Nhà cung cấp" :columns="supplierColumns" :fields="supplierFields" :defaults="{ status: 'ACTIVE' }" />
    <ResourceCrudPanel v-else-if="activeTab === 'incidents'" resource="asset-incidents" title="Sự cố tài sản" description="Không xóa sự cố đang xử lý; cập nhật trạng thái để giữ audit." :columns="incidentColumns" :fields="incidentFields" :defaults="{ status: 'OPEN', damage_level: 'MEDIUM', incident_date: today }" />
    <ResourceCrudPanel v-else resource="asset-maintenance" title="Lịch sử bảo trì" :columns="maintenanceColumns" :fields="maintenanceFields" :defaults="{ maintenance_date: today }" />
  </div>
</template>

<script setup>
import { ref } from 'vue';
import ResourceCrudPanel from '../components/ResourceCrudPanel.vue';

const activeTab = ref('assets');
const today = new Date().toISOString().slice(0, 10);
const tabs = [
  { value: 'assets', label: 'Tài sản' }, { value: 'categories', label: 'Danh mục' },
  { value: 'locations', label: 'Vị trí' }, { value: 'suppliers', label: 'Nhà cung cấp' },
  { value: 'incidents', label: 'Sự cố' }, { value: 'maintenance', label: 'Bảo trì' },
];
const statuses = [{ value: 'AVAILABLE', label: 'Sẵn dùng' }, { value: 'ASSIGNED', label: 'Đang cấp phát' }, { value: 'MAINTENANCE', label: 'Bảo trì' }, { value: 'LOST_BROKEN', label: 'Hỏng / mất' }];
const activeStatuses = [{ value: 'ACTIVE', label: 'Hoạt động' }, { value: 'INACTIVE', label: 'Ngừng dùng' }];
const money = (value) => value == null || value === '' ? '—' : new Intl.NumberFormat('vi-VN').format(Number(value));

const assetColumns = [{ key: 'asset_code', label: 'Mã', mono: true }, { key: 'asset_name', label: 'Tên tài sản' }, { key: 'category_name', label: 'Danh mục' }, { key: 'location_name', label: 'Vị trí' }, { key: 'supplier_name', label: 'Nhà cung cấp' }, { key: 'purchase_cost', label: 'Nguyên giá', format: money }, { key: 'status', label: 'Trạng thái' }];
const assetFields = [
  { key: 'asset_code', label: 'Mã tài sản', required: true }, { key: 'asset_name', label: 'Tên tài sản', required: true },
  { key: 'category_id', label: 'Danh mục', type: 'resource', resource: 'asset-categories', labelKey: 'category_name', codeKey: 'category_code', required: true, cast: 'number' },
  { key: 'location_id', label: 'Vị trí', type: 'resource', resource: 'asset-locations', labelKey: 'location_name', codeKey: 'location_code', nullable: true, cast: 'number' },
  { key: 'supplier_id', label: 'Nhà cung cấp', type: 'resource', resource: 'suppliers', labelKey: 'supplier_name', codeKey: 'supplier_code', nullable: true, cast: 'number' },
  { key: 'status', label: 'Trạng thái', type: 'select', options: statuses, required: true },
  { key: 'purchase_date', label: 'Ngày mua', type: 'date', nullable: true }, { key: 'purchase_cost', label: 'Nguyên giá', type: 'number', min: 0, cast: 'number', nullable: true },
  { key: 'serial_number', label: 'Serial', nullable: true }, { key: 'description', label: 'Mô tả / cấu hình', type: 'textarea', full: true, nullable: true },
];
const categoryColumns = [{ key: 'category_code', label: 'Mã', mono: true }, { key: 'category_name', label: 'Tên' }, { key: 'description', label: 'Mô tả' }, { key: 'status', label: 'Trạng thái' }];
const categoryFields = [{ key: 'category_code', label: 'Mã', required: true }, { key: 'category_name', label: 'Tên', required: true }, { key: 'description', label: 'Mô tả', type: 'textarea', full: true, nullable: true }, { key: 'status', label: 'Trạng thái', type: 'select', options: activeStatuses }];
const locationColumns = [{ key: 'location_code', label: 'Mã', mono: true }, { key: 'location_name', label: 'Tên vị trí' }, { key: 'description', label: 'Mô tả' }, { key: 'status', label: 'Trạng thái' }];
const locationFields = [{ key: 'location_code', label: 'Mã', required: true }, { key: 'location_name', label: 'Tên vị trí', required: true }, { key: 'department_id', label: 'Phòng ban', type: 'resource', resource: 'departments', labelKey: 'department_name', codeKey: 'department_code', nullable: true, cast: 'number' }, { key: 'description', label: 'Mô tả', type: 'textarea', full: true, nullable: true }, { key: 'status', label: 'Trạng thái', type: 'select', options: activeStatuses }];
const supplierColumns = [{ key: 'supplier_code', label: 'Mã', mono: true }, { key: 'supplier_name', label: 'Nhà cung cấp' }, { key: 'contact_person', label: 'Liên hệ' }, { key: 'phone_number', label: 'Điện thoại' }, { key: 'status', label: 'Trạng thái' }];
const supplierFields = [{ key: 'supplier_code', label: 'Mã', required: true }, { key: 'supplier_name', label: 'Tên nhà cung cấp', required: true }, { key: 'contact_person', label: 'Người liên hệ', nullable: true }, { key: 'phone_number', label: 'Điện thoại', nullable: true }, { key: 'email', label: 'Email', type: 'email', nullable: true }, { key: 'address', label: 'Địa chỉ', type: 'textarea', full: true, nullable: true }, { key: 'status', label: 'Trạng thái', type: 'select', options: activeStatuses }];
const incidentColumns = [{ key: 'asset_id', label: 'Tài sản', mono: true }, { key: 'incident_type', label: 'Loại sự cố' }, { key: 'incident_date', label: 'Ngày' }, { key: 'damage_level', label: 'Mức độ' }, { key: 'status', label: 'Trạng thái' }];
const incidentFields = [{ key: 'asset_id', label: 'Tài sản', type: 'resource', resource: 'assets', labelKey: 'asset_name', codeKey: 'asset_code', required: true, cast: 'number' }, { key: 'reported_by', label: 'Người báo', type: 'employee', nullable: true, cast: 'number' }, { key: 'incident_type', label: 'Loại sự cố', required: true }, { key: 'incident_date', label: 'Ngày xảy ra', type: 'date', required: true }, { key: 'damage_level', label: 'Mức độ', type: 'select', options: [{ value: 'LOW', label: 'Nhẹ' }, { value: 'MEDIUM', label: 'Trung bình' }, { value: 'HIGH', label: 'Nặng' }] }, { key: 'status', label: 'Trạng thái', type: 'select', options: [{ value: 'OPEN', label: 'Mới' }, { value: 'IN_PROGRESS', label: 'Đang xử lý' }, { value: 'RESOLVED', label: 'Đã xử lý' }] }, { key: 'description', label: 'Mô tả', type: 'textarea', full: true, required: true }, { key: 'resolved_date', label: 'Ngày xử lý xong', type: 'date', nullable: true }];
const maintenanceColumns = [{ key: 'asset_id', label: 'Tài sản', mono: true }, { key: 'maintenance_type', label: 'Loại bảo trì' }, { key: 'maintenance_date', label: 'Ngày' }, { key: 'vendor', label: 'Đơn vị thực hiện' }, { key: 'cost', label: 'Chi phí', format: money }];
const maintenanceFields = [{ key: 'asset_id', label: 'Tài sản', type: 'resource', resource: 'assets', labelKey: 'asset_name', codeKey: 'asset_code', required: true, cast: 'number' }, { key: 'maintenance_type', label: 'Loại bảo trì', required: true }, { key: 'maintenance_date', label: 'Ngày bảo trì', type: 'date', required: true }, { key: 'cost', label: 'Chi phí', type: 'number', min: 0, cast: 'number', nullable: true }, { key: 'vendor', label: 'Đơn vị thực hiện', nullable: true }, { key: 'next_maintenance_date', label: 'Lần kế tiếp', type: 'date', nullable: true }, { key: 'description', label: 'Mô tả', type: 'textarea', full: true, nullable: true }];
</script>
