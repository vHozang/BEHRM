<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class ShiftQuickLoginSeeder extends Seeder
{
    private const PASSWORD = 'congnhan123';

    private const ACCOUNTS = [
        ['code' => 'CN00003', 'label' => 'Nhân viên ca 1', 'email' => 'nhanvien.ca1@devtapcode.io.vn', 'shift_code' => 'CA1'],
        ['code' => 'CN00001', 'label' => 'Nhân viên ca 2', 'email' => 'nhanvien.ca2@devtapcode.io.vn', 'shift_code' => 'CA2'],
        ['code' => 'CN00002', 'label' => 'Nhân viên ca 3', 'email' => 'nhanvien.ca3@devtapcode.io.vn', 'shift_code' => 'CA3'],
    ];

    public function run(): void
    {
        $tenantId = 1;
        $legalEntityId = (int) DB::table('legal_entities')->where('tenant_id', $tenantId)->orderBy('id')->value('id');
        $employeeRoleId = (int) DB::table('roles')->where('tenant_id', $tenantId)->where('role_code', 'EMPLOYEE')->value('id');
        $contractTypeId = (int) DB::table('contract_types')->where('tenant_id', $tenantId)
            ->where('contract_type_code', 'HDLD01')->value('id');
        $departmentId = (int) (DB::table('departments')->where('tenant_id', $tenantId)
            ->whereIn('department_code', ['PX-A', 'SX'])->orderByRaw("CASE WHEN department_code = 'PX-A' THEN 0 ELSE 1 END")
            ->value('id') ?: DB::table('departments')->where('tenant_id', $tenantId)->orderBy('id')->value('id'));
        $positionId = (int) (DB::table('positions')->where('tenant_id', $tenantId)->where('position_code', 'CN')->value('id')
            ?: DB::table('positions')->where('tenant_id', $tenantId)->orderBy('id')->value('id'));

        if (! $legalEntityId || ! $employeeRoleId || ! $contractTypeId || ! $departmentId || ! $positionId) {
            throw new RuntimeException('ShiftQuickLoginSeeder cần dữ liệu tenant, pháp nhân, role EMPLOYEE, phòng ban, chức danh và loại hợp đồng HDLD01.');
        }

        DB::transaction(function () use ($tenantId, $legalEntityId, $employeeRoleId, $contractTypeId, $departmentId, $positionId): void {
            foreach (self::ACCOUNTS as $account) {
                $shift = DB::table('shift_types')->where('tenant_id', $tenantId)
                    ->where('shift_code', $account['shift_code'])->first();
                if (! $shift) {
                    throw new RuntimeException("Không tìm thấy ca {$account['shift_code']} cho tài khoản đăng nhập nhanh.");
                }

                $emailOwner = DB::table('employees')->where('company_email', $account['email'])
                    ->where('employee_code', '!=', $account['code'])->value('employee_code');
                if ($emailOwner) {
                    throw new RuntimeException("Email {$account['email']} đang thuộc nhân viên {$emailOwner}.");
                }

                $employee = DB::table('employees')->where('tenant_id', $tenantId)
                    ->where('employee_code', $account['code'])->first();
                $profile = $this->decode($employee?->profile ?? null);
                $profile['quick_login'] = true;
                $profile['shift_group'] = $account['label'];

                $employeeValues = [
                    'company_email' => $account['email'],
                    'password_hash' => Hash::make(self::PASSWORD),
                    'status' => 'ACTIVE',
                    'department_id' => $employee?->department_id ?: $departmentId,
                    'position_id' => $employee?->position_id ?: $positionId,
                    'profile' => json_encode($profile, JSON_UNESCAPED_UNICODE),
                    'legal_entity_id' => $employee?->legal_entity_id ?: $legalEntityId,
                    'updated_at' => now(),
                ];

                if ($employee) {
                    DB::table('employees')->where('id', $employee->id)->update($employeeValues);
                    $employeeId = (int) $employee->id;
                } else {
                    $employeeId = (int) DB::table('employees')->insertGetId($employeeValues + [
                        'employee_code' => $account['code'],
                        'full_name' => $account['label'],
                        'hire_date' => now()->subYear()->toDateString(),
                        'tenant_id' => $tenantId,
                        'created_at' => now(),
                    ]);
                }

                DB::table('employee_roles')->where('employee_id', $employeeId)
                    ->where('role_id', '!=', $employeeRoleId)->update([
                        'is_active' => false,
                        'updated_at' => now(),
                    ]);
                DB::table('employee_roles')->updateOrInsert(
                    ['tenant_id' => $tenantId, 'employee_id' => $employeeId, 'role_id' => $employeeRoleId],
                    [
                        'is_active' => true,
                        'effective_date' => now()->toDateString(),
                        'expiry_date' => null,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );

                $assignmentId = DB::table('shift_assignments')->where('tenant_id', $tenantId)
                    ->where('employee_id', $employeeId)->where('status', 'ACTIVE')->orderByDesc('id')->value('id');
                DB::table('shift_assignments')->where('tenant_id', $tenantId)
                    ->where('employee_id', $employeeId)->where('status', 'ACTIVE')
                    ->when($assignmentId, fn ($query) => $query->where('id', '!=', $assignmentId))
                    ->update(['status' => 'INACTIVE', 'updated_at' => now()]);

                $assignmentValues = [
                    'shift_type_id' => $shift->id,
                    'effective_date' => now()->toDateString(),
                    'expiry_date' => null,
                    'is_permanent' => true,
                    'status' => 'ACTIVE',
                    'legal_entity_id' => $legalEntityId,
                    'updated_at' => now(),
                ];
                if ($assignmentId) {
                    DB::table('shift_assignments')->where('id', $assignmentId)->update($assignmentValues);
                } else {
                    DB::table('shift_assignments')->insert($assignmentValues + [
                        'employee_id' => $employeeId,
                        'tenant_id' => $tenantId,
                        'created_at' => now(),
                    ]);
                }

                $contract = DB::table('contracts')->where('tenant_id', $tenantId)
                    ->where('employee_id', $employeeId)
                    ->whereIn('status', ['ACTIVE', 'CÓ_HIỆU_LỰC', 'ĐANG_HIỆU_LỰC'])
                    ->orderByDesc('id')->first();
                $contractMeta = $this->decode($contract?->meta ?? null);
                if (($contractMeta['sign_status'] ?? null) !== 'SIGNED') {
                    $contractMeta['sign_status'] = 'PENDING_SIGN';
                    $contractMeta['sent_for_sign_at'] ??= now()->toIso8601String();
                }

                if ($contract) {
                    DB::table('contracts')->where('id', $contract->id)->update([
                        'meta' => json_encode($contractMeta, JSON_UNESCAPED_UNICODE),
                        'updated_at' => now(),
                    ]);
                } else {
                    DB::table('contracts')->insert([
                        'employee_id' => $employeeId,
                        'contract_type_id' => $contractTypeId,
                        'position_id' => $positionId,
                        'department_id' => $departmentId,
                        'contract_number' => 'HDLD/'.$account['code'],
                        'status' => 'CÓ_HIỆU_LỰC',
                        'start_date' => now()->subYear()->toDateString(),
                        'end_date' => null,
                        'meta' => json_encode($contractMeta, JSON_UNESCAPED_UNICODE),
                        'tenant_id' => $tenantId,
                        'legal_entity_id' => $legalEntityId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });
    }

    private function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
