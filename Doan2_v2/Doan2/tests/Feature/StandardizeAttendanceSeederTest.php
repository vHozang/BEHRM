<?php

namespace Tests\Feature;

use Database\Seeders\StandardizeAttendanceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StandardizeAttendanceSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_fills_only_missing_workdays_and_excludes_system_accounts(): void
    {
        DB::table('shift_types')->insert([
            'tenant_id' => 1,
            'shift_code' => 'HC',
            'shift_name' => 'Hành chính',
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $employeeId = DB::table('employees')->insertGetId([
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'employee_code' => 'ATT0001',
            'full_name' => 'Attendance Test',
            'status' => 'ACTIVE',
            'hire_date' => now()->subYear()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $systemId = DB::table('employees')->insertGetId([
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'employee_code' => 'AD0001',
            'full_name' => 'System Administrator',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sentinelId = DB::table('attendances')->insertGetId([
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'employee_id' => $employeeId,
            'work_date' => now()->subMonth()->startOfMonth()->toDateString(),
            'check_in_time' => '07:07:00',
            'status' => 'ON_TIME',
            'meta' => json_encode(['source' => 'real-test']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $legacyId = DB::table('attendances')->insertGetId([
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'employee_id' => $employeeId,
            'work_date' => null,
            'status' => 'ĐÃ_DUYỆT',
            'meta' => json_encode(['attendance_date' => '2024-03-01']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('attendances')->insert([
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'employee_id' => $systemId,
            'work_date' => now()->toDateString(),
            'status' => 'ON_TIME',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new StandardizeAttendanceSeeder)->run();

        $this->assertSame('07:07:00', DB::table('attendances')->where('id', $sentinelId)->value('check_in_time'));
        $this->assertSame('2024-03-01', DB::table('attendances')->where('id', $legacyId)->value('work_date'));
        $this->assertSame(0, DB::table('attendances')->where('employee_id', $systemId)->count());

        $count = DB::table('attendances')->where('employee_id', $employeeId)->count();
        (new StandardizeAttendanceSeeder)->run();

        $this->assertSame($count, DB::table('attendances')->where('employee_id', $employeeId)->count());
        $this->assertFalse(DB::table('attendances')->where('employee_id', $employeeId)
            ->whereNotNull('work_date')->groupBy('work_date')->havingRaw('COUNT(*) > 1')->exists());
    }
}
