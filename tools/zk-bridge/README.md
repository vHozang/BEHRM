# Cầu nối máy chấm công (Wise Eye / ZKTeco) → HRM

Đọc log chấm công từ máy **Wise Eye 3** (tương thích ZKTeco, TCP cổng **4370**) qua mạng LAN
và đẩy lên HRM: `POST /api/v1/internal/attendance/device-punch`.

## Chuẩn bị

1. **Kết nối máy vào LAN** (cáp RJ45). Trên máy, vào *Menu → Comm/Network* xem **IP** (vd `192.168.1.201`)
   và đảm bảo máy tính chạy bridge **cùng dải mạng**. Kiểm tra: `ping 192.168.1.201`.
2. **Ánh xạ User ID ↔ nhân viên**: "User ID" (số đăng ký vân tay/khuôn mặt/thẻ trên máy) phải khớp
   `employees.profile.enroll_id` trong HRM. Mặc định dự án đã set `enroll_id = id nhân viên` để test.
   Khi đăng ký vân tay cho nhân viên trên máy, đặt User ID = đúng id (hoặc cập nhật `enroll_id` cho khớp).
3. Khuyến nghị đăng ký máy tại màn hình **Máy chấm công** và lấy `device_token`
   riêng của máy. Có thể dùng `INTERNAL_SERVICE_TOKEN` trong `.env` để test local,
   nhưng không nên dùng chung token nội bộ này cho nhiều máy ở production.

## Chạy

```bash
cd tools/zk-bridge
npm install
DEVICE_IP=192.168.1.201 \
API_BASE=http://localhost/api/v1 \
DEVICE_TOKEN=dev_token_cua_may \
node bridge.js
```

Trên Windows PowerShell:

```powershell
cd D:\HRM\BEHRM\tools\zk-bridge
npm install
$env:DEVICE_IP = "192.168.1.201"
$env:API_BASE = "http://localhost/api/v1"
$env:DEVICE_TOKEN = "dev_token_cua_may"
npm start
```

Bridge sẽ poll máy mỗi 30s (đổi qua `POLL_MS`), gửi các punch mới lên API.
Backend tự quyết định **check-in / check-out** theo thời gian, phân loại trễ/sớm theo ca,
ghi vào `attendances` (meta.source = `device`).

## Nếu máy KHÔNG nói chuyện được qua 4370 (một số đời Wise Eye khoá SDK)

Dùng phần mềm **Wise Eye On 39** đi kèm máy để **xuất Excel/CSV** log chấm công, rồi
gửi cùng định dạng punch lên API (mỗi dòng = `{enroll_id, timestamp, verify_method}`):

```bash
curl -X POST http://localhost/api/v1/internal/attendance/device-punch \
  -H "x-device-token: dev_token_cua_may" \
  -H "Content-Type: application/json" \
  -d '{"punches":[{"enroll_id":"2","timestamp":"2026-06-27 08:03:00","verify_method":"fingerprint"}]}'
```

## Ghi chú

- `verify_method`: `fingerprint` (vân tay) | `face` (khuôn mặt) | `card` (thẻ) — chỉ để hiển thị/đối soát.
- Gửi lại punch cũ là an toàn: backend cập nhật giờ ra muộn nhất, không tạo trùng.
- Máy đặt ở Docker/host khác mạng với máy chấm công thì chạy bridge ngay tại máy trong LAN
  rồi trỏ `API_BASE` về địa chỉ backend.
