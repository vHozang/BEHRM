<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AttendanceDuplicatePreflightCommand extends Command
{
    protected $signature = 'attendance:preflight-unique {--output= : Relative path on the private local disk}';

    protected $description = 'Abort safely when duplicate attendance employee/day rows exist';

    public function handle(): int
    {
        $duplicates = DB::table('attendances')
            ->select(['tenant_id', 'employee_id', 'work_date'])
            ->selectRaw('COUNT(*) AS duplicate_count')
            ->groupBy('tenant_id', 'employee_id', 'work_date')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('tenant_id')
            ->orderBy('employee_id')
            ->orderBy('work_date')
            ->get();

        if ($duplicates->isEmpty()) {
            $this->info('Attendance unique preflight: PASS (không có nhân viên/ngày bị trùng).');

            return self::SUCCESS;
        }

        $relativePath = (string) ($this->option('output') ?: 'attendance-preflight/duplicates-'.now()->format('Ymd-His').'.csv');
        Storage::disk('local')->makeDirectory(dirname($relativePath));
        $path = Storage::disk('local')->path($relativePath);
        $stream = fopen($path, 'wb');
        if ($stream === false) {
            $this->error('Không thể tạo báo cáo attendance trùng.');

            return self::FAILURE;
        }

        fputcsv($stream, ['tenant_id', 'employee_id', 'employee_code', 'full_name', 'work_date', 'duplicate_count', 'attendance_ids']);
        foreach ($duplicates as $duplicate) {
            $employee = DB::table('employees')
                ->where('tenant_id', $duplicate->tenant_id)
                ->where('id', $duplicate->employee_id)
                ->first(['employee_code', 'full_name']);
            $ids = DB::table('attendances')
                ->where('tenant_id', $duplicate->tenant_id)
                ->where('employee_id', $duplicate->employee_id)
                ->where('work_date', $duplicate->work_date)
                ->orderBy('id')
                ->pluck('id')
                ->implode('|');
            fputcsv($stream, [
                $duplicate->tenant_id,
                $duplicate->employee_id,
                $employee?->employee_code,
                $employee?->full_name,
                $duplicate->work_date,
                $duplicate->duplicate_count,
                $ids,
            ]);
        }
        fclose($stream);

        $this->error('Attendance unique preflight: FAIL. Deploy bị dừng để không tự gộp/xóa dữ liệu.');
        $this->line('Báo cáo: '.$path);

        return self::FAILURE;
    }
}
