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
 *   INTERNAL_TOKEN=replace_with_internal_service_token node bridge.js
 *
 * Lưu ý ánh xạ: "User ID" đăng ký trên máy (enroll_id) phải khớp
 * employees.profile.enroll_id trong HRM (xem README).
 */
const ZKLib = require('node-zklib');

const DEVICE_IP = process.env.DEVICE_IP || '192.168.1.201';
const DEVICE_PORT = Number(process.env.DEVICE_PORT || 4370);
const API_BASE = process.env.API_BASE || 'http://localhost/api/v1';
// Ưu tiên DEVICE_TOKEN (token riêng của máy, đa-tenant — lấy khi đăng ký thiết
// bị trong HRM). INTERNAL_TOKEN chỉ dùng cho 1 tenant/test.
const DEVICE_TOKEN = process.env.DEVICE_TOKEN || '';
const TOKEN = process.env.INTERNAL_TOKEN || 'replace_with_internal_service_token';
const authHeader = DEVICE_TOKEN ? { 'x-device-token': DEVICE_TOKEN } : { 'x-internal-token': TOKEN };
const POLL_MS = Number(process.env.POLL_MS || 30000); // 30s
const DEVICE_ID = process.env.DEVICE_ID || 'wiseeye-3';

let lastSent = null; // mốc thời gian punch cuối đã gửi (tránh gửi lặp)

async function readAndForward() {
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
    }
  } catch (e) {
    console.error('Lỗi đọc/gửi:', e.message);
  } finally {
    try { await zk.disconnect(); } catch (_) {}
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
