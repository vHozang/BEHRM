/**
 * Cầu nối MÁY CHẤM CÔNG (Wise Eye / ZKTeco, TCP cổng 4370) → HRM.
 *
 * Bridge đọc log qua LAN và đẩy punch lên HRM. Dữ liệu tự động được giữ lại
 * theo thời gian chờ do Admin cấu hình (mặc định 15 phút). HR có thể tạo lệnh
 * đồng bộ ngay; bridge chủ động poll lệnh nên laptop không cần mở cổng inbound.
 */
const fs = require('fs');
const path = require('path');

const DEVICE_IP = process.env.DEVICE_IP || '192.168.1.201';
const DEVICE_PORT = Number(process.env.DEVICE_PORT || 4370);
const API_BASE = process.env.API_BASE || 'http://localhost/api/v1';
const DEVICE_TOKEN = process.env.DEVICE_TOKEN || '';
const TOKEN = process.env.INTERNAL_TOKEN || '';
if (!DEVICE_TOKEN && !TOKEN) {
  console.error('Thiếu DEVICE_TOKEN hoặc INTERNAL_TOKEN để gọi HRM API');
  process.exit(1);
}

const authHeader = DEVICE_TOKEN ? { 'x-device-token': DEVICE_TOKEN } : { 'x-internal-token': TOKEN };
const POLL_MS = Math.max(5000, Number(process.env.POLL_MS || 30000));
const CONTROL_POLL_MS = Math.max(2000, Number(process.env.CONTROL_POLL_MS || 5000));
const DEVICE_ID = process.env.DEVICE_ID || 'wiseeye-3';
const STATE_FILE = process.env.STATE_FILE || path.join(__dirname, '.zk-bridge-state.json');
const INITIAL_SYNC_MODE = String(process.env.INITIAL_SYNC_MODE || 'all').toLowerCase();
const MAX_PUNCHES_PER_REQUEST = 200;
const CHUNK_RETRIES = Math.max(1, Number(process.env.CHUNK_RETRIES || 3));

const savedState = loadState();
let lastSent = savedState.lastSent;
let initialized = savedState.initialized;
let uploadDelayMinutes = normalizeUploadDelay(process.env.UPLOAD_DELAY_MINUTES || 15);
let isRunning = false;
let isControlRunning = false;

const PUNCH_STATE_BY_CODE = Object.freeze({
  0: 'CHECK_IN',
  1: 'CHECK_OUT',
  2: 'BREAK_OUT',
  3: 'BREAK_IN',
  4: 'OVERTIME_IN',
  5: 'OVERTIME_OUT',
});

function loadState() {
  try {
    const state = JSON.parse(fs.readFileSync(STATE_FILE, 'utf8'));
    const lastSentValue = typeof state.last_sent === 'string' ? state.last_sent : null;
    return {
      lastSent: lastSentValue,
      initialized: state.initialized === true || lastSentValue !== null,
    };
  } catch (_) {
    return { lastSent: null, initialized: false };
  }
}

function saveState(value) {
  const tempFile = `${STATE_FILE}.tmp`;
  fs.mkdirSync(path.dirname(STATE_FILE), { recursive: true });
  fs.writeFileSync(tempFile, JSON.stringify({ initialized: true, last_sent: value }), 'utf8');
  fs.renameSync(tempFile, STATE_FILE);
}

async function readAndForward({ force = false } = {}) {
  if (isRunning) return { ok: false, busy: true, processed: 0, error: 'Bridge đang đọc máy' };

  isRunning = true;
  let zk = null;
  try {
    installNodeZkLibRecordDecoder();
    const ZKLib = require('node-zklib');
    zk = new ZKLib(DEVICE_IP, DEVICE_PORT, 10000, 4000);
    await zk.createSocket();
    const res = await zk.getAttendances();
    const rows = (res && res.data) || [];

    let punches = rows.map((row) => {
      const recordTime = new Date(row.recordTime);
      return {
        enroll_id: String(row.deviceUserId ?? row.userId ?? row.uid),
        // Máy lưu giờ địa phương; payload giữ nguyên giờ hiển thị trên máy.
        timestamp: formatLocalDateTime(recordTime),
        cursor: recordTime.toISOString(),
        recorded_at_ms: recordTime.getTime(),
        device_id: DEVICE_ID,
        verify_method: mapVerify(row.verifyMode ?? row.type),
        punch_state: mapPunchState(row.attendanceState ?? row.state),
        device_state: normalizeDeviceState(row.attendanceState ?? row.state),
      };
    });

    // Lần cài đầu ở production có thể chỉ lấy mốc mới nhất để không nhập toàn
    // bộ lịch sử. Lệnh thủ công luôn bỏ qua nhánh này để đúng nghĩa đồng bộ ngay.
    if (!force && !initialized && INITIAL_SYNC_MODE === 'latest') {
      lastSent = punches.length > 0
        ? punches.reduce((max, punch) => (punch.cursor > max ? punch.cursor : max), '')
        : null;
      initialized = true;
      saveState(lastSent);
      console.log(new Date().toISOString(), punches.length > 0
        ? `đã tạo mốc ban đầu ${lastSent}; bỏ qua ${punches.length} punch cũ`
        : 'đã khởi tạo máy không có punch cũ; lượt chấm công đầu tiên sẽ được gửi');
      return { ok: true, processed: 0 };
    }

    if (lastSent) {
      punches = punches.filter((punch) => punch.cursor > lastSent);
    }

    // Đồng bộ tự động chỉ gửi punch đã đủ thời gian chờ. Lệnh HR (force=true)
    // gửi toàn bộ punch mới ngay lập tức.
    if (!force) {
      punches = punches.filter((punch) => isEligibleForAutomaticUpload(
        punch.recorded_at_ms,
        uploadDelayMinutes
      ));
    }

    if (punches.length === 0) {
      console.log(new Date().toISOString(), force
        ? 'đồng bộ ngay: không có punch mới'
        : `không có punch mới đã đủ ${uploadDelayMinutes} phút`);
      return { ok: true, processed: 0 };
    }

    let processed = 0;
    for (const chunk of chunkPunches(punches)) {
      const payloadPunches = chunk.map(({ cursor, recorded_at_ms, ...punch }) => punch);
      await sendPunchChunk(payloadPunches);
      processed += payloadPunches.length;
      lastSent = chunk.reduce((max, punch) => (punch.cursor > max ? punch.cursor : max), lastSent || '');
      initialized = true;
      saveState(lastSent);
    }

    return { ok: true, processed };
  } catch (error) {
    const message = errorMessage(error);
    console.error('Lỗi đọc/gửi:', message);
    return { ok: false, processed: 0, error: message };
  } finally {
    try { await zk?.disconnect(); } catch (_) {}
    isRunning = false;
  }
}

async function sendPunchChunk(payloadPunches) {
  let lastError = null;
  for (let attempt = 1; attempt <= CHUNK_RETRIES; attempt += 1) {
    try {
      const response = await fetch(`${API_BASE}/internal/attendance/device-punch`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', ...authHeader },
        body: JSON.stringify({ punches: payloadPunches }),
      });
      const body = await responseJson(response);
      const errors = Array.isArray(body?.data?.errors) ? body.data.errors : [];
      console.log(new Date().toISOString(), `gửi chunk ${payloadPunches.length} punch →`, response.status, JSON.stringify(body.data || body));
      if (!response.ok) throw new Error(body?.message || `HRM API trả HTTP ${response.status}`);
      if (errors.length > 0) throw new Error(`${errors.length} punch chưa được xử lý`);
      return;
    } catch (error) {
      lastError = error;
      if (attempt < CHUNK_RETRIES) await new Promise((resolve) => setTimeout(resolve, attempt * 1000));
    }
  }
  throw lastError;
}

function chunkPunches(items, size = MAX_PUNCHES_PER_REQUEST) {
  const chunks = [];
  for (let offset = 0; offset < items.length; offset += size) chunks.push(items.slice(offset, offset + size));
  return chunks;
}

async function pollControl() {
  // INTERNAL_TOKEN không định danh được một attendance_devices cụ thể nên chỉ
  // bridge dùng DEVICE_TOKEN mới nhận cấu hình/lệnh từ server.
  if (!DEVICE_TOKEN || isControlRunning) return;

  isControlRunning = true;
  try {
    const response = await fetch(`${API_BASE}/internal/attendance/device-control`, {
      headers: authHeader,
    });
    const body = await responseJson(response);
    if (!response.ok) throw new Error(body?.message || `Control API trả HTTP ${response.status}`);

    const data = body?.data || body;
    const nextDelay = normalizeUploadDelay(data?.upload_delay_minutes);
    if (nextDelay !== uploadDelayMinutes) {
      uploadDelayMinutes = nextDelay;
      console.log(new Date().toISOString(), `đã cập nhật thời gian chờ tự động: ${uploadDelayMinutes} phút`);
    }

    const request = data?.sync_request;
    if (!request?.id || !['PENDING', 'RUNNING'].includes(request.status) || isRunning) return;

    await reportSyncStatus(request.id, 'RUNNING');
    const result = await readAndForward({ force: true });
    if (result.busy) return;

    await reportSyncStatus(
      request.id,
      result.ok ? 'SUCCESS' : 'FAILED',
      result.processed,
      result.ok ? null : result.error
    );
  } catch (error) {
    console.error('Lỗi nhận lệnh đồng bộ:', errorMessage(error));
  } finally {
    isControlRunning = false;
  }
}

async function reportSyncStatus(requestId, status, processed = 0, error = null) {
  const response = await fetch(`${API_BASE}/internal/attendance/device-sync-status`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', ...authHeader },
    body: JSON.stringify({
      request_id: requestId,
      status,
      processed,
      error: error ? String(error).slice(0, 1000) : null,
    }),
  });
  const body = await responseJson(response);
  if (!response.ok) throw new Error(body?.message || `Sync status API trả HTTP ${response.status}`);
}

async function responseJson(response) {
  try {
    return await response.json();
  } catch (_) {
    return {};
  }
}

function errorMessage(error) {
  return typeof error?.toast === 'function'
    ? error.toast()
    : (error?.message || error?.err?.message || String(error));
}

function mapVerify(value) {
  // ZKTeco verify mode: 1=fingerprint, 15=face, 2/3=card/password (tuỳ máy).
  const code = Number(value);
  const name = typeof value === 'string' ? value.trim().toLowerCase() : '';
  if (code === 15 || name === 'face') return 'face';
  if (code === 2 || code === 3 || name === 'card') return 'card';
  return 'fingerprint';
}

function mapPunchState(value) {
  if (typeof value === 'string') {
    const normalized = value.trim().toUpperCase().replace(/[\s-]+/g, '_');
    const aliases = {
      CHECKIN: 'CHECK_IN',
      IN: 'CHECK_IN',
      CHECKOUT: 'CHECK_OUT',
      OUT: 'CHECK_OUT',
      BREAKOUT: 'BREAK_OUT',
      BREAKIN: 'BREAK_IN',
      OVERTIMEIN: 'OVERTIME_IN',
      OVERTIMEOUT: 'OVERTIME_OUT',
      OT_IN: 'OVERTIME_IN',
      OT_OUT: 'OVERTIME_OUT',
    };
    if (Object.values(PUNCH_STATE_BY_CODE).includes(normalized)) return normalized;
    if (aliases[normalized]) return aliases[normalized];
  }

  const code = Number(value);
  return Number.isInteger(code) && PUNCH_STATE_BY_CODE[code]
    ? PUNCH_STATE_BY_CODE[code]
    : 'AUTO';
}

function normalizeDeviceState(value) {
  if (value === undefined || value === null || value === '') return null;
  const code = Number(value);
  return Number.isInteger(code) ? code : String(value).slice(0, 50);
}

/**
 * node-zklib 1.3 đọc đúng mã nhân viên và thời gian nhưng bỏ hai byte trạng
 * thái. Giữ nguyên decoder ổn định của thư viện và chỉ bổ sung các byte ZKTeco
 * chuẩn: verify mode ở offset 26, trạng thái vào/ra ở offset 31.
 */
function enrichRecordData40(recordData, record = {}) {
  if (!Buffer.isBuffer(recordData) || recordData.length < 32) return record;
  return {
    ...record,
    verifyMode: recordData.readUIntLE(26, 1),
    attendanceState: recordData.readUIntLE(31, 1),
  };
}

function installNodeZkLibRecordDecoder() {
  const utils = require('node-zklib/utils');
  if (utils.decodeRecordData40?.hrmStateAware === true) return;

  const originalDecoder = utils.decodeRecordData40;
  const stateAwareDecoder = (recordData) => enrichRecordData40(
    recordData,
    originalDecoder(recordData)
  );
  stateAwareDecoder.hrmStateAware = true;
  utils.decodeRecordData40 = stateAwareDecoder;
}

function formatLocalDateTime(value) {
  const pad = (number) => String(number).padStart(2, '0');
  return `${value.getFullYear()}-${pad(value.getMonth() + 1)}-${pad(value.getDate())} `
    + `${pad(value.getHours())}:${pad(value.getMinutes())}:${pad(value.getSeconds())}`;
}

function normalizeUploadDelay(value) {
  const parsed = Number(value);
  if (!Number.isFinite(parsed)) return 15;
  return Math.min(1440, Math.max(1, Math.round(parsed)));
}

function isEligibleForAutomaticUpload(recordedAtMs, delayMinutes, nowMs = Date.now()) {
  return Number.isFinite(recordedAtMs)
    && recordedAtMs <= nowMs - normalizeUploadDelay(delayMinutes) * 60 * 1000;
}

async function start() {
  console.log(
    `ZK bridge: máy ${DEVICE_IP}:${DEVICE_PORT} → ${API_BASE} `
    + `(đọc mỗi ${POLL_MS / 1000}s, chờ tải ${uploadDelayMinutes} phút)`
  );

  await pollControl();
  await readAndForward();
  setInterval(() => { void readAndForward(); }, POLL_MS);
  if (DEVICE_TOKEN) {
    setInterval(() => { void pollControl(); }, CONTROL_POLL_MS);
  }
}

if (require.main === module) {
  void start();
}

module.exports = {
  chunkPunches,
  enrichRecordData40,
  formatLocalDateTime,
  installNodeZkLibRecordDecoder,
  isEligibleForAutomaticUpload,
  mapPunchState,
  mapVerify,
  normalizeUploadDelay,
};
