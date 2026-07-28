<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-3xl font-bold text-foreground">Hỗ Trợ Nội Bộ (Helpdesk)</h1>
        <p class="text-muted-foreground mt-1">Gửi yêu cầu hỗ trợ kỹ thuật IT, văn phòng phẩm hoặc phản ánh nhân sự</p>
      </div>
      <BaseButton @click="openCreateModal">+ Gửi yêu cầu hỗ trợ</BaseButton>
    </div>

    <!-- Summary Statistics -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <div v-for="stat in stats" :key="stat.label" class="bg-card p-4 rounded-xl border border-border flex items-center justify-between">
        <div>
          <p class="text-xs text-muted-foreground font-semibold uppercase">{{ stat.label }}</p>
          <p class="text-2xl font-black mt-1" :class="stat.colorClass">{{ stat.count }}</p>
        </div>
        <div class="p-3 rounded-full" :class="stat.bgColorClass">
          <svg class="w-5 h-5" :class="stat.colorClass" fill="none" stroke="currentColor" viewBox="0 0 24 24" v-html="stat.iconSvg"></svg>
        </div>
      </div>
    </div>

    <!-- Tickets List Table -->
    <BaseCard>
      <BaseTable
        :columns="[
          { key: 'ticket_code', label: 'Mã Ticket' },
          { key: 'title', label: 'Nội dung yêu cầu' },
          { key: 'category', label: 'Phân loại' },
          { key: 'priority', label: 'Mức độ' },
          { key: 'created_at', label: 'Ngày tạo' },
          { key: 'status', label: 'Trạng thái' }
        ]"
        :data="tickets"
      >
        <template #cell-ticket_code="{ item }">
          <span class="font-bold text-foreground">#{{ item.ticket_code || `TK-${item.id}` }}</span>
        </template>

        <template #cell-title="{ item }">
          <div class="font-semibold text-foreground">{{ item.title }}</div>
          <div class="text-xs text-muted-foreground line-clamp-1 mt-0.5">{{ item.description }}</div>
          <div v-if="item.admin_note" class="text-[10px] text-primary bg-primary/5 px-2 py-0.5 rounded border border-primary/20 w-max mt-1">
            Phản hồi: {{ item.admin_note }}
          </div>
        </template>

        <template #cell-category="{ item }">
          <span>{{ getCategoryLabel(item.category) }}</span>
        </template>

        <template #cell-priority="{ item }">
          <BaseBadge :variant="getPriorityVariant(item.priority)">
            {{ getPriorityLabel(item.priority) }}
          </BaseBadge>
        </template>

        <template #cell-created_at="{ item }">
          <span>{{ formatDate(item.created_at) }}</span>
        </template>

        <template #cell-status="{ item }">
          <BaseBadge :variant="getStatusVariant(item.status)">
            {{ getStatusLabel(item.status) }}
          </BaseBadge>
        </template>

        <template #actions="{ item }">
          <div class="flex gap-1">
            <button 
              @click="viewDetail(item)" 
              class="px-2.5 py-1.5 text-xs font-medium rounded-md bg-primary/10 text-primary hover:bg-primary/20 transition-colors"
            >
              {{ isAdmin ? 'Xử lý' : 'Xem chi tiết' }}
            </button>
            <button 
              v-if="item.status === 'pending'"
              @click="deleteItem(item.id)" 
              class="px-2.5 py-1.5 text-xs font-medium rounded-md bg-destructive/10 text-destructive hover:bg-destructive/20 transition-colors"
            >
              Hủy
            </button>
          </div>
        </template>
      </BaseTable>
    </BaseCard>

    <!-- Create Ticket Modal -->
    <BaseModal v-model="showCreateModal" title="Gửi yêu cầu hỗ trợ mới">
      <div class="space-y-4">
        <BaseInput v-model="form.title" label="Tiêu đề yêu cầu hỗ trợ" placeholder="Ví dụ: Máy in phòng HR bị kẹt giấy, lỗi Wifi tầng 3..." required />
        
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-foreground mb-1">Phân mục yêu cầu <span class="text-destructive">*</span></label>
            <select 
              v-model="form.category" 
              class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground text-sm focus:outline-none focus:ring-2 focus:ring-ring"
              required
            >
              <option value="IT">Hỗ trợ kỹ thuật IT</option>
              <option value="HR">Thắc mắc nhân sự (HR)</option>
              <option value="Admin">Hành chính / Văn phòng phẩm</option>
              <option value="Others">Khác</option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium text-foreground mb-1">Mức độ khẩn cấp <span class="text-destructive">*</span></label>
            <select 
              v-model="form.priority" 
              class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground text-sm focus:outline-none focus:ring-2 focus:ring-ring"
              required
            >
              <option value="low">Thấp</option>
              <option value="medium">Trung bình</option>
              <option value="high">Khẩn cấp</option>
            </select>
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium text-foreground mb-1">Chi tiết yêu cầu / Lỗi gặp phải <span class="text-destructive">*</span></label>
          <textarea 
            v-model="form.description" 
            class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground text-sm focus:outline-none focus:ring-2 focus:ring-ring" 
            rows="4" 
            placeholder="Mô tả chi tiết lỗi, số bàn làm việc hoặc thiết bị đang gặp sự cố..."
            required
          ></textarea>
        </div>
      </div>
      <template #footer>
        <BaseButton variant="outline" @click="showCreateModal = false">Hủy</BaseButton>
        <BaseButton @click="submitCreateTicket">Gửi Ticket</BaseButton>
      </template>
    </BaseModal>

    <!-- Detail & Handle Modal -->
    <BaseModal v-model="showDetailModal" :title="'Chi tiết Ticket #' + selectedTicket?.ticket_code" size="md">
      <div v-if="selectedTicket" class="space-y-4">
        <div class="bg-muted/30 p-4 rounded-xl space-y-2 text-sm">
          <p><strong>Tiêu đề:</strong> {{ selectedTicket.title }}</p>
          <p><strong>Người gửi:</strong> {{ selectedTicket.employee_name || 'Nhân viên' }}</p>
          <p><strong>Phân loại:</strong> {{ getCategoryLabel(selectedTicket.category) }}</p>
          <p><strong>Mức độ:</strong> {{ getPriorityLabel(selectedTicket.priority) }}</p>
          <p><strong>Mô tả lỗi:</strong> {{ selectedTicket.description }}</p>
        </div>

        <div class="border-t border-border pt-4">
          <p class="text-sm font-semibold text-foreground mb-3">Quy trình xử lý</p>
          <ApprovalTimeline :steps="approvalSteps" />
        </div>

        <!-- Admin handling panel -->
        <div v-if="isAdmin" class="space-y-3 p-4 bg-primary/5 rounded-xl border border-primary/20">
          <h4 class="font-bold text-primary text-sm">Xử Lý & Phản Hồi</h4>
          <div>
            <label class="block text-xs font-semibold text-muted-foreground mb-1">Cập nhật trạng thái</label>
            <select 
              v-model="selectedTicket.status" 
              class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground text-sm focus:outline-none focus:ring-2 focus:ring-ring"
            >
              <option value="pending">Chờ tiếp nhận (Pending)</option>
              <option value="processing">Đang xử lý (Processing)</option>
              <option value="completed">Đã hoàn thành (Completed)</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-semibold text-muted-foreground mb-1">Lời nhắn gửi nhân viên</label>
            <textarea 
              v-model="selectedTicket.admin_note" 
              class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground text-sm focus:outline-none focus:ring-2 focus:ring-ring" 
              rows="2" 
              placeholder="Nhập nội dung phản hồi, thời gian dự kiến sửa xong..."
            ></textarea>
          </div>
        </div>
      </div>
      <template #footer>
        <BaseButton variant="outline" @click="showDetailModal = false">Đóng</BaseButton>
        <BaseButton v-if="isAdmin" @click="submitHandleTicket">Lưu trạng thái</BaseButton>
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
import BaseBadge from '../components/BaseBadge.vue';
import ApprovalTimeline from '../components/ApprovalTimeline.vue';
import { serviceTicketService } from '../services/serviceTicketService';
import { authService } from '../services/authService';
import { useToast } from '../composables/useToast';

const toast = useToast();
const tickets = ref([]);
const isAdmin = computed(() => authService.canAccessModule('communications'));

const showCreateModal = ref(false);
const showDetailModal = ref(false);
const selectedTicket = ref(null);

const approvalSteps = computed(() => {
  if (!selectedTicket.value) return [];
  const tk = selectedTicket.value;
  return [
    {
      step_name: 'Khởi tạo Ticket',
      approver_name: tk.employee_name || 'Nhân viên',
      status: 'ĐÃ_DUYỆT',
      action_date: tk.created_at,
      comment: 'Yêu cầu hỗ trợ đã được tạo'
    },
    {
      step_name: 'Tiếp nhận hỗ trợ',
      approver_name: 'Đội ngũ IT/HR',
      status: tk.status !== 'pending' ? 'ĐÃ_DUYỆT' : 'CHỜ_DUYỆT',
      action_date: tk.status !== 'pending' ? tk.updated_at : null,
      comment: tk.status !== 'pending' ? 'Yêu cầu đã được tiếp nhận' : 'Đang chờ điều phối'
    },
    {
      step_name: 'Hoàn thành xử lý',
      approver_name: 'Kỹ thuật viên',
      status: tk.status === 'completed' ? 'HOÀN_THÀNH' : 'CHỜ_DUYỆT',
      action_date: tk.status === 'completed' ? tk.updated_at : null,
      comment: tk.status === 'completed' ? (tk.admin_note || 'Sự cố đã được khắc phục') : null
    }
  ];
});

const form = ref({
  title: '',
  category: 'IT',
  priority: 'medium',
  description: ''
});

const loadData = async () => {
  try {
    const user = authService.getUser();
    const params = isAdmin.value ? { per_page: 100 } : { requester_id: user?.id, per_page: 100 };
    const res = await serviceTicketService.getAllTickets(params);
    tickets.value = res?.data || res || [];
  } catch (err) {
    console.error('Error loading tickets:', err);
    tickets.value = [];
  }
};

const formatDate = (date) => {
  if (!date) return '-';
  return new Date(date).toLocaleDateString('vi-VN');
};

const openCreateModal = () => {
  form.value = {
    title: '',
    category: 'IT',
    priority: 'medium',
    description: ''
  };
  showCreateModal.value = true;
};

const submitCreateTicket = async () => {
  if (!form.value.title || !form.value.description) {
    toast.error('Vui lòng nhập đầy đủ tiêu đề và nội dung mô tả');
    return;
  }
  try {
    const data = {
      ...form.value,
      requester_id: authService.getUser()?.id,
      ticket_code: `TK${Date.now().toString().slice(-6)}`,
      status: 'pending'
    };
    await serviceTicketService.createTicket(data);
    toast.success('Gửi yêu cầu hỗ trợ thành công');
    showCreateModal.value = false;
    await loadData();
  } catch (err) {
    console.error('Error creating ticket:', err);
    toast.error('Gửi yêu cầu thất bại');
  }
};

const viewDetail = (item) => {
  selectedTicket.value = { ...item };
  showDetailModal.value = true;
};

const submitHandleTicket = async () => {
  if (!selectedTicket.value.id) return;
  try {
    await serviceTicketService.updateTicket(selectedTicket.value.id, {
      status: selectedTicket.value.status,
      admin_note: selectedTicket.value.admin_note
    });
    toast.success('Cập nhật trạng thái ticket thành công');
    showDetailModal.value = false;
    await loadData();
  } catch (err) {
    console.error('Error updating ticket:', err);
    toast.error('Cập nhật trạng thái thất bại');
  }
};

const deleteItem = async (id) => {
  if (!confirm('Bạn có chắc chắn muốn hủy yêu cầu hỗ trợ này?')) return;
  try {
    await serviceTicketService.updateTicket(id, { status: 'cancelled' });
    toast.success('Đã hủy yêu cầu hỗ trợ');
    await loadData();
  } catch (err) {
    console.error('Error deleting ticket:', err);
    toast.error('Hủy thất bại');
  }
};

// --- Statistics ---
const stats = computed(() => {
  const list = tickets.value;
  return [
    {
      label: 'Tổng số yêu cầu',
      count: list.length,
      colorClass: 'text-blue-600',
      bgColorClass: 'bg-blue-100 dark:bg-blue-900/30',
      iconSvg: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />'
    },
    {
      label: 'Đang xử lý',
      count: list.filter(t => t.status === 'processing').length,
      colorClass: 'text-amber-600',
      bgColorClass: 'bg-amber-100 dark:bg-amber-900/30',
      iconSvg: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />'
    },
    {
      label: 'Đã hoàn thành',
      count: list.filter(t => t.status === 'completed').length,
      colorClass: 'text-green-600',
      bgColorClass: 'bg-green-100 dark:bg-green-900/30',
      iconSvg: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />'
    }
  ];
});

// --- Label & Style Helpers ---
const getCategoryLabel = (cat) => {
  switch (cat) {
    case 'IT': return 'Hỗ trợ IT';
    case 'HR': return 'Phản ánh HR';
    case 'Admin': return 'Hành chính';
    default: return 'Khác';
  }
};

const getPriorityLabel = (pri) => {
  switch (pri) {
    case 'low': return 'Thấp';
    case 'medium': return 'Thường';
    case 'high': return 'Khẩn cấp';
    default: return 'Thường';
  }
};

const getPriorityVariant = (pri) => {
  switch (pri) {
    case 'low': return 'secondary';
    case 'medium': return 'info';
    case 'high': return 'destructive';
    default: return 'secondary';
  }
};

const getStatusLabel = (status) => {
  switch (status) {
    case 'pending': return 'Chờ tiếp nhận';
    case 'processing': return 'Đang xử lý';
    case 'completed': return 'Đã xong';
    case 'cancelled': return 'Đã hủy';
    default: return 'Chờ';
  }
};

const getStatusVariant = (status) => {
  switch (status) {
    case 'pending': return 'warning';
    case 'processing': return 'info';
    case 'completed': return 'success';
    case 'cancelled': return 'secondary';
    default: return 'secondary';
  }
};

onMounted(loadData);
</script>
