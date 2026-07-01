<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DashboardDemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->syncContractDatesFromMeta();
        $this->assignDemoDepartments();
        $this->assignDemoPositions();
        $this->normalizeLeaveStatuses();
        $this->seedRecruitmentDemo();
        $this->seedRecentAttendance();
    }

    private function syncContractDatesFromMeta(): void
    {
        DB::statement(<<<'SQL'
            UPDATE contracts
            SET start_date = COALESCE(start_date, NULLIF(meta->>'effective_date', '')::date),
                end_date = COALESCE(end_date, NULLIF(meta->>'expiry_date', '')::date),
                updated_at = NOW()
            WHERE meta IS NOT NULL
              AND (
                (start_date IS NULL AND NULLIF(meta->>'effective_date', '') IS NOT NULL)
                OR (end_date IS NULL AND NULLIF(meta->>'expiry_date', '') IS NOT NULL)
              )
        SQL);
    }

    private function assignDemoDepartments(): void
    {
        DB::statement(<<<'SQL'
            UPDATE employees e
            SET department_id = c.department_id,
                updated_at = NOW()
            FROM contracts c
            WHERE c.employee_id = e.id
              AND e.department_id IS NULL
              AND c.department_id IS NOT NULL
              AND (c.status IS NULL OR c.status IN ('ACTIVE', 'CÓ_HIỆU_LỰC', 'ĐANG_HIỆU_LỰC'))
        SQL);

        $departmentIds = DB::table('departments')->orderBy('id')->limit(8)->pluck('id')->all();

        if ($departmentIds === []) {
            return;
        }

        $unassignedEmployees = DB::table('employees')
            ->whereNull('department_id')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        foreach ($unassignedEmployees as $index => $employeeId) {
            DB::table('employees')->where('id', $employeeId)->update([
                'department_id' => $departmentIds[$index % count($departmentIds)],
                'updated_at' => now(),
            ]);
        }
    }

    private function assignDemoPositions(): void
    {
        DB::statement(<<<'SQL'
            UPDATE employees e
            SET position_id = c.position_id,
                updated_at = NOW()
            FROM contracts c
            WHERE c.employee_id = e.id
              AND e.position_id IS NULL
              AND c.position_id IS NOT NULL
              AND (c.status IS NULL OR c.status IN ('ACTIVE', 'CÓ_HIỆU_LỰC', 'ĐANG_HIỆU_LỰC'))
        SQL);

        $positionIds = DB::table('positions')->orderBy('id')->pluck('id')->all();

        if ($positionIds === []) {
            return;
        }

        $missingPositionEmployees = DB::table('employees')
            ->whereNull('position_id')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        foreach ($missingPositionEmployees as $index => $employeeId) {
            DB::table('employees')->where('id', $employeeId)->update([
                'position_id' => $positionIds[$index % count($positionIds)],
                'updated_at' => now(),
            ]);
        }
    }

    private function normalizeLeaveStatuses(): void
    {
        $statuses = ['PENDING', 'APPROVED', 'APPROVED', 'REJECTED', 'PENDING'];

        $leaveIds = DB::table('leave_requests')
            ->orderBy('id')
            ->limit(count($statuses))
            ->pluck('id')
            ->all();

        foreach ($leaveIds as $index => $leaveId) {
            DB::table('leave_requests')->where('id', $leaveId)->update([
                'status' => $statuses[$index],
                'updated_at' => now(),
            ]);
        }
    }

    private function seedRecruitmentDemo(): void
    {
        $departments = DB::table('departments')->orderBy('id')->limit(3)->pluck('id')->all();

        if ($departments === []) {
            return;
        }

        $positions = [
            [
                'legacy_id' => 910001,
                'position_name' => 'AI HR Analyst',
                'department_id' => $departments[0],
                'employment_type' => 'FULL_TIME',
                'status' => 'OPEN',
                'required_skills_json' => json_encode(['HR analytics', 'SQL', 'Dashboard']),
            ],
            [
                'legacy_id' => 910002,
                'position_name' => 'Talent Acquisition Specialist',
                'department_id' => $departments[1] ?? $departments[0],
                'employment_type' => 'FULL_TIME',
                'status' => 'OPEN',
                'required_skills_json' => json_encode(['Recruitment', 'Interviewing', 'Communication']),
            ],
            [
                'legacy_id' => 910003,
                'position_name' => 'Payroll Operations Executive',
                'department_id' => $departments[2] ?? $departments[0],
                'employment_type' => 'FULL_TIME',
                'status' => 'OPEN',
                'required_skills_json' => json_encode(['Payroll', 'Excel', 'Compliance']),
            ],
        ];

        foreach ($positions as $position) {
            DB::table('recruitment_positions')->updateOrInsert(
                ['legacy_id' => $position['legacy_id']],
                $position + [
                    'tenant_id' => 1,
                    'meta' => json_encode(['source' => 'dashboard-demo']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }

        $positionIds = DB::table('recruitment_positions')
            ->whereIn('legacy_id', array_column($positions, 'legacy_id'))
            ->pluck('id', 'legacy_id');

        $candidates = [
            [920001, $positionIds[910001] ?? null, 'Nguyễn Minh Anh', 'minh.anh.demo@example.com', 'PENDING', 72],
            [920002, $positionIds[910001] ?? null, 'Trần Quốc Bảo', 'quoc.bao.demo@example.com', 'SCREENING', 81],
            [920003, $positionIds[910002] ?? null, 'Lê Thu Hà', 'thu.ha.demo@example.com', 'INTERVIEWING', 88],
            [920004, $positionIds[910002] ?? null, 'Phạm Gia Huy', 'gia.huy.demo@example.com', 'OFFERED', 76],
            [920005, $positionIds[910003] ?? null, 'Đỗ Khánh Linh', 'khanh.linh.demo@example.com', 'HIRED', 91],
            [920006, $positionIds[910003] ?? null, 'Vũ Nhật Nam', 'nhat.nam.demo@example.com', 'REJECTED', 54],
        ];

        foreach ($candidates as [$legacyId, $positionId, $name, $email, $status, $score]) {
            DB::table('recruitment_candidates')->updateOrInsert(
                ['legacy_id' => $legacyId],
                [
                    'tenant_id' => 1,
                    'recruitment_position_id' => $positionId,
                    'full_name' => $name,
                    'email' => $email,
                    'phone_number' => '09' . substr((string) $legacyId, -8),
                    'application_status' => $status,
                    'ai_score' => $score,
                    'ai_scoring_status' => 'DONE',
                    'ai_scoring_error' => null,
                    'ai_scored_at' => now(),
                    'ai_matched_skills_json' => json_encode(['Communication', 'HRM']),
                    'ai_missing_skills_json' => json_encode(['Advanced payroll']),
                    'meta' => json_encode(['source' => 'dashboard-demo', 'ai_score' => $score]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    private function seedRecentAttendance(): void
    {
        $employeeIds = DB::table('employees')->orderBy('id')->limit(3)->pluck('id')->all();

        if (count($employeeIds) < 3) {
            return;
        }

        for ($dayOffset = 29; $dayOffset >= 0; $dayOffset--) {
            $date = now()->subDays($dayOffset)->toDateString();
            $statuses = ['ON_TIME', $dayOffset % 4 === 0 ? 'LATE' : 'ON_TIME', $dayOffset % 6 === 0 ? 'ABSENT' : 'ON_TIME'];

            foreach ($employeeIds as $index => $employeeId) {
                $status = $statuses[$index];
                $legacyId = 930000 + ($dayOffset * 10) + $index;

                DB::table('attendances')->updateOrInsert(
                    ['legacy_id' => $legacyId],
                    [
                        'tenant_id' => 1,
                        'legal_entity_id' => 1,
                        'employee_id' => $employeeId,
                        'shift_type_id' => null,
                        'work_date' => $date,
                        'check_in_time' => $status === 'ABSENT' ? null : ($status === 'LATE' ? '08:45:00' : '08:00:00'),
                        'check_out_time' => $status === 'ABSENT' ? null : '17:30:00',
                        'status' => $status,
                        'meta' => json_encode(['source' => 'dashboard-demo']),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }
        }
    }
}
