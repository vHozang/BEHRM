<template>
  <BaseCard>
    <div class="mb-4 flex items-center justify-between gap-3"><div><h3 class="font-semibold">Hồ sơ bảo hiểm</h3><p class="text-xs text-muted-foreground">Nhân viên gửi hồ sơ, HR thẩm định, Kế toán xác nhận thanh toán.</p></div><BaseButton @click="openCreate">+ Tạo hồ sơ</BaseButton></div>
    <div v-if="loading" class="py-8 text-center text-muted-foreground">Đang tải...</div>
    <div v-else class="overflow-x-auto rounded-xl border border-border"><table class="w-full min-w-[900px] text-sm"><thead class="bg-muted/40 text-left text-xs uppercase text-muted-foreground"><tr><th class="px-3 py-2.5">Mã</th><th class="px-3 py-2.5">Nhân viên</th><th class="px-3 py-2.5">Loại</th><th class="px-3 py-2.5">Thời gian</th><th class="px-3 py-2.5">Trạng thái</th><th class="px-3 py-2.5"></th></tr></thead><tbody>
      <tr v-for="claim in claims" :key="claim.id" class="border-t border-border/70"><td class="px-3 py-3 font-mono text-xs">{{ claim.claim_code }}</td><td class="px-3 py-3">{{ claim.employee_name || 'Tôi' }}</td><td class="px-3 py-3">{{ claim.insurance_type_name }}</td><td class="px-3 py-3">{{ claim.start_date || '—' }} → {{ claim.end_date || '—' }}</td><td class="px-3 py-3">{{ statusLabel(claim.payment_status) }}</td><td class="px-3 py-3 text-right whitespace-nowrap"><button class="text-xs font-semibold text-primary" @click="openDetail(claim)">Chi tiết</button><button v-if="claim.payment_status === 'DRAFT'" class="ml-3 text-xs font-semibold text-amber-600" @click="submitClaim(claim)">Gửi HR</button><button v-if="claim.payment_status === 'DRAFT'" class="ml-3 text-xs font-semibold text-destructive" @click="removeClaim(claim)">Xóa</button></td></tr>
      <tr v-if="!claims.length"><td colspan="6" class="py-8 text-center text-muted-foreground">Chưa có hồ sơ.</td></tr>
    </tbody></table></div>
  </BaseCard>

  <BaseModal v-model="formModal" :title="form.id ? 'Sửa hồ sơ bảo hiểm' : 'Tạo hồ sơ bảo hiểm'" size="lg">
    <div class="grid gap-4 sm:grid-cols-2">
      <label v-if="!employeeId" class="block text-sm font-medium">Nhân viên<RemoteEmployeeSelect v-model="form.employee_id" :initial-label="form.employee_label || ''" /></label>
      <label class="block text-sm font-medium">Loại bảo hiểm<ResourceSelect v-model="form.insurance_type_id" resource="insurance-types" label-key="insurance_type_name" code-key="insurance_type_code" /></label>
      <label class="block text-sm font-medium">Từ ngày<input v-model="form.start_date" type="date" class="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2 font-normal" /></label>
      <label class="block text-sm font-medium">Đến ngày<input v-model="form.end_date" type="date" class="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2 font-normal" /></label>
      <label class="block text-sm font-medium">Số chứng từ<input v-model="form.certificate_number" class="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2 font-normal" /></label>
      <label class="block text-sm font-medium">Ngân hàng<ResourceSelect v-model="form.bank_id" resource="banks" label-key="bank_name" code-key="bank_code" /></label>
      <label class="block text-sm font-medium">Tài khoản<input v-model="form.bank_account" class="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2 font-normal" /></label>
      <label class="block text-sm font-medium">Tổng ngày<input v-model="form.total_days" type="number" min="0" step="0.5" class="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2 font-normal" /></label>
      <label class="block text-sm font-medium sm:col-span-2">Ghi chú<textarea v-model="form.notes" rows="3" class="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2 font-normal"></textarea></label>
      <p v-if="formError" class="sm:col-span-2 rounded-lg bg-destructive/10 p-3 text-sm text-destructive">{{ formError }}</p>
    </div>
    <template #footer><BaseButton variant="outline" @click="formModal = false">Hủy</BaseButton><BaseButton :disabled="saving" @click="save">Lưu</BaseButton></template>
  </BaseModal>

  <BaseModal v-model="detailModal" title="Chi tiết hồ sơ bảo hiểm" size="lg">
    <div v-if="selected" class="space-y-4"><div class="rounded-xl bg-muted/40 p-4"><p class="font-mono text-xs">{{ selected.claim_code }}</p><p class="font-semibold">{{ selected.employee_name || 'Nhân viên' }} · {{ selected.insurance_type_name }}</p><p class="mt-1 text-sm">{{ statusLabel(selected.payment_status) }}</p><p class="mt-3 whitespace-pre-line text-sm">{{ selected.notes || 'Không có ghi chú.' }}</p></div>
      <div class="flex flex-wrap gap-2"><label class="cursor-pointer rounded-lg border border-input px-3 py-2 text-sm font-semibold">Tải chứng từ lên<input type="file" accept=".pdf,.jpg,.jpeg,.png" class="hidden" @change="uploadCertificate" /></label><BaseButton v-if="selected.certificate_file_url" variant="outline" @click="downloadCertificate">Tải chứng từ</BaseButton><BaseButton v-if="selected.payment_status === 'DRAFT'" variant="outline" @click="editSelected">Sửa</BaseButton></div>
      <div v-if="canReview && ['SUBMITTED','HR_REJECTED'].includes(selected.payment_status)" class="rounded-xl border border-border p-4"><p class="mb-2 font-semibold">HR thẩm định</p><textarea v-model="decision.note" rows="2" class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm" placeholder="Lý do/ghi chú"></textarea><div class="mt-2 flex gap-2"><BaseButton @click="review('APPROVE')">Duyệt</BaseButton><BaseButton variant="outline" @click="review('REJECT')">Từ chối</BaseButton></div></div>
      <div v-if="canPay && ['HR_APPROVED','PAYMENT_FAILED'].includes(selected.payment_status)" class="rounded-xl border border-border p-4"><p class="mb-2 font-semibold">Kế toán thanh toán</p><input v-model="decision.reference" class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm" placeholder="Mã giao dịch" /><div class="mt-2 flex gap-2"><BaseButton @click="payment('PAID')">Đã thanh toán</BaseButton><BaseButton variant="outline" @click="payment('PAYMENT_FAILED')">Thanh toán lỗi</BaseButton></div></div>
    </div>
    <template #footer><BaseButton variant="outline" @click="detailModal = false">Đóng</BaseButton></template>
  </BaseModal>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import BaseButton from './BaseButton.vue'; import BaseCard from './BaseCard.vue'; import BaseModal from './BaseModal.vue'; import RemoteEmployeeSelect from './RemoteEmployeeSelect.vue'; import ResourceSelect from './ResourceSelect.vue';
import { authService } from '../services/authService'; import { insuranceClaimService } from '../services/insuranceClaimService'; import { useToast } from '../composables/useToast';
const props = defineProps({ employeeId: { type: [String, Number], default: null } }); const toast = useToast(); const claims = ref([]); const loading = ref(false); const saving = ref(false); const formModal = ref(false); const detailModal = ref(false); const selected = ref(null); const formError = ref(''); const canReview = computed(() => authService.hasCapability('insurance.review')); const canPay = computed(() => authService.hasCapability('insurance.pay'));
const form = reactive({ id: null, employee_id: '', employee_label: '', insurance_type_id: '', start_date: '', end_date: '', certificate_number: '', bank_id: '', bank_account: '', total_days: '', notes: '' }); const decision = reactive({ note: '', reference: '' });
const statusLabel = (value) => ({ DRAFT: 'Nháp', SUBMITTED: 'Chờ HR duyệt', HR_APPROVED: 'HR đã duyệt', HR_REJECTED: 'HR từ chối', PAID: 'Đã thanh toán', PAYMENT_FAILED: 'Thanh toán lỗi' }[value] || value);
const load = async () => { loading.value = true; try { claims.value = (await insuranceClaimService.list({ employee_id: props.employeeId || undefined, per_page: 100 })).items; } finally { loading.value = false; } };
const reset = (claim = null) => Object.assign(form, { id: claim?.id || null, employee_id: claim?.employee_id ? String(claim.employee_id) : (props.employeeId ? String(props.employeeId) : ''), employee_label: claim?.employee_name || '', insurance_type_id: claim?.insurance_type_id ? String(claim.insurance_type_id) : '', start_date: claim?.start_date || '', end_date: claim?.end_date || '', certificate_number: claim?.certificate_number || '', bank_id: claim?.bank_id ? String(claim.bank_id) : '', bank_account: claim?.bank_account || '', total_days: claim?.total_days || '', notes: claim?.notes || '' });
const openCreate = () => { reset(); formError.value = ''; formModal.value = true; }; const editSelected = () => { reset(selected.value); detailModal.value = false; formModal.value = true; };
const payload = () => ({ employee_id: form.employee_id ? Number(form.employee_id) : undefined, insurance_type_id: Number(form.insurance_type_id), start_date: form.start_date || null, end_date: form.end_date || null, certificate_number: form.certificate_number || null, bank_id: form.bank_id ? Number(form.bank_id) : null, bank_account: form.bank_account || null, total_days: form.total_days === '' ? null : Number(form.total_days), notes: form.notes || null });
const save = async () => { if (!form.insurance_type_id || (!props.employeeId && !form.employee_id)) { formError.value = 'Chọn nhân viên và loại bảo hiểm'; return; } saving.value = true; try { form.id ? await insuranceClaimService.update(form.id, payload()) : await insuranceClaimService.create(payload()); formModal.value = false; await load(); } catch (error) { formError.value = error.response?.data?.message || 'Không thể lưu hồ sơ'; } finally { saving.value = false; } };
const openDetail = async (claim) => { selected.value = await insuranceClaimService.show(claim.id); detailModal.value = true; Object.assign(decision, { note: '', reference: '' }); };
const submitClaim = async (claim) => { await insuranceClaimService.submit(claim.id); await load(); }; const removeClaim = async (claim) => { if (!confirm('Xóa hồ sơ nháp này?')) return; await insuranceClaimService.remove(claim.id); await load(); };
const review = async (value) => { try { selected.value = await insuranceClaimService.review(selected.value.id, { decision: value, note: decision.note || null }); await load(); } catch (error) { toast.error(error.response?.data?.message || 'Không thể thẩm định'); } };
const payment = async (value) => { selected.value = await insuranceClaimService.payment(selected.value.id, { status: value, reference: decision.reference || null }); await load(); };
const uploadCertificate = async (event) => { const file = event.target.files?.[0]; event.target.value = ''; if (!file) return; await insuranceClaimService.uploadCertificate(selected.value.id, file); selected.value = await insuranceClaimService.show(selected.value.id); };
const downloadCertificate = async () => { const blob = await insuranceClaimService.downloadCertificate(selected.value.id); const url = URL.createObjectURL(blob); const link = document.createElement('a'); link.href = url; link.download = `ChungTu-${selected.value.claim_code}`; link.click(); URL.revokeObjectURL(url); };
watch(() => props.employeeId, load); onMounted(load);
</script>
