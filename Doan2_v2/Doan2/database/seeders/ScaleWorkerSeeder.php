<?php

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Seed công nhân quy mô lớn để test tải (công ty sản xuất, chấm công đông).
 * Mặc định 1.200 công nhân, xoay 3 ca (Ca 1/2/3), rải qua các phân xưởng, kèm
 * hợp đồng + phân ca. Bulk-insert theo lô + DÙNG CHUNG 1 hash mật khẩu để nhanh.
 *
 * Idempotent theo mã CNxxxxx: chạy lại chỉ bù cho đủ TARGET, không nhân đôi.
 * Đổi số lượng: HRM_SCALE_WORKERS env, hoặc sửa TARGET.
 */
class ScaleWorkerSeeder extends Seeder
{
    private const TARGET = 1200;

    private array $ho = ['Nguyễn', 'Trần', 'Lê', 'Phạm', 'Hoàng', 'Huỳnh', 'Phan', 'Vũ', 'Võ', 'Đặng', 'Bùi', 'Đỗ', 'Hồ', 'Ngô', 'Dương', 'Lý', 'Đinh', 'Trịnh', 'Mai', 'Lương'];

    private array $dem = ['Văn', 'Thị', 'Hữu', 'Đức', 'Công', 'Quang', 'Minh', 'Thanh', 'Ngọc', 'Thu', 'Hồng', 'Xuân', 'Bá', 'Đình', 'Gia', 'Khánh', 'Tuấn', 'Kim', 'Phương', 'Mạnh'];

    private array $ten = ['An', 'Bình', 'Cường', 'Dũng', 'Phúc', 'Giang', 'Hà', 'Hải', 'Hùng', 'Khoa', 'Lâm', 'Long', 'Mai', 'Nam', 'Oanh', 'Phong', 'Quân', 'Sơn', 'Tâm', 'Thảo', 'Trung', 'Tuấn', 'Vy', 'Yến', 'Đạt', 'Hạnh', 'Linh', 'Nhung', 'Thắng', 'Trang', 'Huy', 'Kiên', 'Loan', 'Ngân', 'Tú'];

    public function run(): void
    {
        $target = (int) (getenv('HRM_SCALE_WORKERS') ?: self::TARGET);
        if ($target < 1) {
            return;
        }

        $tenantId = 1;
        $legalEntityId = (int) (DB::table('legal_entities')->where('tenant_id', $tenantId)->min('id') ?: 1);
        $now = now();

        // Chức danh Công nhân.
        $posId = DB::table('positions')->where('tenant_id', $tenantId)->where('position_code', 'CN')->value('id');
        if (! $posId) {
            $posId = DB::table('positions')->insertGetId([
                'position_code' => 'CN', 'position_name' => 'Công nhân', 'tenant_id' => $tenantId,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // Phân xưởng sản xuất (con của Sản xuất nếu có).
        $sxId = DB::table('departments')->where('tenant_id', $tenantId)->where('department_code', 'SX')->value('id');
        $deptCodes = [
            ['PX-A', 'Phân xưởng A'], ['PX-B', 'Phân xưởng B'], ['PX-C', 'Phân xưởng C'],
            ['DONGGOI', 'Tổ Đóng gói'], ['KCS', 'Tổ KCS/QC'],
        ];
        $deptIds = [];
        foreach ($deptCodes as [$code, $name]) {
            $id = DB::table('departments')->where('tenant_id', $tenantId)->where('department_code', $code)->value('id');
            if (! $id) {
                $id = DB::table('departments')->insertGetId([
                    'department_code' => $code, 'department_name' => $name, 'status' => DB::raw('true'),
                    'meta' => json_encode(['parent_id' => $sxId, 'demo_seed' => true], JSON_UNESCAPED_UNICODE),
                    'tenant_id' => $tenantId, 'legal_entity_id' => $legalEntityId, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            $deptIds[] = $id;
        }

        $shiftIds = DB::table('shift_types')->where('tenant_id', $tenantId)
            ->whereIn('shift_name', ['Ca 1', 'Ca 2', 'Ca 3'])->orderBy('id')->pluck('id')->all();
        if (empty($shiftIds)) {
            $shiftIds = DB::table('shift_types')->where('tenant_id', $tenantId)->orderBy('id')->pluck('id')->all();
        }
        if (empty($shiftIds)) {
            throw new RuntimeException('ScaleWorkerSeeder cần ít nhất một ca làm việc.');
        }

        $contractTypes = DB::table('contract_types')->where('tenant_id', $tenantId)
            ->whereIn('contract_type_code', ['HDTV', 'HDLD01'])->pluck('id', 'contract_type_code');
        if (! isset($contractTypes['HDTV'], $contractTypes['HDLD01'])) {
            throw new RuntimeException('ScaleWorkerSeeder cần contract type HDTV và HDLD01.');
        }

        $sxMeta = $this->decode(DB::table('departments')->where('id', $sxId)->value('meta'));
        $managerId = (int) ($sxMeta['manager_id'] ?? 0);
        if (! $managerId || ! DB::table('employees')->where('tenant_id', $tenantId)->where('id', $managerId)->exists()) {
            $managerId = (int) DB::table('employees')->where('tenant_id', $tenantId)
                ->where('department_id', $sxId)->orderBy('id')->value('id');
        }

        $codes = collect(range(1, $target))->map(
            fn ($number) => 'CN'.str_pad((string) $number, 5, '0', STR_PAD_LEFT)
        );
        $existingCodes = DB::table('employees')->where('tenant_id', $tenantId)
            ->whereIn('employee_code', $codes)->pluck('employee_code')->flip();
        $missingNumbers = collect(range(1, $target))->reject(
            fn ($number) => isset($existingCodes['CN'.str_pad((string) $number, 5, '0', STR_PAD_LEFT)])
        );

        $created = 0;
        $sharedHash = $missingNumbers->isEmpty() ? null : Hash::make('congnhan123');
        foreach ($missingNumbers->chunk(500) as $numbers) {
            $rows = [];
            foreach ($numbers as $n) {
                $code = 'CN' . str_pad((string) $n, 5, '0', STR_PAD_LEFT);
                $name = $this->ho[array_rand($this->ho)] . ' ' . $this->dem[array_rand($this->dem)] . ' ' . $this->ten[array_rand($this->ten)];
                $rows[] = [
                    'employee_code' => $code,
                    'full_name' => $name,
                    'company_email' => strtolower($code) . '@factory.vn',
                    'password_hash' => $sharedHash,
                    'status' => 'ACTIVE',
                    'department_id' => $deptIds[$n % count($deptIds)],
                    'position_id' => $posId,
                    'manager_id' => $managerId ?: null,
                    'base_salary' => 5_500_000 + (($n % 5) * 400_000), // 5,5–7,1tr
                    'hire_date' => $now->copy()->subDays(30 + ($n % 900))->toDateString(),
                    'gender' => $n % 2 === 0 ? 'MALE' : 'FEMALE',
                    'skills' => json_encode(['Vận hành máy', 'An toàn lao động'], JSON_UNESCAPED_UNICODE),
                    'profile' => json_encode(['system_account' => false, 'shift_group' => 'Ca ' . (($n % 3) + 1), 'source' => 'scale-seed'], JSON_UNESCAPED_UNICODE),
                    'tenant_id' => $tenantId,
                    'legal_entity_id' => $legalEntityId,
                    'is_super_admin' => DB::raw('false'),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('employees')->insert($rows);
            $created += count($rows);
        }

        $synced = $this->syncWorkers(
            $codes, $tenantId, $posId, $deptIds, $shiftIds, $managerId ?: null,
            (int) $contractTypes['HDTV'], (int) $contractTypes['HDLD01'], $now
        );
        $leadershipSynced = $this->syncLeadershipDepartments($tenantId, $now);
        $renewed = $this->ensureCurrentContracts($tenantId, (int) $contractTypes['HDLD01'], $now);

        // Cấp số dư phép năm hiện tại cho toàn bộ (gồm công nhân mới).
        app(\App\Services\LeavePolicyService::class)->recomputeBalances($tenantId, (int) now()->year);

        $this->command?->info("ScaleWorkerSeeder: tạo {$created}, đồng bộ {$synced} công nhân, {$leadershipSynced} lãnh đạo, nối tiếp {$renewed} HĐ hết hạn.");
    }

    private function syncLeadershipDepartments(int $tenantId, $now): int
    {
        $boardId = DB::table('departments')->where('tenant_id', $tenantId)
            ->where('department_code', 'BGD')->value('id');
        if (! $boardId) {
            return 0;
        }

        $leaders = DB::table('employees as e')->join('positions as p', 'p.id', '=', 'e.position_id')
            ->where('e.tenant_id', $tenantId)->whereIn('p.position_code', ['GD', 'PGD'])
            ->select('e.id', 'e.manager_id', 'p.position_code')->get();
        $directorId = $leaders->firstWhere('position_code', 'GD')?->id;

        foreach ($leaders as $leader) {
            DB::table('employees')->where('id', $leader->id)->update([
                'department_id' => $boardId,
                'manager_id' => $leader->position_code === 'GD' ? null : ($directorId ?: $leader->manager_id),
                'updated_at' => $now,
            ]);
            DB::table('employee_departments')->where('tenant_id', $tenantId)
                ->where('employee_id', $leader->id)->where('department_id', '!=', $boardId)->delete();
        }

        if ($directorId) {
            $meta = $this->decode(DB::table('departments')->where('id', $boardId)->value('meta'));
            $meta['manager_id'] = $directorId;
            DB::table('departments')->where('id', $boardId)->update([
                'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
                'updated_at' => $now,
            ]);
        }

        return $leaders->count();
    }

    private function syncWorkers(
        $codes,
        int $tenantId,
        int $positionId,
        array $departmentIds,
        array $shiftIds,
        ?int $managerId,
        int $probationTypeId,
        int $permanentTypeId,
        $now,
    ): int {
        $workers = DB::table('employees')->where('tenant_id', $tenantId)
            ->whereIn('employee_code', $codes)->orderBy('id')->get();

        foreach ($departmentIds as $departmentId) {
            $meta = $this->decode(DB::table('departments')->where('id', $departmentId)->value('meta'));
            $meta['manager_id'] = $managerId;
            DB::table('departments')->where('id', $departmentId)->update([
                'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
                'updated_at' => $now,
            ]);
        }

        foreach ($workers as $worker) {
            $number = (int) substr($worker->employee_code, 2);
            $departmentId = $departmentIds[$number % count($departmentIds)];
            $shiftId = $shiftIds[$number % count($shiftIds)];
            $hireDate = $worker->hire_date ?: CarbonImmutable::today()->subDays(61)->toDateString();
            $probation = CarbonImmutable::parse($hireDate)->addDays(60)->gte(CarbonImmutable::today());
            $profile = $this->decode($worker->profile);
            $profile['source'] = 'scale-seed';
            $profile['system_account'] = false;
            $profile['probation_end_date'] = CarbonImmutable::parse($hireDate)->addDays(60)->toDateString();

            DB::table('employees')->where('id', $worker->id)->update([
                'status' => 'ACTIVE',
                'department_id' => $departmentId,
                'position_id' => $positionId,
                'manager_id' => $managerId,
                'profile' => json_encode($profile, JSON_UNESCAPED_UNICODE),
                'updated_at' => $now,
            ]);

            $contract = DB::table('contracts')->where('tenant_id', $tenantId)
                ->where('employee_id', $worker->id)->orderBy('id')->first();
            $contractValues = [
                'contract_type_id' => $probation ? $probationTypeId : $permanentTypeId,
                'position_id' => $positionId,
                'department_id' => $departmentId,
                'contract_number' => ($probation ? 'HDTV/' : 'HDLD/').$worker->employee_code,
                'status' => 'CÓ_HIỆU_LỰC',
                'start_date' => $hireDate,
                'end_date' => $probation ? CarbonImmutable::parse($hireDate)->addDays(60)->toDateString() : null,
                'meta' => json_encode(array_merge($this->decode($contract?->meta), [
                    'basic_salary' => (float) $worker->base_salary,
                    'job_title' => 'Công nhân',
                    'source' => 'scale-seed',
                ]), JSON_UNESCAPED_UNICODE),
                'tenant_id' => $tenantId,
                'legal_entity_id' => $worker->legal_entity_id,
                'updated_at' => $now,
            ];
            if ($contract) {
                DB::table('contracts')->where('id', $contract->id)->update($contractValues);
            } else {
                DB::table('contracts')->insert($contractValues + [
                    'employee_id' => $worker->id,
                    'created_at' => $now,
                ]);
            }

            DB::table('shift_assignments')->updateOrInsert(
                ['tenant_id' => $tenantId, 'employee_id' => $worker->id],
                [
                    'legal_entity_id' => $worker->legal_entity_id,
                    'shift_type_id' => $shiftId,
                    'effective_date' => $hireDate,
                    'is_permanent' => DB::raw('true'),
                    'status' => 'ACTIVE',
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
            DB::table('employee_departments')->where('employee_id', $worker->id)
                ->where('department_id', '!=', $departmentId)->delete();
            DB::table('employee_departments')->updateOrInsert(
                ['employee_id' => $worker->id, 'department_id' => $departmentId],
                ['tenant_id' => $tenantId, 'role_in_dept' => 'WORKER', 'updated_at' => $now, 'created_at' => $now]
            );

            $history = DB::table('employment_histories')->where('tenant_id', $tenantId)
                ->where('employee_id', $worker->id)->whereRaw('is_current = true')->first();
            $historyValues = [
                'department_id' => $departmentId,
                'position_id' => $positionId,
                'start_date' => $hireDate,
                'end_date' => null,
                'is_current' => DB::raw('true'),
                'notes' => 'Dữ liệu gốc từ hồ sơ nhân viên scale',
                'meta' => json_encode(['source' => 'scale-seed'], JSON_UNESCAPED_UNICODE),
                'updated_at' => $now,
            ];
            if ($history) {
                DB::table('employment_histories')->where('id', $history->id)->update($historyValues);
            } else {
                DB::table('employment_histories')->insert($historyValues + [
                    'tenant_id' => $tenantId,
                    'employee_id' => $worker->id,
                    'created_at' => $now,
                ]);
            }
        }

        return $workers->count();
    }

    private function ensureCurrentContracts(int $tenantId, int $permanentTypeId, $now): int
    {
        $today = CarbonImmutable::today();
        $renewed = 0;
        $employees = DB::table('employees')->where('tenant_id', $tenantId)
            ->whereIn('status', ['ACTIVE', 'PROBATION'])->orderBy('id')->get();

        foreach ($employees as $employee) {
            if (! empty($this->decode($employee->profile)['system_account'])) {
                continue;
            }

            if ($employee->department_id) {
                DB::table('employee_departments')->updateOrInsert(
                    ['employee_id' => $employee->id, 'department_id' => $employee->department_id],
                    ['tenant_id' => $tenantId, 'role_in_dept' => 'MEMBER', 'updated_at' => $now, 'created_at' => $now]
                );
            }
            if ($employee->department_id || $employee->position_id) {
                $history = DB::table('employment_histories')->where('tenant_id', $tenantId)
                    ->where('employee_id', $employee->id)->whereRaw('is_current = true')->first();
                $historyValues = [
                    'department_id' => $employee->department_id,
                    'position_id' => $employee->position_id,
                    'start_date' => $employee->hire_date ?: $today->toDateString(),
                    'end_date' => null,
                    'is_current' => DB::raw('true'),
                    'notes' => 'Đồng bộ từ hồ sơ nhân viên',
                    'meta' => json_encode(['source' => 'employee-sync'], JSON_UNESCAPED_UNICODE),
                    'updated_at' => $now,
                ];
                if ($history) {
                    DB::table('employment_histories')->where('id', $history->id)->update($historyValues);
                } else {
                    DB::table('employment_histories')->insert($historyValues + [
                        'tenant_id' => $tenantId,
                        'employee_id' => $employee->id,
                        'created_at' => $now,
                    ]);
                }
            }

            DB::table('contracts')->where('tenant_id', $tenantId)->where('employee_id', $employee->id)
                ->whereIn('status', ['ACTIVE', 'CÓ_HIỆU_LỰC', 'ĐANG_HIỆU_LỰC'])
                ->whereNotNull('end_date')->where('end_date', '<', $today->toDateString())
                ->update(['status' => 'HẾT_HIỆU_LỰC', 'updated_at' => $now]);

            $current = DB::table('contracts')->where('tenant_id', $tenantId)
                ->where('employee_id', $employee->id)
                ->whereIn('status', ['ACTIVE', 'CÓ_HIỆU_LỰC', 'ĐANG_HIỆU_LỰC'])
                ->where(fn ($query) => $query->whereNull('start_date')->orWhere('start_date', '<=', $today->toDateString()))
                ->where(fn ($query) => $query->whereNull('end_date')->orWhere('end_date', '>=', $today->toDateString()))
                ->first();
            if ($current) {
                DB::table('contracts')->where('id', $current->id)->update([
                    'legal_entity_id' => $employee->legal_entity_id,
                    'department_id' => $employee->department_id,
                    'position_id' => $employee->position_id,
                    'updated_at' => $now,
                ]);
                continue;
            }

            $lastEnd = DB::table('contracts')->where('tenant_id', $tenantId)
                ->where('employee_id', $employee->id)->whereNotNull('end_date')->max('end_date');
            $start = $lastEnd
                ? CarbonImmutable::parse($lastEnd)->addDay()
                : CarbonImmutable::parse($employee->hire_date ?: $today);
            if ($start->gt($today)) {
                $start = $today;
            }

            DB::table('contracts')->insert([
                'tenant_id' => $tenantId,
                'legal_entity_id' => $employee->legal_entity_id,
                'employee_id' => $employee->id,
                'contract_type_id' => $permanentTypeId,
                'department_id' => $employee->department_id,
                'position_id' => $employee->position_id,
                'contract_number' => 'HDLD/'.$employee->employee_code.'/'.$start->format('Y'),
                'status' => 'CÓ_HIỆU_LỰC',
                'start_date' => $start->toDateString(),
                'end_date' => null,
                'meta' => json_encode([
                    'basic_salary' => (float) $employee->base_salary,
                    'source' => 'employee-sync',
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $renewed++;
        }

        return $renewed;
    }

    private function decode(mixed $value): array
    {
        if (! $value) {
            return [];
        }
        $decoded = is_string($value) ? json_decode($value, true) : (array) $value;

        return is_array($decoded) ? $decoded : [];
    }
}
