<template>
  <div class="px-4 pt-8">
    <h1 class="text-lg font-bold mb-4">Phiếu lương</h1>

    <div v-if="details.length === 0" class="rounded-2xl bg-card border border-border p-6 text-center text-sm text-muted-foreground">
      Chưa có phiếu lương — lương hiển thị sau khi phòng nhân sự chạy tính lương kỳ.
    </div>

    <div v-for="d in details" :key="d.id" @click="open(d)"
         class="rounded-2xl bg-card border border-border p-4 mb-2.5 flex items-center justify-between active:scale-[0.98] transition-transform cursor-pointer">
      <div>
        <p class="text-sm font-bold">Kỳ {{ d.period?.period_code || d.period_id }}</p>
        <p class="text-xs text-muted-foreground mt-0.5">Gross {{ vnd(d.gross_salary) }}</p>
      </div>
      <div class="text-right">
        <p class="font-extrabold text-primary">{{ vnd(d.net_salary) }}</p>
        <p class="text-[11px] text-muted-foreground">{{ documentStatus(d.payslip_document) }}</p>
      </div>
    </div>

    <!-- Bottom-sheet phiếu lương chi tiết -->
    <div v-if="slipOpen" class="fixed inset-0 z-50 bg-black/50" @click.self="slipOpen = false">
      <div class="absolute bottom-0 inset-x-0 bg-card rounded-t-3xl p-5 max-h-[88vh] overflow-y-auto"
           style="padding-bottom: calc(env(safe-area-inset-bottom) + 20px)">
        <div class="w-10 h-1 rounded-full bg-muted-foreground/30 mx-auto mb-4"></div>

        <div v-if="loading" class="py-10 text-center text-sm text-muted-foreground">Đang tải...</div>
        <template v-else-if="slip">
          <h2 class="font-bold">Phiếu lương kỳ {{ slip.salary_detail?.period?.period_code }}</h2>
          <p class="text-xs text-muted-foreground mb-4">{{ slip.salary_detail?.employee?.full_name }} · {{ slip.salary_detail?.employee?.employee_code }}</p>
          <p class="text-xs text-muted-foreground mb-4">{{ documentStatus(slip.document) }}</p>

          <!-- Công tháng -->
          <div v-if="slip.attendance_summary" class="grid grid-cols-4 gap-1 rounded-xl bg-muted/50 p-3 text-center mb-4">
            <div><p class="text-[10px] text-muted-foreground">Công chuẩn</p><p class="text-sm font-bold">{{ Number(slip.attendance_summary.standard_days) }}</p></div>
            <div><p class="text-[10px] text-muted-foreground">Đi làm</p><p class="text-sm font-bold">{{ Number(slip.attendance_summary.actual_working_days) }}</p></div>
            <div><p class="text-[10px] text-muted-foreground">Nghỉ phép</p><p class="text-sm font-bold">{{ Number(slip.attendance_summary.paid_leave_days) }}</p></div>
            <div><p class="text-[10px] text-muted-foreground">Tăng ca</p><p class="text-sm font-bold">{{ Number(slip.attendance_summary.overtime_hours) }}h</p></div>
          </div>

          <!-- Thu nhập -->
          <h3 class="text-[11px] font-bold uppercase text-muted-foreground mb-1.5">Thu nhập</h3>
          <div v-for="b in rows('EARNING')" :key="b.id" class="flex justify-between text-sm py-1.5 border-b border-border/50">
            <span>{{ b.item_name }}</span>
            <span class="font-semibold text-green-600">+{{ vnd(b.amount) }}</span>
          </div>

          <!-- Khấu trừ -->
          <h3 class="text-[11px] font-bold uppercase text-muted-foreground mb-1.5 mt-4">Khấu trừ</h3>
          <div v-for="b in rows('DEDUCTION')" :key="b.id" class="flex justify-between text-sm py-1.5 border-b border-border/50">
            <span>{{ b.item_name }}</span>
            <span class="font-semibold text-red-600">−{{ vnd(b.amount) }}</span>
          </div>

          <!-- NET -->
          <div class="flex justify-between items-center rounded-xl bg-primary/10 border border-primary/20 p-4 mt-4">
            <span class="font-bold text-sm">THỰC LĨNH</span>
            <span class="text-xl font-extrabold text-primary">{{ vnd(slip.salary_detail?.net_salary) }}</span>
          </div>

          <div v-if="slip.document?.generation_status === 'READY'" class="grid grid-cols-3 gap-2 mt-4">
            <button type="button" :disabled="actionBusy" @click="viewPdf" class="rounded-xl border border-border px-2 py-2.5 text-xs font-semibold active:bg-muted disabled:opacity-50">Xem PDF</button>
            <button type="button" :disabled="actionBusy" @click="downloadPdf" class="rounded-xl border border-border px-2 py-2.5 text-xs font-semibold active:bg-muted disabled:opacity-50">Tải PDF</button>
            <button type="button" :disabled="actionBusy" @click="emailPdf" class="rounded-xl border border-primary/30 px-2 py-2.5 text-xs font-semibold text-primary active:bg-primary/5 disabled:opacity-50">Gửi email</button>
          </div>
        </template>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { authService } from '../../services/authService';
import { salaryService } from '../../services/salaryService';
import { useNotificationStore } from '../../stores/notificationStore';
import { vnd } from './mformat';

const route = useRoute();
const notificationStore = useNotificationStore();
const empId = authService.getUser()?.employee_id;
const details = ref([]);
const slipOpen = ref(false);
const loading = ref(false);
const slip = ref(null);
const actionBusy = ref(false);

const rows = (type) => (slip.value?.breakdowns || []).filter(b => b.item_type === type && Number(b.amount) > 0);
const documentStatus = (document) => {
  if (!document) return 'Chưa phát hành';
  if (document.email_status === 'SENT') return 'Đã gửi email';
  if (document.email_status === 'MISSING_RECIPIENT') return 'PDF đã có · thiếu email';
  if (document.email_status === 'FAILED') return 'PDF đã có · gửi email lỗi';
  if (['QUEUED', 'SENDING'].includes(document.email_status)) return 'Đang gửi email';
  return document.generation_status === 'READY' ? 'PDF đã phát hành' : 'Đang tạo PDF';
};

const open = async (d) => {
  slipOpen.value = true;
  loading.value = true;
  slip.value = null;
  try {
    slip.value = await salaryService.getPayslip(d.id);
  } catch {
    slipOpen.value = false;
  } finally {
    loading.value = false;
  }
};

const pdfFilename = () => `Phieu_luong_${slip.value?.salary_detail?.employee?.employee_code || 'NhanVien'}_${slip.value?.salary_detail?.period?.period_code || 'ky'}.pdf`;

const handlePdfBlob = (blob, download) => {
  const url = URL.createObjectURL(blob);
  if (download) {
    const link = document.createElement('a');
    link.href = url;
    link.download = pdfFilename();
    link.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
    return;
  }
  const viewer = window.open(url, '_blank', 'noopener,noreferrer');
  if (!viewer) notificationStore.addError('Trình duyệt đang chặn cửa sổ xem PDF');
  setTimeout(() => URL.revokeObjectURL(url), 60_000);
};

const viewPdf = async () => {
  const id = slip.value?.salary_detail?.id;
  if (!id || actionBusy.value) return;
  actionBusy.value = true;
  try {
    handlePdfBlob(await salaryService.getPayslipPdf(id), false);
  } catch (error) {
    notificationStore.addError(error.response?.data?.message || 'Không mở được PDF phiếu lương');
  } finally {
    actionBusy.value = false;
  }
};

const downloadPdf = async () => {
  const id = slip.value?.salary_detail?.id;
  if (!id || actionBusy.value) return;
  actionBusy.value = true;
  try {
    handlePdfBlob(await salaryService.getPayslipPdf(id, true), true);
  } catch (error) {
    notificationStore.addError(error.response?.data?.message || 'Không tải được PDF phiếu lương');
  } finally {
    actionBusy.value = false;
  }
};

const emailPdf = async () => {
  const id = slip.value?.salary_detail?.id;
  if (!id || actionBusy.value) return;
  actionBusy.value = true;
  try {
    await salaryService.emailPayslip(id);
    slip.value.document = { ...slip.value.document, email_status: 'QUEUED' };
    notificationStore.addSuccess('Đã đưa phiếu lương vào hàng đợi gửi email');
  } catch (error) {
    notificationStore.addError(error.response?.data?.message || 'Không gửi được phiếu lương');
  } finally {
    actionBusy.value = false;
  }
};

onMounted(async () => {
  if (!empId) return;
  try {
    const res = await salaryService.getDetails({ employee_id: empId, per_page: 24 });
    details.value = (Array.isArray(res) ? res : (res?.items || []))
      // period_id không theo thứ tự thời gian trong data — sort theo mã kỳ P-YYYY-MM.
      .sort((a, b) => String(b.period?.period_code || '').localeCompare(String(a.period?.period_code || '')));
    const requested = Number(route.query.payslip || 0);
    const selected = details.value.find(item => Number(item.id) === requested);
    if (selected) await open(selected);
  } catch { /* empty state hiển thị */ }
});
</script>
