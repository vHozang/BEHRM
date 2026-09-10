<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $duplicate = DB::table('attendances')
            ->selectRaw('tenant_id, employee_id, work_date, COUNT(*) AS duplicate_count')
            ->groupBy('tenant_id', 'employee_id', 'work_date')
            ->havingRaw('COUNT(*) > 1')
            ->first();
        if ($duplicate) {
            throw new RuntimeException(
                'Không thể tạo unique attendance: còn dữ liệu trùng. Chạy php artisan attendance:preflight-unique để xuất báo cáo.'
            );
        }

        $concurrently = DB::getDriverName() === 'pgsql' ? ' CONCURRENTLY' : '';
        DB::statement(
            "CREATE UNIQUE INDEX{$concurrently} IF NOT EXISTS attendances_tenant_employee_work_date_unique "
            .'ON attendances (tenant_id, employee_id, work_date)'
        );
    }

    public function down(): void
    {
        $concurrently = DB::getDriverName() === 'pgsql' ? ' CONCURRENTLY' : '';
        DB::statement("DROP INDEX{$concurrently} IF EXISTS attendances_tenant_employee_work_date_unique");
    }
};
