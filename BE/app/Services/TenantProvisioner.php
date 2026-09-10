<?php

namespace App\Services;

use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Provisions a brand-new tenant: creates the tenant + default legal entity,
 * seeds starter master data (department, position, roles, leave types) and the
 * first tenant-admin employee, all inside a single DB transaction.
 *
 * IMPORTANT: every raw DB::table() insert sets tenant_id (and legal_entity_id
 * where the table is entity-scoped) EXPLICITLY to the freshly created ids — it
 * never relies on TenantContext for raw inserts. TenantContext is still pointed
 * at the new tenant during provisioning and is cleared again at the end so the
 * caller's request context is not polluted.
 */
class TenantProvisioner
{
    /**
     * @param  array<string, mixed>  $data  { tenant:{name,code?}, admin:{full_name,company_email,password}, options?:{} }
     * @return array<string, mixed>
     */
    public function provision(array $data): array
    {
        $tenant = $data['tenant'];
        $admin = $data['admin'];

        $result = DB::transaction(function () use ($tenant, $admin): array {
            $now = now();

            // a. Tenant -----------------------------------------------------
            $newTenantId = (int) DB::table('tenants')->insertGetId([
                'name' => $tenant['name'],
                'code' => $tenant['code'] ?? null,
                'status' => 'ACTIVE',
                'locale' => 'vi',
                'currency' => 'VND',
                'timezone' => 'Asia/Ho_Chi_Minh',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // b. Default legal entity --------------------------------------
            $newEntityId = (int) DB::table('legal_entities')->insertGetId([
                'tenant_id' => $newTenantId,
                'name' => $tenant['name'].' - Default Entity',
                'code' => 'DEFAULT',
                'status' => 'ACTIVE',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // c. Point context at the NEW tenant (for any Eloquent creates).
            TenantContext::set($newTenantId, $newEntityId);

            // d. Seed starter data -----------------------------------------
            $departmentId = (int) DB::table('departments')->insertGetId([
                'tenant_id' => $newTenantId,
                'legal_entity_id' => $newEntityId,
                'department_code' => 'GENERAL',
                'department_name' => 'General',
                'status' => DB::raw('true'),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $positionId = (int) DB::table('positions')->insertGetId([
                'tenant_id' => $newTenantId,
                'position_code' => 'STAFF',
                'position_name' => 'Staff',
                'status' => DB::raw('true'),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $adminRoleId = (int) DB::table('roles')->insertGetId([
                'tenant_id' => $newTenantId,
                'role_code' => 'TENANT_ADMIN',
                'role_name' => 'Quản trị viên tổ chức',
                'description' => 'Administrator within this tenant.',
                'is_system_role' => DB::raw('true'),
                'meta' => json_encode(['is_admin' => true]),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $employeeRoleId = (int) DB::table('roles')->insertGetId([
                'tenant_id' => $newTenantId,
                'role_code' => 'EMPLOYEE',
                'role_name' => 'Nhân viên',
                'description' => 'Standard employee.',
                'is_system_role' => DB::raw('true'),
                'meta' => json_encode(['is_admin' => false]),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $leaveTypeDefs = [
                ['ANNUAL', 'Nghỉ phép năm'],
                ['SICK', 'Nghỉ ốm'],
                ['UNPAID', 'Nghỉ không lương'],
                ['MATERNITY', 'Nghỉ thai sản'],
                ['MARRIAGE', 'Nghỉ cưới hỏi'],
            ];
            $leaveTypeIds = [];
            foreach ($leaveTypeDefs as [$code, $name]) {
                $leaveTypeIds[$code] = (int) DB::table('leave_types')->insertGetId([
                    'tenant_id' => $newTenantId,
                    'leave_type_code' => $code,
                    'leave_type_name' => $name,
                    'status' => 'ACTIVE',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // d2. Request types + a default 1-step approval flow (admin approves).
            //     A fresh tenant only has TENANT_ADMIN + EMPLOYEE roles, so the
            //     default approver for every flow step is the tenant admin role.
            $requestTypeDefs = [
                ['NP', 'Đơn xin nghỉ phép'],
                ['TC', 'Đơn đăng ký tăng ca'],
                ['CT', 'Đơn đăng ký công tác'],
                ['TUL', 'Đơn tạm ứng lương'],
            ];
            $requestTypeIds = [];
            foreach ($requestTypeDefs as [$code, $name]) {
                $rtId = (int) DB::table('request_types')->insertGetId([
                    'tenant_id' => $newTenantId,
                    'request_type_code' => $code,
                    'request_type_name' => $name,
                    'status' => 'ACTIVE',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $flowId = (int) DB::table('approval_flows')->insertGetId([
                    'tenant_id' => $newTenantId,
                    'request_type_id' => $rtId,
                    'flow_name' => 'Quy trình duyệt '.$name,
                    'status' => 'ACTIVE',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                DB::table('approval_steps')->insert([
                    'tenant_id' => $newTenantId,
                    'approval_flow_id' => $flowId,
                    'step_order' => '1',
                    'approver_role_id' => $adminRoleId,
                    'status' => 'ACTIVE',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                DB::table('request_types')->where('id', $rtId)->update([
                    'approval_flow_id' => $flowId,
                    'updated_at' => $now,
                ]);
                $requestTypeIds[$code] = $rtId;
            }

            // e. Tenant-admin employee -------------------------------------
            // employee_code must match ^[A-Z]{2,4}[0-9]{4,8}$ — 'ADMIN' fails.
            $adminEmployeeId = (int) DB::table('employees')->insertGetId([
                'tenant_id' => $newTenantId,
                'legal_entity_id' => $newEntityId,
                'employee_code' => 'ADM0001',
                'full_name' => $admin['full_name'],
                'company_email' => $admin['company_email'],
                'password_hash' => Hash::make($admin['password']),
                'status' => 'ACTIVE',
                'hire_date' => $now->toDateString(),
                'department_id' => $departmentId,
                'position_id' => $positionId,
                'is_super_admin' => DB::raw('false'),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('employee_roles')->insert([
                'tenant_id' => $newTenantId,
                'employee_id' => $adminEmployeeId,
                'role_id' => $adminRoleId,
                'department_id' => $departmentId,
                'effective_date' => $now->toDateString(),
                'is_active' => DB::raw('true'),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return [
                'tenant' => [
                    'id' => $newTenantId,
                    'name' => $tenant['name'],
                    'code' => $tenant['code'] ?? null,
                    'status' => 'ACTIVE',
                ],
                'legal_entity' => [
                    'id' => $newEntityId,
                    'name' => $tenant['name'].' - Default Entity',
                    'code' => 'DEFAULT',
                ],
                'admin' => [
                    'id' => $adminEmployeeId,
                    'company_email' => $admin['company_email'],
                ],
                'seeded' => [
                    'department_id' => $departmentId,
                    'position_id' => $positionId,
                    'role_ids' => ['admin' => $adminRoleId, 'employee' => $employeeRoleId],
                    'leave_type_ids' => $leaveTypeIds,
                    'request_type_ids' => $requestTypeIds,
                ],
            ];
        });

        // f. Restore context so the caller's request is unaffected.
        TenantContext::clear();

        return $result;
    }
}
