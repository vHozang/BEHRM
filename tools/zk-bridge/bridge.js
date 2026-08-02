/**
 * Cầu nối MÁY CHẤM CÔNG (Wise Eye / ZKTeco, TCP cổng 4370) → HRM.
 *
 * Đọc log chấm công từ máy qua LAN rồi đẩy lên API:
 *   POST {API_BASE}/internal/attendance/device-punch  (header x-internal-token)
 *
 * Wise Eye phần lớn tương thích giao thức ZKTeco → dùng thư viện `node-zklib`.
 * Máy & máy chạy script này PHẢI cùng mạng LAN.
 *
 * Cách chạy:
 *   cd tools/zk-bridge && npm install
 *   DEVICE_IP=192.168.1.201 API_BASE=http://localhost/api/v1 \
 *   DEVICE_TOKEN=dev_token_cua_may node bridge.js
 *
 * Lưu ý ánh xạ: "User ID" đăng ký trên máy (enroll_id) phải khớp
 * employees.profile.enroll_id trong HRM (xem README).
 */
const ZKLib = require('node-zklib');
const fs = require('fs');
const path = require('path');

const DEVICE_IP = process.env.DEVICE_IP || '192.168.1.201';
const DEVICE_PORT = Number(process.env.DEVICE_PORT || 4370);
const API_BASE = process.env.API_BASE || 'http://localhost/api/v1';
// Ưu tiên DEVICE_TOKEN (token riêng của máy, đa-tenant — lấy khi đăng ký thiết
// bị trong HRM). INTERNAL_TOKEN chỉ dùng cho 1 tenant/test.
const DEVICE_TOKEN = process.env.DEVICE_TOKEN || '';
const TOKEN = process.env.INTERNAL_TOKEN || '';
if (!DEVICE_TOKEN && !TOKEN) {
  console.error('Thiếu DEVICE_TOKEN hoặc INTERNAL_TOKEN để gọi HRM API');
  process.exit(1);
}
const authHeader = DEVICE_TOKEN ? { 'x-device-token': DEVICE_TOKEN } : { 'x-internal-token': TOKEN };
const POLL_MS = Number(process.env.POLL_MS || 30000); // 30s
const DEVICE_ID = process.env.DEVICE_ID || 'wiseeye-3';
const STATE_FILE = process.env.STATE_FILE || path.join(__dirname, '.zk-bridge-state.json');
const INITIAL_SYNC_MODE = String(process.env.INITIAL_SYNC_MODE || 'all').toLowerCase();

const savedState = loadState();
let lastSent = savedState.lastSent; // mốc thời gian punch cuối đã gửi (tránh gửi lặp)
let initialized = savedState.initialized;
let isRunning = false;

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

async function readAndForward() {
  if (isRunning) return;
  isRunning = true;
  const zk = new ZKLib(DEVICE_IP, DEVICE_PORT, 10000, 4000);
  try {
    await zk.createSocket();
    const res = await zk.getAttendances();
    const rows = (res && res.data) || [];

    // Chuẩn hoá: deviceUserId = enroll_id, recordTime = thời điểm.
    let punches = rows.map((r) => ({
      enroll_id: String(r.deviceUserId ?? r.userId ?? r.uid),
      timestamp: new Date(r.recordTime).toISOString(),
      device_id: DEVICE_ID,
      verify_method: mapVerify(r.verifyMode ?? r.type),
    }));

    // Khi lắp máy vào production lần đầu, có thể chỉ lấy mốc mới nhất để không
    // nhập toàn bộ lịch sử đang lưu trong thiết bị.
    if (!initialized && INITIAL_SYNC_MODE === 'latest') {
      lastSent = punches.length > 0
        ? punches.reduce((max, p) => (p.timestamp > max ? p.timestamp : max), '')
        : null;
      initialized = true;
      saveState(lastSent);
      console.log(new Date().toISOString(), punches.length > 0
        ? `đã tạo mốc ban đầu ${lastSent}; bỏ qua ${punches.length} punch cũ`
        : 'đã khởi tạo máy không có punch cũ; lượt chấm công đầu tiên sẽ được gửi');
      return;
    }

    // Chỉ gửi các punch mới hơn lần trước (theo timestamp).
    if (lastSent) {
      punches = punches.filter((p) => p.timestamp > lastSent);
    }
    if (punches.length === 0) {
      console.log(new Date().toISOString(), 'không có punch mới');
      return;
    }

    const r = await fetch(`${API_BASE}/internal/attendance/device-punch`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', ...authHeader },
      body: JSON.stringify({ punches }),
    });
    const body = await r.json();
    console.log(new Date().toISOString(), `gửi ${punches.length} punch →`, r.status, JSON.stringify(body.data || body));

    if (r.ok) {
      lastSent = punches.reduce((m, p) => (p.timestamp > m ? p.timestamp : m), lastSent || '');
      initialized = true;
      saveState(lastSent);
    }
  } catch (e) {
    const message = typeof e?.toast === 'function'
      ? e.toast()
      : (e?.message || e?.err?.message || String(e));
    console.error('Lỗi đọc/gửi:', message);
  } finally {
    try { await zk.disconnect(); } catch (_) {}
    isRunning = false;
  }
}

function mapVerify(v) {
  // ZKTeco verify mode: 1=fingerprint, 15=face, 2/3=card/password (tuỳ máy).
  if (v === 15 || v === 'face') return 'face';
  if (v === 2 || v === 3 || v === 'card') return 'card';
  return 'fingerprint';
}

console.log(`ZK bridge: máy ${DEVICE_IP}:${DEVICE_PORT} → ${API_BASE} (mỗi ${POLL_MS / 1000}s)`);
readAndForward();
setInterval(readAndForward, POLL_MS);
