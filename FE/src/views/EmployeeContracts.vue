<template>
  <div class="space-y-6">
    <header>
      <p class="text-xs font-semibold uppercase tracking-[0.2em] text-primary">Hồ sơ nhân viên</p>
      <h1 class="mt-1 text-2xl font-bold text-foreground sm:text-3xl">Hợp đồng lao động</h1>
      <p class="mt-1 text-sm text-muted-foreground">Xem và ký hợp đồng của chính bạn.</p>
    </header>

    <div v-if="loading" class="space-y-3">
      <BaseSkeleton type="block" height="8rem" />
      <BaseSkeleton type="block" height="8rem" />
    </div>

    <BaseCard v-else-if="loadError" class="border-destructive/30">
      <p class="font-semibold text-destructive">Không thể tải hợp đồng</p>
      <p class="mt-1 text-sm text-muted-foreground">{{ loadError }}</p>
      <BaseButton class="mt-4" variant="outline" @click="loadContracts">Thử lại</BaseButton>
    </BaseCard>

    <BaseCard v-else-if="contracts.length === 0" class="py-10 text-center">
      <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-primary/10 text-primary">
        <IconFileText class="h-7 w-7" />
      </div>
      <h2 class="mt-4 font-bold text-foreground">Chưa có hợp đồng lao động</h2>
      <p class="mt-1 text-sm text-muted-foreground">Vui lòng liên hệ bộ phận Nhân sự nếu bạn cần hỗ trợ.</p>
    </BaseCard>

    <div v-else class="grid gap-4 xl:grid-cols-2">
      <BaseCard v-for="contract in contracts" :key="contract.id" class="relative overflow-hidden">
        <div class="absolute inset-y-0 left-0 w-1" :class="contractSignStatus(contract) === 'SIGNED' ? 'bg-success' : contractSignStatus(contract) === 'PENDING_SIGN' ? 'bg-warning' : 'bg-border'"></div>
        <div class="space-y-4 pl-2">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
              <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Số hợp đồng</p>
              <h2 class="mt-1 text-lg font-bold text-foreground">{{ contract.contract_number || contract.contract_code || ('HĐ #' + contract.id) }}</h2>
              <p class="mt-1 text-sm text-muted-foreground">{{ contract.contract_type_name || contract.contract_type?.contract_type_name || 'Hợp đồng lao động' }}</p>
            </div>
            <BaseBadge :variant="badgeVariant(contract)">{{ contractStatusLabel(contract) }}</BaseBadge>
          </div>

          <div class="grid grid-cols-2 gap-3 rounded-xl bg-muted/40 p-3 text-sm">
            <div>
              <p class="text-xs text-muted-foreground">Ngày bắt đầu</p>
              <p class="mt-1 font-medium text-foreground">{{ formatDate(contract.start_date) }}</p>
            </div>
            <div>
              <p class="text-xs text-muted-foreground">Ngày kết thúc</p>
              <p class="mt-1 font-medium text-foreground">{{ contract.end_date ? formatDate(contract.end_date) : 'Vô thời hạn' }}</p>
            </div>
          </div>

          <div v-if="contractSignStatus(contract) === 'SIGNED' && contractSignatureImage(contract)" class="rounded-xl border border-success/20 bg-success/5 p-3">
            <p class="mb-2 text-xs font-semibold text-success">Chữ ký của bạn</p>
            <img :src="contractSignatureImage(contract)" alt="Chữ ký nhân viên" class="h-14 max-w-full object-contain object-left" />
          </div>

          <div class="flex flex-col gap-2 sm:flex-row">
            <BaseButton variant="outline" class="w-full sm:w-auto" @click="openPreview(contract)">Xem hợp đồng</BaseButton>
            <BaseButton v-if="contractSignStatus(contract) === 'PENDING_SIGN'" class="w-full sm:w-auto" @click="openSign(contract)">Thêm chữ ký</BaseButton>
          </div>
        </div>
      </BaseCard>
    </div>

    <BaseModal v-model="showPreview" title="Xem hợp đồng lao động" size="xl">
      <div v-if="previewLoading" class="py-12">
        <BaseSkeleton type="block" height="24rem" />
      </div>
      <div v-else-if="previewError" class="rounded-xl border border-destructive/20 bg-destructive/10 p-4 text-sm text-destructive">
        {{ previewError }}
      </div>
      <iframe
        v-else
        :srcdoc="previewDocument"
        sandbox
        title="Nội dung hợp đồng lao động"
        class="h-[65vh] w-full rounded-xl border border-border bg-white"
      ></iframe>
    </BaseModal>

    <BaseModal v-model="showSign" title="Thêm chữ ký hợp đồng" size="lg" :close-on-backdrop="!signBusy">
      <div v-if="selectedContract" class="space-y-4">
        <div class="rounded-xl bg-muted/50 p-3 text-sm">
          <p class="font-semibold text-foreground">{{ selectedContract.contract_number || selectedContract.contract_code || ('HĐ #' + selectedContract.id) }}</p>
          <p class="mt-1 text-muted-foreground">Vui lòng xem kỹ hợp đồng, nhập OTP rồi vẽ chữ ký của bạn.</p>
          <button type="button" class="mt-2 text-xs font-semibold text-primary hover:underline" @click="previewSelectedContract">Đóng và xem hợp đồng</button>
        </div>

        <div class="rounded-xl border border-border p-4">
          <p class="text-sm font-semibold text-foreground">1. Xác nhận bằng OTP</p>
          <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-center">
            <input v-model="signOtp" type="text" inputmode="numeric" maxlength="6" placeholder="Nhập mã OTP 6 số"
              class="min-h-10 w-full rounded-xl border border-input bg-background px-3 text-foreground outline-none focus:ring-2 focus:ring-ring sm:max-w-52" />
            <BaseButton variant="outline" size="sm" :loading="otpLoading" :disabled="signBusy" @click="requestOtp">Gửi mã OTP</BaseButton>
          </div>
          <p v-if="otpHint" class="mt-2 text-xs text-warning">{{ otpHint }}</p>
        </div>

        <div class="rounded-xl border border-border p-4">
          <p class="mb-3 text-sm font-semibold text-foreground">2. Vẽ chữ ký</p>
          <div v-if="signSignature" class="flex flex-wrap items-center gap-3">
            <div class="rounded-xl border border-border bg-white p-2"><img :src="signSignature" alt="Chữ ký vừa tạo" class="h-16 max-w-full object-contain" /></div>
            <button type="button" class="text-xs text-muted-foreground hover:underline" @click="signSignature = ''">Ký lại</button>
          </div>
          <SignaturePad v-else @save="signSignature = $event" />
        </div>

        <div v-if="signError" class="rounded-xl border border-destructive/20 bg-destructive/10 p-3 text-sm text-destructive">{{ signError }}</div>
      </div>
      <template #footer>
        <BaseButton variant="outline" :disabled="signBusy" @click="showSign = false">Hủy</BaseButton>
        <BaseButton :loading="signBusy" :disabled="!signOtp || !signSignature" @click="submitSignature">Xác nhận ký</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import BaseBadge from '../components/BaseBadge.vue';
import BaseButton from '../components/BaseButton.vue';
import BaseCard from '../components/BaseCard.vue';
import BaseModal from '../components/BaseModal.vue';
import BaseSkeleton from '../components/BaseSkeleton.vue';
import IconFileText from '../components/IconFileText.vue';
import SignaturePad from '../components/SignaturePad.vue';
import { authService } from '../services/authService';
import { contractService } from '../services/contractService';
import { useNotificationStore } from '../stores/notificationStore';
import { contractSignatureImage, contractSignStatus, contractStatusLabel } from '../utils/employeeContract';

const notificationStore = useNotificationStore();
const currentUser = authService.getUser();
const employeeId = currentUser?.employee_id || currentUser?.id;

const contracts = ref([]);
const loading = ref(true);
const loadError = ref('');
const showPreview = ref(false);
const previewLoading = ref(false);
const previewHtml = ref('');
const previewError = ref('');
const showSign = ref(false);
const selectedContract = ref(null);
const signOtp = ref('');
const signSignature = ref('');
const signError = ref('');
const signBusy = ref(false);
const otpLoading = ref(false);
const otpHint = ref('');

const previewDocument = computed(() => `<!doctype html><html><head><meta charset="utf-8"><style>
  body{margin:24px;background:#fff;color:#111;font-family:'Times New Roman',serif;line-height:1.55}
  img{max-width:100%}.docx-wrapper{background:#fff!important;padding:0!important;box-shadow:none!important}
  .docx-wrapper>section.docx{box-shadow:none!important;margin:0 auto!important}
</style></head><body>${previewHtml.value}</body></html>`);

const badgeVariant = (contract) => {
  const status = contractSignStatus(contract);
  if (status === 'SIGNED') return 'success';
  if (status === 'PENDING_SIGN') return 'warning';
  return 'default';
};

const formatDate = (value) => {
  if (!value) return '—';
  const dateOnly = String(value).slice(0, 10);
  const [year, month, day] = dateOnly.split('-');
  return year && month && day ? `${day}/${month}/${year}` : value;
};

const loadContracts = async () => {
  loading.value = true;
  loadError.value = '';
  try {
    if (!employeeId) throw new Error('Tài khoản chưa liên kết với hồ sơ nhân viên');
    const data = await contractService.getAll({ employee_id: employeeId });
    contracts.value = Array.isArray(data) ? data : (data?.items || data?.data || []);
  } catch (error) {
    loadError.value = error.response?.data?.message || error.message || 'Không thể tải hợp đồng';
  } finally {
    loading.value = false;
  }
};

const openPreview = async (contract) => {
  showPreview.value = true;
  previewLoading.value = true;
  previewHtml.value = '';
  previewError.value = '';
  try {
    const data = await contractService.render(contract.id);
    previewHtml.value = data?.html || '';
    if (!previewHtml.value) throw new Error('Hợp đồng chưa có nội dung hiển thị');
  } catch (error) {
    previewError.value = error.response?.data?.message || error.message || 'Không thể hiển thị hợp đồng';
  } finally {
    previewLoading.value = false;
  }
};

const openSign = (contract) => {
  selectedContract.value = contract;
  signOtp.value = '';
  signSignature.value = '';
  signError.value = '';
  otpHint.value = '';
  showSign.value = true;
};

const previewSelectedContract = () => {
  if (!selectedContract.value) return;
  showSign.value = false;
  openPreview(selectedContract.value);
};

const requestOtp = async () => {
  if (!selectedContract.value) return;
  otpLoading.value = true;
  signError.value = '';
  try {
    const data = await contractService.requestOtp(selectedContract.value.id);
    otpHint.value = data?.dev_otp
      ? `Mã OTP demo: ${data.dev_otp} — hiệu lực 10 phút.`
      : 'Đã gửi OTP đến email công ty của bạn. Mã có hiệu lực 10 phút.';
  } catch (error) {
    signError.value = error.response?.data?.data?.errors?.sign_status?.[0]
      || error.response?.data?.message || 'Không thể gửi OTP';
  } finally {
    otpLoading.value = false;
  }
};

const submitSignature = async () => {
  if (!selectedContract.value || !signOtp.value || !signSignature.value) return;
  signBusy.value = true;
  signError.value = '';
  try {
    await contractService.sign(selectedContract.value.id, {
      otp: signOtp.value,
      signature: signSignature.value,
    });
    notificationStore.addSuccess('Đã ký hợp đồng thành công');
    showSign.value = false;
    await loadContracts();
  } catch (error) {
    const errors = error.response?.data?.data?.errors || {};
    signError.value = errors.otp?.[0] || errors.sign_status?.[0]
      || error.response?.data?.message || 'Không thể ký hợp đồng';
  } finally {
    signBusy.value = false;
  }
};

onMounted(loadContracts);
</script>
