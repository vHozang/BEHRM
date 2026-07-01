# CodeDenNgu — HRM System

> **Laravel 13 · PostgreSQL 15 · Redis · Nginx · Docker**
> Hệ thống Quản lý Nhân sự (HRM) cho 5.000 nhân viên — kiến trúc async queue, phân mảnh bảng theo tháng, CTE đệ quy cho sơ đồ tổ chức.

---

## Mục lục

1. [Tech Stack](#1-tech-stack)
2. [Yêu cầu hệ thống](#2-yêu-cầu-hệ-thống)
3. [Cài đặt lần đầu](#3-cài-đặt-lần-đầu)
   - [macOS / Linux](#31-macos--linux)
   - [Windows (PowerShell)](#32-windows-powershell)
   - [Windows WSL 2 (Khuyến nghị)](#33-windows-wsl-2-khuyến-nghị)
4. [Dịch vụ & Cổng mạng](#4-dịch-vụ--cổng-mạng)
5. [Truy cập & Đăng nhập](#5-truy-cập--đăng-nhập)
6. [Lệnh thường dùng hằng ngày](#6-lệnh-thường-dùng-hằng-ngày)
7. [Đóng gói & Chuyển sang máy khác](#7-đóng-gói--chuyển-sang-máy-khác)
8. [Xử lý lỗi phổ biến](#8-xử-lý-lỗi-phổ-biến)
9. [Kiến trúc hệ thống](#9-kiến-trúc-hệ-thống)

---

## 1. Tech Stack

| Thành phần | Phiên bản | Vai trò |
|-----------|-----------|---------|
| PHP-FPM | 8.2 | Application runtime |
| Laravel | 13.x | Web framework |
| Nginx | alpine | HTTP reverse proxy |
| PostgreSQL | 15-alpine | Primary database (partitioned tables) |
| Redis | alpine | Queue, Cache, Session |
| Docker Compose | v2+ | Container orchestration |
| Laravel Horizon | latest | Queue dashboard & worker manager |

---

## 2. Yêu cầu hệ thống

### Phần mềm bắt buộc

| Phần mềm | macOS | Linux | Windows |
|---------|-------|-------|---------|
| **Docker Desktop** | [Tải về](https://www.docker.com/products/docker-desktop/) | [Tải về](https://docs.docker.com/desktop/install/linux/) | [Tải về](https://www.docker.com/products/docker-desktop/) |
| **Git** | `brew install git` | `apt install git` / `yum install git` | [Git for Windows](https://git-scm.com/download/win) |

### Tài nguyên tối thiểu

```
RAM:  4 GB (khuyến nghị 8 GB)
CPU:  2 cores
Disk: 5 GB trống
```

### Kiểm tra Docker đã cài đúng chưa

```bash
docker --version        # Docker version 24.x.x trở lên
docker compose version  # Docker Compose version v2.x.x trở lên
```

> **⚠️ Lưu ý Windows:** Bật **WSL 2 integration** trong Docker Desktop → Settings → Resources → WSL Integration để có hiệu năng tốt nhất.

---

## 3. Cài đặt lần đầu

### 3.1 macOS / Linux

Mở **Terminal** và chạy từng lệnh theo thứ tự:

```bash
# 1. Clone dự án
git clone <your-repo-url> CodeDenNgu
cd CodeDenNgu

# 2. Tạo file cấu hình môi trường
cp .env.example .env

# 3. Build Docker images và khởi động containers (lần đầu mất 3-5 phút)
docker compose up -d --build

# 4. Cài đặt PHP dependencies
docker compose exec php composer install

# 5. Tạo Application Key
docker compose exec php php artisan key:generate

# 6. Chạy migrations và seed dữ liệu mẫu
docker compose exec php php artisan migrate:fresh --seed

# 7. (Tuỳ chọn) Khởi động Laravel Horizon để xử lý Queue
docker compose exec -d php php artisan horizon
```

**Kiểm tra thành công:**

```bash
docker compose ps
# Phải thấy 4 container đều ở trạng thái "Up"
```

Truy cập: **http://localhost/api/v1/health**

---

### 3.2 Windows (PowerShell)

Mở **PowerShell** hoặc **Windows Terminal** với quyền thường (không cần Administrator):

```powershell
# 1. Clone dự án
git clone <your-repo-url> CodeDenNgu
Set-Location .\CodeDenNgu

# 2. Tạo file cấu hình môi trường
Copy-Item .env.example .env

# 3. Build Docker images và khởi động containers
docker compose up -d --build

# 4. Cài đặt PHP dependencies
docker compose exec php composer install

# 5. Tạo Application Key
docker compose exec php php artisan key:generate

# 6. Chạy migrations và seed dữ liệu mẫu
docker compose exec php php artisan migrate:fresh --seed

# 7. (Tuỳ chọn) Horizon
docker compose exec php php artisan horizon
```

> **💡 Mẹo Windows:** Nếu gặp lỗi liên quan đến line endings (CRLF), chạy:
> ```powershell
> git config --global core.autocrlf input
> ```
> Sau đó clone lại dự án.

---

### 3.3 Windows WSL 2 (Khuyến nghị)

WSL 2 cho hiệu năng file I/O tốt hơn so với ổ đĩa Windows gốc.

```bash
# Bên trong WSL 2 terminal (Ubuntu/Debian)

# Đặt project trong filesystem của Linux, KHÔNG phải /mnt/c/ hay /mnt/d/
mkdir -p ~/projects
cd ~/projects

git clone <your-repo-url> CodeDenNgu
cd CodeDenNgu

cp .env.example .env
docker compose up -d --build
docker compose exec php composer install
docker compose exec php php artisan key:generate
docker compose exec php php artisan migrate:fresh --seed
```

> **⚠️ Quan trọng WSL 2:** Đặt project trong `~/projects/` (Linux filesystem), **không** trong `/mnt/d/` (Windows filesystem). Volume mounting từ `/mnt/` sẽ rất chậm.

---

## 4. Dịch vụ & Cổng mạng

### Containers đang chạy

```
┌─────────────────┬──────────────────────┬─────────────────────────────────┐
│ Tên container   │ Image                │ Mô tả                           │
├─────────────────┼──────────────────────┼─────────────────────────────────┤
│ hrm_laravel_php │ php:8.2-fpm (custom) │ Laravel application + Artisan   │
│ hrm_laravel_nginx│ nginx:alpine        │ HTTP server (reverse proxy)     │
│ hrm_postgres    │ postgres:15-alpine   │ PostgreSQL database             │
│ hrm_redis       │ redis:alpine         │ Cache / Queue / Session         │
└─────────────────┴──────────────────────┴─────────────────────────────────┘
```

### Cổng mặc định

```
Nginx       → http://localhost:80
PostgreSQL  → localhost:5432
Redis       → localhost:6379
Horizon     → http://localhost/horizon
```

### Kết nối database bằng GUI tools (TablePlus / DBeaver / pgAdmin)

```
Driver:   PostgreSQL
Host:     localhost
Port:     5432
Database: hrm
Username: hrm
Password: secret
SSL:      Disabled
```

---

## 5. Truy cập & Đăng nhập

### API Endpoints

```
Base URL:     http://localhost/api/v1
Health check: http://localhost/api/v1/health
Horizon:      http://localhost/horizon
```

### Tài khoản mặc định

| Email | Mật khẩu | Vai trò |
|-------|----------|---------|
| `admin@company.com` | `password` | Admin |
| `an.nguyen@company.com` | `NV0001` | Nhân viên |
| `mai.tran@company.com` | `NV0002` | Nhân viên |
| `cuong.le@company.com` | `NV0003` | Nhân viên |

> Nhân viên legacy dùng `employee_code` làm mật khẩu mặc định.

---

## 6. Lệnh thường dùng hằng ngày

### Quản lý container

```bash
# Xem trạng thái containers
docker compose ps

# Xem logs realtime
docker compose logs -f

# Xem logs của một service cụ thể
docker compose logs -f php
docker compose logs -f nginx

# Dừng tất cả (giữ lại dữ liệu)
docker compose down

# Dừng và xóa toàn bộ dữ liệu (DB + Redis)
docker compose down -v

# Khởi động lại một service
docker compose restart php
```

### Artisan commands

```bash
# Chạy migrations mới
docker compose exec php php artisan migrate

# Reset toàn bộ database và seed lại
docker compose exec php php artisan migrate:fresh --seed

# Xóa tất cả cache
docker compose exec php php artisan optimize:clear

# Xem danh sách routes
docker compose exec php php artisan route:list --path=api/v1

# Chạy tests
docker compose exec php php artisan test

# Xử lý queue thủ công (1 lần)
docker compose exec php php artisan queue:work --once

# Tạo partition mới cho attendance_logs (tháng tiếp theo)
docker compose exec php php artisan attendance:create-next-partition

# Tạo partition cho toàn bộ năm tới
docker compose exec php php artisan attendance:create-next-partition --year-ahead
```

### Composer / NPM

```bash
# Cài packages mới
docker compose exec php composer require vendor/package

# Cập nhật packages
docker compose exec php composer update

# Build frontend assets (nếu dùng Vite)
docker compose exec php npm run dev
```

---

## 7. Đóng gói & Chuyển sang máy khác

Có **3 cách** tuỳ nhu cầu:

---

### Cách 1: Dùng Git (Khuyến nghị — không kèm dữ liệu)

Cách đơn giản nhất nếu dự án đã có Git repository.

```bash
# Máy nguồn: push lên Git
git add .
git commit -m "chore: prepare for transfer"
git push origin main

# Máy đích: clone về và cài đặt bình thường (xem mục 3)
git clone <your-repo-url> CodeDenNgu
```

> **Lưu ý:** File `.env` và `vendor/` **không** được commit vào Git (đã có trong `.gitignore`). Máy đích cần chạy lại `cp .env.example .env` và `composer install`.

---

### Cách 2: Nén thư mục (Kèm `vendor/` — không cần internet)

Dùng khi máy đích không có internet để chạy `composer install`.

#### macOS / Linux

```bash
# Máy nguồn: nén dự án (loại bỏ .git để nhỏ hơn)
cd ~
tar -czf CodeDenNgu-transfer.tar.gz \
  --exclude="CodeDenNgu/.git" \
  --exclude="CodeDenNgu/node_modules" \
  --exclude="CodeDenNgu/storage/logs/*" \
  --exclude="CodeDenNgu/storage/framework/cache/*" \
  CodeDenNgu

echo "Kích thước file:"
du -sh CodeDenNgu-transfer.tar.gz
```

```bash
# Máy đích: giải nén và chạy
tar -xzf CodeDenNgu-transfer.tar.gz
cd CodeDenNgu
cp .env.example .env     # hoặc copy file .env từ máy nguồn nếu muốn giữ settings
docker compose up -d --build
docker compose exec php php artisan key:generate
docker compose exec php php artisan migrate:fresh --seed
```

#### Windows (PowerShell)

```powershell
# Máy nguồn: nén dự án
Compress-Archive -Path "D:\CodeDenNgu" `
  -DestinationPath "D:\CodeDenNgu-transfer.zip" `
  -CompressionLevel Optimal

Write-Host "Kích thước file: $((Get-Item D:\CodeDenNgu-transfer.zip).Length / 1MB) MB"
```

```powershell
# Máy đích: giải nén và chạy
Expand-Archive -Path "CodeDenNgu-transfer.zip" -DestinationPath "D:\"
Set-Location "D:\CodeDenNgu"
Copy-Item .env.example .env
docker compose up -d --build
docker compose exec php php artisan key:generate
docker compose exec php php artisan migrate:fresh --seed
```

---

### Cách 3: Export cả Docker Images + Database (Kèm toàn bộ dữ liệu)

Dùng khi cần chuyển **cả dữ liệu production** sang máy khác mà không cần seed lại.

#### Bước 1 — Máy nguồn: Export images và database

```bash
# 1a. Build images trước
docker compose build

# 1b. Lưu Docker images ra file
docker save hrm_laravel_php -o hrm-php-image.tar
docker save nginx:alpine      -o hrm-nginx-image.tar
docker save postgres:15-alpine -o hrm-postgres-image.tar
docker save redis:alpine       -o hrm-redis-image.tar

# 1c. Export PostgreSQL database
docker compose exec postgres pg_dump \
  -U hrm \
  -d hrm \
  --format=custom \
  --file=/tmp/hrm-database.dump

docker compose cp postgres:/tmp/hrm-database.dump ./hrm-database.dump

# 1d. Nén tất cả vào một file
tar -czf CodeDenNgu-full-transfer.tar.gz \
  --exclude=".git" \
  --exclude="node_modules" \
  CodeDenNgu/ \
  hrm-php-image.tar \
  hrm-nginx-image.tar \
  hrm-postgres-image.tar \
  hrm-redis-image.tar \
  hrm-database.dump

echo "Tổng kích thước:"
du -sh CodeDenNgu-full-transfer.tar.gz
```

#### Bước 2 — Máy đích: Restore

```bash
# 2a. Giải nén
tar -xzf CodeDenNgu-full-transfer.tar.gz
cd CodeDenNgu

# 2b. Load Docker images từ file (không cần pull từ internet)
docker load -i ../hrm-php-image.tar
docker load -i ../hrm-nginx-image.tar
docker load -i ../hrm-postgres-image.tar
docker load -i ../hrm-redis-image.tar

# 2c. Copy file .env (hoặc tạo mới)
cp .env.example .env
# (Chỉnh sửa .env nếu cần thay đổi password hoặc cổng)

# 2d. Khởi động containers
docker compose up -d

# 2e. Restore database
docker compose cp ../hrm-database.dump postgres:/tmp/hrm-database.dump
docker compose exec postgres pg_restore \
  -U hrm \
  -d hrm \
  --clean \
  --if-exists \
  /tmp/hrm-database.dump

echo "✅ Hoàn tất! Truy cập http://localhost"
```

#### Windows — Export images (PowerShell)

```powershell
# Export images
docker save hrm_laravel_php -o hrm-php-image.tar
docker save nginx:alpine     -o hrm-nginx-image.tar
docker save postgres:15-alpine -o hrm-postgres-image.tar
docker save redis:alpine      -o hrm-redis-image.tar

# Export database
docker compose exec postgres pg_dump -U hrm -d hrm --format=custom --file=/tmp/hrm-database.dump
docker compose cp postgres:/tmp/hrm-database.dump ./hrm-database.dump

# Nén tất cả
Compress-Archive -Path @(".\CodeDenNgu", ".\*.tar", ".\hrm-database.dump") `
  -DestinationPath "CodeDenNgu-full-transfer.zip"
```

---

### 📋 Checklist trước khi chuyển máy

```
☐ docker compose down (dừng containers sạch sẽ)
☐ File .env đã được cấu hình đúng (hoặc dùng .env.example làm template)
☐ Đã backup database nếu có dữ liệu quan trọng
☐ Đã test: docker compose up -d --build hoạt động bình thường
```

---

## 8. Xử lý lỗi phổ biến

### Lỗi "port is already allocated"

Một ứng dụng khác đang dùng cổng 80, 5432, hoặc 6379.

```bash
# macOS / Linux: tìm tiến trình đang dùng cổng
lsof -i :5432
lsof -i :80

# Windows PowerShell: tìm tiến trình đang dùng cổng
netstat -ano | findstr :5432
netstat -ano | findstr :80
```

**Giải pháp:** Thay đổi cổng trong `docker-compose.yml`:

```yaml
# docker-compose.yml — đổi cổng bên trái (host)
nginx:
  ports:
    - "8080:80"    # Truy cập qua http://localhost:8080

postgres:
  ports:
    - "5433:5432"  # Kết nối DB qua port 5433

redis:
  ports:
    - "6380:6379"
```

Sau đó cập nhật `.env`:

```env
APP_URL=http://localhost:8080
HRM_API_BASE_URL=http://localhost:8080
```

---

### Lỗi "Permission denied" (macOS / Linux)

```bash
# Fix quyền thư mục storage và cache
docker compose exec -u root php chown -R laravel:laravel /var/www/html/storage
docker compose exec -u root php chown -R laravel:laravel /var/www/html/bootstrap/cache
docker compose exec php composer install
```

---

### Lỗi "vendor không tồn tại" hoặc "Class not found"

```bash
docker compose exec php composer install
docker compose exec php php artisan optimize:clear
```

---

### Lỗi "dubious ownership" của Git

```bash
docker compose exec php git config --global --add safe.directory /var/www/html
```

---

### Container khởi động nhưng API trả về lỗi 500

```bash
# Xem logs chi tiết
docker compose logs php
docker compose logs nginx

# Kiểm tra file .env
docker compose exec php php artisan config:show app

# Chạy lại generate key nếu APP_KEY trống
docker compose exec php php artisan key:generate
```

---

### Lỗi database "connection refused" hoặc "SQLSTATE"

```bash
# Kiểm tra PostgreSQL đã sẵn sàng chưa
docker compose exec postgres pg_isready -U hrm -d hrm

# Kết nối thử bằng psql
docker compose exec postgres psql -U hrm -d hrm -c "\dt"
```

---

### Rebuild hoàn toàn từ đầu

```bash
# Xóa sạch containers, volumes, images
docker compose down -v
docker system prune -f        # Xóa dangling images/cache

# Build lại
docker compose up -d --build
docker compose exec php composer install
docker compose exec php php artisan migrate:fresh --seed
docker compose exec php php artisan test
```

---

## 9. Kiến trúc hệ thống

```
┌─────────────────────────────────────────────────────────────────┐
│                     Internet / Client                           │
└─────────────────────┬───────────────────────────────────────────┘
                      │ :80
                      ▼
             ┌────────────────┐
             │  Nginx:alpine  │  ← Reverse proxy, static files
             └────────┬───────┘
                      │ FastCGI :9000
                      ▼
             ┌────────────────┐
             │  PHP 8.2-FPM   │  ← Laravel 13 Application
             │  (Laravel)     │
             └───┬────────┬───┘
                 │        │
          ┌──────┘        └──────┐
          ▼                      ▼
 ┌─────────────────┐   ┌─────────────────┐
 │ PostgreSQL 15   │   │  Redis:alpine   │
 │  :5432          │   │  :6379          │
 │                 │   │                 │
 │ - attendance_   │   │ - Queue jobs    │
 │   logs (part.)  │   │ - Sessions      │
 │ - employees     │   │ - Cache         │
 │   (GIN index)   │   │ - Dedup keys    │
 │ - org chart CTE │   │ - Audit streams │
 └─────────────────┘   └─────────────────┘
```

### Luồng Check-in (8h00 sáng — 5000 nhân viên)

```
Client → POST /api/v1/attendance/check-in
  │
  ├─[1ms]─► Redis SET NX (idempotency check)
  ├─[1ms]─► Redis XADD (audit stream)
  ├─[2ms]─► Queue::dispatch (ProcessAttendanceLog job)
  └─[5ms]─► 202 Accepted ◄── Client nhận response ngay

[Background - Horizon Worker]
  ├─► DB Transaction begin
  ├─► INSERT INTO attendance_logs (partition tháng hiện tại)
  ├─► UPSERT INTO attendances (summary)
  └─► DB Transaction commit
```

---

*Cập nhật lần cuối: 2026-06-19 — CodeDenNgu HRM Team*
