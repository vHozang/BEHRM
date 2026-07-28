<?php

namespace Tests\Feature;

use Database\Seeders\DemoCoverageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DemoCoverageSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_empty_demo_modules_idempotently(): void
    {
        $positionId = DB::table('positions')->insertGetId([
            'tenant_id' => 1,
            'position_code' => 'CV',
            'position_name' => 'Chuyên viên',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $recruitmentPositionId = DB::table('recruitment_positions')->insertGetId([
            'tenant_id' => 1,
            'position_name' => 'Chuyên viên nhân sự',
            'status' => 'OPEN',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $shiftIds = collect(['HC', 'C2'])->map(fn ($code) => DB::table('shift_types')->insertGetId([
            'tenant_id' => 1,
            'shift_code' => $code,
            'shift_name' => "Ca {$code}",
            'start_time' => $code === 'HC' ? '08:00:00' : '14:00:00',
            'end_time' => $code === 'HC' ? '17:00:00' : '22:00:00',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        foreach (range(1, 8) as $index) {
            $employeeId = DB::table('employees')->insertGetId([
                'tenant_id' => 1,
                'legal_entity_id' => 1,
                'employee_code' => 'TS'.str_pad((string) $index, 4, '0', STR_PAD_LEFT),
                'full_name' => "Nhân viên {$index}",
                'status' => 'ACTIVE',
                'position_id' => $positionId,
                'profile' => json_encode(['system_account' => false]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('shift_assignments')->insert([
                'tenant_id' => 1,
                'legal_entity_id' => 1,
                'employee_id' => $employeeId,
                'shift_type_id' => $shiftIds[$index % 2],
                'effective_date' => now()->subMonth()->toDateString(),
                'is_permanent' => true,
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach (range(1, 4) as $index) {
            DB::table('recruitment_candidates')->insert([
                'tenant_id' => 1,
                'recruitment_position_id' => $recruitmentPositionId,
                'full_name' => "Ứng viên {$index}",
                'email' => "ungvien{$index}@example.com",
                'application_status' => 'SCREENING',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $tables = ['assets', 'interview_schedules', 'policies', 'service_tickets', 'shift_swaps'];
        $this->seed(DemoCoverageSeeder::class);

        foreach ($tables as $table) {
            $this->assertGreaterThanOrEqual(4, DB::table($table)->where('tenant_id', 1)->count(), "{$table} phải có dữ liệu demo");
        }

        $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()]);
        $this->seed(DemoCoverageSeeder::class);

        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), "{$table} không được nhân đôi");
        }
    }
}
