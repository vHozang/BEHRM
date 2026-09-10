<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE piece_rate_entries DROP CONSTRAINT IF EXISTS piece_rate_entries_employee_id_foreign');
        DB::statement('ALTER TABLE piece_rate_entries ADD CONSTRAINT piece_rate_entries_employee_id_foreign FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE RESTRICT');
    }

    public function down(): void
    {
        // Never restore a cascading delete on payroll history.
    }
};
