<?php

namespace App\Services;

use App\Mail\PayslipMail;
use App\Models\PayslipDocument;
use App\Support\Notifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class PayslipDeliveryService
{
    public function __construct(private readonly PayslipIssueService $issues) {}

    public function send(PayslipDocument $document): PayslipDocument
    {
        $document->loadMissing(['employee', 'period']);
        if ($document->generation_status !== 'READY' || ! $document->storage_path) {
            throw new RuntimeException('Phiếu lương chưa có PDF sẵn sàng để gửi.', 422);
        }
        if (! Storage::disk('local')->exists($document->storage_path)) {
            $document->update([
                'generation_status' => 'FAILED',
                'last_error' => 'Không tìm thấy file PDF chính thức trong private storage.',
            ]);
            $this->issues->record(
                $document->period,
                $document->employee_id,
                'PDF',
                'PDF_FILE_MISSING',
                'File PDF chính thức không còn trong private storage.',
                'Kế toán hoặc Admin phát hành lại PDF trước khi gửi email.',
                $document->salary_detail_id,
                $document->id,
            );

            throw new RuntimeException('Không tìm thấy file PDF chính thức trong private storage.', 422);
        }

        $document->refresh();
        if ($document->email_status === 'SENT') {
            return $document;
        }

        [$recipient, $source] = $this->recipient($document);
        if (! $recipient) {
            $document->update([
                'email_status' => 'MISSING_RECIPIENT',
                'recipient_email' => null,
                'recipient_source' => null,
                'last_attempted_at' => now(),
                'last_error' => 'Nhân viên chưa có email công ty hoặc email cá nhân.',
            ]);
            $this->issues->record(
                $document->period,
                $document->employee_id,
                'EMAIL',
                'MISSING_RECIPIENT',
                'Nhân viên chưa có email để nhận phiếu lương.',
                'HR bổ sung email công ty hoặc email cá nhân rồi bấm gửi lại.',
                $document->salary_detail_id,
                $document->id,
            );
            Notifier::notifyMany(
                $this->hrIds((int) $document->tenant_id),
                'Phiếu lương chưa gửi được',
                "Nhân viên {$document->employee->employee_code} chưa có email nhận phiếu lương.",
                'payslip_issue',
                $document->id,
                ['priority' => 'high'],
            );

            return $document->fresh();
        }

        $claimed = DB::table('payslip_documents')
            ->where('id', $document->id)
            ->whereIn('email_status', ['PENDING', 'QUEUED', 'FAILED', 'MISSING_RECIPIENT'])
            ->update([
                'email_status' => 'SENDING',
                'recipient_email' => $recipient,
                'recipient_source' => $source,
                'send_attempts' => $document->send_attempts + 1,
                'last_attempted_at' => now(),
                'last_error' => null,
                'updated_at' => now(),
            ]);
        if ($claimed === 0) {
            return $document->fresh();
        }

        $document->refresh();

        try {
            Mail::to($recipient, $document->employee->full_name)->send(new PayslipMail($document->fresh()));
            $document->update([
                'email_status' => 'SENT',
                'sent_at' => now(),
                'last_error' => null,
            ]);
            $this->issues->resolve($document->salary_period_id, $document->employee_id, 'EMAIL');

            return $document->fresh();
        } catch (Throwable $exception) {
            $document->update([
                'email_status' => 'FAILED',
                'last_error' => Str::limit($exception->getMessage(), 2000),
            ]);
            $this->issues->record(
                $document->period,
                $document->employee_id,
                'EMAIL',
                'EMAIL_DELIVERY_FAILED',
                'Gửi email phiếu lương thất bại.',
                'Kiểm tra SMTP/Resend rồi thử gửi lại.',
                $document->salary_detail_id,
                $document->id,
            );

            throw $exception;
        }
    }

    private function recipient(PayslipDocument $document): array
    {
        $employee = $document->employee;
        $profile = is_array($employee->profile) ? $employee->profile : [];
        $candidates = [
            'company_email' => $employee->company_email,
            'personal_email' => $employee->personal_email ?: ($profile['personal_email'] ?? null),
        ];

        foreach ($candidates as $source => $email) {
            $email = trim((string) $email);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return [$email, $source];
            }
        }

        return [null, null];
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
}
