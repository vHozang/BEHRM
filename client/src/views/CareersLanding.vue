<template>
  <main class="careers-page">
    <nav class="careers-nav">
      <a class="brand" href="/careers" aria-label="DevTap Careers">
        <span class="brand-mark">DT</span>
        <span>DEV TAP <em>/</em> CAREERS</span>
      </a>
      <div class="nav-links">
        <a href="#opportunities">Vị trí đang mở</a>
        <a href="#process">Quy trình</a>
        <RouterLink class="nav-login" to="/login">Dành cho nhân sự <span>↗</span></RouterLink>
      </div>
      <a class="nav-mobile-cta" href="#opportunities">Xem vị trí <span>↓</span></a>
    </nav>

    <section class="hero-shell">
      <div class="hero-copy">
        <p class="eyebrow"><span class="eyebrow-dot"></span> Đang tìm những người tạo khác biệt</p>
        <h1>Build the next<br /><span>good thing.</span></h1>
        <p class="hero-lede">Chúng tôi xây những sản phẩm giúp con người làm việc tốt hơn. Nếu bạn muốn nhìn thấy ý tưởng của mình đi vào đời sống, hãy bắt đầu từ đây.</p>
        <div class="hero-actions">
          <a class="button button-primary" href="#opportunities">Khám phá cơ hội <span>↓</span></a>
          <a class="text-link" href="#process">Cách chúng tôi tuyển dụng <span>↗</span></a>
        </div>
        <div class="hero-note"><span class="note-line"></span> Ứng tuyển trong 3 phút · Phản hồi trong 7 ngày</div>
      </div>

      <div class="hero-art" aria-label="AI-powered recruitment preview">
        <div class="orbit orbit-one"></div>
        <div class="orbit orbit-two"></div>
        <div class="signal-card">
          <div class="signal-top"><span>RECRUITMENT / 01</span><span class="signal-live"><i></i> LIVE</span></div>
          <div class="signal-title">Tìm đúng người.<br /><strong>Đúng cảm hứng.</strong></div>
          <div class="signal-score-row"><span class="score-label">AI MATCH</span><strong>92<span>%</span></strong></div>
          <div class="score-track"><span></span></div>
          <div class="signal-tags"><span>human-first</span><span>curious</span><span>craft</span></div>
          <div class="signal-footer"><span>CV screening complete</span><span>→</span></div>
        </div>
        <div class="art-caption"><span>01</span><span>Con người ở trung tâm<br />Công nghệ ở phía sau</span></div>
      </div>
    </section>

    <section class="manifesto-strip">
      <div class="manifesto-item"><strong>01</strong><span>Curiosity<br />over certainty</span></div>
      <div class="manifesto-item"><strong>02</strong><span>Craft<br />over noise</span></div>
      <div class="manifesto-item"><strong>03</strong><span>People<br />over process</span></div>
      <p>Đội ngũ nhỏ, câu hỏi lớn, và đủ không gian để làm mọi thứ thật tốt.</p>
    </section>

    <section id="opportunities" class="opportunities-section">
      <div class="section-heading">
        <div>
          <p class="eyebrow"><span class="eyebrow-dot amber"></span> Mở cửa cho bạn</p>
          <h2>Cơ hội đang mở</h2>
        </div>
        <p class="section-aside">Không thấy vị trí phù hợp? <a href="mailto:hello@devtapcode.io.vn">Gửi hồ sơ tự do ↗</a></p>
      </div>

      <div v-if="loadingPosts" class="jobs-state"><span class="loader"></span> Đang tải vị trí tuyển dụng...</div>
      <div v-else-if="posts.length" class="jobs-grid">
        <article v-for="(job, index) in posts" :key="job.slug" class="job-card" :class="{ featured: index === 0 }">
          <div class="job-index">0{{ index + 1 }}</div>
          <div class="job-main">
            <div class="job-topline"><span class="job-pill">{{ job.position_name || 'OPEN ROLE' }}</span><span class="job-arrow">↗</span></div>
            <h3>{{ job.title }}</h3>
            <p>{{ job.summary || 'Cùng chúng tôi tạo ra những sản phẩm có ích cho con người.' }}</p>
            <div class="job-meta"><span>⌖ {{ job.location || 'TP. Hồ Chí Minh' }}</span><span>◷ {{ employmentLabel(job.employment_type) }}</span><span v-if="job.salary_range">₫ {{ job.salary_range }}</span></div>
          </div>
          <div class="job-actions">
            <button class="job-detail" type="button" @click="openDetails(job)">Chi tiết <span>↗</span></button>
            <button class="job-apply" type="button" @click="openApplication(job)">Ứng tuyển <span>→</span></button>
          </div>
        </article>
      </div>
      <div v-else class="jobs-state empty-state">
        <span class="empty-icon">✳</span>
        <strong>Hiện chưa có vị trí được công bố.</strong>
        <span>Hãy quay lại sau hoặc gửi hồ sơ tự do qua email.</span>
        <button type="button" class="button button-dark" @click="loadPosts">Thử tải lại</button>
      </div>
    </section>

    <section id="process" class="process-section">
      <div class="process-intro">
        <p class="eyebrow"><span class="eyebrow-dot"></span> Không có vòng bí mật</p>
        <h2>Một quy trình<br /><span>rõ ràng, tử tế.</span></h2>
        <p>AI giúp chúng tôi đọc nhanh hơn, nhưng quyết định cuối cùng luôn thuộc về con người. Bạn sẽ biết mình đang ở đâu trong từng bước.</p>
      </div>
      <div class="process-list">
        <div class="process-step"><span>01</span><div><h3>Gửi hồ sơ</h3><p>Chia sẻ câu chuyện và điều bạn muốn xây dựng.</p></div><b>↗</b></div>
        <div class="process-step"><span>02</span><div><h3>AI đọc CV</h3><p>Nhận phản hồi về độ phù hợp trong vài phút.</p></div><b>↗</b></div>
        <div class="process-step"><span>03</span><div><h3>Gặp gỡ đội ngũ</h3><p>Hai chiều trò chuyện, không phải bài kiểm tra.</p></div><b>↗</b></div>
        <div class="process-step"><span>04</span><div><h3>Cùng bắt đầu</h3><p>Offer rõ ràng, onboarding có người đồng hành.</p></div><b>↗</b></div>
      </div>
    </section>

    <footer class="careers-footer"><span>DEV TAP <em>/</em> CAREERS</span><span>Made for people who care.</span><a href="mailto:hello@devtapcode.io.vn">hello@devtapcode.io.vn ↗</a></footer>

    <div v-if="showDetails" class="modal-backdrop" @click.self="closeDetails">
      <section class="job-detail-modal" role="dialog" aria-modal="true" aria-labelledby="job-detail-title">
        <button class="modal-close" type="button" aria-label="Đóng" @click="closeDetails">×</button>
        <div v-if="loadingDetail" class="detail-loading"><span class="loader"></span> Đang tải JD...</div>
        <template v-else-if="detailJob">
          <div class="detail-kicker"><span>JOB DESCRIPTION</span><span>{{ detailJob.slug }}</span></div>
          <h2 id="job-detail-title">{{ detailJob.title }}</h2>
          <p class="detail-summary">{{ detailJob.summary }}</p>
          <div class="detail-meta">
            <span>⌖ {{ detailJob.location || 'TP. Hồ Chí Minh' }}</span>
            <span>◷ {{ employmentLabel(detailJob.employment_type) }}</span>
            <span v-if="detailJob.salary_range">₫ {{ detailJob.salary_range }}</span>
            <span v-if="detailJob.deadline">Hạn {{ formatDate(detailJob.deadline) }}</span>
          </div>

          <div class="detail-divider"></div>
          <section class="detail-section">
            <span class="detail-number">01</span>
            <div><h3>Về vị trí</h3><p class="detail-content">{{ detailJob.content || 'Nội dung JD đang được cập nhật.' }}</p></div>
          </section>
          <section v-if="detailList(detailJob.requirements).length" class="detail-section">
            <span class="detail-number">02</span>
            <div><h3>Chúng tôi tìm kiếm</h3><ul><li v-for="item in detailList(detailJob.requirements)" :key="`requirement-${item}`">{{ item }}</li></ul></div>
          </section>
          <section v-if="detailJob.position?.required_skills?.length" class="detail-section">
            <span class="detail-number">03</span>
            <div><h3>Kỹ năng phù hợp</h3><div class="detail-skills"><span v-for="skill in detailJob.position.required_skills" :key="skill">{{ skill }}</span></div></div>
          </section>
          <section v-if="detailList(detailJob.benefits).length" class="detail-section">
            <span class="detail-number">04</span>
            <div><h3>Điều bạn nhận được</h3><ul><li v-for="item in detailList(detailJob.benefits)" :key="`benefit-${item}`">{{ item }}</li></ul></div>
          </section>

          <div class="detail-footer">
            <p>Bạn thấy mình trong vai trò này?</p>
            <button class="button button-primary" type="button" @click="applyFromDetails">Ứng tuyển vị trí này <span>→</span></button>
          </div>
        </template>
      </section>
    </div>

    <div v-if="showApplication" class="modal-backdrop" @click.self="closeApplication">
      <section class="application-modal" role="dialog" aria-modal="true" aria-labelledby="application-title">
        <button class="modal-close" type="button" aria-label="Đóng" @click="closeApplication">×</button>
        <div v-if="!result" class="application-form-wrap">
          <p class="eyebrow"><span class="eyebrow-dot amber"></span> Ứng tuyển nhanh</p>
          <h2 id="application-title">Hãy để chúng tôi<br /><span>biết về bạn.</span></h2>
          <p class="modal-subtitle">{{ selectedJob?.title }} · {{ selectedJob?.location || 'TP. Hồ Chí Minh' }}</p>
          <form class="application-form" @submit.prevent="submitApplication">
            <div class="form-row">
              <label>Họ và tên <input v-model.trim="form.full_name" required type="text" placeholder="Nguyễn Minh Anh" /></label>
              <label>Email <input v-model.trim="form.email" required type="email" placeholder="you@example.com" /></label>
            </div>
            <div class="form-row">
              <label>Số điện thoại <span class="optional">Không bắt buộc</span><input v-model.trim="form.phone" type="tel" placeholder="090 123 4567" /></label>
              <label>Vị trí <input :value="selectedJob?.title" disabled type="text" /></label>
            </div>
            <label>Lời nhắn <span class="optional">Không bắt buộc</span><textarea v-model.trim="form.cover_letter" rows="3" placeholder="Điều gì khiến bạn muốn gia nhập đội ngũ?"></textarea></label>
            <label class="file-drop" :class="{ 'has-file': form.cv }" @dragover.prevent @drop.prevent="onDrop">
              <input ref="fileInput" type="file" accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document" required @change="onFileChange" />
              <span class="file-icon">↑</span><span v-if="!form.cv"><strong>Kéo thả CV vào đây</strong> hoặc <u>chọn tệp</u><small>PDF, DOC, DOCX · tối đa 10 MB</small></span><span v-else><strong>{{ form.cv.name }}</strong><small>{{ formatFileSize(form.cv.size) }} · Sẵn sàng tải lên</small></span>
            </label>
            <p v-if="errorMessage" class="form-error">{{ errorMessage }}</p>
            <button class="button button-primary submit-button" type="submit" :disabled="submitting"><span v-if="submitting" class="loader loader-light"></span>{{ submitting ? 'Đang gửi và chấm CV...' : 'Gửi hồ sơ & nhận điểm AI' }} <span v-if="!submitting">→</span></button>
            <p class="privacy-note">Bằng việc gửi hồ sơ, bạn đồng ý để DevTap lưu trữ và xử lý thông tin phục vụ tuyển dụng.</p>
          </form>
        </div>
        <div v-else class="application-result">
          <div class="result-check">✓</div>
          <p class="eyebrow"><span class="eyebrow-dot"></span> Đã nhận hồ sơ</p>
          <h2>Cảm ơn {{ form.full_name.split(' ').slice(-1)[0] }}<br /><span>vì đã tin tưởng.</span></h2>
          <p class="result-copy">{{ result.ai_scoring_status === 'DONE' ? 'Hệ thống đã đọc CV của bạn. Đây là kết quả sơ bộ để bạn tham khảo.' : 'Hồ sơ đã được lưu an toàn. Hệ thống sẽ hoàn tất chấm điểm khi dịch vụ sẵn sàng.' }}</p>
          <div v-if="result.ai_scoring_status === 'DONE'" class="score-result">
            <span>AI MATCH SCORE</span><strong>{{ Math.round(Number(result.ai_score || 0)) }}<small>%</small></strong>
            <div class="score-track"><span :style="{ width: `${Math.min(100, Number(result.ai_score || 0))}%` }"></span></div>
            <div class="result-skills"><div><small>ĐIỂM MẠNH</small><span v-for="skill in (result.matched_skills || [])" :key="`matched-${skill}`">{{ skill }}</span></div><div><small>CÓ THỂ BỔ SUNG</small><span v-for="skill in (result.missing_skills || [])" :key="`missing-${skill}`">{{ skill }}</span></div></div>
          </div>
          <button class="button button-dark" type="button" @click="closeApplication">Đóng cửa sổ <span>×</span></button>
        </div>
      </section>
    </div>
  </main>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { recruitmentService } from '../services/recruitmentService';

const TENANT_CODE = 'DEFAULT';
const posts = ref([]);
const loadingPosts = ref(true);
const showDetails = ref(false);
const loadingDetail = ref(false);
const detailJob = ref(null);
const showApplication = ref(false);
const selectedJob = ref(null);
const fileInput = ref(null);
const submitting = ref(false);
const errorMessage = ref('');
const result = ref(null);
const form = ref({ full_name: '', email: '', phone: '', cover_letter: '', cv: null });

const employmentLabel = (type) => ({ FULL_TIME: 'Toàn thời gian', PART_TIME: 'Bán thời gian', CONTRACT: 'Hợp đồng', REMOTE: 'Từ xa' }[type] || type || 'Toàn thời gian');
const formatFileSize = (size) => `${(size / 1024 / 1024).toFixed(1)} MB`;
const formatDate = (date) => new Date(date).toLocaleDateString('vi-VN');
const detailList = (value) => {
  if (Array.isArray(value)) return value;
  if (!value) return [];
  try {
    const parsed = JSON.parse(value);
    return Array.isArray(parsed) ? parsed : [];
  } catch {
    return String(value).split('\n').map(item => item.trim()).filter(Boolean);
  }
};

const loadPosts = async () => {
  loadingPosts.value = true;
  try {
    const data = await recruitmentService.getPublicPosts();
    posts.value = Array.isArray(data) ? data : [];
  } catch (error) {
    posts.value = [];
    errorMessage.value = error.response?.data?.message || 'Không thể tải danh sách vị trí lúc này.';
  } finally {
    loadingPosts.value = false;
  }
};

const openApplication = (job) => {
  selectedJob.value = job;
  result.value = null;
  errorMessage.value = '';
  form.value = { full_name: '', email: '', phone: '', cover_letter: '', cv: null };
  showApplication.value = true;
};

const openDetails = async (job) => {
  detailJob.value = job;
  showDetails.value = true;
  loadingDetail.value = true;
  try {
    detailJob.value = await recruitmentService.getPublicPost(job.slug);
  } catch (error) {
    console.error('Error loading job description:', error);
  } finally {
    loadingDetail.value = false;
  }
};

const closeDetails = () => {
  showDetails.value = false;
};

const applyFromDetails = () => {
  const job = detailJob.value;
  closeDetails();
  openApplication(job);
};

const closeApplication = () => {
  if (submitting.value) return;
  showApplication.value = false;
};

const setFile = (file) => {
  if (!file) return;
  const allowed = ['pdf', 'doc', 'docx'];
  const extension = file.name.split('.').pop()?.toLowerCase();
  if (!allowed.includes(extension) || file.size > 10 * 1024 * 1024) {
    errorMessage.value = 'CV phải là PDF, DOC hoặc DOCX và không vượt quá 10 MB.';
    return;
  }
  errorMessage.value = '';
  form.value.cv = file;
};
const onFileChange = (event) => setFile(event.target.files?.[0]);
const onDrop = (event) => setFile(event.dataTransfer.files?.[0]);

const submitApplication = async () => {
  if (!selectedJob.value || !form.value.cv) return;
  submitting.value = true;
  errorMessage.value = '';
  const payload = new FormData();
  payload.append('tenant_code', TENANT_CODE);
  payload.append('post_slug', selectedJob.value.slug);
  payload.append('full_name', form.value.full_name);
  payload.append('email', form.value.email);
  if (form.value.phone) payload.append('phone', form.value.phone);
  if (form.value.cover_letter) payload.append('cover_letter', form.value.cover_letter);
  payload.append('cv', form.value.cv);
  try {
    result.value = await recruitmentService.submitPublicApplication(payload);
  } catch (error) {
    const errors = error.response?.data?.errors;
    errorMessage.value = errors ? Object.values(errors).flat()[0] : (error.response?.data?.message || 'Không thể gửi hồ sơ. Vui lòng thử lại.');
  } finally {
    submitting.value = false;
  }
};

onMounted(loadPosts);
</script>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Space+Grotesk:wght@400;500;600;700&display=swap');

:global(body) { background: #f5f7f8; }
.careers-page { --ink: #0a1c2b; --muted: #667783; --line: #d8e1e4; --cyan: #13b9c8; --amber: #f2a62c; color: var(--ink); background: #f5f7f8; min-height: 100vh; font-family: 'Space Grotesk', sans-serif; overflow: hidden; }
.careers-nav, .hero-shell, .manifesto-strip, .opportunities-section, .process-section, .careers-footer { max-width: 1240px; margin: 0 auto; padding-left: 34px; padding-right: 34px; }
.careers-nav { height: 86px; display:flex; align-items:center; justify-content:space-between; border-bottom:1px solid var(--line); }
.brand { display:flex; align-items:center; gap:11px; color:var(--ink); text-decoration:none; font-size:11px; font-weight:700; letter-spacing:.13em; }
.brand em, .careers-footer em { color:var(--cyan); font-style:normal; margin:0 2px; }
.brand-mark { display:grid; place-items:center; width:31px; height:31px; background:var(--ink); color:#e8fbfc; font:500 10px 'DM Mono', monospace; letter-spacing:-.1em; border-radius:50%; }
.nav-links { display:flex; align-items:center; gap:35px; font-size:12px; }
.nav-links a { color:var(--muted); text-decoration:none; transition:color .2s; }.nav-links a:hover { color:var(--ink); }
.nav-login { color:var(--ink) !important; border:1px solid var(--line); padding:10px 15px; border-radius:999px; }.nav-login span { color:var(--cyan); margin-left:8px; }.nav-mobile-cta { display:none; }
.hero-shell { display:grid; grid-template-columns: 1.04fr .96fr; min-height:620px; align-items:center; gap:40px; }
.eyebrow { margin:0 0 22px; color:var(--muted); font:500 10px 'DM Mono', monospace; letter-spacing:.09em; text-transform:uppercase; }.eyebrow-dot { display:inline-block; width:7px; height:7px; background:var(--cyan); border-radius:50%; margin:0 8px 1px 0; box-shadow:0 0 0 5px rgba(19,185,200,.12); }.eyebrow-dot.amber { background:var(--amber); box-shadow:0 0 0 5px rgba(242,166,44,.13); }
.hero-copy h1 { margin:0; font-size:clamp(50px,7vw,91px); line-height:.94; letter-spacing:-.07em; font-weight:600; }.hero-copy h1 span, .process-intro h2 span, .application-modal h2 span { color:var(--cyan); }
.hero-lede { max-width:440px; color:#60717b; font-size:16px; line-height:1.65; margin:29px 0 31px; }.hero-actions { display:flex; align-items:center; gap:26px; }.button { border:0; border-radius:999px; padding:15px 21px; font:600 12px 'Space Grotesk',sans-serif; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:18px; transition:transform .2s, box-shadow .2s; }.button:hover { transform:translateY(-2px); }.button-primary { color:#fff; background:var(--ink); box-shadow:0 12px 22px rgba(10,28,43,.15); }.button-primary:hover { box-shadow:0 15px 28px rgba(10,28,43,.24); }.button-primary span { color:#7ee6eb; font-size:16px; }.button-dark { background:#edf2f2; color:var(--ink); }.text-link { color:var(--ink); font-size:12px; text-decoration:none; border-bottom:1px solid #b8c6c9; padding-bottom:4px; }.text-link span { color:var(--cyan); margin-left:7px; }.hero-note { color:#849399; font:10px 'DM Mono',monospace; margin-top:43px; }.note-line { display:inline-block; height:1px; width:31px; vertical-align:middle; background:var(--amber); margin-right:9px; }
.hero-art { min-height:440px; position:relative; display:grid; place-items:center; }.hero-art::before { content:''; position:absolute; width:390px; height:390px; border-radius:50%; background:radial-gradient(circle, rgba(19,185,200,.17), rgba(19,185,200,0) 68%); }.orbit { position:absolute; border:1px solid rgba(19,185,200,.28); border-radius:50%; transform:rotate(-24deg); }.orbit-one { width:420px; height:180px; }.orbit-two { width:280px; height:470px; transform:rotate(35deg); border-color:rgba(242,166,44,.3); }.signal-card { width:min(360px, 82%); min-height:310px; padding:26px 27px 22px; color:#e9fcfd; background:linear-gradient(145deg,#112e3d,#071923); box-shadow:0 30px 60px rgba(10,28,43,.2); position:relative; z-index:1; transform:rotate(4deg); animation:floatCard 7s ease-in-out infinite; }.signal-card::after { content:''; position:absolute; inset:9px; border:1px solid rgba(126,230,235,.14); pointer-events:none; }.signal-top, .signal-footer { display:flex; justify-content:space-between; color:#71909a; font:9px 'DM Mono',monospace; letter-spacing:.08em; }.signal-live { color:#7ee6eb; }.signal-live i { display:inline-block; width:5px; height:5px; margin-right:5px; background:#7ee6eb; border-radius:50%; }.signal-title { font-size:27px; line-height:1.05; letter-spacing:-.05em; margin:45px 0 29px; }.signal-title strong { color:#7ee6eb; font-weight:500; }.signal-score-row { display:flex; align-items:end; justify-content:space-between; }.score-label { color:#91a9af; font:9px 'DM Mono',monospace; letter-spacing:.1em; }.signal-score-row strong { color:#f4b643; font-size:43px; line-height:.9; letter-spacing:-.08em; }.signal-score-row strong span { font-size:17px; letter-spacing:0; margin-left:3px; }.score-track { height:4px; margin:15px 0 22px; background:rgba(255,255,255,.13); overflow:hidden; }.score-track span { display:block; height:100%; width:92%; background:linear-gradient(90deg,var(--cyan),#d3f36e); }.signal-tags { display:flex; gap:6px; flex-wrap:wrap; }.signal-tags span { border:1px solid rgba(126,230,235,.28); padding:5px 8px; color:#b2d4d7; font:9px 'DM Mono',monospace; }.signal-footer { border-top:1px solid rgba(255,255,255,.12); margin-top:26px; padding-top:15px; }.signal-footer span:last-child { color:#7ee6eb; font-size:17px; }.art-caption { position:absolute; right:4%; bottom:8%; display:flex; gap:12px; color:#8a9ca0; font:9px 'DM Mono',monospace; line-height:1.55; }.art-caption span:first-child { color:var(--amber); font-size:14px; }
.manifesto-strip { border-top:1px solid var(--line); border-bottom:1px solid var(--line); min-height:122px; display:grid; grid-template-columns:repeat(3, 1fr) 1.3fr; gap:18px; align-items:center; }.manifesto-item { display:flex; gap:14px; align-items:start; font-size:12px; line-height:1.35; }.manifesto-item strong { color:var(--amber); font:11px 'DM Mono',monospace; }.manifesto-strip > p { color:var(--muted); font-size:12px; line-height:1.5; max-width:230px; margin-left:auto; }
.opportunities-section { padding-top:108px; padding-bottom:122px; }.section-heading { display:flex; justify-content:space-between; align-items:end; margin-bottom:42px; }.section-heading h2, .process-intro h2, .application-modal h2 { margin:0; font-size:clamp(39px,5vw,62px); letter-spacing:-.065em; line-height:.98; font-weight:600; }.section-aside { margin:0 0 3px; color:var(--muted); font-size:12px; }.section-aside a { color:var(--ink); text-decoration:none; border-bottom:1px solid #aebcc0; padding-bottom:3px; }.jobs-grid { display:grid; gap:12px; }.job-card { display:grid; grid-template-columns:70px 1fr auto; align-items:center; gap:20px; min-height:205px; padding:23px 28px; background:#fff; border:1px solid var(--line); transition:transform .25s, border-color .25s, box-shadow .25s; }.job-card:hover { transform:translateX(6px); border-color:#9bd9dc; box-shadow:0 18px 38px rgba(10,28,43,.08); }.job-card.featured { background:var(--ink); color:#fff; border-color:var(--ink); }.job-card.featured .job-index, .job-card.featured .job-meta, .job-card.featured .job-main p { color:#91adb5; }.job-card.featured .job-pill { color:#a6eef0; border-color:#2c606c; }.job-card.featured .job-apply { background:#d4f170; color:var(--ink); }.job-index { align-self:start; color:#9cabb0; font:12px 'DM Mono',monospace; padding-top:4px; }.job-topline { display:flex; justify-content:space-between; align-items:center; }.job-pill { display:inline-block; color:#4e777d; border:1px solid #b8d6d8; border-radius:999px; padding:6px 9px; font:9px 'DM Mono',monospace; text-transform:uppercase; letter-spacing:.06em; }.job-arrow { color:var(--amber); font-size:20px; }.job-main h3 { margin:18px 0 7px; font-size:25px; letter-spacing:-.045em; font-weight:600; }.job-main p { max-width:590px; color:#6d7c83; font-size:13px; line-height:1.5; margin:0; }.job-meta { display:flex; flex-wrap:wrap; gap:19px; color:#7e8c91; font:10px 'DM Mono',monospace; margin-top:23px; }.job-apply { align-self:end; border:0; background:#eef3f3; color:var(--ink); border-radius:999px; padding:12px 15px; font:600 11px 'Space Grotesk',sans-serif; cursor:pointer; }.job-apply span { color:var(--cyan); font-size:15px; margin-left:13px; }.jobs-state { min-height:230px; display:grid; place-items:center; color:var(--muted); font-size:13px; }.empty-state { gap:8px; border:1px dashed var(--line); text-align:center; padding:35px; }.empty-state strong { color:var(--ink); }.empty-icon { color:var(--amber); font-size:25px; }.empty-state .button { margin-top:13px; }.loader { width:14px; height:14px; display:inline-block; border:2px solid #c9d5d7; border-top-color:var(--cyan); border-radius:50%; animation:spin .7s linear infinite; }.loader-light { border-color:rgba(255,255,255,.3); border-top-color:#d4f170; }
.process-section { display:grid; grid-template-columns:.85fr 1.15fr; gap:110px; padding-top:102px; padding-bottom:122px; background:#e9f1f0; max-width:none; padding-left:max(34px, calc((100vw - 1172px)/2)); padding-right:max(34px, calc((100vw - 1172px)/2)); position:relative; }.process-section::before { content:'HOW'; position:absolute; right:2%; top:18px; color:rgba(10,28,43,.04); font-size:160px; font-weight:700; letter-spacing:-.12em; pointer-events:none; }.process-intro p:not(.eyebrow) { max-width:310px; color:#63777d; font-size:14px; line-height:1.6; margin-top:28px; }.process-list { position:relative; z-index:1; }.process-step { display:grid; grid-template-columns:45px 1fr 22px; gap:16px; align-items:start; padding:23px 0; border-top:1px solid #cbdcdd; }.process-step:last-child { border-bottom:1px solid #cbdcdd; }.process-step > span { color:var(--amber); font:11px 'DM Mono',monospace; padding-top:5px; }.process-step h3 { margin:0 0 5px; font-size:19px; letter-spacing:-.04em; }.process-step p { margin:0; color:#72858a; font-size:12px; }.process-step b { color:var(--cyan); font-size:19px; font-weight:400; }
.careers-footer { min-height:95px; display:flex; align-items:center; justify-content:space-between; color:#7b8d92; font:10px 'DM Mono',monospace; letter-spacing:.04em; }.careers-footer span:first-child { color:var(--ink); font-weight:500; }.careers-footer a { color:var(--ink); text-decoration:none; }
.modal-backdrop { position:fixed; z-index:20; inset:0; display:grid; place-items:center; padding:25px; background:rgba(5,19,28,.64); backdrop-filter:blur(7px); animation:fadeIn .2s ease; }.application-modal { position:relative; width:min(650px, 100%); max-height:calc(100vh - 50px); overflow-y:auto; background:#f5f7f8; padding:43px 48px 39px; box-shadow:0 28px 90px rgba(0,0,0,.3); animation:riseIn .3s ease; }.modal-close { position:absolute; right:19px; top:14px; border:0; background:none; font-size:28px; font-weight:300; color:#87979a; cursor:pointer; }.application-modal h2 { font-size:43px; }.modal-subtitle { margin:17px 0 29px; color:#5e7479; font:11px 'DM Mono',monospace; }.application-form { display:grid; gap:16px; }.form-row { display:grid; grid-template-columns:1fr 1fr; gap:14px; }.application-form label { display:grid; gap:7px; color:#536a70; font:10px 'DM Mono',monospace; text-transform:uppercase; letter-spacing:.04em; }.optional { color:#99a8aa; text-transform:none; float:right; font-size:9px; margin-left:auto; }.application-form input:not([type=file]), .application-form textarea { width:100%; border:1px solid #d0dcde; background:#fff; border-radius:2px; padding:12px 13px; color:var(--ink); font:13px 'Space Grotesk',sans-serif; outline:none; resize:vertical; text-transform:none; letter-spacing:0; }.application-form input:focus, .application-form textarea:focus { border-color:var(--cyan); box-shadow:0 0 0 3px rgba(19,185,200,.1); }.application-form input:disabled { color:#829296; background:#edf2f2; }.file-drop { min-height:93px; display:flex !important; align-items:center; gap:15px; justify-content:center; border:1px dashed #a9c6c9; background:#eef8f8; cursor:pointer; text-align:center; text-transform:none !important; }.file-drop input[type=file] { position:absolute; width:1px; height:1px; opacity:0; }.file-drop strong { display:block; color:#315c63; font:600 12px 'Space Grotesk',sans-serif; }.file-drop u { color:var(--cyan); text-underline-offset:3px; }.file-drop small { display:block; color:#85999d; margin-top:5px; font:9px 'DM Mono',monospace; }.file-icon { display:grid; place-items:center; width:32px; height:32px; color:#fff; background:var(--cyan); border-radius:50%; font-size:19px; }.file-drop.has-file { border-style:solid; background:#f1f9e9; }.form-error { color:#bd4d42; margin:0; font-size:11px; }.submit-button { justify-content:center; width:100%; margin-top:3px; }.privacy-note { color:#96a4a6; font:9px 'DM Mono',monospace; text-align:center; line-height:1.5; margin:1px 0 0; }.application-result { text-align:center; padding:21px 10px 4px; }.result-check { display:grid; place-items:center; width:50px; height:50px; margin:0 auto 22px; color:var(--ink); background:#d4f170; border-radius:50%; font-size:27px; }.application-result .eyebrow { margin-bottom:18px; }.application-result h2 { font-size:43px; }.result-copy { max-width:410px; color:#687a7e; font-size:13px; line-height:1.6; margin:19px auto 26px; }.score-result { max-width:390px; margin:0 auto 28px; padding:19px 20px 17px; color:#d9f5f6; background:var(--ink); text-align:left; }.score-result > span { font:9px 'DM Mono',monospace; color:#86a6ab; letter-spacing:.09em; }.score-result > strong { display:block; color:#d4f170; font-size:53px; letter-spacing:-.08em; line-height:1.05; margin:8px 0 4px; }.score-result > strong small { font-size:18px; letter-spacing:0; margin-left:2px; }.score-result .score-track { margin:7px 0 19px; }.result-skills { display:grid; grid-template-columns:1fr 1fr; gap:13px; }.result-skills div { display:flex; flex-wrap:wrap; align-content:start; gap:5px; }.result-skills small { width:100%; color:#82a2a7; font:8px 'DM Mono',monospace; margin-bottom:2px; }.result-skills span { padding:4px 6px; border:1px solid rgba(126,230,235,.25); color:#c2dfe0; font:9px 'DM Mono',monospace; }
.job-actions { align-self:end; display:flex; align-items:center; gap:8px; }
.job-detail { border:1px solid #d8e1e4; border-radius:999px; padding:11px 14px; color:var(--ink); background:transparent; font:600 11px 'Space Grotesk',sans-serif; cursor:pointer; white-space:nowrap; }
.job-detail span { color:var(--amber); margin-left:8px; }
.featured .job-detail { color:#c0d6da; border-color:#34515c; }
.job-detail-modal { position:relative; width:min(820px, 100%); max-height:calc(100vh - 50px); overflow-y:auto; padding:47px 55px 42px; background:#f5f7f8; box-shadow:0 28px 90px rgba(0,0,0,.3); animation:riseIn .3s ease; }
.detail-loading { min-height:300px; display:flex; align-items:center; justify-content:center; gap:10px; color:var(--muted); font-size:12px; }
.detail-kicker { display:flex; justify-content:space-between; padding-right:25px; color:#7a9196; font:9px 'DM Mono',monospace; letter-spacing:.08em; }
.detail-kicker span:first-child { color:var(--amber); }
.job-detail-modal h2 { max-width:650px; margin:26px 0 12px; font-size:clamp(36px,6vw,62px); line-height:.96; letter-spacing:-.06em; }
.detail-summary { max-width:600px; color:#5e7479; font-size:15px; line-height:1.65; }
.detail-meta { display:flex; flex-wrap:wrap; gap:10px 22px; margin-top:24px; color:#6f8388; font:10px 'DM Mono',monospace; }
.detail-divider { height:1px; margin:31px 0 7px; background:#d4dfe1; }
.detail-section { display:grid; grid-template-columns:42px 1fr; gap:17px; padding:25px 0; border-bottom:1px solid #d4dfe1; }
.detail-number { color:var(--amber); font:10px 'DM Mono',monospace; padding-top:4px; }
.detail-section h3 { margin:0 0 12px; font-size:20px; letter-spacing:-.04em; }
.detail-content { white-space:pre-line; }
.detail-section p, .detail-section li { color:#60747a; font-size:13px; line-height:1.72; }
.detail-section ul { display:grid; gap:7px; margin:0; padding-left:17px; }
.detail-section li::marker { color:var(--cyan); }
.detail-skills { display:flex; flex-wrap:wrap; gap:7px; }
.detail-skills span { padding:7px 10px; border:1px solid #b7d7d9; color:#315f65; font:10px 'DM Mono',monospace; }
.detail-footer { display:flex; justify-content:space-between; align-items:center; gap:20px; margin-top:29px; }
.detail-footer p { margin:0; color:#60747a; font-size:13px; }
@keyframes floatCard { 0%,100% { transform:rotate(4deg) translateY(0); } 50% { transform:rotate(2deg) translateY(-11px); } } @keyframes spin { to { transform:rotate(360deg); } } @keyframes fadeIn { from { opacity:0; } to { opacity:1; } } @keyframes riseIn { from { opacity:0; transform:translateY(18px); } to { opacity:1; transform:translateY(0); } }
@media (max-width: 820px) { .careers-nav, .hero-shell, .manifesto-strip, .opportunities-section, .process-section, .careers-footer { padding-left:20px; padding-right:20px; }.careers-nav { height:72px; }.nav-links { display:none; }.nav-mobile-cta { display:block; color:var(--ink); font:11px 'DM Mono',monospace; text-decoration:none; }.nav-mobile-cta span { color:var(--cyan); margin-left:7px; }.hero-shell { display:block; min-height:0; padding-top:73px; padding-bottom:74px; }.hero-lede { font-size:15px; }.hero-actions { gap:18px; flex-wrap:wrap; }.hero-art { min-height:390px; margin-top:48px; }.manifesto-strip { grid-template-columns:repeat(3,1fr); gap:13px; padding-top:28px; padding-bottom:28px; }.manifesto-strip > p { grid-column:1/-1; margin:13px 0 0; max-width:none; }.manifesto-item { font-size:11px; }.opportunities-section { padding-top:75px; padding-bottom:78px; }.section-heading { display:block; }.section-aside { margin-top:20px; }.job-card { grid-template-columns:35px 1fr; gap:13px; padding:19px 15px; }.job-apply { grid-column:2; justify-self:start; margin-top:9px; }.job-main h3 { font-size:22px; }.job-meta { gap:8px 13px; }.process-section { display:block; padding-top:76px; padding-bottom:77px; }.process-intro { margin-bottom:46px; }.careers-footer { min-height:130px; flex-wrap:wrap; align-content:center; gap:13px; }.careers-footer span:nth-child(2) { order:3; width:100%; }.application-modal { padding:37px 22px 27px; }.application-modal h2, .application-result h2 { font-size:35px; }.form-row { grid-template-columns:1fr; gap:16px; }.modal-backdrop { padding:12px; }.signal-card { width:310px; }.orbit-one { width:350px; }.orbit-two { height:400px; } }
@media (max-width: 820px) { .job-actions { grid-column:2; justify-self:start; margin-top:9px; flex-wrap:wrap; }.job-apply { margin-top:0; }.job-detail-modal { padding:37px 22px 27px; }.detail-kicker span:last-child { display:none; }.detail-section { grid-template-columns:28px 1fr; gap:10px; }.detail-footer { align-items:flex-start; flex-direction:column; } }
@media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation-duration:.01ms !important; animation-iteration-count:1 !important; scroll-behavior:auto !important; } }
</style>
