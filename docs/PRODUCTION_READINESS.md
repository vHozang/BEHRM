# HRM production readiness

## Trạng thái mã nguồn

Gate hiện tại:

- Backend: Laravel 12, Composer audit không còn advisory.
- Frontend: Vite 8, npm audit không còn vulnerability.
- Feature suite: 61 test / 277 assertion xanh sau migration sửa số dư phép.
- Dữ liệu lõi: không orphan, không duplicate email/mã nhân viên theo tenant, không tenant mismatch.
- Runtime: PHP-FPM, Nginx, PostgreSQL, Redis, queue worker và scheduler cùng chạy bằng Docker.
- Backup gần nhất trước backfill: `backups/hrm_pre_leave_backfill_20260720.dump`.

## Biến bắt buộc trước khi đưa lên Internet

Sao chép `Doan2_v2/Doan2/.env.production.example` thành `.env`, sau đó điền giá trị thật:

- `APP_KEY`: tạo bằng `php artisan key:generate --show`.
- `APP_URL`, `CORS_ALLOWED_ORIGINS`: domain HTTPS thật, không dùng wildcard.
- `DB_PASSWORD`, `REDIS_PASSWORD`: secret riêng cho từng môi trường.
- `INTERNAL_SERVICE_TOKEN`: secret dài, ngẫu nhiên; để trống thì endpoint worker AI bị khóa.
- SMTP: `MAIL_HOST`, `MAIL_USERNAME`, `MAIL_PASSWORD`, địa chỉ gửi đã xác minh.
- Frontend: `VITE_API_BASE_URL=https://api.example.com/api/v1`.

Không đưa `.env`, dump database hoặc CV ứng viên vào Git.

## Quy trình phát hành

```bash
npm ci
npm run test:csv
npm run build

cd Doan2_v2/Doan2
composer install --no-dev --classmap-authoritative
php artisan migrate --force
php artisan optimize
docker compose up -d --build
```

Sau mỗi lần cập nhật mã backend đang chạy bằng bind mount, restart các process dài hạn:

```bash
docker compose restart php worker scheduler
```

Đặt TLS ở reverse proxy/load balancer, chỉ mở 80/443 ra Internet. PostgreSQL và Redis phải ở private network; cấu hình hiện tại chỉ bind hai cổng này vào `127.0.0.1` trên host.

## Gate sau deploy

```bash
curl -f https://api.example.com/api/v1/health
php artisan migrate:status
php artisan schedule:list
php artisan test tests/Feature
composer audit
npm audit
```

Kiểm tra thêm bằng tài khoản admin và nhân viên thường:

1. Danh sách nhân viên/hợp đồng phân trang, tìm kiếm và lọc.
2. Sửa một phần hồ sơ/hợp đồng không làm mất trường JSON cũ.
3. Chạy lương chuyển sang hàng đợi, worker hoàn tất và không có `failed_jobs`.
4. Nhân viên thường bị 403 khi ghi vào module quản trị nhưng vẫn xem được dữ liệu self-service.
5. CORS chỉ trả header cho domain frontend đã cấu hình.

## Điều kiện trước khi chào bán doanh nghiệp

Các hạng mục sau phụ thuộc hạ tầng/quyết định kinh doanh, không thể hoàn tất chỉ bằng mã nguồn local:

- Domain, TLS, SMTP và nhà cung cấp lưu trữ CV.
- Backup tự động hằng ngày, retention và một lần diễn tập restore có biên bản.
- Monitoring uptime, log/error alert và cảnh báo queue backlog/failed job.
- UAT với dữ liệu ẩn danh của khách hàng, kiểm thử tải theo số nhân sự mục tiêu.
- Chính sách quyền riêng tư, thời hạn lưu CV/hồ sơ, phân quyền vận hành và quy trình offboarding tài khoản.

Chỉ nên quảng bá là “production-ready” sau khi năm nhóm trên có chủ sở hữu và bằng chứng nghiệm thu.
