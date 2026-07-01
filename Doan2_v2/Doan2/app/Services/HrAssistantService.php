<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds a privacy-safe HR system prompt for a single employee and answers
 * their question via ClaudeService. All data gathering is tenant-scoped
 * (models use the BelongsToTenant global scope) and limited to the asking
 * employee's OWN data.
 */
class HrAssistantService
{
    public function __construct(private ClaudeService $claude)
    {
    }

    /**
     * Answer an HR question for the given employee.
     *
     * @param  array<int, array{role:string, content:string}>  $history  Prior turns (optional).
     * @return array{ok:bool, answer:string, configured:bool, usage:array<string,mixed>|null}
     */
    public function ask(int $employeeId, string $question, array $history = []): array
    {
        // Short-circuit when the assistant isn't configured — never hit the API.
        if (! $this->claude->isConfigured()) {
            return [
                'ok' => false,
                'answer' => 'Trợ lý AI chưa được cấu hình (thiếu ANTHROPIC_API_KEY).',
                'configured' => false,
                'usage' => null,
            ];
        }

        $context = $this->gatherContext($employeeId);
        $system = $this->buildSystemPrompt($context);

        $messages = array_merge(
            $this->sanitizeHistory($history),
            [['role' => 'user', 'content' => $question]],
        );

        $result = $this->claude->chat($messages, $system);

        return [
            'ok' => $result['ok'],
            'answer' => $result['ok']
                ? $result['text']
                : ($result['error'] ?? 'Xin lỗi, hiện chưa thể trả lời. Vui lòng thử lại sau.'),
            'configured' => true,
            'usage' => $result['usage'],
        ];
    }

    /**
     * Gather a COMPACT, privacy-safe context for THIS employee only.
     *
     * @return array<string, mixed>
     */
    private function gatherContext(int $employeeId): array
    {
        $employee = Employee::with(['position:id,position_name', 'department:id,department_name'])
            ->find($employeeId);

        if (! $employee) {
            return ['found' => false];
        }

        $year = (string) now()->year;

        $balances = LeaveBalance::where('leave_balances.employee_id', $employeeId)
            ->where('leave_balances.year', $year)
            ->join('leave_types', 'leave_balances.leave_type_id', '=', 'leave_types.id')
            ->get(['leave_types.leave_type_name as name', 'leave_balances.remaining_days as remaining'])
            ->map(fn ($b) => [
                'type' => $b->name,
                'remaining' => (float) $b->remaining,
            ])
            ->all();

        $pendingLeaveCount = LeaveRequest::where('employee_id', $employeeId)
            ->whereIn('status', ['PENDING', 'CHỜ_DUYỆT'])
            ->count();

        $pendingRequestCount = DB::table('requests')
            ->where('requester_id', $employeeId)
            ->whereIn('status', ['PENDING', 'IN_PROGRESS', 'ĐANG_XỬ_LÝ', 'CHỜ_DUYỆT'])
            ->where('tenant_id', $employee->tenant_id)
            ->count();

        $todayAttendance = Attendance::where('employee_id', $employeeId)
            ->whereDate('work_date', Carbon::today()->toDateString())
            ->orderByDesc('id')
            ->first();

        return [
            'found' => true,
            'full_name' => $employee->full_name,
            'position' => optional($employee->position)->position_name,
            'department' => optional($employee->department)->department_name,
            'year' => $year,
            'leave_balances' => $balances,
            'pending_leave_count' => $pendingLeaveCount,
            'pending_request_count' => $pendingRequestCount,
            'today_attendance_status' => $todayAttendance
                ? $this->describeAttendance($todayAttendance)
                : 'Chưa chấm công hôm nay',
        ];
    }

    private function describeAttendance(Attendance $a): string
    {
        $checkIn = $a->check_in_time ? ('vào lúc ' . $a->check_in_time) : null;
        $checkOut = $a->check_out_time ? ('ra lúc ' . $a->check_out_time) : null;
        $status = $a->status ?: 'đã chấm công';

        $parts = array_filter([$status, $checkIn, $checkOut]);

        return implode(', ', $parts);
    }

    /**
     * Build the Vietnamese HR system prompt, embedding a compact "Bối cảnh"
     * section so the model can answer the employee's own-data questions.
     *
     * @param  array<string, mixed>  $ctx
     */
    private function buildSystemPrompt(array $ctx): string
    {
        $rules = <<<'TXT'
Bạn là trợ lý nhân sự (HR) nội bộ của công ty, một trợ lý AI. Nhiệm vụ:
- Chỉ trả lời các câu hỏi liên quan đến nhân sự (chính sách, nghỉ phép, chấm công, quy trình) và dữ liệu CÁ NHÂN của chính nhân viên đang hỏi.
- TUYỆT ĐỐI không tiết lộ hay suy đoán dữ liệu riêng tư của nhân viên khác (lương, nghỉ phép, thông tin cá nhân của người khác). Nếu được hỏi, hãy từ chối lịch sự.
- Nếu câu hỏi nằm ngoài phạm vi nhân sự, hãy lịch sự cho biết bạn chỉ hỗ trợ các vấn đề nhân sự.
- Trả lời ngắn gọn, rõ ràng, bằng tiếng Việt. Khi không chắc chắn hoặc thiếu dữ liệu, hãy nói rõ và hướng dẫn liên hệ bộ phận HR.
- Bạn là một trợ lý AI; không đưa ra cam kết pháp lý hay quyết định thay con người.
TXT;

        if (empty($ctx['found'])) {
            return $rules . "\n\nBối cảnh: Không tìm thấy hồ sơ nhân viên tương ứng.";
        }

        $balanceLines = empty($ctx['leave_balances'])
            ? '  - (không có số dư phép cho năm ' . $ctx['year'] . ')'
            : collect($ctx['leave_balances'])
                ->map(fn ($b) => sprintf('  - %s: còn lại %s ngày', $b['type'], rtrim(rtrim((string) $b['remaining'], '0'), '.')))
                ->implode("\n");

        $context = <<<TXT

Bối cảnh (chỉ dùng để trả lời cho chính nhân viên này, không chia sẻ cho người khác):
- Họ tên: {$ctx['full_name']}
- Chức danh: {$this->orNa($ctx['position'])}
- Phòng ban: {$this->orNa($ctx['department'])}
- Số dư phép năm {$ctx['year']}:
{$balanceLines}
- Số đơn nghỉ phép đang chờ duyệt: {$ctx['pending_leave_count']}
- Số yêu cầu (request) đang chờ xử lý: {$ctx['pending_request_count']}
- Trạng thái chấm công hôm nay: {$ctx['today_attendance_status']}
TXT;

        return $rules . "\n" . $context;
    }

    private function orNa(?string $value): string
    {
        return ($value === null || $value === '') ? 'Chưa cập nhật' : $value;
    }

    /**
     * Keep only valid prior {role, content} turns.
     *
     * @param  array<int, mixed>  $history
     * @return array<int, array{role:string, content:string}>
     */
    private function sanitizeHistory(array $history): array
    {
        $out = [];

        foreach ($history as $turn) {
            if (! is_array($turn)) {
                continue;
            }

            $role = $turn['role'] ?? null;
            $content = $turn['content'] ?? null;

            if (! in_array($role, ['user', 'assistant'], true) || ! is_string($content) || trim($content) === '') {
                continue;
            }

            $out[] = ['role' => $role, 'content' => $content];
        }

        // Cap history length to keep the prompt compact.
        return array_slice($out, -10);
    }
}
