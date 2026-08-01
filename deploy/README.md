# CI/CD production

Repository source duy nhất là `vHozang/BEHRM`, branch triển khai là `production`.

VPS production không chạy `resume-backend`; service này có profile `resume` và chỉ bật trên máy local bằng `docker compose --profile resume up -d resume-backend` khi cần.

## Tailscale cho resume-backend local

Đăng nhập VPS bằng `root` và cài Tailscale:

```bash
curl -fsSL https://tailscale.com/install.sh | sh
systemctl enable --now tailscaled
tailscale up
tailscale ip -4
```

Mở URL xác thực Tailscale nếu `tailscale up` yêu cầu. Không commit hoặc gửi auth key vào Git. Kiểm tra kết nối tới hai máy local:

```bash
tailscale ping 100.95.129.101
tailscale ping 100.105.84.89
```

Chạy resume backend trên máy đang test với bind vào IP Tailscale, sau đó kiểm tra từ VPS. Windows:

```powershell
cd D:\HRM\BEHRM\Doan2_v2\Doan2
$env:RESUME_BIND_IP = "100.95.129.101"
docker compose --profile resume up -d --build --force-recreate resume-backend
curl.exe http://100.95.129.101:8000/health
```

Mac:

```bash
cd /path/to/BEHRM/Doan2_v2/Doan2
RESUME_BIND_IP=100.105.84.89 docker compose --profile resume up -d --build --force-recreate resume-backend
curl -fsS http://100.105.84.89:8000/health
```

Kiểm tra từ VPS:

```bash
curl -fsS http://100.95.129.101:8000/health
```

VPS dùng Windows làm endpoint mặc định và tự chuyển sang Mac nếu Windows tắt:

```dotenv
AUTORECRUIT_URL=http://100.95.129.101:8000
AUTORECRUIT_FALLBACK_URLS=http://100.105.84.89:8000
AUTORECRUIT_CONNECT_TIMEOUT=5
AUTORECRUIT_TIMEOUT=120
```

Cần cho phép TCP/8000 trên interface Tailscale của máy local. Admin có thể kiểm tra từ Laravel qua `GET /api/v1/settings/integrations/autorecruit/health`.

Domain API production là `devtapcode.io.vn`. DNS phải trỏ tới `180.93.42.137`; sau khi có TLS, đặt frontend `VITE_API_BASE_URL=https://devtapcode.io.vn/api/v1` và `APP_URL=https://devtapcode.io.vn`.

## 0. Nếu VPS đã đổi host key

Khi VPS được tạo lại nhưng giữ nguyên IP, SSH có thể báo `REMOTE HOST IDENTIFICATION HAS CHANGED`. Trước khi xóa key cũ, đối chiếu fingerprint với console của nhà cung cấp VPS. Fingerprint hiện VPS đang trình bày là:

```text
SHA256:TVkp/PLJCLAPKPCRB5Qn1orWYlDZ5+3Kl/S9F3r77pk (RSA)
```

Trên PowerShell, chỉ xóa entry của IP này (không xóa toàn bộ `known_hosts`):

```powershell
ssh-keygen -R 180.93.42.137 -f "$env:USERPROFILE\.ssh\known_hosts"
ssh-keygen -R "[180.93.42.137]:22" -f "$env:USERPROFILE\.ssh\known_hosts"
ssh root@180.93.42.137
```

Sau khi xác minh fingerprint và chấp nhận key mới, tạo giá trị secret `VPS_KNOWN_HOSTS` bằng:

```powershell
ssh-keyscan -H -t rsa 180.93.42.137
```

Nếu `ssh-keyscan` trên PowerShell báo `unsupported KEX method`, chạy lệnh tương tự trong terminal Linux của VPS rồi copy các dòng khóa (không copy dòng bắt đầu bằng `#`):

```bash
ssh-keyscan -H -t rsa 180.93.42.137 2>/dev/null
```

## 1. Tạo user triển khai trên VPS

Đăng nhập VPS bằng `root` (IP `180.93.42.137`) và cài Docker Engine cùng Docker Compose v2 nếu máy chưa có. Trên máy local, tạo một cặp khóa riêng cho GitHub Actions:

```bash
ssh-keygen -t ed25519 -C "github-actions-hrm" -f ~/.ssh/hrm_github_actions
cat ~/.ssh/hrm_github_actions.pub
```

Trên VPS, clone tạm repository rồi chạy provisioning bằng **public key** vừa in ra (không dùng private key):

```bash
PROVISION_DIR="$(mktemp -d /tmp/hrm-provision.XXXXXX)"
git clone --branch production --single-branch https://github.com/vHozang/BEHRM.git "$PROVISION_DIR"
cd "$PROVISION_DIR"
export DEPLOY_PUBLIC_KEY='ssh-ed25519 AAAA... github-actions-hrm'
bash deploy/provision-vps.sh
```

Script tạo user `deloy`, thêm user vào nhóm `docker`, clone production vào `/opt/hrm`, và cài `authorized_keys`. Không đặt mật khẩu hoặc private key trong Git.

Tạo file production secrets trên VPS:

```bash
install -m 600 /dev/null /opt/hrm/Doan2_v2/Doan2/.env
# điền APP_KEY, DB_PASSWORD, APP_URL, các thông tin Redis/attendance...
```

### Cấu hình gửi email tuyển dụng

Cloudflare Email Routing chỉ nhận thư gửi đến `hr@devtapcode.io.vn` rồi chuyển tiếp; dịch vụ này không cung cấp SMTP gửi thư. Cần đăng ký một nhà cung cấp gửi mail như Resend, Brevo, Postmark hoặc Mailgun, xác minh domain `devtapcode.io.vn`, rồi thêm thông tin SMTP vào file `.env` trên VPS:

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=tls
MAIL_HOST=<SMTP_HOST>
MAIL_PORT=587
MAIL_USERNAME=<SMTP_USERNAME>
MAIL_PASSWORD=<SMTP_PASSWORD_OR_API_KEY>
MAIL_EHLO_DOMAIN=devtapcode.io.vn
MAIL_FROM_ADDRESS=hr@devtapcode.io.vn
MAIL_FROM_NAME="DEVTAPCODE HR"

RECRUITMENT_MAIL_FROM_ADDRESS=hr@devtapcode.io.vn
RECRUITMENT_MAIL_FROM_NAME="DEVTAPCODE HR"
RECRUITMENT_COMPANY_NAME="DEVTAPCODE"
RECRUITMENT_COMPANY_ADDRESS="<ĐỊA CHỈ CÔNG TY>"
RECRUITMENT_COMPANY_PHONE="<SỐ ĐIỆN THOẠI>"
RECRUITMENT_WEBSITE_URL=https://devtapcode.io.vn
RECRUITMENT_CONTACT_NAME="Bộ phận Tuyển dụng"
```

Trong Cloudflare DNS, thêm DKIM và SPF đúng theo nhà cung cấp SMTP. Domain chỉ được có **một** TXT SPF ở cùng hostname; nếu nhà cung cấp yêu cầu SPF tại root thì phải gộp `include` của họ với `_spf.mx.cloudflare.net`, không tạo hai bản ghi `v=spf1` riêng. Nên thêm DMARC ở chế độ theo dõi trước:

```dns
_dmarc.devtapcode.io.vn TXT "v=DMARC1; p=none; rua=mailto:hr@devtapcode.io.vn; adkim=s; aspf=s"
```

Sau khi cập nhật `.env`:

```bash
cd /opt/hrm/Doan2_v2/Doan2
docker compose exec -T php php artisan optimize:clear
docker compose exec -T php php artisan config:cache
```

### Tự động tạo link Google Meet

Lịch phỏng vấn trực tuyến chỉ chấp nhận link phòng cụ thể, ví dụ
`https://meet.google.com/abc-defg-hij`; trang chủ `https://meet.google.com/`
không được coi là phòng họp. HR có thể nhập link thủ công hoặc bật tùy chọn tạo
Google Meet tự động trên màn hình lịch phỏng vấn.

Để tạo tự động, bật Google Calendar API trong Google Cloud, tạo OAuth client,
ủy quyền scope `https://www.googleapis.com/auth/calendar.events` cho tài khoản
quản lý lịch tuyển dụng và lấy refresh token. Sau đó thêm vào `.env` trên VPS:

```dotenv
GOOGLE_CALENDAR_CLIENT_ID=<OAUTH_CLIENT_ID>
GOOGLE_CALENDAR_CLIENT_SECRET=<OAUTH_CLIENT_SECRET>
GOOGLE_CALENDAR_REFRESH_TOKEN=<OAUTH_REFRESH_TOKEN>
GOOGLE_CALENDAR_ID=primary
GOOGLE_CALENDAR_TIMEZONE=Asia/Ho_Chi_Minh
```

Không commit hoặc gửi các giá trị OAuth lên GitHub. Sau khi cập nhật, chạy lại
`optimize:clear` và `config:cache` như phần cấu hình email. Nếu chưa có OAuth,
HR vẫn có thể tạo phòng trên Google Meet/Calendar rồi dán link phòng cụ thể vào form.

## 2. Cấu hình GitHub Actions

Trong GitHub repository, vào **Settings → Environments → New environment**, tạo environment tên `production`. Thêm environment secret:

| Secret | Giá trị |
|---|---|
| `VPS_SSH_KEY` | toàn bộ private key `~/.ssh/hrm_github_actions` |

IP `180.93.42.137`, user `deloy`, port `22` và fingerprint RSA được cố định trong workflow. Workflow tự quét host key và chỉ kết nối khi fingerprint khớp, vì vậy không cần secret `VPS_KNOWN_HOSTS`.

`CI` chạy frontend tests/build, Laravel tests và kiểm tra Compose. `Deploy production` tự chạy khi push lên `production`, chờ CI của đúng commit thành công, hoặc chạy thủ công bằng **Run workflow**. Workflow checkout code trên VPS, build/restart Docker Compose, cài dependency production, migrate và cache Laravel.

## 3. Frontend

Frontend Vite vẫn dùng cùng repository và có thể deploy qua Vercel. Đặt `VITE_API_BASE_URL` trong Vercel Production trỏ tới API trên VPS, ví dụ `https://your-domain.example/api/v1`. Workflow CI luôn kiểm tra build để tránh deploy frontend hỏng.

## 4. Kiểm tra thủ công

```bash
ssh -p 22 deloy@180.93.42.137
cd /opt/hrm/Doan2_v2/Doan2
docker compose ps
curl -fsS http://127.0.0.1/api/v1/health
```

Không chạy `migrate:fresh` trên production; script chỉ dùng `migrate --force` để giữ dữ liệu hiện có.
