<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: attendance_logs (PostgreSQL Declarative Range Partitioning)
 *
 * Chiến lược:
 *  - Dùng PARTITION BY RANGE (checked_at) để phân mảnh bảng theo tháng.
 *  - Laravel Schema Builder không hỗ trợ native partitioning, nên ta
 *    dùng raw SQL để tận dụng tính năng đặc thù của PostgreSQL.
 *  - Các partition con (child tables) sẽ được tạo tự động bởi cron job
 *    hoặc được seed sẵn cho năm 2026.
 *  - BRIN index phù hợp cho cột timestamp tăng dần (insert-order data),
 *    nhỏ hơn B-Tree rất nhiều và đủ hiệu quả cho scan theo thời gian.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * Tên connection cần chạy migration này.
     * Đảm bảo chỉ chạy trên PostgreSQL.
     */
    public function getConnection(): string
    {
        return config('database.default'); // 'pgsql'
    }

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            Schema::create('attendance_logs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('employee_id')->index();
                $table->string('employee_code', 50)->index();
                $table->string('action', 20);
                $table->string('source', 20)->default('API');
                $table->string('device_id', 100)->nullable();
                $table->string('location_code', 50)->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->jsonb('metadata')->nullable();
                $table->string('status', 20)->default('PENDING')->index();
                $table->timestampTz('checked_at')->useCurrent()->index();
                $table->timestampTz('processed_at')->nullable();
                $table->text('error_message')->nullable();
                $table->timestampsTz();
            });

            return;
        }

        // ── Bước 1: Tạo bảng cha (parent table) với PARTITION BY RANGE ──────
        // Không thể dùng Blueprint vì Laravel chưa hỗ trợ partition syntax.
        // checked_at là cột phân mảnh (partition key).
        DB::statement("
            CREATE TABLE IF NOT EXISTS attendance_logs (
                id              BIGSERIAL       NOT NULL,
                employee_id     BIGINT          NOT NULL,
                employee_code   VARCHAR(50)     NOT NULL,  -- Denormalized để tránh JOIN khi query
                action          VARCHAR(20)     NOT NULL   CHECK (action IN ('CHECK_IN', 'CHECK_OUT')),
                source          VARCHAR(20)     NOT NULL   DEFAULT 'API'
                                                           CHECK (source IN ('API', 'FACE_ID', 'QR_CODE', 'MANUAL')),
                device_id       VARCHAR(100),              -- ID thiết bị chấm công
                location_code   VARCHAR(50),               -- Mã địa điểm (văn phòng, chi nhánh)
                ip_address      INET,                      -- Kiểu INET của PG để validate IP tự động
                metadata        JSONB,                     -- Dữ liệu bổ sung (GPS, ảnh thumbnail...)
                status          VARCHAR(20)     NOT NULL   DEFAULT 'PENDING'
                                                           CHECK (status IN ('PENDING', 'PROCESSED', 'FAILED', 'DUPLICATE')),
                checked_at      TIMESTAMPTZ     NOT NULL   DEFAULT NOW(),  -- Partition key, lưu timezone
                processed_at    TIMESTAMPTZ,               -- Thời điểm worker xử lý xong
                error_message   TEXT,
                created_at      TIMESTAMPTZ     NOT NULL   DEFAULT NOW(),
                PRIMARY KEY (id, checked_at)               -- Partition key PHẢI nằm trong PRIMARY KEY
            ) PARTITION BY RANGE (checked_at)
        ");

        // ── Bước 2: Tạo các partition con cho năm 2026 ──────────────────────
        // Mỗi partition là một tháng, khoảng [start, end) - end KHÔNG bao gồm.
        $this->createMonthlyPartitions(2026, 1, 12);

        // ── Bước 3: Tạo partition DEFAULT để chứa dữ liệu ngoài range ───────
        // Tránh lỗi "no partition of relation found" khi insert tháng chưa có partition.
        DB::statement('
            CREATE TABLE IF NOT EXISTS attendance_logs_default
                PARTITION OF attendance_logs DEFAULT
        ');

        // ── Bước 4: Tạo các Index ────────────────────────────────────────────

        // Index chính: tìm log theo nhân viên trong khoảng thời gian
        // (Partial index: chỉ index các record PENDING - tập nhỏ, cần xử lý nhanh)
        DB::statement('
            CREATE INDEX IF NOT EXISTS idx_attendance_logs_employee_date
                ON attendance_logs (employee_id, checked_at DESC)
        ');

        // Partial index: worker chỉ cần query các bản ghi PENDING
        // Partial index nhỏ hơn full index, query nhanh hơn đáng kể
        DB::statement("
            CREATE INDEX IF NOT EXISTS idx_attendance_logs_pending
                ON attendance_logs (checked_at ASC)
                WHERE status = 'PENDING'
        ");

        // BRIN index cho checked_at: phù hợp với dữ liệu insert theo thứ tự thời gian.
        // Chiếm ít bộ nhớ hơn B-Tree ~200x, hiệu quả cho range scan trên dữ liệu lớn.
        DB::statement('
            CREATE INDEX IF NOT EXISTS idx_attendance_logs_brin_checked_at
                ON attendance_logs USING BRIN (checked_at)
                WITH (pages_per_range = 32)
        ');

        // Index cho employee_code (denormalized) - tìm kiếm không cần JOIN
        DB::statement('
            CREATE INDEX IF NOT EXISTS idx_attendance_logs_employee_code
                ON attendance_logs (employee_code, checked_at DESC)
        ');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            Schema::dropIfExists('attendance_logs');

            return;
        }
        // DROP CASCADE sẽ xóa cả parent lẫn tất cả child partitions
        DB::statement('DROP TABLE IF EXISTS attendance_logs CASCADE');
    }

    /**
     * Tạo các partition con theo tháng cho một năm cụ thể.
     * Mỗi partition được đặt tên theo pattern: attendance_logs_YYYY_MM
     */
    private function createMonthlyPartitions(int $year, int $startMonth, int $endMonth): void
    {
        for ($month = $startMonth; $month <= $endMonth; $month++) {
            $partitionName = sprintf('attendance_logs_%04d_%02d', $year, $month);
            $startDate = sprintf('%04d-%02d-01', $year, $month);

            // Tính tháng tiếp theo (handle rollover sang năm mới)
            $nextYear = $month === 12 ? $year + 1 : $year;
            $nextMonth = $month === 12 ? 1 : $month + 1;
            $endDate = sprintf('%04d-%02d-01', $nextYear, $nextMonth);

            DB::statement("
                CREATE TABLE IF NOT EXISTS {$partitionName}
                    PARTITION OF attendance_logs
                    FOR VALUES FROM ('{$startDate}') TO ('{$endDate}')
            ");
        }
    }
};
