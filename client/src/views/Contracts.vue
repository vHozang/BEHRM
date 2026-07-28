<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-3xl font-bold text-foreground">Hợp Đồng Lao Động</h1>
        <p class="text-muted-foreground mt-1">Quản lý hợp đồng, cảnh báo hết hạn và tuân thủ quy định BLLĐ (thử việc, gia hạn).</p>
      </div>
      <div class="flex gap-2">
        <BaseButton variant="outline" @click="openTemplates">Mẫu hợp đồng</BaseButton>
        <BaseButton @click="openCreateModal">+ Tạo hợp đồng</BaseButton>
      </div>
    </div>

    <!-- Summary strip -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
      <div class="rounded-lg border border-border p-3">
        <p class="text-2xl font-bold text-foreground">{{ summary.active }}</p>
        <p class="text-xs text-muted-foreground">Đang hiệu lực</p>
      </div>
      <button class="rounded-lg border p-3 text-left transition-colors" :class="filters.bucket === 'expiring30' ? 'border-amber-500 bg-amber-500/10' : 'border-border hover:bg-accent/50'" @click="toggleBucket('expiring30')">
        <p class="text-2xl font-bold text-amber-600">{{ summary.expiring30 }}</p>
        <p class="text-xs text-muted-foreground">Sắp hết hạn (≤30 ngày)</p>
      </button>
      <button class="rounded-lg border p-3 text-left transition-colors" :class="filters.bucket === 'expired' ? 'border-red-500 bg-red-500/10' : 'border-border hover:bg-accent/50'" @click="toggleBucket('expired')">
        <p class="text-2xl font-bold text-red-600">{{ summary.expired }}</p>
        <p class="text-xs text-muted-foreground">Đã hết hạn / chấm dứt</p>
      </button>
      <div class="rounded-lg border border-border p-3">
        <p class="text-2xl font-bold text-foreground">{{ summary.probation }}</p>
        <p class="text-xs text-muted-foreground">Đang thử việc</p>
      </div>
    </div>

    <!-- Filters -->
    <BaseCard>
      <div class="grid grid-cols-1 sm:grid-cols-4 gap-4 items-end">
        <div>
          <label class="block text-sm font-medium text-foreground mb-2">Tìm kiếm</label>
          <input v-model="filters.search" @keyup.enter="applyFilters" type="text" placeholder="Số HĐ / tên nhân viên" class="w-full px-4 py-2.5 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring" />
        </div>
        <div>
          <label class="block text-sm font-medium text-foreground mb-2">Loại hợp đồng</label>
          <select v-model="filters.typeId" class="w-full px-4 py-2.5 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring">
            <option value="">Tất cả</option>
            <option v-for="t in contractTypes" :key="t.id" :value="String(t.id)">{{ t.contract_type_name || t.name }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-foreground mb-2">Trạng thái</label>
          <select v-model="filters.bucket" class="w-full px-4 py-2.5 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring">
            <option value="">Tất cả</option>
            <option value="active">Còn hiệu lực</option>
            <option value="expiring30">Sắp hết hạn (≤30 ngày)</option>
            <option value="expiring60">Sắp hết hạn (≤60 ngày)</option>
            <option value="expired">Đã hết hạn / chấm dứt</option>
          </select>
        </div>
        <div>
          <div class="flex gap-2">
            <BaseButton @click="applyFilters">Áp dụng</BaseButton>
            <BaseButton variant="ghost" @click="resetFilters">Xóa lọc</BaseButton>
          </div>
        </div>
      </div>
    </BaseCard>

    <!-- Table Card -->
    <BaseCard>
      <BaseTable
        :columns="[
          { key: 'contract_code', label: 'Số Hợp đồng' },
          { key: 'employee', label: 'Nhân viên' },
          { key: 'type', label: 'Loại hợp đồng' },
          { key: 'duration', label: 'Thời hạn' },
          { key: 'salary', label: 'Lương & Phụ cấp' },
          { key: 'status', label: 'Trạng thái' }
        ]"
        :data="filteredContracts"
      >
        <template #cell-contract_code="{ item }">
          <div class="font-bold text-foreground">{{ item.contract_code }}</div>
          <div class="text-[10px] text-muted-foreground">Ngày ký: {{ formatDate(item.sign_date) }}</div>
        </template>

        <template #cell-employee="{ item }">
          <div class="font-medium text-foreground">{{ item.employee_name || `Mã NV: ${item.employee_id}` }}</div>
          <div class="text-xs text-muted-foreground">{{ item.department_name }}</div>
          <div v-if="renewalCount(item) >= maxFixedTerm && categoryOf(item) === 'FIXED_TERM'" class="text-[10px] text-amber-600 mt-0.5">
            ⚠ Đã ký {{ renewalCount(item) }} HĐ xác định thời hạn (Điều 20: lần kế tiếp phải ký vô thời hạn)
          </div>
        </template>

        <template #cell-type="{ item }">
          <span>{{ item.contract_type_name || item.contract_type || 'Chính thức' }}</span>
          <span v-if="categoryOf(item) === 'PROBATION'" class="block text-[10px] text-muted-foreground">{{ probationNote(item) }}</span>
        </template>

        <template #cell-duration="{ item }">
          <div class="text-sm">
            <p>{{ formatContractDuration(item) }}</p>
          </div>
        </template>

        <template #cell-salary="{ item }">
          <div class="text-sm">
            <p class="font-semibold text-primary">{{ formatCurrency(item.basic_salary) }}</p>
            <p v-if="item.allowances" class="text-xs text-muted-foreground">+ PC: {{ formatCurrency(item.allowances) }}</p>
          </div>
        </template>

        <template #cell-status="{ item }">
          <BaseBadge :variant="statusVariant(item)">{{ statusText(item) }}</BaseBadge>
        </template>

        <template #actions="{ item }">
          <div class="flex items-center justify-end gap-1 whitespace-nowrap">
            <button @click="editItem(item)" title="Chi tiết / Cập nhật" class="w-8 h-8 inline-flex items-center justify-center rounded-md text-primary hover:bg-primary/10 transition-colors">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
            </button>
            <button @click="viewPrint(item)" title="Xem & In hợp đồng" class="w-8 h-8 inline-flex items-center justify-center rounded-md text-violet-600 hover:bg-violet-500/10 transition-colors">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" /></svg>
            </button>
            <button @click="sendSign(item)" title="Gửi nhân viên ký" class="w-8 h-8 inline-flex items-center justify-center rounded-md text-sky-600 hover:bg-sky-500/10 transition-colors">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" /></svg>
            </button>
            <button @click="activateItem(item.id)" title="Kích hoạt" class="w-8 h-8 inline-flex items-center justify-center rounded-md text-emerald-600 hover:bg-emerald-500/10 transition-colors">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            </button>
            <button @click="openTerminateModal(item)" title="Chấm dứt" class="w-8 h-8 inline-flex items-center justify-center rounded-md text-amber-600 hover:bg-amber-500/10 transition-colors">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" /></svg>
            </button>
          </div>
        </template>
      </BaseTable>
      <div v-if="filteredContracts.length === 0" class="text-center py-8 text-muted-foreground">Không có hợp đồng phù hợp bộ lọc.</div>
      <div v-if="pagination && pagination.last_page > 1" class="flex items-center justify-between mt-4 pt-4 border-t border-border">
        <span class="text-sm text-muted-foreground">
          Trang {{ pagination.current_page }} / {{ pagination.last_page }} · {{ pagination.total }} hợp đồng
        </span>
        <div class="flex gap-2">
          <BaseButton variant="outline" :disabled="page <= 1" @click="goTo(page - 1)">Trước</BaseButton>
          <BaseButton variant="outline" :disabled="page >= pagination.last_page" @click="goTo(page + 1)">Sau</BaseButton>
        </div>
      </div>
    </BaseCard>

    <!-- Create/Edit Modal -->
    <BaseModal v-model="showModal" :title="form.id ? 'Chi tiết / Cập nhật hợp đồng' : 'Tạo hợp đồng lao động mới'" size="lg">
      <div class="space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <BaseInput v-model="form.contract_code" label="Số hợp đồng (Ví dụ: HDLD/2026/001)" required />

          <div v-if="!form.id">
            <label class="block text-sm font-medium text-foreground mb-1">Nhân viên liên kết <span class="text-destructive">*</span></label>
            <select v-model="form.employee_id" class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground text-sm focus:outline-none focus:ring-2 focus:ring-ring" required>
              <option value="">-- Chọn nhân viên --</option>
              <option v-for="emp in employees" :key="emp.id" :value="emp.id">{{ emp.full_name }} ({{ emp.employee_code }})</option>
            </select>
          </div>
          <div v-else class="p-3 bg-muted rounded-lg">
            <p class="text-xs text-muted-foreground">Nhân viên</p>
            <p class="font-bold text-foreground">{{ form.employee_name || 'Nhân viên' }}</p>
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-foreground mb-1">Loại hợp đồng <span class="text-destructive">*</span></label>
            <select v-model="form.contract_type_id" class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground text-sm focus:outline-none focus:ring-2 focus:ring-ring" required>
              <option value="">-- Chọn loại hợp đồng --</option>
              <option v-for="t in contractTypes" :key="t.id" :value="t.id">{{ t.contract_type_name || t.name }}</option>
            </select>
          </div>
          <BaseInput v-model="form.sign_date" type="date" label="Ngày ký HĐ" hint="Ngày đặt bút ký" required />
        </div>

        <!-- Compliance hint for new fixed-term contracts -->
        <div v-if="createRenewalHint" class="p-3 bg-amber-500/10 border border-amber-500/30 rounded-lg text-sm text-amber-700 dark:text-amber-400">
          {{ createRenewalHint }}
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <BaseInput v-model="form.start_date" type="date" label="Ngày bắt đầu làm chính thức (hiệu lực)" hint="Thời hạn HĐ tính từ đây" required />
          <BaseInput v-model="form.end_date" type="date" label="Ngày hết hạn (để trống nếu vô hạn)" />
        </div>

        <p v-if="signDateWarning" class="text-xs text-amber-600 -mt-2">⚠ {{ signDateWarning }}</p>
        <p v-if="formProbationWarning" class="text-xs text-amber-600 -mt-2">{{ formProbationWarning }}</p>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <BaseInput v-model.number="form.basic_salary" type="number" label="Mức lương cơ bản (VNĐ)" required />
          <BaseInput v-model.number="form.allowances" type="number" label="Khoản phụ cấp cố định (VNĐ)" />
        </div>

        <div>
          <label class="block text-sm font-medium text-foreground mb-1">Điều khoản & Ghi chú bổ sung</label>
          <textarea v-model="form.notes" class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground text-sm focus:outline-none focus:ring-2 focus:ring-ring" rows="3" placeholder="Nhập ghi chú hoặc các điều khoản đi kèm..."></textarea>
        </div>

        <!-- E-Signature Section -->
        <div v-if="form.id" class="border-t border-border pt-4">
          <label class="block text-sm font-bold text-foreground mb-2">Chữ ký số bên B (Nhân viên)</label>
          <div v-if="form.signature" class="flex flex-col gap-2">
            <div class="bg-white p-2 rounded-xl border border-border w-max">
              <img :src="form.signature" alt="Chữ ký nhân viên" class="h-20 object-contain" />
            </div>
            <p class="text-xs text-emerald-600 font-semibold flex items-center gap-1">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" /></svg>
              Đã ký số thành công bằng chữ ký điện tử xác thực
            </p>
          </div>
          <div v-else>
            <BaseButton size="sm" variant="outline" @click="showSignatureModal = true">
              <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" /></svg>
              Ký số trực tiếp (E-Sign)
            </BaseButton>
          </div>
        </div>

        <!-- Change history -->
        <div v-if="form.id" class="border-t border-border pt-4">
          <label class="block text-sm font-bold text-foreground mb-2">Lịch sử thay đổi hợp đồng</label>
          <div v-if="changeLogsLoading" class="text-sm text-muted-foreground">Đang tải...</div>
          <div v-else-if="changeLogs.length === 0" class="text-sm text-muted-foreground">Chưa có thay đổi nào được ghi nhận.</div>
          <ul v-else class="space-y-1 max-h-48 overflow-y-auto">
            <li v-for="log in changeLogs" :key="log.id" class="text-xs border border-border rounded px-2 py-1.5">
              <span class="font-medium">{{ changeTypeLabel(log.change_type) }}</span>
              <span v-if="log.old_value || log.new_value" class="text-muted-foreground"> · {{ log.old_value || '∅' }} → {{ log.new_value || '∅' }}</span>
              <span class="block text-[10px] text-muted-foreground">{{ formatDateTime(log.created_at) }}</span>
            </li>
          </ul>
        </div>
      </div>
      <template #footer>
        <BaseButton variant="outline" @click="showModal = false">Hủy</BaseButton>
        <BaseButton @click="submitForm">Lưu</BaseButton>
      </template>
    </BaseModal>

    <!-- Signature Pad Popup Modal -->
    <BaseModal v-model="showSignatureModal" title="Vẽ chữ ký điện tử của bạn" size="md">
      <SignaturePad @save="saveSignature" @cancel="showSignatureModal = false" />
    </BaseModal>

    <!-- Terminate Contract Modal -->
    <BaseModal v-model="showTerminateModal" title="Chấm dứt hợp đồng lao động" size="md">
      <div class="space-y-4">
        <p class="text-sm text-muted-foreground">Bạn có chắc chắn muốn chấm dứt hợp đồng này? Bạn có thể chọn ngày chấm dứt (để trống nếu chấm dứt ngay hôm nay).</p>
        <BaseInput v-model="terminateEndDate" type="date" label="Ngày chấm dứt (không bắt buộc)" />
      </div>
      <template #footer>
        <BaseButton variant="outline" @click="showTerminateModal = false">Hủy</BaseButton>
        <BaseButton @click="confirmTerminate">Xác nhận chấm dứt</BaseButton>
      </template>
    </BaseModal>

    <!-- Template manager -->
    <BaseModal v-model="showTemplates" title="Mẫu hợp đồng" size="xl">
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <!-- List -->
        <div class="md:col-span-1 space-y-2">
          <div class="flex items-center justify-between">
            <p class="text-sm font-semibold">Danh sách mẫu</p>
            <button class="text-xs text-primary hover:underline" @click="newTemplate">+ Thêm mẫu</button>
          </div>
          <div
            v-for="t in templates"
            :key="t.id"
            @click="selectTemplate(t)"
            class="p-2.5 rounded-lg border cursor-pointer transition-colors"
            :class="tplForm.id === t.id ? 'border-primary bg-primary/5' : 'border-border hover:bg-accent/40'"
          >
            <div class="flex items-center justify-between gap-2">
              <span class="text-sm font-medium truncate">{{ t.name }}</span>
              <BaseBadge v-if="t.is_default" variant="success" size="sm">Mặc định</BaseBadge>
            </div>
            <p class="text-[10px] text-muted-foreground">{{ t.source === 'system' ? 'Mẫu hệ thống' : 'Mẫu doanh nghiệp' }}</p>
          </div>
        </div>

        <!-- Editor -->
        <div class="md:col-span-2 space-y-3">
          <!-- Upload & quét mẫu -->
          <div class="rounded-lg border border-dashed border-border p-3 bg-muted/30">
            <p class="text-sm font-medium mb-1">Tải mẫu công ty để dùng luôn</p>
            <p class="text-xs text-muted-foreground mb-2">
              <span class="font-medium text-foreground">Khuyến nghị dùng .docx</span> — giữ nguyên định dạng (font, canh lề, bảng) và điền động tốt.
              PDF chỉ trích văn bản để dò trường + lưu bản gốc làm tham khảo (không giữ được định dạng — nên dùng file Word gốc nếu có).
            </p>
            <div class="flex items-center gap-2">
              <input ref="tplFileInput" type="file" accept=".docx,.pdf" class="hidden" @change="onTemplateFile" />
              <BaseButton size="sm" variant="outline" @click="$refs.tplFileInput.click()" :disabled="parsingTpl">
                {{ parsingTpl ? 'Đang quét...' : 'Chọn tệp & quét' }}
              </BaseButton>
              <span v-if="parsedFileName" class="text-xs text-muted-foreground">{{ parsedFileName }}</span>
              <BaseButton v-if="tplForm.referenceFile" size="sm" variant="ghost" @click="viewReferencePdf">Xem PDF gốc</BaseButton>
            </div>
            <p v-if="parseWarning" class="text-xs text-amber-600 mt-2">{{ parseWarning }}</p>
            <p v-if="parseError" class="text-xs text-destructive mt-2">{{ parseError }}</p>

            <!-- Mapping detected fields -->
            <div v-if="detectedFields.length" class="mt-3 border-t border-border pt-3">
              <p class="text-sm font-medium mb-2">Ánh xạ trường cần điền ({{ detectedFields.length }})</p>
              <div class="space-y-2 max-h-56 overflow-y-auto">
                <div v-for="f in detectedFields" :key="f.id" class="flex items-center gap-2">
                  <span class="text-xs text-muted-foreground w-40 truncate" :title="f.label">…{{ f.label || ('Trường ' + (f.id + 1)) }}</span>
                  <span class="text-[10px] px-1.5 py-0.5 rounded bg-muted">{{ kindLabel(f.kind) }}</span>
                  <select v-model="f.key" class="flex-1 px-2 py-1.5 rounded-md border border-input bg-background text-xs focus:outline-none focus:ring-2 focus:ring-ring">
                    <option value="">— Giữ trống (nhập tay) —</option>
                    <option v-for="p in placeholders" :key="p.key" :value="p.key">{{ p.label }} ({{ p.key }})</option>
                  </select>
                </div>
              </div>
              <BaseButton size="sm" class="mt-2" @click="applyDetectedMapping">Áp dụng ánh xạ vào nội dung</BaseButton>
            </div>
          </div>

          <BaseInput v-model="tplForm.name" label="Tên mẫu" />
          <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" v-model="tplForm.is_default" class="h-4 w-4 rounded border-input text-primary" />
            Đặt làm mẫu mặc định (dùng khi in / gửi ký)
          </label>
          <div>
            <label class="block text-sm font-medium mb-1">Nội dung mẫu (HTML, dùng placeholder {{ mustacheHint }})</label>
            <textarea v-model="tplForm.content" rows="12" class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground text-xs font-mono focus:outline-none focus:ring-2 focus:ring-ring"></textarea>
          </div>
          <details class="text-xs text-muted-foreground">
            <summary class="cursor-pointer">Các trường trộn khả dụng ({{ placeholders.length }})</summary>
            <div class="flex flex-wrap gap-1 mt-2">
              <code v-for="p in placeholders" :key="p.key" class="px-1.5 py-0.5 rounded bg-muted">{{ wrapPh(p.key) }} — {{ p.label }}</code>
            </div>
          </details>
          <div class="flex flex-wrap gap-2">
            <BaseButton @click="saveTemplate" :disabled="tplBusy">{{ tplForm.id ? 'Lưu mẫu' : 'Tạo mẫu' }}</BaseButton>
            <BaseButton variant="outline" @click="previewTemplate" :disabled="!tplForm.content">Xem bản mẫu</BaseButton>
            <BaseButton v-if="tplForm.id && tplForm.source !== 'system'" variant="destructive" @click="removeTemplate" :disabled="tplBusy">Xóa mẫu</BaseButton>
          </div>
        </div>
      </div>
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
import BaseBadge from '../components/BaseBadge.vue';
import SignaturePad from '../components/SignaturePad.vue';
import { contractService, printContractHtml } from '../services/contractService';
import { parseFileToHtml, scanTemplate, applyMapping } from '../utils/contractTemplateImport';
import { employeeService } from '../services/employeeService';
import { settingsService } from '../services/settingsService';
import { useToast } from '../composables/useToast';

const toast = useToast();
// Policy thresholds — from tenant config, fallback to VN statutory defaults.
const probationMaxDays = ref(60);
const maxFixedTerm = ref(2);
const expiryAlertDays = ref(30);
const contracts = ref([]);
const contractLookup = ref([]);
const pagination = ref(null);
const page = ref(1);
const employees = ref([]);
const contractTypes = ref([]);
const showModal = ref(false);
const showSignatureModal = ref(false);
const showTerminateModal = ref(false);
const terminateId = ref(null);
const terminateEndDate = ref('');

const changeLogs = ref([]);
const changeLogsLoading = ref(false);

const filters = ref({ search: '', typeId: '', bucket: '' });

const form = ref({
  contract_code: '', employee_id: '', contract_type_id: '', sign_date: '',
  start_date: '', end_date: '', basic_salary: 0, allowances: 0, notes: '', signature: ''
});

// ── Contract classification & lifecycle helpers ──────────
const TERMINATED_STATUSES = ['TERMINATED', 'INACTIVE', 'HẾT_HIỆU_LỰC', 'ĐÃ_CHẤM_DỨT', 'CANCELLED'];

const isTerminatedStatus = (item) => TERMINATED_STATUSES.includes(String(item?.status || '').toUpperCase());

// Classify by contract type name (VN naming is stable enough): thử việc /
// không xác định thời hạn / xác định thời hạn.
const categoryOf = (item) => {
  const name = String(item?.contract_type_name || item?.contract_type || '').toLowerCase();
  if (name.includes('thử việc')) return 'PROBATION';
  if (name.includes('không xác định')) return 'INDEFINITE';
  if (name.includes('xác định thời hạn') || name.includes('thời hạn') || /\b(tháng|năm)\b/.test(name)) return 'FIXED_TERM';
  return 'OTHER';
};

const startOfToday = () => new Date(new Date().setHours(0, 0, 0, 0));

const daysToExpiry = (item) => {
  if (!item?.end_date) return null;
  const end = new Date(item.end_date);
  if (Number.isNaN(end.getTime())) return null;
  return Math.round((end.getTime() - startOfToday().getTime()) / 86400000);
};

// Effective lifecycle state combining status + dates.
const lifecycleOf = (item) => {
  if (isTerminatedStatus(item)) return 'terminated';
  const d = daysToExpiry(item);
  if (d === null) return 'active'; // indefinite
  if (d < 0) return 'expired';
  if (d <= expiryAlertDays.value) return 'expiring30';
  if (d <= Math.max(expiryAlertDays.value * 2, 60)) return 'expiring60';
  return 'active';
};

const statusVariant = (item) => {
  switch (lifecycleOf(item)) {
    case 'active': return 'success';
    case 'expiring30': return 'warning';
    case 'expiring60': return 'warning';
    case 'expired': return 'error';
    default: return 'default';
  }
};

const statusText = (item) => {
  const s = lifecycleOf(item);
  const d = daysToExpiry(item);
  if (s === 'terminated') return 'Đã chấm dứt';
  if (s === 'expired') return 'Hết hiệu lực';
  if (s === 'expiring30' || s === 'expiring60') return `Còn ${d} ngày`;
  return 'Còn hiệu lực';
};

// VN probation cap reference (Điều 25 BLLĐ 2019): ≤180 ngày (quản lý DN),
// ≤60 (CĐ trở lên), ≤30 (trung cấp), ≤6 ngày làm việc (khác). We can't read the
// position level reliably, so we caution past the common 60-day mark.
const probationDays = (item) => {
  if (!item?.start_date || !item?.end_date) return null;
  const s = new Date(item.start_date), e = new Date(item.end_date);
  if (Number.isNaN(s.getTime()) || Number.isNaN(e.getTime())) return null;
  return Math.round((e.getTime() - s.getTime()) / 86400000);
};
const probationNote = (item) => {
  const d = probationDays(item);
  return d === null ? 'Thử việc' : `Thử việc ${d} ngày`;
};

// Count an employee's fixed-term contracts (for the Điều 20 max-2 rule).
const fixedTermCountByEmployee = computed(() => {
  const map = {};
  contractLookup.value.forEach((c) => {
    if (categoryOf(c) === 'FIXED_TERM') {
      map[c.employee_id] = (map[c.employee_id] || 0) + 1;
    }
  });
  return map;
});
const renewalCount = (item) => fixedTermCountByEmployee.value[item.employee_id] || 0;

const summary = computed(() => {
  let active = 0, expiring30 = 0, expired = 0, probation = 0;
  contractLookup.value.forEach((c) => {
    const s = lifecycleOf(c);
    if (s === 'terminated' || s === 'expired') expired++;
    else {
      active++;
      if (s === 'expiring30') expiring30++;
      if (categoryOf(c) === 'PROBATION') probation++;
    }
  });
  return { active, expiring30, expired, probation };
});

const filteredContracts = computed(() => contracts.value);

const applyFilters = () => { page.value = 1; loadData(); };
const goTo = (target) => { page.value = target; loadData(); };
const toggleBucket = (b) => { filters.value.bucket = filters.value.bucket === b ? '' : b; applyFilters(); };
const resetFilters = () => { filters.value = { search: '', typeId: '', bucket: '' }; applyFilters(); };

// ── Create-form compliance hints ─────────────────────────
const selectedTypeCategory = computed(() => {
  const t = contractTypes.value.find((x) => String(x.id) === String(form.value.contract_type_id));
  if (!t) return 'OTHER';
  return categoryOf({ contract_type_name: t.contract_type_name || t.name });
});

const createRenewalHint = computed(() => {
  if (form.value.id || !form.value.employee_id) return '';
  if (selectedTypeCategory.value !== 'FIXED_TERM') return '';
  const count = fixedTermCountByEmployee.value[form.value.employee_id] || 0;
  if (count >= maxFixedTerm.value) {
    return `Nhân viên này đã có ${count} hợp đồng xác định thời hạn. Theo Điều 20 BLLĐ, hợp đồng tiếp theo phải là hợp đồng KHÔNG xác định thời hạn.`;
  }
  return '';
});

// Cảnh báo mềm: ngày ký nên ≤ ngày bắt đầu (luật: ký chậm nhất ngày đầu nhận việc).
const signDateWarning = computed(() => {
  const s = form.value.sign_date, st = form.value.start_date;
  if (!s || !st) return '';
  if (s > st) {
    return 'Ngày ký đang SAU ngày bắt đầu làm — theo BLLĐ, HĐ phải ký trước hoặc chậm nhất là ngày đầu nhận việc. Kiểm tra lại (vẫn lưu được).';
  }
  return '';
});

const formProbationWarning = computed(() => {
  if (selectedTypeCategory.value !== 'PROBATION') return '';
  const d = probationDays(form.value);
  if (d !== null && d > probationMaxDays.value) {
    return `Thử việc ${d} ngày — vượt giới hạn cấu hình (${probationMaxDays.value} ngày). Điều 25 BLLĐ: tối đa 60 ngày (CĐ trở lên), 180 ngày chỉ cho người quản lý doanh nghiệp.`;
  }
  return '';
});

// ── Data loading ─────────────────────────────────────────
const loadData = async () => {
  try {
    const params = { page: page.value };
    if (filters.value.search.trim()) params.search = filters.value.search.trim();
    if (filters.value.typeId) params.contract_type_id = filters.value.typeId;
    if (filters.value.bucket) params.bucket = filters.value.bucket;
    const [conRes, lookupRes, typeRes, empRes] = await Promise.all([
      contractService.getPage(params),
      contractService.getLookup(),
      contractService.getTypes().catch(() => []),
      employeeService.getLookup().catch(() => [])
    ]);
    contracts.value = conRes.items;
    pagination.value = conRes.pagination;
    contractLookup.value = lookupRes;

    let types = typeRes?.data || typeRes || [];
    if (!Array.isArray(types)) types = types.items || types.data || [];
    contractTypes.value = Array.isArray(types) ? types : [];

    let emps = empRes?.data?.data || empRes?.data || empRes || [];
    if (!Array.isArray(emps)) emps = emps.items || emps.data || [];
    employees.value = Array.isArray(emps) ? emps : [];
  } catch (err) {
    console.error('Error loading contracts:', err);
  }
};

const loadChangeLogs = async (contractId) => {
  changeLogsLoading.value = true;
  changeLogs.value = [];
  try {
    const res = await contractService.getChangeLogs(contractId);
    let logs = Array.isArray(res) ? res : (res?.items || res?.data || []);
    changeLogs.value = Array.isArray(logs) ? logs : [];
  } catch (err) {
    console.error('Error loading contract change logs:', err);
  } finally {
    changeLogsLoading.value = false;
  }
};

// ── Formatters ───────────────────────────────────────────
const formatDate = (date) => date ? new Date(date).toLocaleDateString('vi-VN') : '-';
const formatDateTime = (dt) => dt ? new Date(dt).toLocaleString('vi-VN') : '-';
const formatContractDuration = (item) => `${formatDate(item.start_date)} - ${item.end_date ? formatDate(item.end_date) : 'Vô thời hạn'}`;
const formatCurrency = (value) => new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(value || 0);

const CHANGE_TYPE_LABELS = { ACTIVATE: 'Kích hoạt', TERMINATE: 'Chấm dứt' };
const changeTypeLabel = (t) => {
  const key = String(t || '').toUpperCase();
  if (CHANGE_TYPE_LABELS[key]) return CHANGE_TYPE_LABELS[key];
  if (key.startsWith('UPDATE_')) return 'Cập nhật ' + key.replace('UPDATE_', '').toLowerCase();
  return t || 'Thay đổi';
};

// ── CRUD actions ─────────────────────────────────────────
const saveSignature = (signatureBase64) => {
  form.value.signature = signatureBase64;
  showSignatureModal.value = false;
  toast.success('Đã lưu nét ký số thành công');
};

const openCreateModal = () => {
  form.value = {
    contract_code: '', employee_id: '', contract_type_id: '',
    sign_date: new Date().toISOString().substring(0, 10),
    start_date: new Date().toISOString().substring(0, 10),
    end_date: '', basic_salary: 0, allowances: 0, notes: '', signature: ''
  };
  changeLogs.value = [];
  showModal.value = true;
};

const editItem = (item) => {
  form.value = {
    ...item,
    sign_date: item.sign_date ? item.sign_date.substring(0, 10) : '',
    start_date: item.start_date ? item.start_date.substring(0, 10) : '',
    end_date: item.end_date ? item.end_date.substring(0, 10) : '',
    signature: item.signature || ''
  };
  showModal.value = true;
  loadChangeLogs(item.id);
};

const submitForm = async () => {
  if (!form.value.contract_code || !form.value.contract_type_id || !form.value.start_date || (!form.value.id && !form.value.employee_id)) {
    toast.error('Vui lòng điền đầy đủ các thông tin bắt buộc');
    return;
  }
  try {
    if (form.value.id) {
      await contractService.update(form.value.id, form.value);
      toast.success('Cập nhật hợp đồng thành công');
    } else {
      await contractService.create(form.value);
      toast.success('Tạo hợp đồng thành công');
    }
    showModal.value = false;
    await loadData();
  } catch (err) {
    console.error('Error saving contract:', err);
    toast.error(err?.response?.data?.message || 'Có lỗi xảy ra khi lưu hợp đồng');
  }
};

const activateItem = async (id) => {
  if (!confirm('Bạn có chắc chắn muốn kích hoạt hợp đồng lao động này?')) return;
  try {
    await contractService.activate(id);
    toast.success('Kích hoạt hợp đồng thành công');
    await loadData();
  } catch (err) {
    console.error('Error activating contract:', err);
    toast.error(err?.response?.data?.message || 'Có lỗi xảy ra khi kích hoạt hợp đồng');
  }
};

const openTerminateModal = (item) => {
  terminateId.value = item.id;
  terminateEndDate.value = '';
  showTerminateModal.value = true;
};

const confirmTerminate = async () => {
  try {
    const payload = terminateEndDate.value ? { end_date: terminateEndDate.value } : {};
    await contractService.terminate(terminateId.value, payload);
    toast.success('Chấm dứt hợp đồng thành công');
    showTerminateModal.value = false;
    await loadData();
  } catch (err) {
    console.error('Error terminating contract:', err);
    toast.error(err?.response?.data?.message || 'Có lỗi xảy ra khi chấm dứt hợp đồng');
  }
};

// ── Document: view/print + send for signing ──────────────
const viewPrint = async (item) => {
  try {
    const res = await contractService.render(item.id);
    if (res?.html) printContractHtml(res.html, `Hợp đồng ${item.contract_code || ''}`);
    else toast.error('Không thể tạo bản hợp đồng');
  } catch (err) {
    toast.error(err?.response?.data?.message || 'Không thể hiển thị hợp đồng');
  }
};

const sendSign = async (item) => {
  if (!confirm(`Gửi hợp đồng ${item.contract_code || ''} cho ${item.employee_name || 'nhân viên'} để ký?`)) return;
  try {
    await contractService.sendForSign(item.id);
    toast.success('Đã gửi hợp đồng cho nhân viên ký trên cổng cá nhân');
    await loadData();
  } catch (err) {
    toast.error(err?.response?.data?.message || 'Không thể gửi ký');
  }
};

// ── Template manager ─────────────────────────────────────
const showTemplates = ref(false);
const templates = ref([]);
const placeholders = ref([]);
const tplBusy = ref(false);
const tplForm = ref({ id: null, name: '', content: '', is_default: false, source: 'uploaded', referenceFile: null, meta: {} });
const mustacheHint = '{{key}}';
const wrapPh = (k) => '{{' + k + '}}';

const readDataUrl = (file) => new Promise((resolve, reject) => {
  const r = new FileReader();
  r.onload = () => resolve(r.result);
  r.onerror = reject;
  r.readAsDataURL(file);
});

// ── Upload & quét mẫu (.docx/.pdf → HTML + auto-detect trường) ──
const tplFileInput = ref(null);
const parsingTpl = ref(false);
const parsedFileName = ref('');
const parseWarning = ref('');
const parseError = ref('');
const detectedFields = ref([]);
const mappingContent = ref(''); // nội dung có sentinel để áp dụng ánh xạ

const kindLabel = (k) => ({ bracket: '[ ]', underscore: '___', dotted: '…' }[k] || 'trống');

const onTemplateFile = async (e) => {
  const file = e.target.files?.[0];
  e.target.value = ''; // cho phép chọn lại cùng tệp
  if (!file) return;
  parsingTpl.value = true;
  parseError.value = '';
  parseWarning.value = '';
  detectedFields.value = [];
  const isPdf = /\.pdf$/i.test(file.name);
  try {
    const { html, warning } = await parseFileToHtml(file);
    parsedFileName.value = file.name;
    parseWarning.value = warning || '';
    const scan = scanTemplate(html || '');
    mappingContent.value = scan.content;
    detectedFields.value = scan.fields;
    // Đặt nội dung + tên ngay (kể cả khi không ánh xạ vẫn dùng được).
    tplForm.value.content = html || '';
    if (!tplForm.value.name) tplForm.value.name = file.name.replace(/\.(docx|pdf)$/i, '');

    // PDF: lưu file gốc làm bản tham khảo (≤5MB), nội dung HTML chỉ là text dò trường.
    if (isPdf) {
      tplForm.value.referenceFile = null;
      if (file.size <= 5 * 1024 * 1024) {
        try { tplForm.value.referenceFile = { name: file.name, type: 'pdf', dataUrl: await readDataUrl(file) }; } catch (e) { /* ignore */ }
      }
      parseWarning.value = 'PDF chỉ trích văn bản để dò trường; bản gốc đã được lưu làm tham khảo. Khuyến nghị dùng .docx để giữ nguyên định dạng và điền động.';
    } else {
      tplForm.value.referenceFile = null; // .docx không cần lưu bản tham khảo
    }

    if (!detectedFields.value.length) {
      parseWarning.value = (parseWarning.value ? parseWarning.value + ' ' : '') + 'Không phát hiện trường trống tự động — bạn có thể tự chèn {{placeholder}} trong nội dung.';
    }
  } catch (err) {
    parseError.value = err?.message || 'Không thể đọc tệp';
  } finally {
    parsingTpl.value = false;
  }
};

const applyDetectedMapping = () => {
  tplForm.value.content = applyMapping(mappingContent.value, detectedFields.value);
  const mapped = detectedFields.value.filter((f) => f.key).length;
  toast.success(`Đã áp dụng ${mapped}/${detectedFields.value.length} trường vào nội dung mẫu`);
};

// Xem bản mẫu: mở nội dung HTML trong cửa sổ mới để xem định dạng.
const previewTemplate = () => {
  if (!tplForm.value.content) return;
  const w = window.open('', '_blank');
  if (!w) { toast.error('Trình duyệt chặn cửa sổ bật lên — hãy cho phép pop-up'); return; }
  const title = tplForm.value.name || 'Mẫu hợp đồng';
  w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>Xem mẫu: ${title}</title>
    <style>body{margin:32px;font-family:'Times New Roman',serif;color:#111;line-height:1.6}
    .note{background:#fef3c7;border:1px solid #fcd34d;padding:8px 12px;border-radius:8px;margin-bottom:16px;font-family:sans-serif;font-size:13px}
    .docx-wrapper{background:#fff!important;padding:0!important;box-shadow:none!important;display:block!important}
    .docx-wrapper>section.docx{box-shadow:none!important;margin:0 auto!important;width:auto!important;min-height:auto!important}</style>
    </head><body><div class="note">Ban xem mau - cac truong dang {{ }} se duoc tu dien khi tao/in hop dong thuc te.</div>${tplForm.value.content}</body></html>`);
  w.document.close();
};

// Xem PDF gốc đã lưu kèm mẫu (bản tham khảo).
const viewReferencePdf = () => {
  const url = tplForm.value.referenceFile?.dataUrl;
  if (!url) return;
  const w = window.open('', '_blank');
  if (!w) { toast.error('Trình duyệt chặn cửa sổ bật lên — hãy cho phép pop-up'); return; }
  w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>${tplForm.value.referenceFile.name || 'PDF gốc'}</title></head><body style="margin:0"><iframe src="${url}" style="border:0;width:100%;height:100vh"></iframe></body></html>`);
  w.document.close();
};

const loadTemplates = async () => {
  try {
    const [tpls, ph] = await Promise.all([
      contractService.getTemplates(),
      contractService.getPlaceholders().catch(() => []),
    ]);
    templates.value = tpls;
    placeholders.value = ph;
  } catch (e) { console.error('load templates', e); }
};

const openTemplates = async () => {
  showTemplates.value = true;
  await loadTemplates();
  if (templates.value.length) selectTemplate(templates.value[0]);
  else newTemplate();
};

const resetParseState = () => {
  detectedFields.value = [];
  mappingContent.value = '';
  parsedFileName.value = '';
  parseWarning.value = '';
  parseError.value = '';
};

const selectTemplate = (t) => {
  let meta = t.meta && typeof t.meta === 'object' ? t.meta : {};
  if (typeof t.meta === 'string') { try { meta = JSON.parse(t.meta) || {}; } catch (e) { meta = {}; } }
  tplForm.value = {
    id: t.id, name: t.name, content: t.content || '',
    is_default: !!t.is_default, source: t.source || 'uploaded',
    referenceFile: meta.reference_file || null, meta,
  };
  resetParseState();
};

const newTemplate = () => {
  tplForm.value = { id: null, name: '', content: '', is_default: false, source: 'uploaded', referenceFile: null, meta: {} };
  resetParseState();
};

const saveTemplate = async () => {
  if (!tplForm.value.name || !tplForm.value.content) { toast.error('Nhập tên và nội dung mẫu'); return; }
  tplBusy.value = true;
  try {
    const meta = { ...(tplForm.value.meta || {}) };
    if (tplForm.value.referenceFile) meta.reference_file = tplForm.value.referenceFile;
    else delete meta.reference_file;
    const payload = { name: tplForm.value.name, content: tplForm.value.content, is_default: tplForm.value.is_default, meta };
    if (tplForm.value.id) await contractService.updateTemplate(tplForm.value.id, payload);
    else await contractService.createTemplate(payload);
    toast.success('Đã lưu mẫu hợp đồng');
    await loadTemplates();
  } catch (err) {
    toast.error(err?.response?.data?.message || 'Không thể lưu mẫu');
  } finally {
    tplBusy.value = false;
  }
};

const removeTemplate = async () => {
  if (!tplForm.value.id || !confirm('Xóa mẫu này?')) return;
  tplBusy.value = true;
  try {
    await contractService.deleteTemplate(tplForm.value.id);
    toast.success('Đã xóa mẫu');
    newTemplate();
    await loadTemplates();
  } catch (err) {
    toast.error(err?.response?.data?.message || 'Không thể xóa mẫu');
  } finally {
    tplBusy.value = false;
  }
};

const loadConfig = async () => {
  const map = await settingsService.getEffectiveMap();
  const pm = Number(map['contract.probation_max_days']);
  const mf = Number(map['contract.max_fixed_term']);
  const ea = Number(map['contract.expiry_alert_days']);
  if (!Number.isNaN(pm) && pm > 0) probationMaxDays.value = pm;
  if (!Number.isNaN(mf) && mf > 0) maxFixedTerm.value = mf;
  if (!Number.isNaN(ea) && ea > 0) expiryAlertDays.value = ea;
};

onMounted(() => { loadData(); loadConfig(); });
</script>
