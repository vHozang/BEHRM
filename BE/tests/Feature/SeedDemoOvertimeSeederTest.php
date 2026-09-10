<?php

namespace Tests\Feature;

use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\SeedDemoOvertimeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SeedDemoOvertimeSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_cap_safe_overtime_without_touching_real_rows_or_duplicating(): void
    {
        CarbonImmutable::setTestNow('2026-07-16 09:00:00');
        TenantContext::clear();

        $employeeIds = [];
        foreach (range(1, 4) as $index) {
            $employeeIds[] = DB::table('employees')->insertGetId([
                'tenant_id' => 1,
                'legal_entity_id' => 1,
                'employee_code' => sprintf('OT%04d', $index),
                'full_name' => "Overtime {$index}",
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $systemId = DB::table('employees')->insertGetId([
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'employee_code' => 'AD0001',
            'full_name' => 'System Administrator',
            'status' => 'ACTIVE',
            'profile' => json_encode(['system_account' => true]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([...$employeeIds, $systemId] as $employeeId) {
            for ($date = CarbonImmutable::parse('2026-05-01'); $date->lte(CarbonImmutable::now()); $date = $date->addDay()) {
                if ($date->isSunday()) {
                    continue;
                }
                DB::table('attendances')->insert([
                    'tenant_id' => 1,
                    'legal_entity_id' => 1,
                    'employee_id' => $employeeId,
                    'work_date' => $date->toDateString(),
                    'check_in_time' => '08:00:00',
                    'check_out_time' => '17:00:00',
                    'status' => 'ON_TIME',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $realId = DB::table('overtime_requests')->insertGetId([
            'tenant_id' => 1,
            'employee_id' => $employeeIds[0],
            'work_date' => '2026-05-04',
            'start_time' => '17:00:00',
            'end_time' => '18:00:00',
            'total_hours' => 1,
            'status' => 'APPROVED',
            'meta' => json_encode(['source' => 'real-test']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->seed(SeedDemoOvertimeSeeder::class);

        $demoCount = DB::table('overtime_requests')->where('meta->source', 'demo-ot')->count();
        $this->assertGreaterThan(0, $demoCount);
        $this->assertTrue(DB::table('overtime_requests')->where('id', $realId)->exists());
        $this->assertFalse(DB::table('overtime_requests')->where('employee_id', $systemId)->exists());
        $this->assertFalse(DB::table('overtime_requests')->where('work_date', '>', '2026-07-16')->exists());
        $this->assertLessThanOrEqual(4.0, (float) DB::table('overtime_requests')
            ->selectRaw('employee_id, work_date, SUM(total_hours) AS hours')
            ->groupBy('employee_id', 'work_date')
            ->get()
            ->max('hours'));

        $this->seed(SeedDemoOvertimeSeeder::class);

        $this->assertSame($demoCount, DB::table('overtime_requests')->where('meta->source', 'demo-ot')->count());
        $this->assertFalse(DB::table('overtime_requests')
            ->selectRaw('employee_id, work_date, start_time, end_time, COUNT(*) AS rows_count')
            ->groupBy('employee_id', 'work_date', 'start_time', 'end_time')
            ->havingRaw('COUNT(*) > 1')
            ->exists());
        $this->assertFalse(TenantContext::hasTenant());

        CarbonImmutable::setTestNow();
    }
}
