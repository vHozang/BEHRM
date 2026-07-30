# HRM System — Báo cáo checkpoint dữ liệu và Task 1

**Ngày thực hiện:** 17/07/2026
**Phạm vi:** Hoàn thiện dữ liệu demo Task 1 và đồng bộ xuyên suốt dữ liệu nhân viên, phòng ban, hợp đồng, chấm công, lương.
**Trạng thái:** Đã hoàn thành và áp dụng trên CSDL local. Task VPS tiếp tục để chờ do chưa có VPS.

## 1. Vấn đề ban đầu

- Hệ thống có hơn 1.200 nhân viên nhưng màn Nhân viên chỉ hiển thị 100 người.
- Trạng thái nhân viên hiển thị đồng loạt là thử việc.
- Nhân viên, phòng ban, hợp đồng, chấm công và bảng lương chưa liên kết nhất quán.
- Nhân sự cũ và cấp lãnh đạo bị khuất hoặc sai phòng ban.
- Số hợp đồng trên giao diện thấp hơn số nhân viên.
- Dữ liệu demo của một số phân hệ trong Task 1 còn thiếu hoặc seeder chưa chạy ổn định.

## 2. Công việc đã thực hiện

### 2.1. Hoàn thiện dữ liệu demo Task 1

- Sửa và đăng ký `DemoCoverageSeeder` vào `DatabaseSeeder`.
- Sửa mã nhân viên demo bị trùng quy ước.
- Sửa truy vấn vị trí tuyển dụng và JSON để chạy được trên PostgreSQL/SQLite.
- Bảo đảm seeder chạy lặp lại không sinh dữ liệu trùng.
- Dữ liệu demo sau khi seed:
  - 8 tài sản.
  - 5 bàn giao tài sản.
  - 4 lịch phỏng vấn.
  - 4 chính sách.
  - 3 nhóm dịch vụ.
  - 6 phiếu dịch vụ.
  - 4 yêu cầu đổi ca.

### 2.2. Đồng bộ quy mô nhân viên

- Chuẩn hóa 1.200 mã công nhân theo dạng `CN00001` đến `CN01200`.
- Seeder hiện sửa dữ liệu đã có thay vì bỏ qua khi đủ số lượng.
- Mỗi công nhân được liên kết với:
  - Phòng ban/phân xưởng.
  - Chức danh công nhân.
  - Quản lý trực tiếp.
  - Hợp đồng.
  - Ca làm việc.
  - Lịch sử công tác hiện hành.
  - Quan hệ phòng ban.
  - Số dư phép năm.
- Phân bổ loại hợp đồng công nhân hiện hành:
  - 1.139 hợp đồng lao động chính thức `HDLD01`.
  - 61 hợp đồng thử việc `HDTV`.

### 2.3. Đồng bộ phòng ban và cấp lãnh đạo

- Chuyển `NV0009 — Giám đốc` về `BGD — Ban Giám đốc`.
- Chuyển `NV0005 — Phó giám đốc` về `BGD — Ban Giám đốc`.
- Thiết lập `NV0009` là quản lý của Ban Giám đốc.
- Thiết lập `NV0005` báo cáo cho `NV0009`.
- Xóa quan hệ phòng ban cũ không còn đúng của hai lãnh đạo.
- Đồng bộ lại phòng ban trên hồ sơ nhân viên, quan hệ phòng ban, lịch sử công tác và hợp đồng hiện hành.
- Các trưởng/phó phòng còn lại giữ đúng phòng chức năng:

| Nhân viên | Chức danh | Phòng ban |
|---|---|---|
| NV0001 | Trưởng phòng | HCNS |
| NV0002 | Phó phòng | KT |
| NV0003 | Trưởng phòng | KD |
| NV0004 | Phó phòng | IT |
| NV0005 | Phó giám đốc | BGD |
| NV0007 | Trưởng phòng | CSKH |
| NV0008 | Phó phòng | KHO |
| NV0009 | Giám đốc | BGD |

### 2.4. Đồng bộ hợp đồng

- Tạo hợp đồng hiện hành còn thiếu cho toàn bộ nhân viên thật.
- Đánh dấu 6 hợp đồng cũ đã hết hạn là `HẾT_HIỆU_LỰC` và tạo hợp đồng nối tiếp khi cần.
- Khi phòng ban, chức danh hoặc pháp nhân của nhân viên thay đổi, hợp đồng hiện hành được cập nhật theo hồ sơ gốc.
- Không tạo hợp đồng cho tài khoản kỹ thuật `System Administrator`.
- Kết quả:
  - 1.222 nhân viên thật.
  - 1.222 nhân viên có hợp đồng hiện hành.
  - 1.228 bản ghi hợp đồng tổng cộng.
  - Chênh lệch 6 bản ghi là hợp đồng lịch sử đã hết hiệu lực, không phải dữ liệu trùng.

### 2.5. Đồng bộ chấm công

- Sinh dữ liệu chấm công từ 01/06/2026 đến 17/07/2026 cho toàn bộ nhân viên thật còn thiếu dữ liệu.
- Bổ sung 49.070 bản ghi chấm công.
- Loại tài khoản kỹ thuật khỏi chấm công và tổng hợp công.
- Không ghi đè ngày công đã tồn tại.
- Kết quả đối soát: 1.222/1.222 nhân viên có dữ liệu chấm công gần nhất và tổng hợp công.

### 2.6. Đồng bộ lương

- Chuẩn hóa kỳ lương theo từng pháp nhân thay vì chỉ pháp nhân đầu tiên.
- Tạo/tính lại kỳ lương tháng 06 và 07/2026 cho các pháp nhân có nhân viên.
- Loại nhân viên vào làm sau ngày kết thúc kỳ lương.
- Liên kết chi tiết lương với hợp đồng hiện hành và dữ liệu chấm công.
- Không dùng cách gán lương ròng cố định bằng tỷ lệ phần trăm; giữ kết quả từ payroll engine.
- Kết quả đối soát: 1.222/1.222 nhân viên có dữ liệu lương mở và tổng hợp công tương ứng.

### 2.7. Sửa giới hạn hiển thị trên giao diện/API

- Nâng giới hạn API Nhân viên và Hợp đồng từ 100 lên 2.000 bản ghi để phù hợp màn hình đang lọc phía client.
- Dịch vụ frontend tự yêu cầu `per_page=2000`.
- Loại tài khoản kỹ thuật khỏi danh sách nhân viên nghiệp vụ.
- Reload PHP/OPcache sau khi cập nhật mã nguồn.
- Kết quả API thực tế:
  - Nhân viên: trả đủ 1.222/1.222 bản ghi.
  - Hợp đồng: trả đủ 1.228/1.228 bản ghi.
  - Hợp đồng hiện hành: đủ cho 1.222/1.222 nhân viên thật.

## 3. Kiểm thử và xác minh

### Kiểm thử backend

- `ScaleWorkerSeederTest`: kiểm tra sửa dữ liệu cũ, đồng bộ lãnh đạo, hợp đồng và tính idempotent.
- `EmployeeStatusTest`: kiểm tra trạng thái nhân viên không bị đổi sai do thiếu/hết hạn hợp đồng.
- `StandardizeAttendanceSeederTest`: kiểm tra chỉ bổ sung ngày công thiếu và loại tài khoản kỹ thuật.
- `StandardizePayrollSeederTest`: kiểm tra nhiều pháp nhân và chạy lặp không sinh trùng.
- `PayrollDataCompletenessTest`: kiểm tra liên kết kỳ lương, hợp đồng, công và phiếu lương.

**Kết quả:** 5 test pass, 45 assertions.

`DemoCoverageSeederTest` cũng đã pass riêng với 15 assertions.

### Kiểm thử frontend

- Production build bằng Vite thành công.
- 687 modules được biên dịch.
- Chỉ còn cảnh báo `eval` từ dependency `bluebird`; không làm build thất bại.

### Đối soát toàn vẹn dữ liệu

| Hạng mục | Kết quả |
|---|---:|
| Nhân viên thật | 1.222 |
| Có hợp đồng hiện hành | 1.222 |
| Có dữ liệu chấm công gần nhất | 1.222 |
| Có tổng hợp công | 1.222 |
| Có dữ liệu lương mở | 1.222 |
| Có quan hệ phòng ban | 1.222 |
| Có lịch sử công tác hiện hành | 1.222 |
| Có phân ca | 1.222 |
| Có số dư phép | 1.222 |
| Hợp đồng mồ côi | 0 |
| Chấm công mồ côi | 0 |
| Chi tiết lương mồ côi | 0 |
| Quan hệ quản lý mồ côi | 0 |

## 4. Tệp chính đã thay đổi

- `Doan2_v2/Doan2/database/seeders/DemoCoverageSeeder.php`
- `Doan2_v2/Doan2/database/seeders/ScaleWorkerSeeder.php`
- `Doan2_v2/Doan2/database/seeders/StandardizeAttendanceSeeder.php`
- `Doan2_v2/Doan2/database/seeders/StandardizePayrollSeeder.php`
- `Doan2_v2/Doan2/database/seeders/DatabaseSeeder.php`
- `Doan2_v2/Doan2/app/Http/Controllers/Api/EmployeeController.php`
- `Doan2_v2/Doan2/app/Http/Controllers/Api/ContractController.php`
- `Doan2_v2/Doan2/app/Support/EmployeeStatus.php`
- `Doan2_v2/Doan2/app/Services/AttendanceSummaryService.php`
- `Doan2_v2/Doan2/app/Services/PayrollRunService.php`
- `client/src/services/employeeService.js`
- `client/src/services/contractService.js`
- Các test feature tương ứng trong `Doan2_v2/Doan2/tests/Feature/`.

## 5. Sao lưu và trạng thái bàn giao

- Bản sao CSDL trước khi đồng bộ: `backups/hrm_before_employee_sync_20260717.dump`.
- Các thay đổi hiện nằm trong working tree; chưa stage và chưa commit.
- Task VPS chưa thực hiện theo yêu cầu vì chưa mua VPS.
- Đây là checkpoint hiện tại để kiểm tra trước khi tiếp tục task kế tiếp trong kế hoạch.
