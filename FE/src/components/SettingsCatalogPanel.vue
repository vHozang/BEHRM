<template>
  <div class="space-y-4">
    <div class="flex gap-2 overflow-x-auto rounded-xl border border-border bg-card p-2"><button v-for="tab in tabs" :key="tab.value" class="whitespace-nowrap rounded-lg px-3 py-2 text-sm font-semibold" :class="activeTab === tab.value ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-muted'" @click="activeTab = tab.value">{{ tab.label }}</button></div>
    <ResourceCrudPanel v-if="activeTab === 'banks'" resource="banks" title="Ngân hàng" :columns="bankColumns" :fields="bankFields" :defaults="{ status: true }" />
    <ResourceCrudPanel v-else-if="activeTab === 'nationalities'" resource="nationalities" title="Quốc tịch" :columns="nationalityColumns" :fields="nationalityFields" :defaults="{ status: true }" />
    <ResourceCrudPanel v-else-if="activeTab === 'documents'" resource="document-types" title="Loại giấy tờ" :columns="documentColumns" :fields="documentFields" />
    <ResourceCrudPanel v-else resource="qualification-types" title="Loại trình độ" :columns="qualificationColumns" :fields="qualificationFields" />
  </div>
</template>
<script setup>
import { ref } from 'vue'; import ResourceCrudPanel from './ResourceCrudPanel.vue';
const activeTab = ref('banks'); const tabs = [{ value: 'banks', label: 'Ngân hàng' }, { value: 'nationalities', label: 'Quốc tịch' }, { value: 'documents', label: 'Loại giấy tờ' }, { value: 'qualifications', label: 'Loại trình độ' }];
const bankColumns = [{ key: 'bank_code', label: 'Mã', mono: true }, { key: 'bank_name', label: 'Tên ngân hàng' }, { key: 'swift_code', label: 'SWIFT' }, { key: 'status', label: 'Hoạt động' }]; const bankFields = [{ key: 'bank_code', label: 'Mã', required: true }, { key: 'bank_name', label: 'Tên ngân hàng', required: true }, { key: 'swift_code', label: 'SWIFT', nullable: true }, { key: 'description', label: 'Mô tả', type: 'textarea', full: true, nullable: true }, { key: 'status', label: 'Hoạt động', type: 'checkbox' }];
const nationalityColumns = [{ key: 'nationality_code', label: 'Mã', mono: true }, { key: 'nationality_name', label: 'Quốc tịch' }, { key: 'status', label: 'Hoạt động' }]; const nationalityFields = [{ key: 'nationality_code', label: 'Mã', required: true }, { key: 'nationality_name', label: 'Tên quốc tịch', required: true }, { key: 'description', label: 'Mô tả', type: 'textarea', full: true, nullable: true }, { key: 'status', label: 'Hoạt động', type: 'checkbox' }];
const documentColumns = [{ key: 'document_type_code', label: 'Mã', mono: true }, { key: 'document_type_name', label: 'Loại giấy tờ' }, { key: 'description', label: 'Mô tả' }]; const documentFields = [{ key: 'document_type_code', label: 'Mã', required: true }, { key: 'document_type_name', label: 'Tên loại', required: true }, { key: 'description', label: 'Mô tả', type: 'textarea', full: true, nullable: true }];
const qualificationColumns = [{ key: 'qualification_type_code', label: 'Mã', mono: true }, { key: 'qualification_type_name', label: 'Loại trình độ' }, { key: 'description', label: 'Mô tả' }]; const qualificationFields = [{ key: 'qualification_type_code', label: 'Mã', required: true }, { key: 'qualification_type_name', label: 'Tên loại', required: true }, { key: 'description', label: 'Mô tả', type: 'textarea', full: true, nullable: true }];
</script>
