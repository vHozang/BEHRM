# 📊 Dashboard Chấm Công 30 Ngày - Demo Data Setup

## 🎯 Vấn đề

Dashboard đang hiển thị **"Thống kê chấm công 30 ngày"** nhưng **không có dữ liệu** vì cơ sở dữ liệu chưa có bản ghi chấm công.

### Triệu chứng
- Biểu đồ trống hoặc chỉ hiển thị dữ liệu mock/ảo
- Thông báo "API không khả dụng" xuất hiện trên dashboard
- Không thể xem thống kê chấm công trong 30 ngày

---

## ✅ Giải Pháp - 3 Cách Thực Hiện

### **Cách 1️⃣: Chạy Script Python (KHUYẾN NGHỊ - Dễ Nhất)**

#### 📝 Bước 1: Đảm bảo MySQL đang chạy

Nếu dùng Docker:
```bash
cd doan1
docker-compose up -d
```

Hoặc nếu có MySQL cục bộ, hãy khởi động nó.

#### 📝 Bước 2: Chạy script

**Windows:**
```bash
cd BE
seed_attendance.bat
```

**Linux/Mac:**
```bash
cd BE
python seed_attendance.py
```

Hoặc chạy trực tiếp:
```bash
python scripts/seed_attendance_30days.py
```

✅ Script sẽ tự động cài đặt MySQL connector nếu cần

---

### **Cách 2️⃣: Chạy SQL Script**

#### Sử dụng MySQL Command Line:
```bash
# Nếu có MySQL cục bộ
mysql -h 127.0.0.1 -u root -p HRM_SYSTEM < BE/scripts/20260623_attendance_30day_demo_data.sql
```

#### Sử dụng phpMyAdmin:
1. Truy cập phpMyAdmin (thường ở `http://localhost/phpmyadmin`)
2. Chọn database `HRM_SYSTEM`
3. Chọn tab **"Import"**
4. Chọn file: `BE/scripts/20260623_attendance_30day_demo_data.sql`
5. Nhấn **"Import"**

#### Sử dụng Docker:
```bash
# Tìm container MySQL
docker ps -a | grep -i mysql

# Chạy SQL script trong container
docker exec hrm mysql -u root -proot HRM_SYSTEM < BE/scripts/20260623_attendance_30day_demo_data.sql
```

---

### **Cách 3️⃣: Chạy trực tiếp SQL trong Database Client**

1. Kết nối tới database `HRM_SYSTEM`
2. Mở file: `BE/scripts/20260623_attendance_30day_demo_data.sql`
3. Copy toàn bộ nội dung và chạy (Execute)

---

## 📊 Dữ Liệu Sẽ Được Tạo

### Thông tin chung
- **Khoảng thời gian:** 30 ngày gần nhất
- **Nhân viên:** 60 nhân viên đầu tiên từ database
- **Tổng bản ghi:** Khoảng 1,800 records

### Phân bố thống kê
| Trạng thái | Tỷ lệ | Mô tả |
|-----------|-------|--------|
| ✅ Có mặt | 85% | Đã duyệt, check-in 7:00-8:00 |
| 🕐 Đi muộn | 10% | Đã duyệt, check-in 8:00-9:00 |
| ❌ Vắng | 5% | Chờ duyệt, không có check-in |

---

## 🔍 Kiểm Tra Kết Quả

### Xem dữ liệu đã được thêm:

```sql
SELECT COUNT(*) as total_records 
FROM attendances 
WHERE attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY);
```

**Kết quả mong đợi:** ~1,800 bản ghi

### Xem chi tiết theo ngày:

```sql
SELECT 
    DATE(attendance_date) as date,
    COUNT(*) as total,
    SUM(CASE WHEN check_in_time IS NULL THEN 1 ELSE 0 END) as absent,
    SUM(CASE WHEN late_minutes > 0 THEN 1 ELSE 0 END) as late,
    SUM(CASE WHEN status = 'ĐÃ_DUYỆT' THEN 1 ELSE 0 END) as approved
FROM attendances
WHERE attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
GROUP BY DATE(attendance_date)
ORDER BY attendance_date DESC
LIMIT 10;
```

---

## 🚀 Kiểm Tra Trên Dashboard

Sau khi chạy script xong:

1. **Mở trình duyệt:** `http://localhost:5000` (hoặc URL của bạn)
2. **Đăng nhập** vào hệ thống
3. **Mở trang Dashboard**
4. Cuộn xuống tìm phần **"Thống kê chấm công 30 ngày"**
5. ✅ Biểu đồ thanh hiển thị dữ liệu (3 màu: xanh/vàng/đỏ)

### Các thành phần sẽ cập nhật:
- 📊 Biểu đồ "Thống kê chấm công 30 ngày" (Bar chart)
- 📈 Metrics: "Có mặt hôm nay", "Tỷ lệ hiện diện"
- 📋 Thống kê KPI các phòng ban

---

## 🆘 Khắc Phục Sự Cố

### ❌ Lỗi: "Can't connect to MySQL server on '127.0.0.1:3306'"

**Nguyên nhân:** MySQL không chạy

**Giải pháp:**
```bash
# Nếu dùng Docker (khuyến nghị)
cd doan1
docker-compose up -d

# Kiểm tra container đang chạy
docker ps
```

### ❌ Lỗi: "Database 'HRM_SYSTEM' not found"

**Nguyên nhân:** Database chưa được tạo

**Giải pháp:**
```bash
# Tạo database nếu chưa tồn tại
mysql -u root -p -e "CREATE DATABASE HRM_SYSTEM CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Hoặc chạy migration từ doan1
docker exec hrm bash -c "mysql -u root -proot < /var/www/html/final_of_final.sql"
```

### ❌ Lỗi: "ModuleNotFoundError: No module named 'mysql'"

**Nguyên nhân:** MySQL connector chưa cài đặt

**Giải pháp:**
```bash
pip install mysql-connector-python
```

### ❌ Lỗi: "Access denied for user 'root'"

**Nguyên nhân:** Sai thông tin đăng nhập MySQL

**Giải pháp:**
- Kiểm tra `.env` trong Doan2 hoặc doan1
- Default thường là: `user=root`, `password=root` hoặc `password=` (trống)
- Chỉnh sửa trong `BE/config/database.php` nếu cần

---

## 📁 Tệp Liên Quan

```
HRM-System-2/
├── BE/
│   ├── seed_attendance.py          ← Chạy trực tiếp (Python)
│   ├── seed_attendance.bat         ← Chạy trực tiếp (Windows)
│   ├── config/
│   │   └── database.php            ← Database configuration
│   └── scripts/
│       ├── seed_attendance_30days.py         ← Script chính
│       ├── 20260623_attendance_30day_demo_data.sql  ← SQL script
│       └── SETUP_DEMO_DATA.md      ← Chi tiết cấu hình
│
├── doan1/
│   ├── docker-compose.yml          ← Docker MySQL setup
│   └── Dockerfile                  ← PHP container
│
└── client/
    └── src/
        ├── views/Dashboard.vue     ← Dashboard component
        └── services/attendanceService.js ← API service
```

---

## 💡 Ghi Chú

✅ **Script sẽ ghi đè** dữ liệu của 30 ngày gần nhất (nếu tồn tại)  
✅ **Dữ liệu mock** trong database sẽ thay thế mock data trên frontend  
✅ **Nếu không có nhân viên**, script sẽ không tạo dữ liệu (hãy tạo nhân viên trước)  
✅ **Dữ liệu ngẫu nhiên nhưng hợp lý** - phù hợp cho testing/demo  

---

## 🎓 Hướng Dẫn Nhanh

**Tất cả chỉ trong 2 bước:**

```bash
# Bước 1: Đảm bảo MySQL chạy
docker-compose -f doan1/docker-compose.yml up -d

# Bước 2: Chạy script demo data
python BE/seed_attendance.py
```

**Xong!** ✅ Dashboard sẽ hiển thị dữ liệu trong 30 giây.

---

**Cần trợ giúp thêm?** Kiểm tra file chi tiết: `BE/scripts/SETUP_DEMO_DATA.md`
