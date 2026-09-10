<?php

namespace Tests\Feature;

use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class ContractSelfServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->updateOrInsert(['id' => 1], [
            'name' => 'Contract test tenant',
            'code' => 'CONTRACT-TEST',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('legal_entities')->updateOrInsert(['id' => 1], [
            'tenant_id' => 1,
            'name' => 'Contract test entity',
            'code' => 'CONTRACT-TEST',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('roles')->updateOrInsert(['id' => 1], [
            'role_code' => 'EMPLOYEE',
            'role_name' => 'Employee',
            'is_system_role' => true,
            'meta' => json_encode(['modules' => []]),
            'tenant_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('contract_types')->updateOrInsert(['id' => 1], [
            'contract_type_code' => 'HDLD01',
            'contract_type_name' => 'Hợp đồng không xác định thời hạn',
            'tenant_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    public function test_employee_can_view_and_sign_only_their_pending_contract(): void
    {
        Mail::fake();
        config(['app.debug' => true]);

        $owner = $this->employee('owner');
        $other = $this->employee('other');
        $ownContract = $this->contract($owner['id'], 'PENDING_SIGN');
        $otherContract = $this->contract($other['id'], 'PENDING_SIGN');
        $hr = $this->employee('hr');
        $hrRoleId = (int) DB::table('roles')->insertGetId([
            'role_code' => 'HR',
            'role_name' => 'Human Resources',
            'is_system_role' => true,
            'meta' => json_encode(['modules' => ['hr']]),
            'tenant_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('employee_roles')->where('employee_id', $hr['id'])->update(['role_id' => $hrRoleId]);

        $this->withToken($owner['token'])
            ->getJson('/api/v1/contracts?employee_id='.$owner['id'])
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $ownContract);
        $this->withToken($owner['token'])->getJson("/api/v1/contracts/{$ownContract}/render")->assertOk();
        $this->withToken($owner['token'])->getJson("/api/v1/contracts/{$otherContract}/render")->assertForbidden();
        $this->withToken($owner['token'])->postJson("/api/v1/contracts/{$otherContract}/request-otp")->assertForbidden();
        $this->withToken($owner['token'])->postJson("/api/v1/contracts/{$otherContract}/sign", [
            'otp' => '000000',
            'signature' => 'data:image/png;base64,b3RoZXI=',
        ])->assertForbidden();
        $this->withToken($hr['token'])->postJson("/api/v1/contracts/{$ownContract}/request-otp")
            ->assertForbidden()
            ->assertJsonPath('message', 'Chỉ nhân viên sở hữu hợp đồng mới có thể yêu cầu OTP');
        $this->withToken($hr['token'])->postJson("/api/v1/contracts/{$ownContract}/sign", [
            'otp' => '000000',
            'signature' => 'data:image/png;base64,aHI=',
        ])->assertForbidden()
            ->assertJsonPath('message', 'Chỉ nhân viên sở hữu hợp đồng mới có thể ký');

        $otp = $this->withToken($owner['token'])
            ->postJson("/api/v1/contracts/{$ownContract}/request-otp")
            ->assertOk()
            ->json('data.dev_otp');

        $this->assertMatchesRegularExpression('/^\d{6}$/', $otp);
        $this->withToken($owner['token'])->postJson("/api/v1/contracts/{$ownContract}/sign", [
            'otp' => $otp,
            'signature' => 'data:image/png;base64,c2lnbmF0dXJl',
        ])->assertOk()->assertJsonPath('data.meta.sign_status', 'SIGNED');

        $this->withToken($owner['token'])
            ->postJson("/api/v1/contracts/{$ownContract}/request-otp")
            ->assertUnprocessable()
            ->assertJsonPath('data.errors.sign_status.0', 'Hợp đồng chưa được gửi ký hoặc đã hoàn tất ký');
        $this->withToken($owner['token'])->postJson("/api/v1/contracts/{$ownContract}/sign", [
            'otp' => $otp,
            'signature' => 'data:image/png;base64,c2lnbmF0dXJlMg==',
        ])->assertUnprocessable()
            ->assertJsonPath('data.errors.sign_status.0', 'Hợp đồng chưa được gửi ký hoặc đã hoàn tất ký');
    }

    public function test_contract_must_be_pending_and_otp_must_be_valid(): void
    {
        $employee = $this->employee('validation');
        $notPending = $this->contract($employee['id'], null);

        $this->withToken($employee['token'])
            ->postJson("/api/v1/contracts/{$notPending}/request-otp")
            ->assertUnprocessable()
            ->assertJsonPath('data.errors.sign_status.0', 'Hợp đồng chưa được gửi ký hoặc đã hoàn tất ký');

        $pending = $this->contract($employee['id'], 'PENDING_SIGN', [
            'sign_otp' => [
                'hash' => Hash::make('123456'),
                'expires_at' => now()->addMinutes(10)->toIso8601String(),
            ],
        ]);
        $this->withToken($employee['token'])->postJson("/api/v1/contracts/{$pending}/sign", [
            'otp' => '654321',
            'signature' => 'data:image/png;base64,c2lnbmF0dXJl',
        ])->assertUnprocessable()->assertJsonPath('data.errors.otp.0', 'Mã OTP không đúng');

        DB::table('contracts')->where('id', $pending)->update(['meta' => json_encode([
            'sign_status' => 'PENDING_SIGN',
            'sign_otp' => [
                'hash' => Hash::make('123456'),
                'expires_at' => now()->subMinute()->toIso8601String(),
            ],
        ])]);
        $this->withToken($employee['token'])->postJson("/api/v1/contracts/{$pending}/sign", [
            'otp' => '123456',
            'signature' => 'data:image/png;base64,c2lnbmF0dXJl',
        ])->assertUnprocessable()->assertJsonPath('data.errors.otp.0', 'Mã OTP đã hết hạn, vui lòng yêu cầu lại');
    }

    private function employee(string $name): array
    {
        $employeeId = (int) DB::table('employees')->insertGetId([
            'employee_code' => 'QA'.Str::upper($name).Str::upper(Str::random(4)),
            'full_name' => Str::headline($name),
            'company_email' => $name.'.'.Str::lower(Str::random(4)).'@example.test',
            'status' => 'ACTIVE',
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('employee_roles')->insert([
            'employee_id' => $employeeId,
            'role_id' => 1,
            'is_active' => true,
            'tenant_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $token = Str::random(64);
        DB::table('api_tokens')->insert([
            'employee_id' => $employeeId,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHour(),
            'tenant_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['id' => $employeeId, 'token' => $token];
    }

    private function contract(int $employeeId, ?string $signStatus, array $extraMeta = []): int
    {
        $meta = $extraMeta;
        if ($signStatus !== null) {
            $meta['sign_status'] = $signStatus;
        }

        return (int) DB::table('contracts')->insertGetId([
            'employee_id' => $employeeId,
            'contract_type_id' => 1,
            'contract_number' => 'HD-'.$employeeId.'-'.Str::random(4),
            'status' => 'CÓ_HIỆU_LỰC',
            'start_date' => now()->subMonth()->toDateString(),
            'meta' => $meta ? json_encode($meta) : null,
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
