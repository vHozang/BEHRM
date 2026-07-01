<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Artisan Command: Tự động tạo partition cho tháng tiếp theo.
 *
 * Lên lịch chạy mỗi tháng (cron: 0 0 1 * *):
 *   $schedule->command('attendance:create-next-partition')->monthlyOn(1, '00:00');
 *
 * Tại sao cần automation này?
 *  - Declarative Partitioning của PG yêu cầu tạo partition trước khi INSERT.
 *  - Nếu không có partition cho tháng hiện tại → INSERT sẽ vào partition DEFAULT.
 *  - Tạo partition DEFAULT là fallback, nhưng nên có partition đúng để tối ưu query.
 *
 * Chạy thủ công:
 *   php artisan attendance:create-next-partition
 *   php artisan attendance:create-next-partition --year=2027 --month=3
 */
class CreateAttendancePartitionCommand extends Command
{
    protected $signature = 'attendance:create-next-partition
                            {--year= : Năm cần tạo partition (mặc định: năm tới)}
                            {--month= : Tháng cần tạo partition (mặc định: tháng tới)}
                            {--year-ahead : Tạo partition cho toàn bộ năm tiếp theo}';

    protected $description = 'Tạo partition mới cho bảng attendance_logs';

    public function handle(): int
    {
        if ($this->option('year-ahead')) {
            return $this->createFullYearPartitions();
        }

        $targetDate = now()->addMonth();
        $year = (int) ($this->option('year') ?? $targetDate->year);
        $month = (int) ($this->option('month') ?? $targetDate->month);

        return $this->createPartition($year, $month) ? self::SUCCESS : self::FAILURE;
    }

    private function createPartition(int $year, int $month): bool
    {
        $partitionName = sprintf('attendance_logs_%04d_%02d', $year, $month);
        $startDate = sprintf('%04d-%02d-01', $year, $month);

        $nextYear = $month === 12 ? $year + 1 : $year;
        $nextMonth = $month === 12 ? 1 : $month + 1;
        $endDate = sprintf('%04d-%02d-01', $nextYear, $nextMonth);

        // Kiểm tra partition đã tồn tại chưa bằng cách query pg_class
        $exists = DB::selectOne("
            SELECT 1 FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE c.relname = ? AND n.nspname = 'public'
        ", [$partitionName]);

        if ($exists) {
            $this->info("Partition {$partitionName} đã tồn tại, bỏ qua.");

            return true;
        }

        try {
            DB::statement("
                CREATE TABLE IF NOT EXISTS {$partitionName}
                    PARTITION OF attendance_logs
                    FOR VALUES FROM ('{$startDate}') TO ('{$endDate}')
            ");

            $this->info("✅ Đã tạo partition: {$partitionName} ({$startDate} → {$endDate})");

            Log::info("Attendance partition created: {$partitionName}");

            return true;
        } catch (\Throwable $e) {
            $this->error("❌ Lỗi tạo partition {$partitionName}: ".$e->getMessage());
            Log::error('Failed to create attendance partition', [
                'partition' => $partitionName,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function createFullYearPartitions(): int
    {
        $nextYear = now()->year + 1;
        $this->info("Tạo 12 partitions cho năm {$nextYear}...");

        $allSuccess = true;
        for ($month = 1; $month <= 12; $month++) {
            if (! $this->createPartition($nextYear, $month)) {
                $allSuccess = false;
            }
        }

        return $allSuccess ? self::SUCCESS : self::FAILURE;
    }
}
