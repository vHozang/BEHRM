<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OvertimeTicketTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_offer_ticket_only_to_managed_employee_and_employee_can_respond(): void
    {
        $manager = $this->actor('MANAGER', ['time']);
        $managed = $this->employee('OT-MANAGED', $manager['id']);
        $outsider = $this->employee('OT-OUTSIDE');

        $created = $this->withToken($manager['token'])->postJson('/api/v1/overtime-tickets', [
            'employee_id' => $managed['id'],
            'work_date' => '2026-08-11',
            'start_time' => '14:00',
            'end_time' => '15:00',
            'reason' => 'Hoàn tất lô sản xuất',
        ]);

        $created->assertCreated()
            ->assertJsonPath('data.status', 'OFFERED')
            ->assertJsonPath('data.meta.kind', 'MANAGER_TICKET');
        $ticketId = (int) $created->json('data.id');

        $this->withToken($manager['token'])->postJson('/api/v1/overtime-tickets', [
            'employee_id' => $outsider['id'],
            'work_date' => '2026-08-11',
            'start_time' => '14:00',
            'end_time' => '15:00',
        ])->assertForbidden();

        $this->withToken($outsider['token'])
            ->postJson("/api/v1/overtime-tickets/{$ticketId}/respond", ['decision' => 'accept'])
            ->assertForbidden();

        $this->withToken($managed['token'])
            ->postJson("/api/v1/overtime-tickets/{$ticketId}/respond", ['decision' => 'accept'])
            ->assertOk()
            ->assertJsonPath('data.status', 'APPROVED');
    }

    public function test_ticket_and_employee_request_require_a_minimum_fifteen_minute_interval(): void
    {
        $manager = $this->actor('MANAGER', ['time']);
        $managed = $this->employee('OT-MINIMUM', $manager['id']);

        $this->withToken($manager['token'])->postJson('/api/v1/overtime-tickets', [
            'employee_id' => $managed['id'],
            'work_date' => '2026-08-11',
            'start_time' => '14:00',
            'end_time' => '14:10',
        ])->assertUnprocessable();

        $this->withToken($managed['token'])->postJson('/api/v1/overtime-requests', [
            'employee_id' => $managed['id'],
            'work_date' => '2026-08-11',
            'start_time' => '14:00',
            'end_time' => '14:10',
        ])->assertUnprocessable();
    }

    public function test_employee_request_is_self_scoped_and_manager_must_use_ticket_for_other_people(): void
    {
        $manager = $this->actor('MANAGER', ['time']);
        $managed = $this->employee('OT-SELF', $manager['id']);

        $this->withToken($manager['token'])->postJson('/api/v1/overtime-requests', [
            'employee_id' => $managed['id'],
            'work_date' => '2026-08-11',
            'start_time' => '14:00',
            'end_time' => '15:00',
        ])->assertForbidden();

        $this->withToken($managed['token'])->postJson('/api/v1/overtime-requests', [
            'employee_id' => $managed['id'],
            'work_date' => '2026-08-11',
            'start_time' => '14:00',
            'end_time' => '15:00',
            'reason' => 'Tự đăng ký',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('data.meta.kind', 'EMPLOYEE_REQUEST');
    }

    /** @return array{id:int, token:string} */
    private function actor(string $roleCode, array $modules): array
    {
        $actor = $this->employee('OT-'.$roleCode);
        $roleId = DB::table('roles')->insertGetId([
            'role_code' => $roleCode,
            'role_name' => Str::headline(strtolower($roleCode)),
            'is_system_role' => true,
            'meta' => json_encode(['modules' => $modules]),
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

    /** @return array{id:int, token:string} */
    private function employee(string $code, ?int $managerId = null): array
    {
        $id = DB::table('employees')->insertGetId([
            'employee_code' => $code,
            'full_name' => str_replace('-', ' ', $code),
            'company_email' => strtolower($code).'@example.test',
            'status' => 'ACTIVE',
            'manager_id' => $managerId,
            'tenant_id' => 1,
            'legal_entity_id' => 1,
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

        return ['id' => $id, 'token' => $token];
    }
}
