#!/usr/bin/env bash
# =============================================================================
# pack.sh — Đóng gói dự án CodeDenNgu để chuyển sang máy khác
# Chạy: bash pack.sh [mode]
#
# Modes:
#   code    — Chỉ mã nguồn, không có vendor/ (cần internet ở máy đích)
#   vendor  — Mã nguồn + vendor/ (không cần internet, file lớn hơn)
#   full    — Mã nguồn + vendor/ + Docker images + database backup
#
# Ví dụ:
#   bash pack.sh              # Mặc định: mode "code"
#   bash pack.sh vendor
#   bash pack.sh full
# =============================================================================

set -euo pipefail

MODE="${1:-code}"
PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_NAME="$(basename "$PROJECT_DIR")"
TIMESTAMP="$(date +%Y%m%d_%H%M%S)"
OUTPUT_DIR="${HOME}/Desktop"
OUTPUT_NAME="${PROJECT_NAME}-${MODE}-${TIMESTAMP}"

# Màu sắc terminal
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'
BOLD='\033[1m'; NC='\033[0m'

info()    { echo -e "${BLUE}[INFO]${NC}  $*"; }
success() { echo -e "${GREEN}[OK]${NC}    $*"; }
warn()    { echo -e "${YELLOW}[WARN]${NC}  $*"; }
error()   { echo -e "${RED}[ERROR]${NC} $*" >&2; exit 1; }

# =============================================================================
echo -e "${BOLD}╔══════════════════════════════════════════╗${NC}"
echo -e "${BOLD}║   CodeDenNgu HRM — Pack Script          ║${NC}"
echo -e "${BOLD}╚══════════════════════════════════════════╝${NC}"
echo ""
info "Mode: ${BOLD}${MODE}${NC}"
info "Project: ${PROJECT_DIR}"
info "Output: ${OUTPUT_DIR}/${OUTPUT_NAME}.tar.gz"
echo ""

# Kiểm tra đang ở đúng thư mục
[ -f "${PROJECT_DIR}/composer.json" ] || error "Không tìm thấy composer.json. Chạy script từ thư mục gốc dự án."

# =============================================================================
# CÁC FILE / THƯ MỤC LUÔN LOẠI TRỪ
# =============================================================================
EXCLUDES=(
    "--exclude=.git"
    "--exclude=.DS_Store"
    "--exclude=node_modules"
    "--exclude=storage/logs/*.log"
    "--exclude=storage/framework/cache/data/*"
    "--exclude=storage/framework/sessions/*"
    "--exclude=storage/framework/views/*"
    "--exclude=*.tar"
    "--exclude=*.zip"
    "--exclude=${OUTPUT_NAME}*"
)

# =============================================================================
pack_code() {
    info "Packing mode: CODE (mã nguồn, không có vendor/)"
    EXCLUDES+=("--exclude=vendor")

    cd "$(dirname "$PROJECT_DIR")"
    tar -czf "${OUTPUT_DIR}/${OUTPUT_NAME}.tar.gz" \
        "${EXCLUDES[@]}" \
        "$(basename "$PROJECT_DIR")"

    success "Hoàn tất!"
    warn "Máy đích cần: composer install, php artisan migrate:fresh --seed"
}

# =============================================================================
pack_vendor() {
    info "Packing mode: VENDOR (mã nguồn + vendor/ — không cần composer ở máy đích)"

    cd "$(dirname "$PROJECT_DIR")"
    tar -czf "${OUTPUT_DIR}/${OUTPUT_NAME}.tar.gz" \
        "${EXCLUDES[@]}" \
        "$(basename "$PROJECT_DIR")"

    success "Hoàn tất!"
    warn "Máy đích cần: php artisan migrate:fresh --seed (vendor/ đã có sẵn)"
}

# =============================================================================
pack_full() {
    info "Packing mode: FULL (mã nguồn + Docker images + database backup)"
    TMPDIR=$(mktemp -d)
    trap "rm -rf $TMPDIR" EXIT

    # Kiểm tra Docker đang chạy
    docker info > /dev/null 2>&1 || error "Docker không chạy. Hãy khởi động Docker trước."

    # Lấy tên image từ docker compose
    info "1/5 — Đang build Docker images..."
    cd "$PROJECT_DIR"
    docker compose build --quiet

    info "2/5 — Đang lưu Docker images..."
    PHP_IMAGE=$(docker compose config --images 2>/dev/null | grep php || echo "hrm_laravel_php")
    docker save hrm_laravel_php      -o "$TMPDIR/image-php.tar"      2>/dev/null || docker save $(docker compose images -q php 2>/dev/null | head -1) -o "$TMPDIR/image-php.tar" || warn "Bỏ qua PHP image"
    docker save nginx:alpine         -o "$TMPDIR/image-nginx.tar"
    docker save postgres:15-alpine   -o "$TMPDIR/image-postgres.tar"
    docker save redis:alpine         -o "$TMPDIR/image-redis.tar"
    success "Đã lưu 4 Docker images"

    info "3/5 — Đang backup PostgreSQL database..."
    if docker compose exec -T postgres pg_isready -U hrm -d hrm > /dev/null 2>&1; then
        docker compose exec -T postgres pg_dump \
            -U hrm \
            -d hrm \
            --format=custom \
            --file=/tmp/hrm-database.dump
        docker compose cp postgres:/tmp/hrm-database.dump "$TMPDIR/hrm-database.dump"
        success "Đã backup database → hrm-database.dump"
    else
        warn "PostgreSQL chưa chạy, bỏ qua backup database."
        echo "# No database" > "$TMPDIR/hrm-database.dump"
    fi

    info "4/5 — Đang ghi README hướng dẫn restore..."
    cat > "$TMPDIR/RESTORE.md" << 'RESTORE_EOF'
# Hướng dẫn Restore — CodeDenNgu HRM

## Yêu cầu
- Docker Desktop đã cài và đang chạy
- Giải nén file .tar.gz trước khi thực hiện

## Bước 1: Load Docker images (không cần internet)
```bash
docker load -i image-php.tar
docker load -i image-nginx.tar
docker load -i image-postgres.tar
docker load -i image-redis.tar
```

## Bước 2: Cấu hình và khởi động
```bash
cd CodeDenNgu
cp .env.example .env
# Chỉnh sửa .env nếu cần (mật khẩu, cổng, ...)
docker compose up -d
```

## Bước 3: Restore database (nếu có file hrm-database.dump)
```bash
# Chờ PostgreSQL sẵn sàng (~5 giây)
sleep 5
docker compose cp ../hrm-database.dump postgres:/tmp/hrm-database.dump
docker compose exec postgres pg_restore \
  -U hrm -d hrm --clean --if-exists \
  /tmp/hrm-database.dump
```

## Bước 4: Hoàn tất
```bash
docker compose exec php php artisan key:generate
docker compose exec php php artisan optimize:clear
```

Truy cập: http://localhost/api/v1/health
RESTORE_EOF

    info "5/5 — Đang nén tất cả..."
    cd "$(dirname "$PROJECT_DIR")"
    tar -czf "${OUTPUT_DIR}/${OUTPUT_NAME}.tar.gz" \
        "${EXCLUDES[@]}" \
        "$(basename "$PROJECT_DIR")" \
        -C "$TMPDIR" \
        image-php.tar image-nginx.tar image-postgres.tar image-redis.tar \
        hrm-database.dump RESTORE.md

    success "Hoàn tất!"
    info "Máy đích: giải nén → đọc RESTORE.md để restore"
}

# =============================================================================
# CHẠY
# =============================================================================
case "$MODE" in
    code)   pack_code   ;;
    vendor) pack_vendor ;;
    full)   pack_full   ;;
    *)      error "Mode không hợp lệ: '${MODE}'. Dùng: code | vendor | full" ;;
esac

# Thông tin file kết quả
OUTPUT_FILE="${OUTPUT_DIR}/${OUTPUT_NAME}.tar.gz"
if [ -f "$OUTPUT_FILE" ]; then
    SIZE=$(du -sh "$OUTPUT_FILE" | cut -f1)
    echo ""
    echo -e "${BOLD}════════════════════════════════════════${NC}"
    echo -e "  📦 File: ${GREEN}${OUTPUT_FILE}${NC}"
    echo -e "  📏 Kích thước: ${BOLD}${SIZE}${NC}"
    echo -e "${BOLD}════════════════════════════════════════${NC}"
fi
