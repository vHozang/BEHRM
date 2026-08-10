<?php

namespace Tests\Feature;

use App\Mail\PayslipMail;
use App\Services\PayslipDeliveryService;
use App\Services\PayslipDocumentService;
use App\Support\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class PayslipPublicationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    public function test_partial_publication_generates_only_ready_payslips_and_keeps_an_audit_trail(): void
    {
        Storage::fake('local');
        Mail::fake();

        $accountant = $this->actor('ACCOUNTANT', ['payroll']);
        $admin = $this->actor('ADMIN', null, true);
        $hr = $this->actor('HR', ['hr']);
        $payrollViewer = $this->actor('PAYROLL_VIEWER', ['payroll']);
        $fixture = $this->payrollFixture(10, 8, 7);
        $periodId = $fixture['period_id'];

        $this->withToken($accountant['token'])
            ->getJson("/api/v1/salary-periods/{$periodId}/payslips/readiness")
            ->assertOk()
            ->assertJsonPath('data.total_employees', 10)
            ->assertJsonPath('data.pass_count', 8)
            ->assertJsonPath('data.fail_count', 2);

        $this->withToken($payrollViewer['token'])
            ->postJson("/api/v1/salary-periods/{$periodId}/submit")
            ->assertForbidden();

        $this->withToken($accountant['token'])
            ->postJson("/api/v1/salary-periods/{$periodId}/submit")
            ->assertUnprocessable()
            ->assertJsonPath('data.readiness.fail_count', 2);
        $this->assertDatabaseHas('salary_periods', ['id' => $periodId, 'status' => 'OPEN']);

        $this->withToken($accountant['token'])
            ->postJson("/api/v1/salary-periods/{$periodId}/submit", ['allow_partial' => true])
            ->assertOk()
            ->assertJsonPath('data.status', 'CHỜ_DUYỆT');

        $this->withToken($admin['token'])
            ->postJson("/api/v1/salary-periods/{$periodId}/close")
            ->assertUnprocessable()
            ->assertJsonPath('data.readiness.fail_count', 2);

        $this->withToken($admin['token'])
            ->postJson("/api/v1/salary-periods/{$periodId}/close", ['allow_partial' => true])
            ->assertOk()
            ->assertJsonPath('data.status', 'CLOSED');

        $periodMeta = json_decode((string) DB::table('salary_periods')->where('id', $periodId)->value('meta'), true);
        $this->assertSame(['SUBMIT', 'CLOSE'], array_column($periodMeta['payslip_readiness_audit'], 'phase'));
        $this->assertCount(2, $periodMeta['payslip_readiness_audit'][1]['excluded_employee_ids']);
        $this->assertCount(2, $periodMeta['payslip_readiness_audit'][1]['exclusions']);
        $this->assertDatabaseCount('payslip_publication_issues', 2);

        $publish = $this->withToken($admin['token'])
            ->postJson("/api/v1/salary-periods/{$periodId}/payslips/publish")
            ->assertStatus(202)
            ->assertJsonPath('data.queued', 8)
            ->assertJsonPath('data.fail_count', 2);
        $this->assertSame(8, $publish->json('data.pass_count'));

        $documents = DB::table('payslip_documents')->where('salary_period_id', $periodId)->orderBy('employee_id')->get();
        $this->assertCount(8, $documents);
        $this->assertSame(7, $documents->where('email_status', 'SENT')->count());
        $this->assertSame(1, $documents->where('email_status', 'MISSING_RECIPIENT')->count());
        Mail::assertSent(PayslipMail::class, 7);

        foreach ($documents as $document) {
            $this->assertSame('READY', $document->generation_status);
            $this->assertTrue(Storage::disk('local')->exists($document->storage_path));
            $contents = Storage::disk('local')->get($document->storage_path);
            $this->assertStringStartsWith('%PDF', $contents);
            $this->assertSame(hash('sha256', $contents), $document->sha256);
            $this->assertSame(strlen($contents), (int) $document->file_size);
        }

        $fallbackDocument = $documents->firstWhere('employee_id', $fixture['employees'][6]['id']);
        $this->assertSame('personal_email', $fallbackDocument->recipient_source);
        $this->assertSame($fixture['employees'][6]['personal_email'], $fallbackDocument->recipient_email);

        $firstDocument = $documents->first();
        $originalHash = $firstDocument->sha256;
        $originalGeneratedAt = $firstDocument->generated_at;

        // Even if a legacy/manual write repairs excluded rows after CLOSE, the
        // immutable close snapshot keeps those employees out of this period.
        $repairedInvalidEmployee = $fixture['employees'][8];
        $repairedInvalidDetailId = (int) DB::table('salary_details')
            ->where('period_id', $periodId)
            ->where('employee_id', $repairedInvalidEmployee['id'])
            ->value('id');
        DB::table('salary_details')->where('id', $repairedInvalidDetailId)->update([
            'net_salary' => 900,
            'updated_at' => now(),
        ]);
        DB::table('salary_breakdowns')
            ->where('salary_detail_id', $repairedInvalidDetailId)
            ->where('item_type', 'NET')
            ->where('item_code', 'NET')
            ->update(['amount' => 900, 'updated_at' => now()]);

        $repairedMissingEmployee = $fixture['employees'][9];
        $repairedMissingDetailId = DB::table('salary_details')->insertGetId([
            'period_id' => $periodId,
            'employee_id' => $repairedMissingEmployee['id'],
            'gross_salary' => 1000,
            'net_salary' => 900,
            'transfer_status' => 'PENDING',
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->breakdown($repairedMissingDetailId, 'EARNING', 'BASE', 'Lương cơ bản (theo công)', 1000);
        $this->breakdown($repairedMissingDetailId, 'DEDUCTION', 'FIXED_DEDUCTION', 'Khấu trừ cố định', 100);
        $this->breakdown($repairedMissingDetailId, 'NET', 'NET', 'Thực nhận', 900);

        $this->withToken($admin['token'])
            ->getJson("/api/v1/salary-periods/{$periodId}/payslips/readiness")
            ->assertOk()
            ->assertJsonPath('data.pass_count', 8)
            ->assertJsonPath('data.fail_count', 2);

        $this->withToken($admin['token'])
            ->postJson("/api/v1/salary-periods/{$periodId}/payslips/publish")
            ->assertStatus(202)
            ->assertJsonPath('data.already_sent', 7)
            ->assertJsonPath('data.queued', 1);
        Mail::assertSent(PayslipMail::class, 7);
        $this->assertSame($originalHash, DB::table('payslip_documents')->where('id', $firstDocument->id)->value('sha256'));
        $this->assertSame($originalGeneratedAt, DB::table('payslip_documents')->where('id', $firstDocument->id)->value('generated_at'));

        $missingEmployee = $fixture['employees'][7];
        $missingIssue = DB::table('payslip_publication_issues')
            ->where('employee_id', $missingEmployee['id'])
            ->where('issue_code', 'MISSING_RECIPIENT')
            ->first();
        $this->assertNotNull($missingIssue);

        $issueResponse = $this->withToken($hr['token'])
            ->getJson('/api/v1/payroll/payslip-issues?status=OPEN')
            ->assertOk();
        $this->assertStringNotContainsString('gross_salary', $issueResponse->getContent());
        $this->assertStringNotContainsString('net_salary', $issueResponse->getContent());
        $this->withToken($hr['token'])->getJson('/api/v1/salary-periods')->assertForbidden();

        DB::table('employees')->where('id', $missingEmployee['id'])->update([
            'company_email' => 'repaired-recipient@example.test',
            'updated_at' => now(),
        ]);
        $this->withToken($hr['token'])
            ->postJson('/api/v1/payroll/payslip-issues/'.$missingIssue->id.'/retry')
            ->assertStatus(202);
        Mail::assertSent(PayslipMail::class, 8);
        $this->assertDatabaseHas('payslip_documents', [
            'employee_id' => $missingEmployee['id'],
            'email_status' => 'SENT',
            'recipient_email' => 'repaired-recipient@example.test',
        ]);
    }

    public function test_payslip_pdf_and_email_endpoints_are_owner_scoped(): void
    {
        Storage::fake('local');
        Mail::fake();

        $admin = $this->actor('ADMIN', null, true);
        $hr = $this->actor('HR', ['hr']);
        $fixture = $this->payrollFixture(2, 2);
        $periodId = $fixture['period_id'];
        DB::table('salary_periods')->where('id', $periodId)->update(['status' => 'CLOSED']);

        $this->withToken($admin['token'])
            ->postJson("/api/v1/salary-periods/{$periodId}/payslips/publish")
            ->assertStatus(202);
        Mail::assertSent(PayslipMail::class, 2);

        $first = $fixture['employees'][0];
        $second = $fixture['employees'][1];
        $firstDetailId = (int) DB::table('salary_details')
            ->where('period_id', $periodId)
            ->where('employee_id', $first['id'])
            ->value('id');

        $this->withToken($first['token'])
            ->get("/api/v1/salary-details/{$firstDetailId}/payslip/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->withToken($first['token'])
            ->get("/api/v1/salary-details/{$firstDetailId}/payslip/pdf?download=1")
            ->assertOk()
            ->assertHeader('content-disposition');
        $this->withToken($second['token'])
            ->get("/api/v1/salary-details/{$firstDetailId}/payslip/pdf")
            ->assertForbidden();
        $this->withToken($hr['token'])
            ->get("/api/v1/salary-details/{$firstDetailId}/payslip/pdf")
            ->assertForbidden();

        $this->withToken($second['token'])
            ->getJson('/api/v1/salary-details?employee_id='.$first['id'])
            ->assertForbidden();
        $this->withToken($first['token'])
            ->getJson('/api/v1/salary-details?employee_id='.$first['id'])
            ->assertOk()
            ->assertJsonCount(1, 'data.items');

        $this->withToken($second['token'])
            ->postJson("/api/v1/salary-details/{$firstDetailId}/payslip/email", [
                'email' => 'attacker@example.test',
            ])
            ->assertForbidden();
        $this->withToken($first['token'])
            ->postJson("/api/v1/salary-details/{$firstDetailId}/payslip/email", [
                'email' => 'attacker@example.test',
            ])
            ->assertStatus(202);
        Mail::assertSent(PayslipMail::class, 3);
        $this->assertDatabaseHas('payslip_documents', [
            'salary_detail_id' => $firstDetailId,
            'recipient_email' => $first['company_email'],
            'email_status' => 'SENT',
        ]);
    }

    public function test_pdf_generation_failure_creates_an_issue_without_sending_email(): void
    {
        Storage::fake('local');
        Mail::fake();
        $fixture = $this->payrollFixture(1, 1);
        DB::table('salary_periods')->where('id', $fixture['period_id'])->update(['status' => 'CLOSED']);
        $detailId = (int) DB::table('salary_details')->where('period_id', $fixture['period_id'])->value('id');
        TenantContext::set(1, 1);

        Pdf::shouldReceive('loadView')
            ->once()
            ->andThrow(new RuntimeException('Dompdf render failed'));

        try {
            app(PayslipDocumentService::class)->generate($detailId);
            $this->fail('PDF generation should have failed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Dompdf render failed', $exception->getMessage());
        }

        $this->assertDatabaseHas('payslip_documents', [
            'salary_detail_id' => $detailId,
            'generation_status' => 'FAILED',
            'email_status' => 'PENDING',
        ]);
        $this->assertDatabaseHas('payslip_publication_issues', [
            'salary_detail_id' => $detailId,
            'issue_type' => 'PDF',
            'issue_code' => 'PDF_GENERATION_FAILED',
            'status' => 'OPEN',
        ]);
        Mail::assertNothingSent();
    }

    public function test_smtp_failure_keeps_the_official_pdf_and_retry_reuses_it(): void
    {
        Storage::fake('local');
        $fixture = $this->payrollFixture(1, 1);
        DB::table('salary_periods')->where('id', $fixture['period_id'])->update(['status' => 'CLOSED']);
        $detailId = (int) DB::table('salary_details')->where('period_id', $fixture['period_id'])->value('id');
        TenantContext::set(1, 1);

        $document = app(PayslipDocumentService::class)->generate($detailId);
        $originalHash = $document->sha256;
        $originalGeneratedAt = $document->generated_at?->toISOString();
        Event::listen(MessageSending::class, static function (): never {
            throw new RuntimeException('SMTP unavailable');
        });

        try {
            app(PayslipDeliveryService::class)->send($document);
            $this->fail('Email delivery should have failed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('SMTP unavailable', $exception->getMessage());
        }

        $failed = $document->fresh();
        $this->assertSame('READY', $failed->generation_status);
        $this->assertSame('FAILED', $failed->email_status);
        $this->assertSame($originalHash, $failed->sha256);
        $this->assertTrue(Storage::disk('local')->exists($failed->storage_path));
        $this->assertDatabaseHas('payslip_publication_issues', [
            'payslip_document_id' => $failed->id,
            'issue_type' => 'EMAIL',
            'issue_code' => 'EMAIL_DELIVERY_FAILED',
            'status' => 'OPEN',
        ]);

        Event::forget(MessageSending::class);
        Mail::fake();
        $failed->update(['email_status' => 'QUEUED', 'last_error' => null]);
        $sent = app(PayslipDeliveryService::class)->send($failed->fresh());

        $this->assertSame('SENT', $sent->email_status);
        $this->assertSame($originalHash, $sent->sha256);
        $this->assertSame($originalGeneratedAt, $sent->generated_at?->toISOString());
        $this->assertDatabaseHas('payslip_publication_issues', [
            'payslip_document_id' => $sent->id,
            'issue_type' => 'EMAIL',
            'status' => 'RESOLVED',
        ]);
        Mail::assertSent(PayslipMail::class, 1);
    }

    /**
     * @return array{id:int, token:string}
     */
    private function actor(string $roleCode, ?array $modules = null, bool $admin = false): array
    {
        $employee = $this->employee('SYS-'.$roleCode, strtolower($roleCode).'@example.test', null, [
            'system_account' => true,
        ]);
        $roleId = DB::table('roles')->insertGetId([
            'role_code' => $roleCode,
            'role_name' => Str::headline(strtolower($roleCode)),
            'is_system_role' => true,
            'meta' => json_encode($admin ? ['is_admin' => true] : ['modules' => $modules ?? []]),
            'tenant_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('employee_roles')->insert([
            'employee_id' => $employee['id'],
            'role_id' => $roleId,
            'is_active' => true,
            'tenant_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['id' => $employee['id'], 'token' => $employee['token']];
    }

    /**
     * @return array{period_id:int, employees:array<int,array<string,mixed>>}
     */
    private function payrollFixture(int $employeeCount, int $passCount, ?int $missingEmailIndex = null): array
    {
        $departmentId = DB::table('departments')->insertGetId([
            'department_code' => 'PAY-QA-'.Str::upper(Str::random(4)),
            'department_name' => 'Phòng kiểm thử lương',
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $periodId = DB::table('salary_periods')->insertGetId([
            'period_code' => 'PAY-QA-'.Str::upper(Str::random(6)),
            'period_name' => 'Kỳ kiểm thử phiếu lương',
            'period_type' => 'MONTHLY',
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-31',
            'status' => 'OPEN',
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $employees = [];
        for ($index = 0; $index < $employeeCount; $index++) {
            $companyEmail = $index === $missingEmailIndex || $index === 6
                ? null
                : "payslip.worker{$index}@example.test";
            $personalEmail = $index === 6 ? 'personal.worker6@example.test' : null;
            $employee = $this->employee(
                'PAY'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                $companyEmail,
                $personalEmail,
                [],
                $departmentId,
            );
            $employees[] = $employee;

            if ($index >= $passCount + 1) {
                continue;
            }

            $isInvalid = $index === $passCount;
            $detailId = DB::table('salary_details')->insertGetId([
                'period_id' => $periodId,
                'employee_id' => $employee['id'],
                'gross_salary' => 1000,
                'net_salary' => $isInvalid ? 850 : 900,
                'transfer_status' => 'PENDING',
                'tenant_id' => 1,
                'legal_entity_id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->breakdown($detailId, 'EARNING', 'BASE', 'Lương cơ bản (theo công)', 1000);
            $this->breakdown($detailId, 'DEDUCTION', 'FIXED_DEDUCTION', 'Khấu trừ cố định', 100);
            $this->breakdown($detailId, 'NET', 'NET', 'Thực nhận', $isInvalid ? 850 : 900);
        }

        return ['period_id' => $periodId, 'employees' => $employees];
    }

    /**
     * @return array{id:int, token:string, company_email:?string, personal_email:?string}
     */
    private function employee(
        string $code,
        ?string $companyEmail,
        ?string $personalEmail = null,
        array $profile = [],
        ?int $departmentId = null,
    ): array {
        $id = DB::table('employees')->insertGetId([
            'employee_code' => $code,
            'full_name' => 'Nhân viên '.$code,
            'company_email' => $companyEmail,
            'personal_email' => $personalEmail,
            'status' => 'ACTIVE',
            'hire_date' => '2025-01-01',
            'base_salary' => 1000,
            'department_id' => $departmentId,
            'profile' => json_encode($profile),
            'is_super_admin' => false,
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

        return compact('id', 'token', 'companyEmail', 'personalEmail') + [
            'company_email' => $companyEmail,
            'personal_email' => $personalEmail,
        ];
    }

    private function breakdown(int $detailId, string $type, string $code, string $name, float $amount): void
    {
        DB::table('salary_breakdowns')->insert([
            'salary_detail_id' => $detailId,
            'item_type' => $type,
            'item_code' => $code,
            'item_name' => $name,
            'amount' => $amount,
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
