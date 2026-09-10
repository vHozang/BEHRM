<?php

namespace App\Services;

use App\Models\PayslipDocument;
use App\Models\SalaryDetail;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class PayslipDocumentService
{
    public function __construct(
        private readonly PayslipDataBuilder $builder,
        private readonly PayslipIssueService $issues,
    ) {}

    public function generate(int|SalaryDetail $salaryDetail, ?int $publishedBy = null): PayslipDocument
    {
        $detail = $salaryDetail instanceof SalaryDetail
            ? $salaryDetail
            : SalaryDetail::with(['period', 'employee'])->findOrFail($salaryDetail);
        $detail->loadMissing(['period', 'employee']);

        if (! $detail->period?->isClosed()) {
            throw new RuntimeException('Chỉ kỳ lương đã chốt mới được phát hành PDF.', 422);
        }

        $document = PayslipDocument::firstOrCreate(
            ['salary_detail_id' => $detail->id],
            [
                'tenant_id' => $detail->tenant_id,
                'legal_entity_id' => $detail->legal_entity_id,
                'salary_period_id' => $detail->period_id,
                'employee_id' => $detail->employee_id,
                'generation_status' => 'PENDING',
                'email_status' => 'PENDING',
                'published_by' => $publishedBy,
            ]
        );

        if ($document->generation_status === 'READY'
            && $document->storage_path
            && Storage::disk('local')->exists($document->storage_path)) {
            return $document;
        }

        $document->update([
            'generation_status' => 'PROCESSING',
            'published_by' => $document->published_by ?: $publishedBy,
            'last_error' => null,
        ]);

        try {
            $data = $this->builder->build($detail);
            $pdf = Pdf::loadView('pdf.payslip', $data)
                ->setPaper('a4', 'portrait')
                ->setOption('defaultFont', 'DejaVu Sans');
            $contents = $pdf->output();
            if (! str_starts_with($contents, '%PDF')) {
                throw new RuntimeException('Dữ liệu render không phải PDF hợp lệ.');
            }

            $periodCode = $this->safeSegment((string) $detail->period->period_code);
            $employeeCode = $this->safeSegment((string) ($detail->employee->employee_code ?: $detail->employee_id));
            $filename = "Phieu_luong_{$employeeCode}_{$periodCode}.pdf";
            $path = "payslips/{$detail->tenant_id}/{$periodCode}/{$filename}";

            if (! Storage::disk('local')->put($path, $contents)) {
                throw new RuntimeException('Không thể lưu file PDF vào private storage.');
            }

            $document->update([
                'storage_path' => $path,
                'filename' => $filename,
                'file_size' => strlen($contents),
                'sha256' => hash('sha256', $contents),
                'generation_status' => 'READY',
                'generated_at' => now(),
                'published_at' => $document->published_at ?: now(),
                'last_error' => null,
                'meta' => [
                    'template_version' => PayslipDataBuilder::TEMPLATE_VERSION,
                    'money_separator' => $data['money_separator'],
                ],
            ]);
            $this->issues->resolve($detail->period_id, $detail->employee_id, 'PDF');

            return $document->fresh();
        } catch (Throwable $exception) {
            $document->update([
                'generation_status' => 'FAILED',
                'last_error' => Str::limit($exception->getMessage(), 2000),
            ]);
            $this->issues->record(
                $detail->period,
                $detail->employee_id,
                'PDF',
                'PDF_GENERATION_FAILED',
                'Không thể tạo PDF phiếu lương.',
                'Kế toán hoặc Admin kiểm tra log và thử phát hành lại.',
                $detail->id,
                $document->id,
            );

            throw $exception;
        }
    }

    private function safeSegment(string $value): string
    {
        $value = Str::ascii($value);
        $value = preg_replace('/[^A-Za-z0-9_-]+/', '_', $value) ?: 'unknown';

        return trim($value, '_');
    }
}
