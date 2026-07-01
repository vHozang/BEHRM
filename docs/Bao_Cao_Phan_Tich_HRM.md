# BÁO CÁO PHÂN TÍCH ĐỒ ÁN TỐT NGHIỆP: HỆ THỐNG QUẢN LÝ NHÂN SỰ (HRM SYSTEM)

---

## I. MỤC ĐÍCH CỦA ĐỒ ÁN

Đồ án hướng tới xây dựng một **Hệ thống Quản lý Nhân sự Toàn diện (HRM System - All-in-One)** nhằm:
1. **Số hóa quy trình vận hành:** Chuyển đổi toàn bộ các hoạt động thủ công của phòng nhân sự thành các quy trình điện tử tự động và nhất quán trên nền tảng Web & Mobile.
2. **Quản trị hệ thống linh hoạt & mạnh mẽ:** Cung cấp Backend mạnh mẽ đáp ứng khối lượng dữ liệu lớn nhờ kiến trúc phân tầng chuyên biệt, dễ dàng mở rộng và bảo trì.
3. **Môi trường làm việc không giấy tờ (Paperless):** Tận dụng công nghệ chữ ký số (Digital Signature) để ký kết các tài liệu chính sách, nội quy và hợp đồng lao động trực tuyến.
4. **Tối ưu hóa trải nghiệm di động (Grab/Super-App Style):** Mang đến trải nghiệm tiện lợi, trực quan nhất cho nhân viên khi tương tác các nghiệp vụ hàng ngày (chấm công, xem ca, nộp đơn phép) trên điện thoại di động.

---

## II. CÁC VẤN ĐỀ HỆ THỐNG GIẢI QUYẾT (PROBLEM STATEMENT)

Hệ thống được thiết kế để giải quyết triệt để 5 nhóm vấn đề chính của doanh nghiệp:
1. **Sự rời rạc và thủ công trong quản lý:** Loại bỏ tình trạng quản lý hồ sơ nhân viên, ngày phép, thâm niên và tính lương bằng bảng tính Excel rời rạc, dễ mất mát dữ liệu và khó kiểm toán.
2. **Quy trình tuyển dụng cồng kềnh:** Giải quyết triệt để từ khâu tiếp nhận hồ sơ trên nền tảng số, theo dõi qua từng vòng phỏng vấn, duyệt Offer và chuyển đổi thành nhân viên chính thức trong một luồng duy nhất.
3. **Chấm công không minh bạch:** Ngăn chặn gian lận giờ công bằng cách tích hợp định vị GPS và thông tin thiết bị (Device Info) khi chấm công di động, đảm bảo nhân viên chấm công đúng địa điểm và đúng giờ.
4. **Quy trình phê duyệt phức tạp:** Rút ngắn thời gian xử lý đơn nghỉ phép, công tác bằng việc tạo luồng phê duyệt tự động đa cấp gửi trực tiếp đến cấp quản lý tương ứng dựa trên cấu trúc phòng ban (Org Hierarchy).
5. **Chi phí in ấn và đi lại để ký kết chứng từ:** Giải quyết sự bất tiện đối với nhân viên làm việc Hybrid/Remote hoặc các chi nhánh xa thông qua cơ chế ký số cam kết nội quy và hợp đồng lao động tức thì.

---

## III. QUY TRÌNH XỬ LÝ TÀI LIỆU VÀ CHỮ KÝ SỐ

Hệ thống triển khai quy trình tài liệu và xác thực không giấy tờ qua các bước sau:

### 1. Quản lý và xử lý tài liệu (Document Processing)
* **Sơ đồ lưu trữ:** Hồ sơ ứng viên (CV) tải lên từ Landing Page hoặc trang quản trị được lưu trữ vật lý an toàn dưới định dạng PDF/Word thông qua Storage Facade.
* **Metadata & Logs:** Mỗi tài liệu đều được theo dõi bằng metadata bao gồm: dung lượng file, tên file gốc đã chuẩn hóa chống lỗi ký tự đặc biệt, người tải lên, thời gian cập nhật và nhật ký lịch sử thay đổi thông tin hợp đồng (`contract_change_logs`).

### 2. Xử lý Chữ ký số (Digital Signature Workflow)
Hệ thống sử dụng component chuyên biệt `SignaturePad.vue` để hiện thực hóa quy trình ký điện tử:
* **Khung vẽ chữ ký (Canvas):** Người dùng sử dụng chuột (trên PC) hoặc ngón tay/stylus (trên di động) vẽ trực tiếp trên khung vẽ cảm ứng nhạy bén.
* **Mã hóa truyền tải:** Chữ ký sau khi hoàn tất được mã hóa về chuỗi **Base64** định dạng ảnh PNG.
* **Xác thực và lưu trữ:** Chuỗi Base64 này được gửi lên Backend API và lưu trữ trực tiếp vào cơ sở dữ liệu làm bằng chứng số, đồng thời liên kết trực tiếp với biểu mẫu ghi nhận chính sách hoặc phụ lục hợp đồng lao động.

---

## IV. CÁC TÍNH NĂNG & KIẾN TRÚC KỸ THUẬT NỔI BẬT

1. **Kiến trúc Lai (Hybrid Architecture) trên nền tảng Laravel 11/12:**
   * Sử dụng Pattern `GenericResourceController` xử lý toàn bộ các bảng danh mục (Master Data) để tiết kiệm thời gian phát triển và code boilerplate.
   * Áp dụng các Controller chuyên biệt (`EmployeeController`, `AttendanceController`, `PayrollController`...) để bắt chặt logic nghiệp vụ (Business logic) như kiểm tra phân quyền, xác thực số dư ngày phép, chốt kỳ lương tự động.
2. **Triển khai Containerization (Docker):**
   * Đóng gói toàn bộ ứng dụng Backend (PHP-FPM, Nginx, PostgreSQL, Redis) trong các Docker container độc lập (`docker-compose`), đảm bảo tính nhất quán giữa môi trường phát triển và môi trường triển khai thực tế.
3. **Sơ đồ tiến trình phê duyệt trực quan (Approval Timeline Flow):**
   * Trực quan hóa tiến trình xử lý đơn từ (Nghỉ phép, dịch vụ hỗ trợ) theo dạng các node trạng thái (Gửi đơn -> Quản lý duyệt -> HR phê duyệt cuối).
4. **Bảng xếp ca tuần linh hoạt (Weekly Shift Roster Grid):**
   * Quản lý phân ca làm việc tuần cho nhân viên theo các mã ca màu sắc trực quan. Hỗ trợ quy trình xin đổi ca giữa các nhân viên (Shift Swaps) trực tuyến và được duyệt tự động.

---

## V. TÍNH ỨNG DỤNG THỰC TIỄN

* **Doanh nghiệp linh hoạt quy mô (SMEs đến Mid-sized):** Kiến trúc Laravel hiện đại kết hợp với cơ sở dữ liệu PostgreSQL cho phép hệ thống vận hành cực kỳ ổn định, mở rộng dữ liệu lớn (Scaling) tốt mà không bị giới hạn.
* **Doanh nghiệp làm việc Hybrid/Remote:** Tối ưu hóa giao tiếp nội bộ, chấm công GPS trên điện thoại di động giúp quản lý chính xác nhân viên làm việc bên ngoài văn phòng.
* **Môi trường kinh doanh theo ca kíp (Bán lẻ, F&B, Sản xuất):** Module xếp ca kíp và tính toán lương động theo hệ số ca làm việc giải quyết bài toán phức tạp trong vận hành hàng ngày của nhóm ngành này.

---

## VI. KHẢO SÁT THỊ TRƯỜNG & ĐỐI THỦ CẠNH TRANH

### 1. Đối thủ nước ngoài (Global Benchmarks)
* **Gusto:** Tiêu chuẩn vàng về trải nghiệm giao diện thân thiện, dễ tiếp cận. Tuy nhiên chi phí cực kỳ đắt đỏ và chỉ tối ưu cho luật lao động Mỹ.
* **BambooHR:** Quản trị dữ liệu hồ sơ nhân sự rất mạnh nhưng giao diện thiên về PC truyền thống, khó tương tác nhanh trên các tác vụ di động hàng ngày của nhân viên.
* **Deel / Remote:** Đi đầu trong Onboarding từng bước và ký số từ xa nhưng chỉ tập trung vào nhân sự remote toàn cầu, thiếu các nghiệp vụ xếp ca kíp, tính lương chi tiết theo quy định Việt Nam.

### 2. Đối thủ trong nước (Vietnam Competitors)
* **Base.vn (Base HRM):** Quy trình và tính năng phê duyệt cực mạnh. Điểm yếu là hệ sinh thái phân mảnh ra quá nhiều ứng dụng nhỏ riêng biệt, chi phí tích hợp cao đối với SMEs.
* **1Office / MISA AMIS HRM:** Quản trị doanh nghiệp chặt chẽ, liên kết kế toán tốt. Tuy nhiên giao diện còn mang tính bảng biểu truyền thống, gây ngợp cho người dùng phổ thông.

### 3. Điểm độc đáo của đồ án (Our Unique Value Proposition)
* **Kiến trúc Module linh hoạt, triển khai nhanh qua Docker:** Cực kỳ dễ để đội ngũ kỹ thuật cài đặt (On-premise hoặc Cloud) mà không gặp rắc rối cấu hình môi trường.
* **Trải nghiệm All-in-One liền mạch:** Tích hợp quy trình khép kín từ lúc nộp CV trực tuyến, tự động hẹn lịch phỏng vấn và gửi email mời nhận việc tự động cho ứng viên.
* **Giao diện di động Super-App:** Thiết kế di động lấy cảm hứng từ các siêu ứng dụng (Grab, Gojek) giúp nhân viên thao tác nhanh (chấm công định vị, xem ca, ký số bằng tay) trong chưa đầy 30 giây.

---

## VII. HƯỚNG DẪN KHỞI CHẠY HỆ THỐNG

### 1. Khởi chạy Backend (Laravel + Docker)
Di chuyển vào thư mục **`Doan2`** và sử dụng Docker Compose:
```powershell
cd Doan2
cp .env.example .env
docker compose up -d
```
*Hệ thống Backend (API) sẽ tự động hoạt động ở cổng HTTP 80 (hoặc 8000 tùy cấu hình).* 
Chạy thêm các lệnh cài đặt và migrate nếu cần:
```powershell
docker compose exec php composer install
docker compose exec php php artisan key:generate
docker compose exec php php artisan migrate --seed
```

### 2. Khởi chạy Frontend (Vite + Vue 3)
Chạy lệnh khởi tạo dev server tại thư mục gốc của dự án:
```powershell
npm run dev
```
*Truy cập:* Trình duyệt web sẽ mở tại `http://localhost:5173`.
