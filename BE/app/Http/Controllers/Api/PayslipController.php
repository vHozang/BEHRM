<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\PublishPayslipJob;
use App\Jobs\SendPayslipEmailJob;
use App\Models\PayslipDocument;
use App\Models\PayslipPublicationIssue;
use App\Models\SalaryDetail;
use App\Models\SalaryPeriod;
use App\Services\PayslipIssueService;
use App\Services\PayslipReadinessService;
use App\Support\AccessControl;
use App\Support\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class PayslipController extends Controller
{
    public function readiness(Request $request, int $id, PayslipReadinessService $service): JsonResponse
    {
        if (! $this->canManage($request)) {
            return $this->forbidden('Chỉ Kế toán hoặc Admin được kiểm tra phát hành phiếu lương.');
        }

        $period = SalaryPeriod::find($id);
        if (! $period) {
            return $this->notFound('Không tìm thấy kỳ lương.');
        }

        return $this->ok($service->analyze($period), 'Kết quả kiểm tra phiếu lương.');
    }

    public function publish(
        Request $request,
        int $id,
        PayslipReadinessService $readiness,
        PayslipIssueService $issues
    ): JsonResponse {
        if (! $this->canManage($request)) {
            return $this->forbidden('Chỉ Kế toán hoặc Admin được phát hành phiếu lương.');
        }

        $period = SalaryPeriod::find($id);
        if (! $period) {
            return $this->notFound('Không tìm thấy kỳ lương.');
        }
        if (! $period->isClosed()) {
            return $this->validationError(['status' => ['Chỉ kỳ lương đã chốt mới được phát hành.']]);
        }

        $analysis = $readiness->analyze($period);
        $actorId = (int) $request->attributes->get('auth_employee_id');
        $issues->syncPayrollIssues($period, $analysis, $actorId, true);

        $queued = 0;
        $skippedSent = 0;
        foreach ($analysis['ready'] as $ready) {
            $document = PayslipDocument::firstOrCreate(
                ['salary_detail_id' => $ready['salary_detail_id']],
                [
                    'tenant_id' => $period->tenant_id,
                    'legal_entity_id' => $period->legal_entity_id,
                    'salary_period_id' => $period->id,
                    'employee_id' => $ready['employee_id'],
                    'generation_status' => 'PENDING',
                    'email_status' => 'PENDING',
                    'published_by' => $actorId,
                ]
            );

            if ($document->generation_status === 'READY'
                && $document->email_status === 'SENT'
                && $document->storage_path
                && Storage::disk('local')->exists($document->storage_path)) {
                $skippedSent++;

                continue;
            }

            PublishPayslipJob::dispatch(
                $ready['salary_detail_id'],
                (int) $period->tenant_id,
                (int) $period->legal_entity_id,
                $actorId,
            );
            $queued++;
        }

        if ($analysis['fail_count'] > 0) {
            Notifier::notifyMany(
                $this->hrIds((int) $period->tenant_id),
                'Đã phát hành một phần phiếu lương',
                "Kỳ {$period->period_code} có {$analysis['fail_count']} nhân viên chưa đủ điều kiện nhận phiếu.",
                'payslip_issue',
                $period->id,
                ['priority' => 'high'],
                $actorId,
            );
        }

        return response()->json([
            'status' => 202,
            'message' => 'Đã đưa phiếu lương hợp lệ vào hàng đợi phát hành.',
            'data' => [
                'period_id' => $period->id,
                'pass_count' => $analysis['pass_count'],
                'fail_count' => $analysis['fail_count'],
                'queued' => $queued,
                'already_sent' => $skippedSent,
                'issues' => $analysis['issues'],
            ],
        ], 202);
    }

    public function status(Request $request, int $id, PayslipReadinessService $readiness): JsonResponse
    {
        if (! $this->canManage($request)) {
            return $this->forbidden('Chỉ Kế toán hoặc Admin được xem tiến độ phát hành.');
        }

        $period = SalaryPeriod::find($id);
        if (! $period) {
            return $this->notFound('Không tìm thấy kỳ lương.');
        }

        $analysis = $readiness->analyze($period);
        $documents = PayslipDocument::query()->where('salary_period_id', $period->id)->get();
        $openIssues = PayslipPublicationIssue::query()
            ->where('salary_period_id', $period->id)
            ->whereIn('status', ['OPEN', 'DEFERRED'])
            ->get();

        return $this->ok([
            'period_id' => $period->id,
            'total_employees' => $analysis['total_employees'],
            'pass_count' => $analysis['pass_count'],
            'fail_count' => $analysis['fail_count'],
            'documents' => [
                'pending' => $documents->whereIn('generation_status', ['PENDING', 'PROCESSING'])->count(),
                'ready' => $documents->where('generation_status', 'READY')->count(),
                'pdf_failed' => $documents->where('generation_status', 'FAILED')->count(),
                'sent' => $documents->where('email_status', 'SENT')->count(),
                'missing_email' => $documents->where('email_status', 'MISSING_RECIPIENT')->count(),
                'email_failed' => $documents->where('email_status', 'FAILED')->count(),
            ],
            'issues' => [
                'payroll' => $openIssues->where('issue_type', 'PAYROLL')->count(),
                'pdf' => $openIssues->where('issue_type', 'PDF')->count(),
                'email' => $openIssues->where('issue_type', 'EMAIL')->count(),
            ],
        ], 'Tiến độ phát hành phiếu lương.');
    }

    public function archive(Request $request, int $id): JsonResponse|BinaryFileResponse
    {
        if (! $this->canManage($request)) {
            return $this->forbidden('Chỉ Kế toán hoặc Admin được tải phiếu lương cả kỳ.');
        }

        $period = SalaryPeriod::find($id);
        if (! $period) {
            return $this->notFound('Không tìm thấy kỳ lương.');
        }

        $documents = PayslipDocument::query()
            ->where('salary_period_id', $period->id)
            ->where('generation_status', 'READY')
            ->whereNotNull('storage_path')
            ->get()
            ->filter(fn (PayslipDocument $document) => Storage::disk('local')->exists($document->storage_path));

        if ($documents->isEmpty()) {
            return $this->validationError(['documents' => ['Kỳ lương chưa có PDF đã phát hành.']]);
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'payslips-');
        if (! $tempPath) {
            return response()->json(['status' => 500, 'message' => 'Không thể tạo file ZIP tạm.', 'data' => null], 500);
        }

        $zip = new ZipArchive;
        if ($zip->open($tempPath, ZipArchive::OVERWRITE) !== true) {
            @unlink($tempPath);

            return response()->json(['status' => 500, 'message' => 'Không thể mở file ZIP.', 'data' => null], 500);
        }
        foreach ($documents as $document) {
            $zip->addFile(Storage::disk('local')->path($document->storage_path), $document->filename);
        }
        $zip->close();

        $filename = 'Phieu_luong_'.$period->period_code.'.zip';

        return response()->download($tempPath, $filename, [
            'Cache-Control' => 'private, no-store, max-age=0',
        ])->deleteFileAfterSend(true);
    }

    public function pdf(Request $request, int $id): JsonResponse|BinaryFileResponse
    {
        $detail = SalaryDetail::find($id);
        if (! $detail) {
            return $this->notFound('Không tìm thấy phiếu lương.');
        }
        if (! $this->canAccessDetail($request, $detail)) {
            return $this->forbidden('Bạn không có quyền xem phiếu lương này.');
        }

        $document = PayslipDocument::query()
            ->where('salary_detail_id', $detail->id)
            ->where('generation_status', 'READY')
            ->first();
        if (! $document || ! $document->storage_path || ! Storage::disk('local')->exists($document->storage_path)) {
            return $this->notFound('Phiếu lương PDF chưa được phát hành.');
        }

        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response()->file(Storage::disk('local')->path($document->storage_path), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="'.$document->filename.'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    public function email(Request $request, int $id): JsonResponse
    {
        $detail = SalaryDetail::find($id);
        if (! $detail) {
            return $this->notFound('Không tìm thấy phiếu lương.');
        }
        if (! $this->canAccessDetail($request, $detail)) {
            return $this->forbidden('Bạn không có quyền gửi phiếu lương này.');
        }

        $document = PayslipDocument::query()
            ->where('salary_detail_id', $detail->id)
            ->where('generation_status', 'READY')
            ->first();
        if (! $document) {
            return $this->validationError(['document' => ['Phiếu lương PDF chưa được phát hành.']]);
        }

        $document->update(['email_status' => 'QUEUED', 'last_error' => null]);
        SendPayslipEmailJob::dispatch(
            $document->id,
            (int) $document->tenant_id,
            (int) $document->legal_entity_id,
        );

        return response()->json([
            'status' => 202,
            'message' => 'Đã đưa email phiếu lương vào hàng đợi.',
            'data' => ['document_id' => $document->id, 'email_status' => 'QUEUED'],
        ], 202);
    }

    public function issues(Request $request): JsonResponse
    {
        $employeeId = (int) $request->attributes->get('auth_employee_id');
        if (! AccessControl::hasCapability($employeeId, 'payslip_issues.view')) {
            return $this->forbidden('Bạn không có quyền xem danh sách phiếu chưa phát hành.');
        }

        $query = PayslipPublicationIssue::query()
            ->with([
                'employee:id,employee_code,full_name,department_id',
                'employee.department:id,department_name',
                'period:id,period_code,status',
            ])
            ->orderByRaw("CASE WHEN status = 'OPEN' THEN 0 WHEN status = 'DEFERRED' THEN 1 ELSE 2 END")
            ->orderByDesc('updated_at');

        foreach (['salary_period_id', 'issue_type', 'status'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->query($field));
            }
        }

        $page = $query->paginate(min(max((int) $request->query('per_page', 50), 1), 100));
        $items = collect($page->items())->map(fn (PayslipPublicationIssue $issue) => [
            'id' => $issue->id,
            'salary_period_id' => $issue->salary_period_id,
            'period_code' => $issue->period?->period_code,
            'employee_id' => $issue->employee_id,
            'employee_code' => $issue->employee?->employee_code,
            'full_name' => $issue->employee?->full_name,
            'department_name' => $issue->employee?->department?->department_name,
            'issue_type' => $issue->issue_type,
            'issue_code' => $issue->issue_code,
            'message' => $issue->message,
            'resolution_hint' => $issue->resolution_hint,
            'status' => $issue->status,
            'updated_at' => $issue->updated_at,
            'can_retry' => $this->canRetryIssue($request, $issue),
        ]);

        return $this->ok([
            'items' => $items,
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ], 'Danh sách phiếu lương cần xử lý.');
    }

    public function retryIssue(Request $request, int $id): JsonResponse
    {
        $employeeId = (int) $request->attributes->get('auth_employee_id');
        if (! AccessControl::hasCapability($employeeId, 'payslip_issues.view')) {
            return $this->forbidden('Bạn không có quyền xử lý lỗi phiếu lương.');
        }

        $issue = PayslipPublicationIssue::find($id);
        if (! $issue) {
            return $this->notFound('Không tìm thấy lỗi phát hành.');
        }

        if ($issue->issue_type === 'EMAIL' && $this->canRetryIssue($request, $issue)) {
            $document = PayslipDocument::find($issue->payslip_document_id);
            if (! $document) {
                return $this->validationError(['document' => ['Chưa có PDF để gửi lại.']]);
            }
            $document->update(['email_status' => 'QUEUED', 'last_error' => null]);
            SendPayslipEmailJob::dispatch($document->id, $document->tenant_id, $document->legal_entity_id);

            return response()->json(['status' => 202, 'message' => 'Đã đưa email vào hàng đợi gửi lại.', 'data' => null], 202);
        }

        if ($issue->issue_type === 'EMAIL') {
            return $this->forbidden('HR chỉ được gửi lại sau khi bổ sung email người nhận.');
        }

        if ($issue->issue_type === 'PDF' && $this->canManage($request) && $issue->salary_detail_id) {
            PublishPayslipJob::dispatch(
                $issue->salary_detail_id,
                $issue->tenant_id,
                $issue->legal_entity_id,
                $employeeId,
            );

            return response()->json(['status' => 202, 'message' => 'Đã đưa PDF vào hàng đợi tạo lại.', 'data' => null], 202);
        }

        return $this->validationError([
            'issue' => ['Lỗi dữ liệu payroll của kỳ đã chốt cần được xử lý bằng điều chỉnh ở kỳ sau.'],
        ]);
    }

    private function canManage(Request $request): bool
    {
        return AccessControl::hasAnyRole(
            (int) $request->attributes->get('auth_employee_id'),
            ['ADMIN', 'TENANT_ADMIN', 'ACCOUNTANT']
        );
    }

    private function canAccessDetail(Request $request, SalaryDetail $detail): bool
    {
        $employeeId = (int) $request->attributes->get('auth_employee_id');

        return $this->canManage($request) || $employeeId === (int) $detail->employee_id;
    }

    private function canRetryIssue(Request $request, PayslipPublicationIssue $issue): bool
    {
        if ($this->canManage($request)) {
            return in_array($issue->issue_type, ['EMAIL', 'PDF'], true);
        }

        return $issue->issue_type === 'EMAIL' && $issue->issue_code === 'MISSING_RECIPIENT';
    }

    private function hrIds(int $tenantId): array
    {
        return DB::table('employee_roles as er')
            ->join('roles as r', 'r.id', '=', 'er.role_id')
            ->where('er.tenant_id', $tenantId)
            ->whereRaw('er.is_active = true')
            ->whereIn('r.role_code', ['HR', 'ADMIN'])
            ->pluck('er.employee_id')
            ->unique()
            ->values()
            ->all();
    }

    private function ok(mixed $data, string $message): JsonResponse
    {
        return response()->json(['status' => 200, 'message' => $message, 'data' => $data]);
    }

    private function notFound(string $message): JsonResponse
    {
        return response()->json(['status' => 404, 'message' => $message, 'data' => null], 404);
    }

    private function forbidden(string $message): JsonResponse
    {
        return response()->json(['status' => 403, 'message' => $message, 'data' => null], 403);
    }

    private function validationError(array $errors): JsonResponse
    {
        return response()->json([
            'status' => 422,
            'message' => 'Dữ liệu không hợp lệ.',
            'data' => ['errors' => $errors],
        ], 422);
    }
}
