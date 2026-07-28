<?php

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Bổ sung dữ liệu demo cho các màn hình còn trống.
 *
 * Mọi bản ghi do seeder quản lý đều có meta.source/demo_key ổn định hoặc một
 * khóa tự nhiên, nên chạy lại chỉ cập nhật dữ liệu demo và không nhân bản dòng.
 */
class DemoCoverageSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(CreateAttendanceDevicesTableSeeder::class);
        $this->call(CreateShiftCoverageTablesSeeder::class);

        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            DB::transaction(fn () => $this->seedTenant((int) $tenantId));
        }
    }

    private function seedTenant(int $tenantId): void
    {
        $entities = $this->seedLegalEntities($tenantId);
        $families = $this->seedJobFamilies($tenantId);
        $branchEmployees = $this->seedBranchEmployees($tenantId, $entities, $families);

        $this->syncDepartmentMemberships($tenantId, $branchEmployees);
        $this->seedRolePermissions($tenantId);
        $this->seedInterviews($tenantId);
        $this->seedProfileChanges($tenantId);
        $this->seedAttendanceAdjustments($tenantId);
        $this->seedShiftSwaps($tenantId);
        $this->seedPolicies($tenantId);
        $this->seedAssets($tenantId, $entities);
        $this->seedServiceTickets($tenantId);
        $this->seedAttendanceDevices($tenantId, $entities);
        $this->seedShiftCoverage($tenantId, $entities);
        $this->seedPayrollAdjustments($tenantId);

        $this->command?->info("Tenant {$tenantId}: đã bổ sung dữ liệu cho các màn hình demo còn trống.");
    }

    /** @return array{hq:int,hn:int,dn:int} */
    private function seedLegalEntities(int $tenantId): array
    {
        $primary = DB::table('legal_entities')->where('tenant_id', $tenantId)->orderBy('id')->first();

        if (! $primary) {
            $hqId = $this->demoId('legal_entities', $tenantId, 'entity-hq', [
                'name' => 'Công ty Cổ phần Codedengú Việt Nam',
                'code' => 'CDN-HQ',
                'tax_code' => '0312345678',
                'address' => '123 Nguyễn Huệ, Phường Sài Gòn, TP. Hồ Chí Minh',
                'status' => 'ACTIVE',
                'meta' => [
                    'representative' => 'Nguyễn Văn An',
                    'representative_title' => 'Tổng Giám đốc',
                    'branch_type' => 'HEAD_OFFICE',
                ],
            ], ['code' => 'CDN-HQ']);
        } else {
            $meta = $this->decode($primary->meta ?? null);
            $isPlaceholder = in_array(strtoupper((string) $primary->code), ['', 'DEFAULT'], true)
                || trim((string) $primary->name) === 'Default Entity'
                || ($meta['demo_key'] ?? null) === 'entity-hq';

            $updates = [
                'tax_code' => $primary->tax_code ?: '0312345678',
                'address' => $primary->address ?: '123 Nguyễn Huệ, Phường Sài Gòn, TP. Hồ Chí Minh',
                'status' => 'ACTIVE',
                'updated_at' => now(),
            ];
            if ($isPlaceholder) {
                $updates['name'] = 'Công ty Cổ phần Codedengú Việt Nam';
                $updates['code'] = 'CDN-HQ';
            }
            $updates['meta'] = $this->json(array_merge([
                'source' => 'demo-coverage',
                'demo_key' => 'entity-hq',
                'representative' => 'Nguyễn Văn An',
                'representative_title' => 'Tổng Giám đốc',
                'branch_type' => 'HEAD_OFFICE',
            ], $meta));
            DB::table('legal_entities')->where('id', $primary->id)->update($updates);
            $hqId = (int) $primary->id;
        }

        $hnId = $this->demoId('legal_entities', $tenantId, 'entity-hn', [
            'name' => 'Chi nhánh Codedengú Hà Nội',
            'code' => 'CDN-HN',
            'tax_code' => '0312345678-001',
            'address' => '88 Trần Duy Hưng, Phường Yên Hòa, Hà Nội',
            'status' => 'ACTIVE',
            'meta' => [
                'representative' => 'Trần Thu Hà',
                'representative_title' => 'Giám đốc Chi nhánh',
                'branch_type' => 'BRANCH',
                'parent_entity_id' => $hqId,
            ],
        ], ['code' => 'CDN-HN']);

        $dnId = $this->demoId('legal_entities', $tenantId, 'entity-dn', [
            'name' => 'Chi nhánh Codedengú Đà Nẵng',
            'code' => 'CDN-DN',
            'tax_code' => '0312345678-002',
            'address' => '36 Bạch Đằng, Phường Hải Châu, Đà Nẵng',
            'status' => 'ACTIVE',
            'meta' => [
                'representative' => 'Lê Minh Khoa',
                'representative_title' => 'Giám đốc Chi nhánh',
                'branch_type' => 'BRANCH',
                'parent_entity_id' => $hqId,
            ],
        ], ['code' => 'CDN-DN']);

        return ['hq' => $hqId, 'hn' => $hnId, 'dn' => $dnId];
    }

    /** @return array<string,int> */
    private function seedJobFamilies(int $tenantId): array
    {
        $rows = [
            'EXEC' => ['Quản lý & Điều hành', 'Nhóm chức danh quản lý và lãnh đạo'],
            'TECH' => ['Công nghệ', 'Phát triển phần mềm và hạ tầng công nghệ'],
            'FIN' => ['Tài chính & Kế toán', 'Kế toán, kiểm toán và tài chính'],
            'HR' => ['Nhân sự & Hành chính', 'Nhân sự và vận hành văn phòng'],
            'OPS' => ['Vận hành', 'Các chức danh vận hành và hỗ trợ'],
        ];
        $ids = [];

        foreach ($rows as $code => [$name, $description]) {
            $ids[$code] = $this->demoId('job_families', $tenantId, "job-family-{$code}", [
                'code' => $code,
                'name' => $name,
                'description' => $description,
                'is_active' => true,
                'meta' => [],
            ], ['code' => $code]);
        }

        foreach ([
            'EXEC' => ['GD', 'PGD', 'TP', 'PP'],
            'TECH' => ['LD'],
            'FIN' => ['KTV', 'KTVC', 'KTTH'],
            'HR' => ['NS', 'HC'],
            'OPS' => ['CV', 'NV', 'TV', 'TL', 'BV'],
        ] as $family => $positionCodes) {
            DB::table('positions')->where('tenant_id', $tenantId)->whereIn('position_code', $positionCodes)
                ->update(['job_family_id' => $ids[$family], 'updated_at' => now()]);
        }

        return $ids;
    }

    /** @return array<int,int> map employee id => department id */
    private function seedBranchEmployees(int $tenantId, array $entities, array $families): array
    {
        $managerId = (int) DB::table('employees')->where('tenant_id', $tenantId)
            ->where(fn ($query) => $query->whereNull('profile->system_account')->orWhere('profile->system_account', false))
            ->orderBy('id')->value('id');
        $positionId = (int) (DB::table('positions')->where('tenant_id', $tenantId)->where('position_code', 'CV')->value('id')
            ?: DB::table('positions')->where('tenant_id', $tenantId)->min('id'));

        $branches = [
            [
                'key' => 'hn', 'department_code' => 'CNHN-OPS', 'department_name' => 'Vận hành Chi nhánh Hà Nội',
                'employee_code' => 'CNHN0001', 'full_name' => 'Trần Thu Hà', 'email' => 'ha.tran.hn@company.com',
                'dob' => '1990-04-12', 'gender' => 'FEMALE', 'hire_date' => '2026-01-05', 'salary' => 22000000,
                'phone' => '0904100101', 'bank' => 'Vietcombank', 'account' => '102600010001',
                'tax' => '0109001001', 'insurance' => 'HN010001', 'address' => 'Cầu Giấy, Hà Nội',
            ],
            [
                'key' => 'dn', 'department_code' => 'CNDN-OPS', 'department_name' => 'Vận hành Chi nhánh Đà Nẵng',
                'employee_code' => 'CNDN0001', 'full_name' => 'Lê Minh Khoa', 'email' => 'khoa.le.dn@company.com',
                'dob' => '1992-09-20', 'gender' => 'MALE', 'hire_date' => '2026-03-02', 'salary' => 20000000,
                'phone' => '0905100202', 'bank' => 'BIDV', 'account' => '561100020002',
                'tax' => '0409002002', 'insurance' => 'DN020002', 'address' => 'Hải Châu, Đà Nẵng',
            ],
        ];

        $employeeDepartments = [];
        foreach ($branches as $branch) {
            $entityId = $entities[$branch['key']];
            $departmentId = $this->demoId('departments', $tenantId, 'department-'.$branch['key'], [
                'department_code' => $branch['department_code'],
                'department_name' => $branch['department_name'],
                'status' => true,
                'description' => 'Đơn vị vận hành trực thuộc chi nhánh',
                'legal_entity_id' => $entityId,
                'meta' => ['branch_type' => 'BRANCH_DEPARTMENT'],
            ], ['department_code' => $branch['department_code']]);

            $employeeId = $this->upsertId('employees', [
                'tenant_id' => $tenantId,
                'employee_code' => $branch['employee_code'],
            ], [
                'full_name' => $branch['full_name'],
                'date_of_birth' => $branch['dob'],
                'gender' => $branch['gender'],
                'phone_number' => $branch['phone'],
                'personal_email' => strtolower($branch['employee_code']).'@gmail.com',
                'company_email' => $branch['email'],
                'status' => 'ACTIVE',
                'hire_date' => $branch['hire_date'],
                'department_id' => $departmentId,
                'position_id' => $positionId,
                'manager_id' => $managerId ?: null,
                'base_salary' => $branch['salary'],
                'skills' => $this->json(['Vận hành chi nhánh', 'Quản trị hồ sơ']),
                'profile' => $this->json([
                    'source' => 'demo-coverage',
                    'bank_name' => $branch['bank'],
                    'bank_account' => $branch['account'],
                    'tax_number' => $branch['tax'],
                    'insurance_number' => $branch['insurance'],
                    'id_number' => '0'.substr($branch['tax'], 0, 11),
                    'address' => $branch['address'],
                    'permanent_address' => $branch['address'],
                    'personal_phone' => $branch['phone'],
                    'personal_email' => strtolower($branch['employee_code']).'@gmail.com',
                    'education_level' => 'Đại học',
                    'nationality_name' => 'Việt Nam',
                    'system_account' => false,
                ]),
                'legal_entity_id' => $entityId,
                'is_super_admin' => false,
            ]);

            DB::table('departments')->where('id', $departmentId)->update([
                'meta' => $this->json([
                    'source' => 'demo-coverage', 'demo_key' => 'department-'.$branch['key'],
                    'branch_type' => 'BRANCH_DEPARTMENT', 'manager_id' => $employeeId,
                ]),
                'updated_at' => now(),
            ]);

            $this->demoId('contracts', $tenantId, 'contract-'.$branch['key'], [
                'employee_id' => $employeeId,
                'contract_type_id' => DB::table('contract_types')->where('tenant_id', $tenantId)->orderBy('id')->value('id'),
                'position_id' => $positionId,
                'department_id' => $departmentId,
                'contract_number' => 'HĐ/2026/'.strtoupper($branch['key']).'/001',
                'status' => 'CÓ_HIỆU_LỰC',
                'start_date' => $branch['hire_date'],
                'end_date' => null,
                'legal_entity_id' => $entityId,
                'meta' => [
                    'contract_code' => 'HD-'.strtoupper($branch['key']).'-001',
                    'job_title' => 'Chuyên viên vận hành chi nhánh',
                    'basic_salary' => $branch['salary'],
                    'gross_salary' => $branch['salary'] + 1500000,
                    'net_salary' => $branch['salary'] - 2000000,
                    'effective_date' => $branch['hire_date'],
                    'sign_date' => CarbonImmutable::parse($branch['hire_date'])->subDays(3)->toDateString(),
                    'work_location' => $branch['address'],
                ],
            ], ['contract_number' => 'HĐ/2026/'.strtoupper($branch['key']).'/001']);

            $roleId = DB::table('roles')->where('tenant_id', $tenantId)->where('role_code', 'EMPLOYEE')->value('id');
            if ($roleId) {
                DB::table('employee_roles')->updateOrInsert(
                    ['employee_id' => $employeeId, 'role_id' => $roleId],
                    ['tenant_id' => $tenantId, 'is_active' => DB::raw('true'), 'created_at' => now(), 'updated_at' => now()]
                );
            }
            $employeeDepartments[$employeeId] = $departmentId;
        }

        return $employeeDepartments;
    }

    private function syncDepartmentMemberships(int $tenantId, array $branchEmployees): void
    {
        $employees = DB::table('employees')->where('tenant_id', $tenantId)->whereNotNull('department_id')
            ->get(['id', 'department_id']);

        foreach ($employees as $employee) {
            DB::table('employee_departments')->updateOrInsert(
                ['employee_id' => $employee->id, 'department_id' => $employee->department_id],
                [
                    'tenant_id' => $tenantId,
                    'role_in_dept' => isset($branchEmployees[(int) $employee->id]) ? 'BRANCH_MANAGER' : 'MEMBER',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    private function seedRolePermissions(int $tenantId): void
    {
        $roles = DB::table('roles')->where('tenant_id', $tenantId)->pluck('id', 'role_code');
        $permissions = DB::table('permissions')->where('tenant_id', $tenantId)->pluck('id', 'permission_code');
        $grants = [
            'ADMIN' => $permissions->keys()->all(),
            'HR' => ['EMP_VIEW', 'EMP_CREATE', 'LEAVE_VIEW', 'LEAVE_CREATE', 'LEAVE_APPROVE', 'ATTENDANCE_VIEW'],
            'MANAGER' => ['EMP_VIEW', 'LEAVE_VIEW', 'LEAVE_APPROVE', 'ATTENDANCE_VIEW'],
            'ACCOUNTANT' => ['EMP_VIEW', 'SALARY_VIEW', 'ATTENDANCE_VIEW'],
            'EMPLOYEE' => ['LEAVE_VIEW', 'LEAVE_CREATE', 'ATTENDANCE_VIEW', 'SALARY_VIEW'],
        ];

        foreach ($grants as $roleCode => $permissionCodes) {
            if (! isset($roles[$roleCode])) {
                continue;
            }
            foreach ($permissionCodes as $permissionCode) {
                if (! isset($permissions[$permissionCode])) {
                    continue;
                }
                $this->demoId('role_permissions', $tenantId, "role-{$roleCode}-{$permissionCode}", [
                    'role_id' => $roles[$roleCode],
                    'permission_id' => $permissions[$permissionCode],
                    'meta' => [],
                ], ['role_id' => $roles[$roleCode], 'permission_id' => $permissions[$permissionCode]]);
            }
        }
    }

    private function seedInterviews(int $tenantId): void
    {
        $candidates = DB::table('recruitment_candidates as c')
            ->leftJoin('recruitment_positions as p', 'p.id', '=', 'c.recruitment_position_id')
            ->where('c.tenant_id', $tenantId)->orderBy('c.id')->limit(4)
            ->get(['c.id', 'c.full_name', 'c.application_status', 'p.position_name as position_title']);
        $interviewers = DB::table('employees')->where('tenant_id', $tenantId)
            ->where(fn ($query) => $query->whereNull('profile->system_account')->orWhere('profile->system_account', false))
            ->orderBy('id')->limit(3)->get(['id', 'full_name']);

        if ($candidates->isEmpty() || $interviewers->isEmpty()) {
            return;
        }

        $today = CarbonImmutable::today();
        foreach ($candidates as $index => $candidate) {
            $interviewer = $interviewers[$index % $interviewers->count()];
            $completed = in_array($candidate->application_status, ['OFFERED', 'HIRED', 'REJECTED'], true);
            $passed = in_array($candidate->application_status, ['OFFERED', 'HIRED'], true);
            $date = $completed ? $today->subDays(8 + $index) : $today->addDays(2 + $index);

            $this->demoId('interview_schedules', $tenantId, 'interview-'.$candidate->id, [
                'candidate_id' => $candidate->id,
                'interviewer_id' => $interviewer->id,
                'department_manager_id' => $interviewers->first()->id,
                'interview_date' => $date->toDateString(),
                'interview_time' => sprintf('%02d:00:00', 9 + $index),
                'interview_mode' => $index % 2 === 0 ? 'ONSITE' : 'ONLINE',
                'status' => $completed ? 'COMPLETED' : 'SCHEDULED',
                'result' => $completed ? ($passed ? 'PASSED' : 'FAILED') : null,
                'manager_decision' => $completed ? ($passed ? 'APPROVED' : 'REJECTED') : null,
                'meta' => [
                    'candidate_name' => $candidate->full_name,
                    'position_title' => $candidate->position_title,
                    'interviewer_name' => $interviewer->full_name,
                    'location' => $index % 2 === 0 ? 'Phòng họp A02' : 'https://meet.google.com/demo-hrm',
                    'result_note' => $completed ? ($passed ? 'Đáp ứng tốt yêu cầu chuyên môn và văn hóa.' : 'Chưa phù hợp yêu cầu chuyên môn hiện tại.') : null,
                ],
            ]);
        }
    }

    private function seedProfileChanges(int $tenantId): void
    {
        $employees = $this->realEmployees($tenantId, 4);
        $approverId = $employees->first()?->id;
        $statuses = ['PENDING', 'APPROVED', 'REJECTED', 'CANCELLED'];

        foreach ($employees as $index => $employee) {
            $profile = $this->decode($employee->profile);
            $status = $statuses[$index % count($statuses)];
            $current = $profile['bank_account'] ?? '';
            $newValue = $status === 'APPROVED' ? $current : '88880000'.str_pad((string) $employee->id, 4, '0', STR_PAD_LEFT);

            $this->demoId('profile_change_requests', $tenantId, 'profile-change-'.$employee->id, [
                'legal_entity_id' => $employee->legal_entity_id,
                'employee_id' => $employee->id,
                'changes' => $this->json([
                    'bank_account' => [
                        'label' => 'Số tài khoản ngân hàng',
                        'old' => $status === 'APPROVED' ? 'Tài khoản cũ' : $current,
                        'new' => $newValue,
                    ],
                ]),
                'reason' => 'Cập nhật thông tin nhận lương theo hồ sơ mới',
                'status' => $status,
                'approver_id' => in_array($status, ['APPROVED', 'REJECTED'], true) ? $approverId : null,
                'decided_at' => in_array($status, ['APPROVED', 'REJECTED'], true) ? now()->subDays(2) : null,
                'decision_comment' => $status === 'REJECTED' ? 'Thông tin xác minh chưa khớp chứng từ.' : null,
                'meta' => [],
            ]);
        }
    }

    private function seedAttendanceAdjustments(int $tenantId): void
    {
        $employees = $this->realEmployees($tenantId, 4);
        $statuses = ['PENDING', 'APPROVED', 'REJECTED', 'CANCELLED'];

        foreach ($employees as $index => $employee) {
            $attendance = DB::table('attendances')->where('tenant_id', $tenantId)
                ->where('employee_id', $employee->id)->whereNotNull('work_date')
                ->orderByDesc('work_date')->skip($index)->first();
            $date = $attendance?->work_date ?: CarbonImmutable::today()->subWeekdays(5 + $index)->toDateString();
            $status = $statuses[$index % count($statuses)];

            $this->demoId('attendance_adjustment_requests', $tenantId, 'attendance-adjustment-'.$employee->id, [
                'legal_entity_id' => $employee->legal_entity_id,
                'employee_id' => $employee->id,
                'attendance_id' => $attendance?->id,
                'work_date' => $date,
                'requested_check_in_time' => $status === 'APPROVED' && $attendance?->check_in_time ? $attendance->check_in_time : '08:00:00',
                'requested_check_out_time' => $status === 'APPROVED' && $attendance?->check_out_time ? $attendance->check_out_time : '17:00:00',
                'requested_status' => 'ON_TIME',
                'reason' => 'Quên chấm công do thiết bị mất kết nối',
                'status' => $status,
                'approver_id' => in_array($status, ['APPROVED', 'REJECTED'], true) ? $employees->first()->id : null,
                'decided_at' => in_array($status, ['APPROVED', 'REJECTED'], true) ? now()->subDay() : null,
                'decision_comment' => $status === 'REJECTED' ? 'Chưa đủ bằng chứng xác nhận.' : null,
                'meta' => ['evidence' => 'Biên bản xác nhận của quản lý trực tiếp'],
            ]);
        }
    }

    private function seedShiftSwaps(int $tenantId): void
    {
        $employees = $this->realEmployees($tenantId, 6);
        $shiftIds = DB::table('shift_types')->where('tenant_id', $tenantId)->orderBy('id')->pluck('id');
        if ($employees->count() < 4 || $shiftIds->isEmpty()) {
            return;
        }

        $statuses = ['PENDING', 'APPROVED', 'REJECTED', 'CANCELLED'];
        foreach ($statuses as $index => $status) {
            $requester = $employees[$index];
            $target = $employees[$index + 1];
            $date = CarbonImmutable::today()->addDays(4 + $index);
            $this->demoId('shift_swaps', $tenantId, 'shift-swap-'.$status, [
                'requester_id' => $requester->id,
                'target_employee_id' => $target->id,
                'original_shift_id' => $shiftIds[$index % $shiftIds->count()],
                'requested_shift_id' => $shiftIds[($index + 1) % $shiftIds->count()],
                'swap_date' => $date->toDateString(),
                'reason' => ['Khám bệnh định kỳ', 'Việc gia đình', 'Trùng lịch đào tạo', 'Không còn nhu cầu đổi ca'][$index],
                'approval_status' => $status,
                'approver_id' => in_array($status, ['APPROVED', 'REJECTED'], true) ? $employees->first()->id : null,
                'meta' => [
                    'status' => strtolower($status),
                    'from_date' => $date->toDateString(),
                    'to_date' => $date->toDateString(),
                    'requester_name' => $requester->full_name,
                    'target_name' => $target->full_name,
                ],
            ]);
        }
    }

    private function seedPolicies(int $tenantId): void
    {
        $rows = [
            ['POL-HR-001', 'Nội quy lao động', 'HR', '2.1', 'Quy định giờ làm việc, tác phong, bảo mật và kỷ luật lao động.'],
            ['POL-IT-001', 'Chính sách an toàn thông tin', 'IT', '1.4', 'Quy định sử dụng thiết bị, mật khẩu, dữ liệu và hệ thống nội bộ.'],
            ['POL-WF-001', 'Chính sách làm việc linh hoạt', 'WELFARE', '1.2', 'Điều kiện làm việc từ xa, đăng ký lịch và trách nhiệm phối hợp.'],
            ['POL-EXP-001', 'Quy chế công tác phí', 'FINANCE', '1.0', 'Định mức, chứng từ và quy trình thanh toán chi phí công tác.'],
        ];
        $employees = $this->realEmployees($tenantId, 8);

        foreach ($rows as $policyIndex => [$code, $name, $type, $version, $summary]) {
            $policyId = $this->demoId('policies', $tenantId, 'policy-'.$code, [
                'policy_code' => $code,
                'policy_name' => $name,
                'policy_type' => $type,
                'content' => $name."\n\n".$summary."\n\nToàn thể nhân viên có trách nhiệm đọc, xác nhận và tuân thủ chính sách này.",
                'status' => 'PUBLISHED',
                'effective_date' => CarbonImmutable::today()->subMonths(3 - $policyIndex)->startOfMonth()->toDateString(),
                'meta' => [
                    'title' => $name,
                    'version' => $version,
                    'description' => $summary,
                    'published_date' => CarbonImmutable::today()->subMonths(3 - $policyIndex)->startOfMonth()->toDateString(),
                ],
            ], ['policy_code' => $code]);

            $ackCount = min($employees->count(), 2 + $policyIndex * 2);
            foreach ($employees->take($ackCount) as $employee) {
                $this->demoId('policy_acknowledgments', $tenantId, "policy-ack-{$policyId}-{$employee->id}", [
                    'policy_id' => $policyId,
                    'employee_id' => $employee->id,
                    'acknowledged_at' => now()->subDays(10 - $policyIndex),
                    'ip_address' => '127.0.0.1',
                    'meta' => ['signature' => 'demo-digital-signature'],
                ], ['policy_id' => $policyId, 'employee_id' => $employee->id]);
            }

            $policy = DB::table('policies')->find($policyId);
            $meta = $this->decode($policy?->meta);
            $meta['acknowledgments_count'] = DB::table('policy_acknowledgments')->where('tenant_id', $tenantId)
                ->where('policy_id', $policyId)->count();
            DB::table('policies')->where('id', $policyId)->update(['meta' => $this->json($meta), 'updated_at' => now()]);
        }
    }

    private function seedAssets(int $tenantId, array $entities): void
    {
        $categoryRows = [
            'LAPTOP' => ['Máy tính xách tay', 'Thiết bị máy tính cá nhân'],
            'MONITOR' => ['Màn hình', 'Màn hình làm việc'],
            'OFFICE' => ['Thiết bị văn phòng', 'Thiết bị dùng chung tại văn phòng'],
        ];
        $categories = [];
        foreach ($categoryRows as $code => [$name, $description]) {
            $categories[$code] = $this->demoId('asset_categories', $tenantId, 'asset-category-'.$code, [
                'category_code' => $code, 'category_name' => $name, 'description' => $description,
                'status' => 'ACTIVE', 'meta' => [],
            ], ['category_code' => $code]);
        }

        $suppliers = [];
        foreach ([
            'FPT' => ['FPT Trading', 'Nguyễn Quốc Huy', '0909000111', 'sales@fpt.demo'],
            'PHONGVU' => ['Phong Vũ', 'Trần Ngọc Minh', '0909000222', 'sales@phongvu.demo'],
        ] as $code => [$name, $contact, $phone, $email]) {
            $suppliers[$code] = $this->demoId('suppliers', $tenantId, 'supplier-'.$code, [
                'supplier_code' => $code, 'supplier_name' => $name, 'contact_person' => $contact,
                'phone_number' => $phone, 'email' => $email, 'address' => 'Việt Nam',
                'status' => 'ACTIVE', 'meta' => [],
            ], ['supplier_code' => $code]);
        }

        $locationSpecs = [
            'HQ' => [$entities['hq'], 'Kho tài sản Trụ sở', 'TP. Hồ Chí Minh'],
            'HN' => [$entities['hn'], 'Kho tài sản Hà Nội', 'Hà Nội'],
            'DN' => [$entities['dn'], 'Kho tài sản Đà Nẵng', 'Đà Nẵng'],
        ];
        $locations = [];
        foreach ($locationSpecs as $code => [$entityId, $name, $description]) {
            $locations[$code] = $this->demoId('asset_locations', $tenantId, 'asset-location-'.$code, [
                'location_code' => $code, 'location_name' => $name, 'department_id' => null,
                'description' => $description, 'status' => 'ACTIVE', 'legal_entity_id' => $entityId, 'meta' => [],
            ], ['location_code' => $code]);
        }

        $assetRows = [
            ['LAP-001', 'Dell Latitude 5440', 'LAPTOP', 'FPT', 'HQ', 'assigned', '2025-08-15', 28900000],
            ['LAP-002', 'MacBook Air M2', 'LAPTOP', 'PHONGVU', 'HQ', 'assigned', '2025-10-02', 25900000],
            ['LAP-HN-001', 'Lenovo ThinkPad E14', 'LAPTOP', 'FPT', 'HN', 'assigned', '2026-01-03', 21500000],
            ['LAP-DN-001', 'HP ProBook 440', 'LAPTOP', 'FPT', 'DN', 'assigned', '2026-02-20', 19800000],
            ['MON-001', 'Dell P2422H 24 inch', 'MONITOR', 'PHONGVU', 'HQ', 'available', '2025-06-12', 4800000],
            ['MON-002', 'LG 27UP600 27 inch', 'MONITOR', 'PHONGVU', 'HQ', 'maintenance', '2025-06-12', 7900000],
            ['PRN-001', 'Máy in HP LaserJet Pro', 'OFFICE', 'FPT', 'HN', 'available', '2026-01-03', 6700000],
            ['PROJ-001', 'Máy chiếu Epson EB-E01', 'OFFICE', 'FPT', 'DN', 'lost_broken', '2026-02-20', 12500000],
        ];
        $assets = [];
        foreach ($assetRows as [$code, $name, $category, $supplier, $location, $status, $purchaseDate, $price]) {
            $entityId = $locationSpecs[$location][0];
            $assets[$code] = $this->demoId('assets', $tenantId, 'asset-'.$code, [
                'asset_code' => $code, 'asset_name' => $name, 'category_id' => $categories[$category],
                'supplier_id' => $suppliers[$supplier], 'location_id' => $locations[$location],
                'status' => $status, 'legal_entity_id' => $entityId,
                'meta' => [
                    'name' => $name, 'category' => $category === 'LAPTOP' ? 'Laptop/PC' : ($category === 'MONITOR' ? 'Monitor' : 'Office Equipment'),
                    'purchase_date' => $purchaseDate, 'price' => $price, 'purchase_cost' => $price,
                    'description' => 'Thiết bị demo có đầy đủ thông tin mua sắm, vị trí và vòng đời.',
                    'serial_number' => 'SN-'.str_replace('-', '', $code).'-2026',
                ],
            ], ['asset_code' => $code]);
        }

        $employees = DB::table('employees')->where('tenant_id', $tenantId)
            ->where(fn ($query) => $query->whereNull('profile->system_account')->orWhere('profile->system_account', false))
            ->orderBy('id')->get(['id', 'employee_code', 'full_name', 'legal_entity_id']);
        foreach (['LAP-001', 'LAP-002', 'LAP-HN-001', 'LAP-DN-001'] as $index => $assetCode) {
            $asset = DB::table('assets')->find($assets[$assetCode]);
            $employee = $employees->firstWhere('legal_entity_id', $asset->legal_entity_id) ?: $employees[$index];
            $this->demoId('asset_assignments', $tenantId, 'asset-assignment-'.$assetCode, [
                'asset_id' => $asset->id,
                'employee_id' => $employee->id,
                'assigned_by' => (string) ($employees->first()?->id ?? ''),
                'assigned_date' => CarbonImmutable::today()->subMonths(3)->addDays($index)->toDateString(),
                'return_date' => null,
                'status' => 'assigned',
                'meta' => [
                    'asset_code' => $assetCode, 'asset_name' => $asset->asset_name,
                    'employee_name' => $employee->full_name, 'employee_code' => $employee->employee_code,
                    'assignment_date' => CarbonImmutable::today()->subMonths(3)->addDays($index)->toDateString(),
                    'condition_details' => 'Hoạt động tốt, đầy đủ phụ kiện',
                ],
            ]);
        }

        $assignmentId = $this->demoId('asset_assignments', $tenantId, 'asset-assignment-returned', [
            'asset_id' => $assets['MON-001'], 'employee_id' => $employees->last()->id,
            'assigned_by' => (string) ($employees->first()?->id ?? ''),
            'assigned_date' => CarbonImmutable::today()->subMonths(6)->toDateString(),
            'return_date' => CarbonImmutable::today()->subMonth()->toDateString(), 'status' => 'returned',
            'meta' => [
                'asset_code' => 'MON-001', 'asset_name' => 'Dell P2422H 24 inch',
                'employee_name' => $employees->last()->full_name, 'employee_code' => $employees->last()->employee_code,
                'condition_details' => 'Đã hoàn trả, hoạt động bình thường',
                'returned_date' => CarbonImmutable::today()->subMonth()->toDateString(),
            ],
        ]);

        $this->demoId('asset_incidents', $tenantId, 'asset-incident-projector', [
            'asset_id' => $assets['PROJ-001'], 'assignment_id' => null, 'reported_by' => $employees->first()->id,
            'incident_type' => 'DAMAGE', 'incident_date' => CarbonImmutable::today()->subDays(20)->toDateString(),
            'description' => 'Thiết bị không lên nguồn sau khi di chuyển phòng họp.',
            'damage_level' => 'HIGH', 'status' => 'OPEN', 'resolved_by' => null, 'resolved_date' => null,
            'meta' => [],
        ]);
        $this->demoId('asset_maintenance', $tenantId, 'asset-maintenance-monitor', [
            'asset_id' => $assets['MON-002'], 'maintenance_type' => 'REPAIR',
            'maintenance_date' => CarbonImmutable::today()->subDays(3)->toDateString(), 'cost' => 850000,
            'vendor' => 'Trung tâm bảo hành LG', 'description' => 'Thay bo nguồn màn hình.',
            'next_maintenance_date' => CarbonImmutable::today()->addYear()->toDateString(), 'meta' => [],
        ]);
    }

    private function seedServiceTickets(int $tenantId): void
    {
        $categoryRows = [
            'IT' => ['Hỗ trợ IT', 'Thiết bị, tài khoản và phần mềm'],
            'HR' => ['Nhân sự', 'Hồ sơ, chế độ và quy trình nhân sự'],
            'ADMIN' => ['Hành chính', 'Văn phòng phẩm, phòng họp và cơ sở vật chất'],
        ];
        $categories = [];
        foreach ($categoryRows as $code => [$name, $description]) {
            $categories[$code] = $this->demoId('service_categories', $tenantId, 'service-category-'.$code, [
                'category_code' => $code, 'category_name' => $name, 'description' => $description,
                'status' => 'ACTIVE', 'meta' => [],
            ], ['category_code' => $code]);
        }

        $employees = $this->realEmployees($tenantId, 6);
        $rows = [
            ['TK-2026-001', 'IT', 'Không đăng nhập được VPN', 'high', 'pending', 'Chưa tiếp nhận'],
            ['TK-2026-002', 'IT', 'Cài đặt phần mềm ký số', 'medium', 'processing', 'Đã hẹn hỗ trợ từ xa'],
            ['TK-2026-003', 'HR', 'Điều chỉnh thông tin người phụ thuộc', 'medium', 'completed', 'Đã cập nhật hồ sơ'],
            ['TK-2026-004', 'ADMIN', 'Đăng ký phòng họp chiều thứ Sáu', 'low', 'completed', 'Đã đặt phòng A02'],
            ['TK-2026-005', 'IT', 'Laptop thường xuyên mất Wi-Fi', 'high', 'processing', 'Đang kiểm tra card mạng'],
            ['TK-2026-006', 'HR', 'Hỏi về hồ sơ bảo hiểm', 'low', 'pending', 'Chờ chuyên viên phản hồi'],
        ];

        foreach ($rows as $index => [$code, $category, $title, $priority, $status, $note]) {
            $employee = $employees[$index % $employees->count()];
            $ticketId = $this->demoId('service_tickets', $tenantId, 'service-ticket-'.$code, [
                'ticket_code' => $code, 'requester_id' => $employee->id, 'category_id' => $categories[$category],
                'assigned_to' => $category === 'IT' ? 'IT Support' : ($category === 'HR' ? 'HR Operations' : 'Admin Team'),
                'title' => $title, 'description' => 'Mô tả chi tiết cho yêu cầu: '.$title.'.',
                'priority' => $priority, 'status' => $status,
                'meta' => ['category' => $category, 'employee_name' => $employee->full_name, 'admin_note' => $note],
            ], ['ticket_code' => $code]);

            $this->demoId('service_ticket_updates', $tenantId, 'service-update-'.$code, [
                'ticket_id' => $ticketId, 'created_by' => $employees->first()->id, 'action_type' => 'STATUS_CHANGE',
                'old_status' => 'pending', 'new_status' => $status, 'comment' => $note, 'meta' => [],
            ]);
        }
    }

    private function seedAttendanceDevices(int $tenantId, array $entities): void
    {
        $rows = [
            ['device-hq', $entities['hq'], 'Máy chấm công Cổng chính', 'wiseeye', 'zk_pull', 'Sảnh trụ sở', '192.168.10.201'],
            ['device-hn', $entities['hn'], 'Máy chấm công Chi nhánh Hà Nội', 'zkteco', 'adms_push', 'Sảnh chi nhánh Hà Nội', '192.168.20.201'],
            ['device-dn', $entities['dn'], 'Máy chấm công Chi nhánh Đà Nẵng', 'hikvision', 'cloud_api', 'Sảnh chi nhánh Đà Nẵng', '192.168.30.201'],
        ];
        foreach ($rows as [$key, $entityId, $name, $brand, $protocol, $location, $ip]) {
            $this->demoId('attendance_devices', $tenantId, $key, [
                'legal_entity_id' => $entityId, 'name' => $name, 'brand' => $brand, 'protocol' => $protocol,
                'device_token' => hash('sha256', "hrm-demo-{$tenantId}-{$key}"), 'status' => 'ACTIVE',
                'location' => $location, 'last_seen_at' => now()->subMinutes(5),
                'meta' => ['ip' => $ip, 'port' => 4370, 'serial' => strtoupper($key).'-2026'],
            ], ['device_token' => hash('sha256', "hrm-demo-{$tenantId}-{$key}")]);
        }
    }

    private function seedShiftCoverage(int $tenantId, array $entities): void
    {
        $employees = $this->realEmployees($tenantId, 8);
        $shiftId = DB::table('shift_types')->where('tenant_id', $tenantId)->orderBy('id')->value('id');
        if ($employees->count() < 6 || ! $shiftId) {
            return;
        }

        $requests = [
            ['open', 'OPEN', 8, 0, 'sick'],
            ['partial', 'PARTIALLY_COVERED', 8, 4, 'leave'],
            ['covered', 'COVERED', 8, 8, 'no_show'],
            ['cancelled', 'CANCELLED', 8, 0, 'extra_demand'],
        ];

        foreach ($requests as $index => [$key, $status, $needed, $covered, $reason]) {
            $absent = $employees[$index];
            $date = CarbonImmutable::today()->addDays(2 + $index);
            $requestId = $this->demoId('shift_coverage_requests', $tenantId, 'coverage-'.$key, [
                'legal_entity_id' => $absent->legal_entity_id,
                'work_date' => $date->toDateString(), 'shift_type_id' => $shiftId,
                'absent_employee_id' => $absent->id, 'reason' => $reason,
                'hours_needed' => $needed, 'hours_covered' => $covered, 'status' => $status,
                'created_by' => $employees->first()->id, 'notes' => 'Dữ liệu demo chuỗi giao ca', 'meta' => [],
            ]);

            if ($status === 'OPEN') {
                continue;
            }

            $coverEmployee = $employees[$index + 2];
            $offerStatus = $status === 'CANCELLED' ? 'CANCELLED' : 'ACCEPTED';
            $hours = $status === 'PARTIALLY_COVERED' ? 4 : ($status === 'COVERED' ? 8 : 0);
            $overtimeId = null;
            if ($hours > 0) {
                $overtimeId = $this->demoId('overtime_requests', $tenantId, 'coverage-overtime-'.$key, [
                    'employee_id' => $coverEmployee->id, 'work_date' => $date->toDateString(),
                    'start_time' => '18:00:00', 'end_time' => $hours === 4 ? '22:00:00' : '02:00:00',
                    'total_hours' => $hours, 'status' => 'PENDING',
                    'meta' => [
                        'reason' => 'Phủ ca thay người vắng', 'coverage_request_id' => $requestId,
                        'day_type' => 'WEEKDAY', 'multiplier' => 1.5,
                    ],
                ]);
            }

            $this->demoId('shift_coverage_offers', $tenantId, 'coverage-offer-'.$key, [
                'legal_entity_id' => $coverEmployee->legal_entity_id,
                'coverage_request_id' => $requestId, 'employee_id' => $coverEmployee->id,
                'start_time' => $hours ? '18:00:00' : null, 'end_time' => $hours === 4 ? '22:00:00' : ($hours === 8 ? '02:00:00' : null),
                'hours' => $hours, 'status' => $offerStatus, 'overtime_request_id' => $overtimeId, 'meta' => [],
            ]);
        }
    }

    private function seedPayrollAdjustments(int $tenantId): void
    {
        $details = DB::table('salary_details as sd')->join('salary_periods as sp', 'sp.id', '=', 'sd.period_id')
            ->where('sd.tenant_id', $tenantId)->where('sp.status', 'PAID')->orderByDesc('sp.end_date')
            ->limit(2)->get(['sd.id', 'sd.employee_id', 'sd.period_id', 'sd.legal_entity_id']);

        foreach ($details as $index => $detail) {
            $this->demoId('payroll_adjustments', $tenantId, 'payroll-adjustment-'.$detail->id, [
                'employee_id' => $detail->employee_id, 'paid_salary_detail_id' => $detail->id,
                'paid_period_id' => $detail->period_id, 'adjustment_type' => $index === 0 ? 'BACKPAY' : 'CORRECTION',
                'amount' => $index === 0 ? 350000 : -120000, 'status' => $index === 0 ? 'PENDING' : 'APPLIED',
                'legal_entity_id' => $detail->legal_entity_id,
                'meta' => ['reason' => $index === 0 ? 'Truy lĩnh phụ cấp' : 'Điều chỉnh khấu trừ kỳ trước'],
            ]);
        }
    }

    private function realEmployees(int $tenantId, int $limit)
    {
        return DB::table('employees')->where('tenant_id', $tenantId)
            ->whereIn('status', ['ACTIVE', 'PROBATION'])
            ->where(fn ($query) => $query->whereNull('profile->system_account')->orWhere('profile->system_account', false))
            ->orderBy('id')->limit($limit)
            ->get(['id', 'employee_code', 'full_name', 'legal_entity_id', 'profile']);
    }

    private function demoId(string $table, int $tenantId, string $demoKey, array $values, array $natural = []): int
    {
        $meta = is_array($values['meta'] ?? null) ? $values['meta'] : $this->decode($values['meta'] ?? null);
        $values['meta'] = $this->json(array_merge($meta, ['source' => 'demo-coverage', 'demo_key' => $demoKey]));
        $values = $this->postgresBooleans($values);

        $base = DB::table($table)->where('tenant_id', $tenantId);
        $id = $natural ? (clone $base)->where($natural)->value('id') : null;
        $id ??= (clone $base)->whereRaw("meta->>'demo_key' = ?", [$demoKey])->value('id');

        if ($id) {
            DB::table($table)->where('id', $id)->update($values + ['updated_at' => now()]);

            return (int) $id;
        }

        return (int) DB::table($table)->insertGetId($values + [
            'tenant_id' => $tenantId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function upsertId(string $table, array $natural, array $values): int
    {
        $values = $this->postgresBooleans($values);
        $id = DB::table($table)->where($natural)->value('id');
        if ($id) {
            DB::table($table)->where('id', $id)->update($values + ['updated_at' => now()]);

            return (int) $id;
        }

        return (int) DB::table($table)->insertGetId($natural + $values + [
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function postgresBooleans(array $values): array
    {
        return array_map(
            fn ($value) => is_bool($value) ? DB::raw($value ? 'true' : 'false') : $value,
            $values
        );
    }

    private function decode(mixed $value): array
    {
        if (! $value) {
            return [];
        }
        $decoded = is_string($value) ? json_decode($value, true) : (array) $value;

        return is_array($decoded) ? $decoded : [];
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
