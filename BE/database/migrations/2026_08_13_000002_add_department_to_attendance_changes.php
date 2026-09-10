<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_change_events', function (Blueprint $table): void {
            $table->unsignedBigInteger('attendance_id')->nullable()->change();
            $table->unsignedBigInteger('employee_id')->nullable()->change();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->json('audience_department_ids')->nullable();
            $table->json('audience_employee_ids')->nullable();
            $table->index(['tenant_id', 'department_id', 'id'], 'attendance_changes_department_cursor_index');
        });

        $cutoff = now()->subDays(7);
        DB::table('attendance_change_events')
            ->whereNull('department_id')
            ->where('created_at', '>=', $cutoff)
            ->whereExists(function ($query): void {
                $query->selectRaw('1')->from('employees as e')
                    ->whereColumn('e.id', 'attendance_change_events.employee_id')
                    ->whereColumn('e.tenant_id', 'attendance_change_events.tenant_id')
                    ->whereNotNull('e.department_id');
            })
            ->update([
                'department_id' => DB::raw('(SELECT e.department_id FROM employees e WHERE e.id = attendance_change_events.employee_id AND e.tenant_id = attendance_change_events.tenant_id LIMIT 1)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('attendance_change_events', function (Blueprint $table): void {
            $table->dropIndex('attendance_changes_department_cursor_index');
            $table->dropColumn(['department_id', 'audience_department_ids', 'audience_employee_ids']);
            $table->unsignedBigInteger('attendance_id')->nullable(false)->change();
            $table->unsignedBigInteger('employee_id')->nullable(false)->change();
        });
    }
};
