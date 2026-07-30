# BÁO CÁO TIẾN ĐỘ DỰ ÁN HRM SYSTEM

**Đề tài:** Hệ thống Quản lý Nhân sự (HRM) — SaaS đa tổ chức, chuẩn nghiệp vụ Việt Nam
**Nhóm:** CODEDENNGU
**Ngày báo cáo:** 17/07/2026
**Kỳ báo cáo:** Từ sau báo cáo engine lương + backlog T1–T8 đến nay
**Trạng thái:** Lõi hệ thống hoàn thiện, đã kiểm chứng end-to-end; còn lại hạ tầng deploy (chờ VPS)

---

## 1. TÓM TẮT CHO NGƯỜI ĐỌC NHANH

Kể từ báo cáo trước (tập trung engine lương đúng luật VN + backlog tính năng), dự án đã tiến thêm **6 mảng lớn**, tất cả đều **kiểm chứng bằng dữ liệu/chạy thật**, không chỉ tuyên bố:

| # | Mảng | Kết quả nổi bật |
|---|---|---|
| A | **Bảo mật & ổn định** | 3 vòng "zero-trust": vá 13 lỗ (6 bảo mật + 4 crash + 3 race đồng thời) |
| B | **Nghiệp vụ lương VN** | Thêm lương tháng 13/thưởng (đúng thuế); xác nhận đã có kê khai BHXH + quyết toán thuế |
| C | **Đồng bộ & toàn vẹn dữ liệu** | 1.222 nhân viên liên kết đầy đủ hồ sơ–HĐ–công–lương–phép, **0 dữ liệu mồ côi** |
| D | **Khả năng mở rộng (scale)** | Test tải 1.200 công nhân; chuyển tính lương sang **chạy nền (queue)** để không timeout |
| E | **Trải nghiệm nhân viên** | Bản mobile self-service; vá 3 lỗi màn nhân viên (chặn xin phép, hiển thị sai) |
| F | **Dữ liệu demo** | Seed đầy đủ các phân hệ còn trống (tài sản, phỏng vấn, chính sách, ticket, đổi ca) |

**Đánh giá tổng thể:** Hệ thống **đủ vững để demo/chào cho doanh nghiệp SME Việt Nam**. Phần còn lại là cấu hình khi lên VPS (HTTPS, CORS, SMTP, worker chạy nền) — không phải lỗi mã nguồn.

---

## 2. CHI TIẾT: ĐÃ LÀM GÌ SO VỚI BÁO CÁO TRƯỚC

### A. Bảo mật & ổn định — 3 vòng kiểm thử "zero-trust"

Đóng vai kẻ tấn công, ép hệ thống lộ lỗi rồi vá tận gốc.

**Vòng 1 — Lỗ hổng bảo mật (6):**
- Chặn **leo thang quyền**: sửa hồ sơ nhân viên không còn tự cấp `is_super_admin`/đổi công ty.
- Ẩn **lộ lương + thông tin cá nhân**: nhân viên thường không xem được lương/CMND đồng nghiệp.
- Chặn **tự duyệt nghỉ phép**: không thể tạo đơn đã "APPROVED" để né duyệt + trừ quota.
- Không trả **băm mật khẩu** ra client; **OTP** không lộ trong response.
- Thêm **giới hạn tần suất đăng nhập** (chống dò mật khẩu).

**Vòng 2 — Điểm sập (4):** fuzz 127 endpoint bằng dữ liệu rác → tìm 4 chỗ trả lỗi 500 (sập). Vá bằng **một bộ xử lý ngoại lệ chung** (quy lỗi database sai kiểu/quá dài về 4xx sạch, không lộ câu SQL).

**Vòng 3 — Đồng thời (3):** bắn nhiều request cùng lúc → phát hiện **trừ trùng quota**: duyệt 1 đơn nghỉ 8 lần song song trừ 6 ngày thay vì 2. Vá bằng **khóa hàng + kiểm tra lại trong transaction** (áp cho cả duyệt nghỉ phép và duyệt nghỉ bù tăng ca).

**Đã kiểm = vững:** chống IDOR (đọc phiếu lương/đơn người khác → 403), phân quyền ghi, cô lập đa-tenant, ranh giới token.

### B. Nghiệp vụ lương Việt Nam

- **Lương tháng 13 / thưởng Tết:** engine đọc khoản thưởng theo kỳ, cộng vào **thu nhập gộp + chịu thuế TNCN** nhưng **KHÔNG vào nền BHXH** (đúng Đ.89 Luật BHXH). Kiểm chứng: thưởng 10tr → thuế TNCN tăng đúng bậc lũy tiến, BHXH giữ nguyên.
- **Xác nhận đã có** (không phải làm mới): báo cáo **kê khai BHXH** và **quyết toán thuế TNCN cuối năm** — 2 nghiệp vụ tuân thủ pháp lý quan trọng.
- Tham số luật đã cập nhật 2026: PIT 5 bậc, giảm trừ 15,5tr/6,2tr, BHXH 8/1,5/1%, lương tối thiểu vùng, mức tham chiếu BHXH.

### C. Đồng bộ & toàn vẹn dữ liệu (mảng lớn nhất kỳ này)

Vấn đề trước: >1.200 nhân viên nhưng màn chỉ hiện 100, trạng thái đồng loạt "thử việc", hồ sơ–HĐ–công–lương chưa liên kết, lãnh đạo cũ sai phòng ban.

Đã xử lý:
- **Chuẩn hóa 1.200 công nhân** (mã CN00001–CN01200), mỗi người gắn đủ: phòng/phân xưởng, chức danh, quản lý, hợp đồng, ca, lịch sử công tác, số dư phép.
- **Sửa suy diễn trạng thái**: chỉ nhân viên có HĐ thử việc (HDTV) mới hiện "Thử việc"; còn lại "Đang làm việc" — hết cảnh "tất cả thử việc".
- **Đưa lãnh đạo về đúng Ban Giám đốc**: Giám đốc (NV0009) quản lý BGD, Phó GĐ (NV0005) báo cáo Giám đốc.
- **Đồng bộ hợp đồng**: mọi nhân viên thật đều có HĐ hiệu lực; 6 HĐ hết hạn được đánh dấu đúng và nối tiếp.
- **Sinh dữ liệu chấm công** còn thiếu (~49.000 bản ghi) + tổng hợp công cho toàn bộ.
- **Chuẩn hóa kỳ lương** theo từng pháp nhân, liên kết phiếu lương với HĐ + chấm công.
- **Nâng giới hạn API** hiển thị từ 100 → đủ toàn bộ; loại tài khoản kỹ thuật khỏi danh sách nghiệp vụ.

### D. Khả năng mở rộng (kịch bản công ty sản xuất)

- **Test tải 1.200 công nhân**: danh sách/tìm kiếm/báo cáo < 700ms; nhưng **tính lương đồng bộ mất ~30s** (sát ngưỡng timeout khi đông hơn).
- **Chuyển tính lương sang chạy nền (queue job)**: bấm "Tính lương" trả về ngay (<1s), một tiến trình nền tính toàn bộ, giao diện hiển thị "đang xử lý" rồi báo xong — **không bao giờ timeout, chạy được mọi quy mô**.
- **Sơ đồ tổ chức lọc bỏ công nhân** (chỉ giữ quản lý + văn phòng): từ 1.221 → 23 node, hết rối/lag.

### E. Trải nghiệm nhân viên

- **Bản mobile self-service** (kiểu Grab): trang chủ, chấm công, đơn từ, lương, hồ sơ — tối ưu cho công nhân dùng điện thoại.
- Vá 3 lỗi màn nhân viên: **không xin được nghỉ phép** (thiếu số dư năm → đã cấp), giờ công hiển thị **"Invalid Date"**, thống kê **"tháng này" nhưng hiện tổng all-time**.

### F. Dữ liệu demo (hoàn thiện Task 1)

Seed đầy đủ các phân hệ trước đây trống: 8 tài sản + 5 bàn giao, 4 lịch phỏng vấn, 4 chính sách, 3 nhóm dịch vụ + 6 ticket hỗ trợ, 4 yêu cầu đổi ca, 3 chi nhánh pháp nhân. Seeder idempotent (chạy lại không nhân đôi).

---

## 3. BẰNG CHỨNG KIỂM CHỨNG (đã đối soát ngày 17/07)

### Toàn vẹn dữ liệu — 1.222 nhân viên thật

| Hạng mục | Kết quả |
|---|---:|
| Nhân viên thật (loại tài khoản kỹ thuật) | 1.222 |
| Có hợp đồng hiệu lực | 1.222 |
| Có dữ liệu chấm công | 1.222 |
| Có tổng hợp công | 1.222 |
| Có phiếu lương | 1.222 |
| Có số dư phép 2026 | 1.222 |
| Hợp đồng mồ côi | 0 |
| Phiếu lương mồ côi | 0 |
| Tổng hợp đồng (1.222 hiệu lực + 6 hết hạn) | 1.228 |
| Phân loại HĐ: chính thức / thử việc | 1.161 / 61 |

### Kiểm thử tự động (backend)

`5 test PASS — 45 assertions`: ScaleWorkerSeederTest, EmployeeStatusTest, StandardizeAttendanceSeederTest, StandardizePayrollSeederTest, PayrollDataCompletenessTest. (DemoCoverageSeederTest pass riêng — 15 assertions.)

### API & async

- `GET /employees` → trả đủ **1.222/1.222**; `GET /contracts` → **1.228**.
- Tính lương nền: POST trả **<1s**, worker xử lý **1.218 NV**, trạng thái → DONE, **0 job lỗi**.

---

## 4. HƯỚNG TEST CASE ĐỂ TRÌNH BÀY VỚI THẦY

Đề xuất demo theo **3 luồng người dùng thật** + **1 điểm nhấn kỹ thuật**, mỗi bước nêu rõ "kỳ vọng thấy gì".

### Kịch bản 1 — Quản trị/HR (tài khoản an.nguyen@company.com)

| Bước | Thao tác | Kỳ vọng |
|---|---|---|
| 1 | Đăng nhập → Dashboard | Thống kê tổng quan đúng số |
| 2 | Nhân viên | Hiện **đủ 1.222 NV** (không còn 100), lọc theo phòng/trạng thái |
| 3 | Chi tiết 1 nhân viên | Đủ hồ sơ + phòng ban + chức danh + HĐ hiệu lực |
| 4 | **Tính lương kỳ 07/2026** | Bấm → **"đang xử lý nền"** → vài chục giây sau báo xong (điểm nhấn scale) |
| 5 | Xem phiếu lương | Breakdown: lương công → BHXH → thuế TNCN → thực nhận |
| 6 | Báo cáo → Kê khai BHXH / Quyết toán thuế | Ra bảng số liệu tuân thủ pháp lý |
| 7 | Sơ đồ tổ chức | Chỉ lãnh đạo + văn phòng, phân cấp gọn (không nhồi công nhân) |

### Kịch bản 2 — Nhân viên văn phòng (huong.pham@company.com)

| Bước | Thao tác | Kỳ vọng |
|---|---|---|
| 1 | Cổng nhân viên | Số dư phép năm đúng, thống kê **tháng này** đúng |
| 2 | Xin nghỉ phép năm | Tạo đơn thành công (PENDING) — không còn bị chặn |
| 3 | (HR) Duyệt đơn | Nhân viên thấy "Đã duyệt", quota trừ đúng |
| 4 | Xem phiếu lương | Hiển thị đúng lương của mình |

### Kịch bản 3 — Công nhân trên mobile (cn00001@factory.vn / congnhan123)

| Bước | Thao tác | Kỳ vọng |
|---|---|---|
| 1 | Mở bản mobile `/m` | Giao diện tối giản kiểu app |
| 2 | Chấm công vào/ra | Ghi nhận giờ, phân loại đúng giờ/muộn |
| 3 | Xem lịch ca + phiếu lương | Đúng ca được phân, đúng lương |

### Điểm nhấn kỹ thuật cần nêu với thầy

1. **Chuẩn nghiệp vụ VN:** lương đúng luật 2026 (PIT/BHXH/giảm trừ), thưởng tháng 13 chịu thuế nhưng ngoài BHXH, kê khai BHXH + quyết toán thuế.
2. **Toàn vẹn dữ liệu:** 1.222 nhân viên liên kết chặt hồ sơ–HĐ–công–lương–phép, 0 mồ côi — chứng minh mô hình quan hệ đúng.
3. **Mở rộng thật:** test 1.200 công nhân, tính lương chạy nền không timeout — sẵn sàng cho công ty sản xuất đông người.
4. **An toàn:** 3 vòng zero-trust, chống leo thang quyền / lộ dữ liệu / trừ trùng quota.

### Bộ test case đối chứng (để thầy kiểm bất kỳ)

- **Toàn vẹn:** chạy đối soát SQL → mọi hạng mục 1.222, mồ côi 0.
- **Tự động:** `php artisan test` các Feature test seeder/payroll (5 pass, 45 assertions).
- **Ca biên:** input rác → 4xx (không sập); duyệt đồng thời → trừ đúng 1 lần; kỳ lương đã khóa → chặn tính lại.

---

## 5. CÒN LẠI & BÀN GIAO

| Việc | Trạng thái | Ghi chú |
|---|---|---|
| Cấu hình deploy VPS (HTTPS, CORS, biến môi trường) | ⏳ Chờ | Chưa có VPS |
| Worker chạy nền thường trực (supervisor / container riêng) | ⏳ Chờ deploy | Dev đang chạy tay `queue:work` |
| Nối SMTP thật (email phiếu lương/OTP) | ⏳ Chờ deploy | Code đã sẵn, dev ghi log |
| Tích hợp thị trường (kê khai điện tử, chấm công khuôn mặt, chi lương bank) | 🔮 Phase 2 | Khi có khách enterprise |

**Sao lưu:** có bản dump CSDL trước khi đồng bộ (`backups/hrm_before_employee_sync_20260717.dump`).
**Trạng thái mã nguồn:** thay đổi nằm trong working tree, chưa commit — cần commit để bền qua reseed/checkout.

---

*Báo cáo lập trên cơ sở đối soát trực tiếp CSDL + chạy lại kiểm thử ngày 17/07/2026, không dựa trên ghi chú cũ.*
