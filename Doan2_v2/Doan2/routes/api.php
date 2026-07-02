<?php

use App\Http\Controllers\Api\AiAssistantController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AttendanceRegularizationController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ContractController;
use App\Http\Controllers\Api\ContractTemplateController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\GenericResourceController;
use App\Http\Controllers\Api\HolidayController;
use App\Http\Controllers\Api\LeaveController;
use App\Http\Controllers\Api\OnboardingController;
use App\Http\Controllers\Api\LegalEntityController;
use App\Http\Controllers\Api\PayrollController;
use App\Http\Controllers\Api\PlatformController;
use App\Http\Controllers\Api\ProfileChangeRequestController;
use App\Http\Controllers\Api\RecruitmentController;
use App\Http\Controllers\Api\RecruitmentPostController;
use App\Http\Controllers\Api\RequestApprovalController;
use App\Http\Controllers\Api\SettingsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/', [GenericResourceController::class, 'root']);

Route::prefix('v1')->group(function (): void {
    Route::get('/', [GenericResourceController::class, 'root']);
    Route::get('/health', [GenericResourceController::class, 'health']);
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);

    // ─── Public Routes ───────────────────────────────────
    Route::get('/public/positions', [GenericResourceController::class, 'index'])->defaults('resource', 'positions');
    Route::post('/public/recruitment/applications', [RecruitmentController::class, 'store']);
    Route::get('/public/recruitment-posts', [RecruitmentPostController::class, 'publicListing']);
    Route::get('/public/recruitment-posts/{slug}', [RecruitmentPostController::class, 'publicShow']);
    Route::get('/recruitment-candidates/{id}/cv', [RecruitmentController::class, 'downloadCv'])->whereNumber('id');

    // Máy chấm công (vân tay/khuôn mặt/thẻ) — token nội bộ, gọi bởi bridge thiết bị.
    Route::post('/internal/attendance/device-punch', [\App\Http\Controllers\Api\DeviceAttendanceController::class, 'punch']);

    // ─── Internal API ────────────────────────────────────
    Route::post('/internal/recruitment-ai-jobs/process', function (Request $request) {
        $provided = $request->header('x-internal-token') ?: $request->bearerToken();

        abort_if($provided !== env('INTERNAL_SERVICE_TOKEN'), 403, 'Invalid internal token');

        $jobs = DB::table('recruitment_ai_scoring_jobs')
            ->whereIn('status', ['PENDING', 'FAILED'])
            ->limit((int) $request->integer('limit', 10))
            ->get();

        $scorer = new \App\Services\RecruitmentScoringService();
        $processed = 0;
        $failed = 0;

        foreach ($jobs as $job) {
            try {
                $candidate = DB::table('recruitment_candidates')->where('id', $job->candidate_id)->first();

                if (! $candidate) {
                    throw new \RuntimeException("Candidate {$job->candidate_id} not found");
                }

                $position = $candidate->recruitment_position_id
                    ? DB::table('recruitment_positions')->where('id', $candidate->recruitment_position_id)->first()
                    : null;

                $result = $scorer->score($candidate, $position);

                // Ghi điểm vào cột ai_score của ứng viên (kèm chi tiết khớp kỹ năng).
                $candidateMeta = json_decode($candidate->meta ?? '{}', true) ?: [];
                $candidateMeta['ai_score'] = $result['score'];

                DB::table('recruitment_candidates')->where('id', $candidate->id)->update([
                    'ai_score' => $result['score'],
                    'ai_scoring_status' => 'DONE',
                    'ai_scoring_error' => null,
                    'ai_scored_at' => now(),
                    'ai_matched_skills_json' => json_encode($result['matched_skills']),
                    'ai_missing_skills_json' => json_encode($result['missing_skills']),
                    'meta' => json_encode($candidateMeta),
                    'updated_at' => now(),
                ]);

                // Lưu điểm lên chính job (jobs không có cột score riêng => dùng meta jsonb).
                $jobMeta = json_decode($job->meta ?? '{}', true) ?: [];
                $jobMeta['score'] = $result['score'];

                DB::table('recruitment_ai_scoring_jobs')->where('id', $job->id)->update([
                    'status' => 'DONE',
                    'last_error' => null,
                    'meta' => json_encode($jobMeta),
                    'updated_at' => now(),
                ]);

                $processed++;
            } catch (\Throwable $e) {
                DB::table('recruitment_ai_scoring_jobs')->where('id', $job->id)->update([
                    'status' => 'FAILED',
                    'last_error' => $e->getMessage(),
                    'updated_at' => now(),
                ]);

                DB::table('recruitment_candidates')->where('id', $job->candidate_id)->update([
                    'ai_scoring_status' => 'FAILED',
                    'ai_scoring_error' => $e->getMessage(),
                    'updated_at' => now(),
                ]);

                $failed++;
            }
        }

        return response()->json([
            'status' => 200,
            'message' => 'AI scoring queue processed',
            'data' => ['processed' => $processed, 'failed' => $failed],
        ]);
    });

    // ═══════════════════════════════════════════════════════
    // AUTHENTICATED ROUTES
    // ═══════════════════════════════════════════════════════
    Route::middleware(['hrm.auth', 'module.access'])->group(function (): void {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/refresh', [AuthController::class, 'refresh']);
        Route::post('/auth/change-password', [AuthController::class, 'changePassword']);
        Route::get('/auth/hierarchy', [AuthController::class, 'hierarchy']);
        Route::get('/activity-logs', [AuthController::class, 'activityLogs']);
        Route::get('/audit-logs', [AuditLogController::class, 'index']);

        // ─── Settings (Generic) ──────────────────────────
        Route::get('/settings/general', [GenericResourceController::class, 'index'])->defaults('resource', 'settings');
        Route::put('/settings/general', [GenericResourceController::class, 'store'])->defaults('resource', 'settings');
        Route::get('/settings/notifications', [GenericResourceController::class, 'index'])->defaults('resource', 'settings');
        Route::put('/settings/notifications', [GenericResourceController::class, 'store'])->defaults('resource', 'settings');

        // ═══════════════════════════════════════════════════
        // NHÓM B: DEDICATED CONTROLLERS (Nghiệp vụ cốt lõi)
        // ═══════════════════════════════════════════════════

        // ─── Employees ───────────────────────────────────
        Route::get('/employees/org-chart', [EmployeeController::class, 'orgChart']);
        Route::get('/employees', [EmployeeController::class, 'index']);
        Route::post('/employees', [EmployeeController::class, 'store']);
        Route::get('/employees/{id}', [EmployeeController::class, 'show'])->whereNumber('id');
        Route::put('/employees/{id}', [EmployeeController::class, 'update'])->whereNumber('id');
        Route::patch('/employees/{id}', [EmployeeController::class, 'update'])->whereNumber('id');
        Route::delete('/employees/{id}', [EmployeeController::class, 'destroy'])->whereNumber('id');
        Route::post('/employees/{id}/detach-from-org', [EmployeeController::class, 'detachFromOrg'])->whereNumber('id');
        Route::get('/employees/{id}/departments', [EmployeeController::class, 'departments'])->whereNumber('id');
        Route::patch('/employees/{id}/profile', [EmployeeController::class, 'updateProfile'])->whereNumber('id');
        Route::get('/employees/{id}/profile', [EmployeeController::class, 'showProfile'])->whereNumber('id');
        Route::get('/employees/{id}/employment-histories', [EmployeeController::class, 'employmentHistories'])->whereNumber('id');
        Route::get('/employees/{id}/certificates', [EmployeeController::class, 'certificates'])->whereNumber('id');
        Route::post('/employees/{id}/certificates', [EmployeeController::class, 'storeCertificate'])->whereNumber('id');
        Route::delete('/employees/{id}/certificates/{certId}', [EmployeeController::class, 'destroyCertificate'])->whereNumber('id');
        Route::post('/employees/import-probation', [EmployeeController::class, 'importProbation']);
        Route::get('/employees/{id}/leave-balances', [LeaveController::class, 'balance'])->whereNumber('id');

        // ─── Contracts ───────────────────────────────────
        Route::get('/contracts', [ContractController::class, 'index']);
        Route::post('/contracts', [ContractController::class, 'store']);
        Route::get('/contracts/{id}', [ContractController::class, 'show'])->whereNumber('id');
        Route::put('/contracts/{id}', [ContractController::class, 'update'])->whereNumber('id');
        Route::patch('/contracts/{id}', [ContractController::class, 'update'])->whereNumber('id');
        Route::delete('/contracts/{id}', [ContractController::class, 'destroy'])->whereNumber('id');
        Route::post('/contracts/{id}/activate', [ContractController::class, 'activate'])->whereNumber('id');
        Route::post('/contracts/{id}/terminate', [ContractController::class, 'terminate'])->whereNumber('id');

        // Contract document & signing (mẫu + render + ký trên cổng)
        Route::get('/contracts/{id}/render', [ContractController::class, 'render'])->whereNumber('id');
        Route::post('/contracts/{id}/send-sign', [ContractController::class, 'sendForSign'])->whereNumber('id');
        Route::post('/contracts/{id}/request-otp', [ContractController::class, 'requestOtp'])->whereNumber('id');
        Route::post('/contracts/{id}/sign', [ContractController::class, 'sign'])->whereNumber('id');

        // Contract templates (thư viện mẫu hợp đồng)
        Route::get('/contract-templates/placeholders', [ContractTemplateController::class, 'placeholders']);
        Route::get('/contract-templates', [ContractTemplateController::class, 'index']);
        Route::post('/contract-templates', [ContractTemplateController::class, 'store']);
        Route::get('/contract-templates/{id}', [ContractTemplateController::class, 'show'])->whereNumber('id');
        Route::patch('/contract-templates/{id}', [ContractTemplateController::class, 'update'])->whereNumber('id');
        Route::delete('/contract-templates/{id}', [ContractTemplateController::class, 'destroy'])->whereNumber('id');

        // ─── Leave Management ────────────────────────────
        Route::get('/leave-requests', [LeaveController::class, 'index']);
        Route::post('/leave-requests', [LeaveController::class, 'store']);
        Route::get('/leave-requests/{id}', [LeaveController::class, 'show'])->whereNumber('id');
        Route::put('/leave-requests/{id}', [LeaveController::class, 'update'])->whereNumber('id');
        Route::patch('/leave-requests/{id}', [LeaveController::class, 'update'])->whereNumber('id');
        Route::delete('/leave-requests/{id}', [LeaveController::class, 'destroy'])->whereNumber('id');
        Route::post('/leave-requests/{id}/approve', [LeaveController::class, 'approve'])->whereNumber('id');
        Route::post('/leave-requests/{id}/reject', [LeaveController::class, 'reject'])->whereNumber('id');
        Route::post('/leave-requests/{id}/cancel', [LeaveController::class, 'cancel'])->whereNumber('id');
        Route::post('/leave/accrual/run', [LeaveController::class, 'accrualRun']);

        // ─── Attendance & Shifts ─────────────────────────
        Route::get('/attendances', [AttendanceController::class, 'index']);
        Route::get('/attendances/{id}', [AttendanceController::class, 'show'])->whereNumber('id');
        Route::patch('/attendances/{id}', [AttendanceController::class, 'update'])->whereNumber('id');
        Route::post('/attendances/check-in', [AttendanceController::class, 'checkIn']);
        Route::post('/attendances/check-out', [AttendanceController::class, 'checkOut']);
        Route::post('/attendances/{id}/verify', [AttendanceController::class, 'verifyAttendance'])->whereNumber('id');
        // Quản lý máy chấm công (đa-tenant device registry).
        Route::get('/attendance-devices', [\App\Http\Controllers\Api\AttendanceDeviceController::class, 'index']);
        Route::post('/attendance-devices', [\App\Http\Controllers\Api\AttendanceDeviceController::class, 'store']);
        Route::patch('/attendance-devices/{id}', [\App\Http\Controllers\Api\AttendanceDeviceController::class, 'update'])->whereNumber('id');
        Route::delete('/attendance-devices/{id}', [\App\Http\Controllers\Api\AttendanceDeviceController::class, 'destroy'])->whereNumber('id');
        Route::post('/attendance-devices/{id}/rotate-token', [\App\Http\Controllers\Api\AttendanceDeviceController::class, 'rotateToken'])->whereNumber('id');
        Route::get('/overtime-requests', [AttendanceController::class, 'overtimeIndex']);
        Route::get('/overtime-requests/usage', [AttendanceController::class, 'overtimeUsage']);
        Route::post('/attendance/summary/run', [AttendanceController::class, 'summaryRun']);
        Route::get('/attendance/timesheet', [AttendanceController::class, 'timesheet']);
        Route::post('/attendance/recompute', [AttendanceController::class, 'recompute']);
        Route::post('/overtime-requests', [AttendanceController::class, 'storeOvertime']);
        Route::post('/overtime-requests/{id}/approve', [AttendanceController::class, 'approveOvertime'])->whereNumber('id');
        Route::post('/shift-roster/generate', [AttendanceController::class, 'generateRoster']);
        Route::post('/shift-swaps', [AttendanceController::class, 'requestShiftSwap']);
        Route::post('/shift-swaps/{id}/approve', [AttendanceController::class, 'approveShiftSwap'])->whereNumber('id');

        // Phủ ca khi vắng đột xuất (coverage / điều người tăng ca + chuỗi giao ca)
        Route::get('/shift-coverage-requests', [\App\Http\Controllers\Api\ShiftCoverageController::class, 'index']);
        Route::post('/shift-coverage-requests', [\App\Http\Controllers\Api\ShiftCoverageController::class, 'store']);
        Route::post('/shift-coverage-requests/{id}/offer', [\App\Http\Controllers\Api\ShiftCoverageController::class, 'offer'])->whereNumber('id');
        Route::post('/shift-coverage-requests/{id}/cancel', [\App\Http\Controllers\Api\ShiftCoverageController::class, 'cancel'])->whereNumber('id');
        Route::get('/shift-coverage-offers', [\App\Http\Controllers\Api\ShiftCoverageController::class, 'myOffers']);
        Route::post('/shift-coverage-offers/{id}/respond', [\App\Http\Controllers\Api\ShiftCoverageController::class, 'respond'])->whereNumber('id');

        // Thông báo cá nhân (chuông) — receiver = NV đang đăng nhập
        Route::get('/notifications', [\App\Http\Controllers\Api\NotificationController::class, 'index']);
        Route::post('/notifications/read-all', [\App\Http\Controllers\Api\NotificationController::class, 'markAllRead']);
        Route::post('/notifications/{id}/read', [\App\Http\Controllers\Api\NotificationController::class, 'markRead'])->whereNumber('id');

        // Attendance regularization (đơn điều chỉnh công)
        Route::get('/attendance-adjustments', [AttendanceRegularizationController::class, 'index']);
        Route::post('/attendance-adjustments', [AttendanceRegularizationController::class, 'store']);
        Route::get('/attendance-adjustments/{id}', [AttendanceRegularizationController::class, 'show'])->whereNumber('id');
        Route::post('/attendance-adjustments/{id}/approve', [AttendanceRegularizationController::class, 'approve'])->whereNumber('id');
        Route::post('/attendance-adjustments/{id}/reject', [AttendanceRegularizationController::class, 'reject'])->whereNumber('id');
        Route::post('/attendance-adjustments/{id}/cancel', [AttendanceRegularizationController::class, 'cancel'])->whereNumber('id');

        // Profile change requests (đơn đề nghị thay đổi thông tin cá nhân)
        Route::get('/profile-change-requests/fields', [ProfileChangeRequestController::class, 'fields']);
        Route::get('/profile-change-requests', [ProfileChangeRequestController::class, 'index']);
        Route::post('/profile-change-requests', [ProfileChangeRequestController::class, 'store']);
        Route::get('/profile-change-requests/{id}', [ProfileChangeRequestController::class, 'show'])->whereNumber('id');
        Route::post('/profile-change-requests/{id}/approve', [ProfileChangeRequestController::class, 'approve'])->whereNumber('id');
        Route::post('/profile-change-requests/{id}/reject', [ProfileChangeRequestController::class, 'reject'])->whereNumber('id');
        Route::post('/profile-change-requests/{id}/cancel', [ProfileChangeRequestController::class, 'cancel'])->whereNumber('id');

        // ─── Payroll ─────────────────────────────────────
        Route::get('/salary-periods', [PayrollController::class, 'index']);
        Route::post('/salary-periods', [PayrollController::class, 'store']);
        Route::get('/salary-periods/{id}', [PayrollController::class, 'show'])->whereNumber('id');
        Route::put('/salary-periods/{id}', [PayrollController::class, 'update'])->whereNumber('id');
        Route::patch('/salary-periods/{id}', [PayrollController::class, 'update'])->whereNumber('id');
        Route::delete('/salary-periods/{id}', [PayrollController::class, 'destroy'])->whereNumber('id');
        Route::post('/salary-periods/{id}/close', [PayrollController::class, 'closePeriod'])->whereNumber('id');
        Route::post('/payroll/run', [PayrollController::class, 'run']);
        // Công khoán theo sản phẩm (piece-rate).
        Route::get('/piece-rate-entries', [\App\Http\Controllers\Api\PieceRateController::class, 'index']);
        Route::post('/piece-rate-entries', [\App\Http\Controllers\Api\PieceRateController::class, 'store']);
        Route::delete('/piece-rate-entries/{id}', [\App\Http\Controllers\Api\PieceRateController::class, 'destroy'])->whereNumber('id');
        Route::get('/salary-details', [PayrollController::class, 'salaryDetails']);
        Route::post('/salary-details', [PayrollController::class, 'storeSalaryDetail']);
        Route::put('/salary-details/{id}', [PayrollController::class, 'updateSalaryDetail'])->whereNumber('id');
        Route::patch('/salary-details/{id}', [PayrollController::class, 'updateSalaryDetail'])->whereNumber('id');
        Route::delete('/salary-details/{id}', [PayrollController::class, 'destroySalaryDetail'])->whereNumber('id');
        Route::get('/salary-details/{id}/payslip', [PayrollController::class, 'payslip'])->whereNumber('id');

        // ─── Recruitment ─────────────────────────────────
        Route::get('/recruitment-candidates', [RecruitmentController::class, 'index']);
        Route::post('/recruitment-candidates', [RecruitmentController::class, 'store']);
        Route::get('/recruitment-candidates/{id}', [RecruitmentController::class, 'show'])->whereNumber('id');
        Route::put('/recruitment-candidates/{id}', [RecruitmentController::class, 'update'])->whereNumber('id');
        Route::patch('/recruitment-candidates/{id}', [RecruitmentController::class, 'update'])->whereNumber('id');
        Route::delete('/recruitment-candidates/{id}', [RecruitmentController::class, 'destroy'])->whereNumber('id');
        Route::post('/recruitment-candidates/{id}/cv', [RecruitmentController::class, 'uploadCv'])->whereNumber('id');
        Route::post('/recruitment-candidates/{id}/advance', [RecruitmentController::class, 'advanceStage'])->whereNumber('id');
        Route::patch('/recruitment-candidates/{id}/manager-review', [RecruitmentController::class, 'managerReview'])->whereNumber('id');
        Route::post('/recruitment-candidates/{id}/ai-score/retry', [RecruitmentController::class, 'retryAiScore'])->whereNumber('id');
        Route::post('/recruitment-candidates/{id}/reject', [RecruitmentController::class, 'reject'])->whereNumber('id');
        Route::post('/recruitment-candidates/{id}/hire', [RecruitmentController::class, 'hire'])->whereNumber('id');
        Route::get('/interviews', [RecruitmentController::class, 'interviewsIndex']);
        Route::post('/interviews', [RecruitmentController::class, 'storeInterview']);
        Route::get('/interviews/{id}', [RecruitmentController::class, 'showInterview'])->whereNumber('id');
        Route::put('/interviews/{id}', [RecruitmentController::class, 'updateInterview'])->whereNumber('id');
        Route::patch('/interviews/{id}', [RecruitmentController::class, 'updateInterview'])->whereNumber('id');
        Route::delete('/interviews/{id}', [RecruitmentController::class, 'destroyInterview'])->whereNumber('id');
        Route::patch('/interviews/{id}/manager-review', [RecruitmentController::class, 'interviewManagerReview'])->whereNumber('id');
        Route::get('/recruitment-ai/feedback-stats', [RecruitmentController::class, 'aiFeedbackStats']);

        // ─── Recruitment Posts (Bài đăng tuyển dụng) ────
        Route::get('/recruitment-posts', [RecruitmentPostController::class, 'index']);
        Route::post('/recruitment-posts', [RecruitmentPostController::class, 'store']);
        Route::get('/recruitment-posts/{id}', [RecruitmentPostController::class, 'show'])->whereNumber('id');
        Route::put('/recruitment-posts/{id}', [RecruitmentPostController::class, 'update'])->whereNumber('id');
        Route::patch('/recruitment-posts/{id}', [RecruitmentPostController::class, 'update'])->whereNumber('id');
        Route::delete('/recruitment-posts/{id}', [RecruitmentPostController::class, 'destroy'])->whereNumber('id');
        Route::post('/recruitment-posts/{id}/publish', [RecruitmentPostController::class, 'publish'])->whereNumber('id');
        Route::post('/recruitment-posts/{id}/close', [RecruitmentPostController::class, 'close'])->whereNumber('id');

        // ─── Request Approval ────────────────────────────
        Route::get('/requests', [RequestApprovalController::class, 'index']);
        Route::post('/requests', [RequestApprovalController::class, 'store']);
        Route::get('/requests/{id}', [RequestApprovalController::class, 'show'])->whereNumber('id');
        Route::put('/requests/{id}', [RequestApprovalController::class, 'update'])->whereNumber('id');
        Route::patch('/requests/{id}', [RequestApprovalController::class, 'update'])->whereNumber('id');
        Route::delete('/requests/{id}', [RequestApprovalController::class, 'destroy'])->whereNumber('id');
        Route::post('/requests/{id}/approve', [RequestApprovalController::class, 'approve'])->whereNumber('id');
        Route::post('/requests/{id}/reject', [RequestApprovalController::class, 'reject'])->whereNumber('id');
        Route::post('/requests/{id}/cancel', [RequestApprovalController::class, 'cancel'])->whereNumber('id');

        // ─── Policies (special actions, CRUD via generic) ─
        Route::post('/policies/{id}/acknowledge', [GenericResourceController::class, 'update'])->defaults('resource', 'policies')->defaults('child', 'acknowledge');
        Route::post('/news/{id}/read', [GenericResourceController::class, 'store'])->defaults('resource', 'news')->defaults('child', 'read');

        // ─── Department membership (matrix) ──────────────
        Route::get('/departments/{id}/members', [DepartmentController::class, 'members'])->whereNumber('id');
        Route::post('/departments/{id}/members', [DepartmentController::class, 'addMember'])->whereNumber('id');
        Route::get('/departments/cost-summary', [DepartmentController::class, 'costSummary']);
        Route::post('/departments/{id}/assign-employee', [DepartmentController::class, 'assignEmployee'])->whereNumber('id');
        Route::post('/departments/{id}/remove-employee', [DepartmentController::class, 'unassignEmployee'])->whereNumber('id');
        Route::delete('/departments/{id}/members/{employeeId}', [DepartmentController::class, 'removeMember'])->whereNumber('id')->whereNumber('employeeId');

        // ─── Settings (tenant business-rule config) ──────
        Route::get('/settings/catalog', [SettingsController::class, 'catalog']);
        Route::post('/settings/save', [SettingsController::class, 'save']);

        // ─── Holidays (statutory VN holiday seeding) ─────
        // MUST precede the generic /{resource} catch-all below.
        Route::get('/holidays/statutory-preview', [HolidayController::class, 'preview']);
        Route::post('/holidays/seed-vn', [HolidayController::class, 'seed']);

        // ─── Onboarding / Offboarding checklists ─────────
        Route::get('/onboarding-checklists', [OnboardingController::class, 'index']);
        Route::post('/onboarding-checklists', [OnboardingController::class, 'store']);
        Route::get('/onboarding-checklists/{id}', [OnboardingController::class, 'show'])->whereNumber('id');
        Route::patch('/onboarding-checklists/{id}/status', [OnboardingController::class, 'updateStatus'])->whereNumber('id');
        Route::delete('/onboarding-checklists/{id}', [OnboardingController::class, 'destroy'])->whereNumber('id');
        Route::post('/onboarding-checklists/{id}/tasks', [OnboardingController::class, 'addTask'])->whereNumber('id');
        Route::patch('/onboarding-checklists/{id}/tasks/{taskId}', [OnboardingController::class, 'updateTask'])->whereNumber('id')->whereNumber('taskId');
        Route::delete('/onboarding-checklists/{id}/tasks/{taskId}', [OnboardingController::class, 'deleteTask'])->whereNumber('id')->whereNumber('taskId');

        // ─── Legal Entities (multi-entity management) ────
        Route::get('/legal-entities', [LegalEntityController::class, 'index']);
        Route::post('/legal-entities', [LegalEntityController::class, 'store']);
        Route::get('/legal-entities/{id}', [LegalEntityController::class, 'show'])->whereNumber('id');
        Route::put('/legal-entities/{id}', [LegalEntityController::class, 'update'])->whereNumber('id');
        Route::patch('/legal-entities/{id}', [LegalEntityController::class, 'update'])->whereNumber('id');
        Route::delete('/legal-entities/{id}', [LegalEntityController::class, 'destroy'])->whereNumber('id');

        // ─── Analytics / Reports ─────────────────────────
        Route::get('/dashboard/stats', [AnalyticsController::class, 'stats']);
        Route::post('/reports/generate', [AnalyticsController::class, 'generateReport']);

        // ─── AI HR Assistant ─────────────────────────────
        Route::post('/ai/ask', [AiAssistantController::class, 'ask']);

        // ─── Platform (super-admin, cross-tenant) ────────
        Route::middleware('platform.admin')->prefix('platform')->group(function (): void {
            Route::get('/tenants', [PlatformController::class, 'index']);
            Route::post('/tenants', [PlatformController::class, 'store']);
            Route::get('/tenants/{id}', [PlatformController::class, 'show'])->whereNumber('id');
        });

        // ═══════════════════════════════════════════════════
        // NHÓM A: GENERIC CRUD (Bảng cơ bản / danh mục)
        // ĐẶT CUỐI CÙNG — catch-all cho các resource còn lại
        // ═══════════════════════════════════════════════════
        Route::get('/{resource}', [GenericResourceController::class, 'index']);
        Route::post('/{resource}', [GenericResourceController::class, 'store']);
        Route::get('/{resource}/{id}', [GenericResourceController::class, 'show'])->whereNumber('id');
        Route::put('/{resource}/{id}', [GenericResourceController::class, 'update'])->whereNumber('id');
        Route::patch('/{resource}/{id}', [GenericResourceController::class, 'update'])->whereNumber('id');
        Route::delete('/{resource}/{id}', [GenericResourceController::class, 'destroy'])->whereNumber('id');
    });
});
