# CI/CD production

Repository source duy nhất là `vHozang/BEHRM`, branch triển khai là `production`.

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
