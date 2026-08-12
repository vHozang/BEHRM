<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach ($this->postgresIndexes() as $sql) {
                DB::statement($sql);
            }

            return;
        }

        DB::statement('CREATE INDEX IF NOT EXISTS attendances_tenant_date_id_index ON attendances (tenant_id, work_date DESC, id DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS attendances_tenant_employee_date_id_index ON attendances (tenant_id, employee_id, work_date DESC, id DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS attendances_tenant_status_date_id_index ON attendances (tenant_id, status, work_date DESC, id DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS attendances_tenant_updated_id_index ON attendances (tenant_id, updated_at, id)');
    }

    public function down(): void
    {
        $concurrently = DB::getDriverName() === 'pgsql' ? ' CONCURRENTLY' : '';
        foreach ([
            'attendances_tenant_date_id_index',
            'attendances_tenant_employee_date_id_index',
            'attendances_tenant_status_date_id_index',
            'attendances_tenant_updated_id_index',
            'attendances_needs_review_index',
        ] as $index) {
            DB::statement("DROP INDEX{$concurrently} IF EXISTS {$index}");
        }
    }

    /** @return array<int, string> */
    private function postgresIndexes(): array
    {
        return [
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS attendances_tenant_date_id_index ON attendances (tenant_id, work_date DESC, id DESC)',
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS attendances_tenant_employee_date_id_index ON attendances (tenant_id, employee_id, work_date DESC, id DESC)',
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS attendances_tenant_status_date_id_index ON attendances (tenant_id, status, work_date DESC, id DESC)',
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS attendances_tenant_updated_id_index ON attendances (tenant_id, updated_at, id)',
            "CREATE INDEX CONCURRENTLY IF NOT EXISTS attendances_needs_review_index ON attendances (tenant_id, work_date DESC, id DESC) WHERE meta->>'review_status' = 'needs_review'",
        ];
    }
};
