<#
.SYNOPSIS
    Đóng gói dự án CodeDenNgu để chuyển sang máy khác (Windows PowerShell)

.DESCRIPTION
    Script đóng gói với 3 mode:
      code    - Chỉ mã nguồn (cần composer install ở máy đích)
      vendor  - Mã nguồn + vendor/ (không cần internet)
      full    - Mã nguồn + Docker images + database backup

.PARAMETER Mode
    Chọn mode: code | vendor | full (mặc định: code)

.EXAMPLE
    .\pack.ps1
    .\pack.ps1 -Mode vendor
    .\pack.ps1 -Mode full
#>

param(
    [ValidateSet("code", "vendor", "full")]
    [string]$Mode = "code"
)

# =============================================================================
$ErrorActionPreference = "Stop"
$ProjectDir  = $PSScriptRoot
$ProjectName = Split-Path -Leaf $ProjectDir
$Timestamp   = Get-Date -Format "yyyyMMdd_HHmmss"
$OutputDir   = [Environment]::GetFolderPath("Desktop")
$OutputName  = "${ProjectName}-${Mode}-${Timestamp}"
$OutputFile  = Join-Path $OutputDir "${OutputName}.zip"

# Kiểm tra composer.json
if (-not (Test-Path (Join-Path $ProjectDir "composer.json"))) {
    Write-Error "Không tìm thấy composer.json. Chạy script từ thư mục gốc dự án."
}

# =============================================================================
function Write-Header {
    Write-Host ""
    Write-Host "╔══════════════════════════════════════════╗" -ForegroundColor Cyan
    Write-Host "║   CodeDenNgu HRM — Pack Script (Win)    ║" -ForegroundColor Cyan
    Write-Host "╚══════════════════════════════════════════╝" -ForegroundColor Cyan
    Write-Host ""
    Write-Host "[INFO]  Mode    : " -NoNewline; Write-Host $Mode -ForegroundColor Yellow
    Write-Host "[INFO]  Project : $ProjectDir"
    Write-Host "[INFO]  Output  : $OutputFile"
    Write-Host ""
}

# Patterns loại trừ khi nén
$ExcludePatterns = @(
    ".git", ".DS_Store", "node_modules",
    "storage\logs\*.log",
    "storage\framework\cache\data",
    "storage\framework\sessions",
    "storage\framework\views",
    "*.tar", "*.zip"
)

function Get-FilesToPack {
    param([string[]]$AdditionalExclude = @())

    $all = @(Get-ChildItem -Path $ProjectDir -Recurse -Force)
    $excludeAll = $ExcludePatterns + $AdditionalExclude

    $filtered = $all | Where-Object {
        $relativePath = $_.FullName.Replace($ProjectDir + "\", "")
        $excluded = $false
        foreach ($pattern in $excludeAll) {
            if ($relativePath -like "*$pattern*") {
                $excluded = $true
                break
            }
        }
        -not $excluded
    }
    return $filtered
}

# =============================================================================
function Pack-Code {
    Write-Host "[INFO]  Packing: CODE (mã nguồn, không vendor/)" -ForegroundColor Blue

    $files = Get-FilesToPack -AdditionalExclude @("vendor")
    $files | Compress-Archive -DestinationPath $OutputFile -Update

    Write-Host "[OK]    Hoàn tất!" -ForegroundColor Green
    Write-Host "[WARN]  Máy đích cần: composer install, php artisan migrate:fresh --seed" -ForegroundColor Yellow
}

# =============================================================================
function Pack-Vendor {
    Write-Host "[INFO]  Packing: VENDOR (mã nguồn + vendor/)" -ForegroundColor Blue

    $files = Get-FilesToPack
    $files | Compress-Archive -DestinationPath $OutputFile -Update

    Write-Host "[OK]    Hoàn tất!" -ForegroundColor Green
    Write-Host "[WARN]  Máy đích cần: php artisan migrate:fresh --seed" -ForegroundColor Yellow
}

# =============================================================================
function Pack-Full {
    Write-Host "[INFO]  Packing: FULL (mã nguồn + Docker images + database)" -ForegroundColor Blue

    # Kiểm tra Docker
    try { docker info | Out-Null }
    catch { Write-Error "Docker không chạy. Hãy khởi động Docker Desktop trước." }

    $TempDir = New-Item -ItemType Directory -Path (Join-Path $env:TEMP "hrm-pack-$Timestamp")

    try {
        # 1. Build images
        Write-Host "[1/5]  Building Docker images..." -ForegroundColor Blue
        Set-Location $ProjectDir
        docker compose build --quiet

        # 2. Save images
        # Lấy tên image thực tế từ docker compose (tên phụ thuộc vào tên thư mục project)
        # Ví dụ: thư mục "Doan2" → image "doan2-php", thư mục "hrm" → "hrm-php"
        Write-Host "[2/5]  Saving Docker images..." -ForegroundColor Blue

        $PhpImage = (docker compose images -q php 2>$null | Select-Object -First 1).Trim()
        if (-not $PhpImage) {
            # Fallback: lấy image name từ docker inspect
            $PhpImage = (docker compose config --format json 2>$null | ConvertFrom-Json).services.php.image
        }
        if (-not $PhpImage) {
            # Fallback cuối: tên theo tên thư mục project
            $PhpImage = "$($ProjectName.ToLower())-php"
        }
        Write-Host "[INFO]  PHP image name: $PhpImage" -ForegroundColor Gray

        $savedCount = 0
        @(
            @{ name = $PhpImage;         file = "image-php.tar"      },
            @{ name = "nginx:alpine";    file = "image-nginx.tar"    },
            @{ name = "postgres:15-alpine"; file = "image-postgres.tar" },
            @{ name = "redis:alpine";    file = "image-redis.tar"    }
        ) | ForEach-Object {
            $imgName = $_.name; $imgFile = Join-Path $TempDir $_.file
            Write-Host "       Saving $imgName ..." -ForegroundColor Gray -NoNewline
            docker save $imgName -o $imgFile 2>$null
            if ($LASTEXITCODE -eq 0) {
                $sizeMB = [math]::Round((Get-Item $imgFile).Length / 1MB, 0)
                Write-Host " ${sizeMB}MB ✓" -ForegroundColor Green
                $savedCount++
            } else {
                Write-Host " SKIP (image chưa pull)" -ForegroundColor Yellow
            }
        }
        Write-Host "[OK]   Đã lưu $savedCount Docker image(s)" -ForegroundColor Green

        # 3. Backup database
        Write-Host "[3/5]  Backup PostgreSQL database..." -ForegroundColor Blue
        $dbReady = docker compose exec -T postgres pg_isready -U hrm -d hrm 2>&1
        if ($LASTEXITCODE -eq 0) {
            docker compose exec -T postgres pg_dump `
                -U hrm -d hrm `
                --format=custom `
                --file=/tmp/hrm-database.dump
            docker compose cp "postgres:/tmp/hrm-database.dump" (Join-Path $TempDir "hrm-database.dump")
            Write-Host "[OK]   Đã backup database" -ForegroundColor Green
        } else {
            Write-Host "[WARN] PostgreSQL chưa chạy, bỏ qua backup." -ForegroundColor Yellow
            "# No database" | Out-File (Join-Path $TempDir "hrm-database.dump")
        }

        # 4. Tạo hướng dẫn restore
        Write-Host "[4/5]  Tạo RESTORE.md..." -ForegroundColor Blue
        @"
# Hướng dẫn Restore — CodeDenNgu HRM (Windows)

## Yêu cầu
- Docker Desktop đang chạy
- PowerShell 5+

## Bước 1: Giải nén
Giải nén file .zip vào thư mục muốn đặt project.

## Bước 2: Load Docker images (không cần internet)
``````powershell
docker load -i image-php.tar
docker load -i image-nginx.tar
docker load -i image-postgres.tar
docker load -i image-redis.tar
``````

## Bước 3: Khởi động
``````powershell
cd CodeDenNgu
Copy-Item .env.example .env
docker compose up -d
``````

## Bước 4: Restore database
``````powershell
Start-Sleep -Seconds 5
docker compose cp ..\hrm-database.dump postgres:/tmp/hrm-database.dump
docker compose exec postgres pg_restore ``
    -U hrm -d hrm --clean --if-exists ``
    /tmp/hrm-database.dump
``````

## Bước 5: Hoàn tất
``````powershell
docker compose exec php php artisan key:generate
docker compose exec php php artisan optimize:clear
``````

Truy cập: http://localhost/api/v1/health
"@ | Out-File (Join-Path $TempDir "RESTORE.md") -Encoding UTF8

        # 5. Nén tất cả vào zip
        Write-Host "[5/5]  Nén tất cả..." -ForegroundColor Blue

        # Nén source code
        $files = Get-FilesToPack
        $files | Compress-Archive -DestinationPath $OutputFile -Update

        # Thêm Docker images và database vào zip
        Get-ChildItem $TempDir | Compress-Archive -DestinationPath $OutputFile -Update

        Write-Host "[OK]   Hoàn tất!" -ForegroundColor Green

    } finally {
        Remove-Item $TempDir -Recurse -Force -ErrorAction SilentlyContinue
    }
}

# =============================================================================
Write-Header

switch ($Mode) {
    "code"   { Pack-Code   }
    "vendor" { Pack-Vendor }
    "full"   { Pack-Full   }
}

# Hiển thị kết quả
if (Test-Path $OutputFile) {
    $size = [math]::Round((Get-Item $OutputFile).Length / 1MB, 1)
    Write-Host ""
    Write-Host "════════════════════════════════════════" -ForegroundColor Cyan
    Write-Host "  📦 File   : $OutputFile" -ForegroundColor Green
    Write-Host "  📏 Size   : ${size} MB" -ForegroundColor Yellow
    Write-Host "════════════════════════════════════════" -ForegroundColor Cyan
    Write-Host ""

    # Mở Explorer tới file vừa tạo
    explorer.exe /select, $OutputFile
}
