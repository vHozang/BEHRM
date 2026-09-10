<?php

namespace Tests\Feature;

use App\Support\TenantContext;
use Database\Seeders\OrganizationStructureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrganizationStructureTest extends TestCase
{
    use RefreshDatabase;

    private int $headOfficeId;

    private int $branchId;

    private int $hqDepartmentId;

    private int $productionDepartmentId;

    private int $workshopId;

    private int $teamId;

    /** @var array<string, array{id:int,token:string}> */
    private array $people = [];

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->updateOrInsert(['id' => 1], [
            'name' => 'Organization test tenant',
            'code' => 'ORG-TEST',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('legal_entities')->updateOrInsert(['id' => 1], [
            'tenant_id' => 1,
            'name' => 'Trụ sở chính',
            'code' => 'HQ',
            'status' => 'ACTIVE',
            'meta' => json_encode(['branch_type' => 'HEAD_OFFICE', 'representative' => 'Đại diện HQ']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->headOfficeId = 1;
        $this->branchId = (int) DB::table('legal_entities')->insertGetId([
            'tenant_id' => 1,
            'name' => 'Chi nhánh Hà Nội',
            'code' => 'HN',
            'status' => 'ACTIVE',
            'meta' => json_encode(['branch_type' => 'BRANCH']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $gdPosition = $this->position('GD', 'Giám đốc');
        $pgdPosition = $this->position('PGD', 'Phó giám đốc');
        $managerPosition = $this->position('TP', 'Trưởng phòng');
        $workerPosition = $this->position('CN', 'Công nhân');

        $this->people['ceo'] = $this->employee('GD0001', 'Nguyễn Tổng Giám đốc', $this->headOfficeId, null, $gdPosition);
        $this->people['deputy'] = $this->employee('PGD0001', 'Trần Phó Giám đốc', $this->headOfficeId, null, $pgdPosition, $this->people['ceo']['id']);
        $this->people['hq_manager'] = $this->employee('TPHQ01', 'Lê Trưởng phòng HQ', $this->headOfficeId, null, $managerPosition);
        $this->people['branch_head'] = $this->employee('CNHN01', 'Phạm Đầu Chi nhánh', $this->branchId, null, $managerPosition);
        $this->people['workshop_head'] = $this->employee('PXHN01', 'Võ Trưởng Phân xưởng', $this->branchId, null, $managerPosition);

        $this->hqDepartmentId = $this->department('VP-HQ', 'Phòng Văn phòng', $this->headOfficeId, null, $this->people['hq_manager']['id'], 'DEPARTMENT');
        $this->productionDepartmentId = $this->department('SX', 'Phòng Sản xuất', $this->branchId, null, $this->people['branch_head']['id'], 'DEPARTMENT');
        $this->workshopId = $this->department('PX-A', 'Phân xưởng A', $this->branchId, $this->productionDepartmentId, $this->people['workshop_head']['id'], 'WORKSHOP');
        $this->teamId = $this->department('TO-A', 'Tổ Đóng gói', $this->branchId, $this->workshopId, null, 'TEAM');

        DB::table('employees')->where('id', $this->people['hq_manager']['id'])->update(['department_id' => $this->hqDepartmentId]);
        DB::table('employees')->where('id', $this->people['branch_head']['id'])->update(['department_id' => $this->productionDepartmentId]);
        DB::table('employees')->where('id', $this->people['workshop_head']['id'])->update(['department_id' => $this->workshopId]);

        $worker = $this->employee('CN0001', 'Công nhân chính', $this->branchId, $this->teamId, $workerPosition);
        $probation = $this->employee('CN0002', 'Công nhân thử việc', $this->branchId, $this->teamId, $workerPosition, null, 'PROBATION');
        $this->employee('CNSTOP', 'Công nhân nghỉ việc', $this->branchId, $this->teamId, $workerPosition, null, 'TERMINATED');
        $this->employee('SYSTEM', 'Tài khoản hệ thống', $this->branchId, $this->teamId, $workerPosition, null, 'ACTIVE', ['system_account' => true]);
        $this->employee('FREE01', 'Nhân viên chưa phân phòng', $this->branchId, null, $workerPosition);

        DB::table('employee_departments')->insert([
            'tenant_id' => 1,
            'employee_id' => $worker['id'],
            'department_id' => $this->hqDepartmentId,
            'role_in_dept' => 'Kiêm nhiệm',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $branchMeta = json_decode((string) DB::table('legal_entities')->where('id', $this->branchId)->value('meta'), true);
        $branchMeta['head_employee_id'] = $this->people['branch_head']['id'];
        DB::table('legal_entities')->where('id', $this->branchId)->update(['meta' => json_encode($branchMeta)]);

        $this->people['admin'] = $this->actor('ADMIN', ['is_admin' => true]);
        $this->people['hr'] = $this->actor('HR', ['modules' => ['hr']]);
        $this->people['manager'] = $this->actor('MANAGER', ['modules' => ['time']]);
        $this->people['dept_head'] = $this->actor('DEPT_HEAD', ['modules' => []]);
        $this->people['employee'] = $this->employee('EMPONLY', 'Nhân viên thường', $this->headOfficeId, null, $workerPosition);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    public function test_company_structure_uses_units_and_safe_aggregate_counts(): void
    {
        $data = $this->withToken($this->people['admin']['token'])
            ->getJson('/api/v1/organization-chart/structure?scope=company')
            ->assertOk()
            ->assertJsonPath('data.executives.0.display_role', 'Tổng giám đốc')
            ->assertJsonPath('data.executives.1.display_role', 'Phó giám đốc')
            ->json('data');

        $hq = $this->findNode($data['roots'], 'department:'.$this->hqDepartmentId);
        $branch = $this->findNode($data['roots'], 'branch:'.$this->branchId);
        $production = $this->findNode($data['roots'], 'department:'.$this->productionDepartmentId);
        $workshop = $this->findNode($data['roots'], 'department:'.$this->workshopId);
        $team = $this->findNode($data['roots'], 'department:'.$this->teamId);

        $this->assertNotNull($hq);
        $this->assertNotNull($branch);
        $this->assertNotNull($production);
        $this->assertNotNull($workshop);
        $this->assertNotNull($team);
        $this->assertSame('BRANCH', $branch['unit_type']);
        $this->assertSame($this->people['branch_head']['id'], $branch['head']['id']);
        $this->assertSame('WORKSHOP', $workshop['unit_type']);
        $this->assertSame('TEAM', $team['unit_type']);
        $this->assertSame(2, $team['headcount_total']);
        $this->assertSame(4, $production['headcount_total']);
        $this->assertSame(1, $hq['headcount_total'], 'Kiêm nhiệm không được làm tăng sĩ số phòng ban chính');
        $this->assertSame(5, $branch['headcount_total'], 'Chi nhánh phải tính cả nhân viên chưa phân phòng');

        $serialized = json_encode($data);
        $this->assertStringNotContainsString('base_salary', $serialized);
        $this->assertStringNotContainsString('personal_email', $serialized);
        $this->assertStringNotContainsString('profile', $serialized);
    }

    public function test_branch_and_department_scopes_return_breadcrumbs_and_full_subtrees(): void
    {
        $branch = $this->withToken($this->people['manager']['token'])
            ->getJson('/api/v1/organization-chart/structure?scope=branch&legal_entity_id='.$this->branchId)
            ->assertOk()
            ->assertJsonPath('data.scope.type', 'branch')
            ->assertJsonPath('data.roots.0.key', 'branch:'.$this->branchId)
            ->json('data');
        $this->assertCount(2, $branch['breadcrumbs']);
        $this->assertFalse($this->findNode($branch['roots'], 'department:'.$this->productionDepartmentId)['collapsed_by_default']);

        $department = $this->withToken($this->people['dept_head']['token'])
            ->getJson('/api/v1/organization-chart/structure?scope=department&department_id='.$this->workshopId)
            ->assertOk()
            ->assertJsonPath('data.scope.type', 'department')
            ->assertJsonPath('data.roots.0.key', 'department:'.$this->workshopId)
            ->json('data');
        $this->assertSame('department:'.$this->teamId, $department['roots'][0]['children'][0]['key']);
        $this->assertGreaterThanOrEqual(4, count($department['breadcrumbs']));
    }

    public function test_company_executives_are_preferred_from_head_office(): void
    {
        DB::table('employees')->where('id', $this->people['ceo']['id'])->update([
            'manager_id' => $this->people['deputy']['id'],
        ]);
        $branchDirector = $this->employee(
            'GDBRANCH',
            'Giám đốc chi nhánh',
            $this->branchId,
            null,
            (int) DB::table('positions')->where('position_code', 'GD')->value('id'),
        );

        $data = $this->withToken($this->people['admin']['token'])
            ->getJson('/api/v1/organization-chart/structure?scope=company')
            ->assertOk()
            ->json('data');

        $this->assertSame($this->people['ceo']['id'], $data['executives'][0]['employee']['id']);
        $this->assertNotSame($branchDirector['id'], $data['executives'][0]['employee']['id']);
    }

    public function test_1200_workers_are_represented_by_one_aggregate_not_employee_nodes(): void
    {
        $rows = [];
        for ($index = 1; $index <= 1200; $index++) {
            $rows[] = [
                'employee_code' => 'LOAD'.str_pad((string) $index, 4, '0', STR_PAD_LEFT),
                'full_name' => 'Công nhân tải '.$index,
                'status' => 'ACTIVE',
                'department_id' => $this->workshopId,
                'tenant_id' => 1,
                'legal_entity_id' => $this->branchId,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if (count($rows) === 300) {
                DB::table('employees')->insert($rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            DB::table('employees')->insert($rows);
        }

        $data = $this->withToken($this->people['hr']['token'])
            ->getJson('/api/v1/organization-chart/structure?scope=department&department_id='.$this->workshopId)
            ->assertOk()
            ->json('data');

        $this->assertSame(1203, $data['roots'][0]['headcount_total']);
        $this->assertLessThan(5, $data['summary']['unit_count']);
        $this->assertStringNotContainsString('Công nhân tải 1200', json_encode($data));
    }

    public function test_permissions_allow_management_roles_and_reject_regular_employee(): void
    {
        foreach (['admin', 'hr', 'manager', 'dept_head'] as $role) {
            $this->withToken($this->people[$role]['token'])
                ->getJson('/api/v1/organization-chart/structure')
                ->assertOk();
        }

        $this->withToken($this->people['employee']['token'])
            ->getJson('/api/v1/organization-chart/structure')
            ->assertForbidden();

        $this->withToken($this->people['employee']['token'])
            ->getJson('/api/v1/employees/org-chart')
            ->assertOk();
    }

    public function test_invalid_heads_cross_entity_managers_and_cycles_are_handled(): void
    {
        $this->withToken($this->people['admin']['token'])
            ->putJson('/api/v1/legal-entities/'.$this->branchId, [
                'meta' => ['head_employee_id' => $this->people['hq_manager']['id']],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('data.errors.head_employee_id.0', 'Người đứng đầu phải thuộc đúng chi nhánh');

        $this->withToken($this->people['admin']['token'])
            ->patchJson('/api/v1/departments/'.$this->workshopId, [
                'manager_id' => $this->people['hq_manager']['id'],
                'unit_type' => 'TEAM',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('data.errors.manager_id.0', 'Người phụ trách phải thuộc cùng chi nhánh');

        $this->withToken($this->people['admin']['token'])
            ->patchJson('/api/v1/departments/'.$this->workshopId, [
                'manager_id' => $this->people['workshop_head']['id'],
                'unit_type' => 'WORKSHOP',
            ])
            ->assertOk()
            ->assertJsonPath('data.unit_type', 'WORKSHOP');

        $cycleA = $this->department('CYCLE-A', 'Phòng vòng A', $this->branchId, null, null, 'DEPARTMENT');
        $cycleB = $this->department('CYCLE-B', 'Phòng vòng B', $this->branchId, $cycleA, null, 'DEPARTMENT');
        $meta = json_decode((string) DB::table('departments')->where('id', $cycleA)->value('meta'), true);
        $meta['parent_id'] = $cycleB;
        $meta['parent_department_id'] = $cycleB;
        DB::table('departments')->where('id', $cycleA)->update(['meta' => json_encode($meta)]);

        $warnings = $this->withToken($this->people['admin']['token'])
            ->getJson('/api/v1/organization-chart/structure')
            ->assertOk()
            ->json('data.warnings');
        $this->assertContains('DEPARTMENT_CYCLE', array_column($warnings, 'code'));
    }

    public function test_structure_seeder_is_idempotent_and_preserves_existing_metadata(): void
    {
        DB::table('departments')->where('id', $this->workshopId)->update([
            'meta' => json_encode(['parent_id' => $this->productionDepartmentId, 'manager_id' => $this->people['workshop_head']['id'], 'cost_center' => 'PX-001']),
        ]);
        DB::table('legal_entities')->where('id', $this->branchId)->update([
            'meta' => json_encode(['branch_type' => 'BRANCH', 'representative' => 'Người đại diện']),
        ]);

        $seeder = new OrganizationStructureSeeder;
        $seeder->run();
        $seeder->run();

        $departmentMeta = json_decode((string) DB::table('departments')->where('id', $this->workshopId)->value('meta'), true);
        $entityMeta = json_decode((string) DB::table('legal_entities')->where('id', $this->branchId)->value('meta'), true);
        $this->assertSame('WORKSHOP', $departmentMeta['unit_type']);
        $this->assertSame('PX-001', $departmentMeta['cost_center']);
        $this->assertSame('Người đại diện', $entityMeta['representative']);
        $this->assertSame($this->people['branch_head']['id'], $entityMeta['head_employee_id']);
    }

    private function position(string $code, string $name): int
    {
        return (int) DB::table('positions')->insertGetId([
            'position_code' => $code,
            'position_name' => $name,
            'status' => true,
            'tenant_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array{id:int,token:string} */
    private function employee(
        string $code,
        string $name,
        int $entityId,
        ?int $departmentId,
        ?int $positionId,
        ?int $managerId = null,
        string $status = 'ACTIVE',
        array $profile = [],
    ): array {
        $id = (int) DB::table('employees')->insertGetId([
            'employee_code' => $code,
            'full_name' => $name,
            'company_email' => strtolower($code).'.'.Str::lower(Str::random(5)).'@example.test',
            'status' => $status,
            'department_id' => $departmentId,
            'position_id' => $positionId,
            'manager_id' => $managerId,
            'profile' => $profile === [] ? null : json_encode($profile),
            'tenant_id' => 1,
            'legal_entity_id' => $entityId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $token = Str::random(64);
        DB::table('api_tokens')->insert([
            'employee_id' => $id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHour(),
            'tenant_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('id', 'token');
    }

    /** @return array{id:int,token:string} */
    private function actor(string $roleCode, array $meta): array
    {
        $actor = $this->employee('ACT'.Str::upper(Str::random(6)), Str::headline($roleCode), $this->headOfficeId, null, null);
        $roleId = (int) DB::table('roles')->insertGetId([
            'role_code' => $roleCode,
            'role_name' => Str::headline($roleCode),
            'is_system_role' => true,
            'meta' => json_encode($meta),
            'tenant_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('employee_roles')->insert([
            'employee_id' => $actor['id'],
            'role_id' => $roleId,
            'is_active' => true,
            'tenant_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $actor;
    }

    private function department(string $code, string $name, int $entityId, ?int $parentId, ?int $managerId, string $unitType): int
    {
        return (int) DB::table('departments')->insertGetId([
            'department_code' => $code,
            'department_name' => $name,
            'status' => true,
            'meta' => json_encode([
                'parent_id' => $parentId,
                'parent_department_id' => $parentId,
                'manager_id' => $managerId,
                'unit_type' => $unitType,
            ]),
            'tenant_id' => 1,
            'legal_entity_id' => $entityId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param array<int, array<string, mixed>> $nodes */
    private function findNode(array $nodes, string $key): ?array
    {
        foreach ($nodes as $node) {
            if (($node['key'] ?? null) === $key) {
                return $node;
            }
            $match = $this->findNode($node['children'] ?? [], $key);
            if ($match) {
                return $match;
            }
        }

        return null;
    }
}
