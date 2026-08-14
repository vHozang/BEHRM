<?php

namespace App\Jobs;

use App\Models\AttendanceTimesheetExport;
use App\Services\TimesheetService;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class GenerateTimesheetExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 2;

    public function __construct(public string $exportId)
    {
        $this->onQueue('exports');
    }

    public function handle(TimesheetService $timesheets): void
    {
        $export = AttendanceTimesheetExport::withoutTenantScope()->find($this->exportId);
        if (! $export || $export->status === 'COMPLETED') {
            return;
        }

        $export->update(['status' => 'PROCESSING', 'started_at' => now(), 'error' => null]);
        TenantContext::set((int) $export->tenant_id, $export->legal_entity_id ? (int) $export->legal_entity_id : null);

        try {
            $filters = is_array($export->filters) ? $export->filters : [];
            $employeeIds = array_key_exists('employee_ids', $filters)
                ? array_values(array_unique(array_map('intval', (array) $filters['employee_ids'])))
                : (! empty($filters['employee_id']) ? [(int) $filters['employee_id']] : null);
            $grid = $timesheets->monthlyGrid(
                (int) $export->tenant_id,
                (int) $export->legal_entity_id,
                $export->month,
                $employeeIds,
                ! empty($filters['department_id']) ? (int) $filters['department_id'] : null,
            );

            $spreadsheet = $this->spreadsheet($grid);
            $relativePath = "attendance/timesheet-exports/{$export->tenant_id}/{$export->id}.{$export->format}";
            $absolutePath = Storage::disk('local')->path($relativePath);
            if (! is_dir(dirname($absolutePath))) {
                mkdir(dirname($absolutePath), 0775, true);
            }

            if ($export->format === 'csv') {
                $writer = new Csv($spreadsheet);
                $writer->setUseBOM(true);
                $writer->setDelimiter(',');
            } else {
                $writer = new Xlsx($spreadsheet);
            }
            $writer->save($absolutePath);
            $spreadsheet->disconnectWorksheets();

            $export->update([
                'status' => 'COMPLETED',
                'file_path' => $relativePath,
                'file_size' => filesize($absolutePath) ?: null,
                'completed_at' => now(),
                'expires_at' => now()->addDay(),
            ]);
        } catch (\Throwable $exception) {
            $export->update([
                'status' => 'FAILED',
                'error' => mb_substr($exception->getMessage(), 0, 4000),
                'expires_at' => now()->addDay(),
            ]);
            throw $exception;
        } finally {
            TenantContext::clear();
        }
    }

    /** @param array<string, mixed> $grid */
    private function spreadsheet(array $grid): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Bang cong');

        $header = ['Mã NV', 'Họ tên'];
        foreach ($grid['days'] as $day) {
            $header[] = sprintf('%02d/%02d', $day['day'], (int) substr($day['date'], 5, 2));
        }
        array_push($header, 'Công', 'Trễ (phút)', 'Sớm (phút)', 'Vắng', 'Nghỉ', 'OT (giờ)');
        $sheet->fromArray($header, null, 'A1');

        $symbols = [
            'ON_TIME' => '✓', 'LATE' => 'M', 'EARLY_LEAVE' => 'S', 'HALF_DAY' => '1/2',
            'ABSENT' => 'V', 'LEAVE' => 'P', 'LEAVE_HALF' => 'P1/2',
            'LEAVE_PENDING' => 'P...', 'HOLIDAY' => 'L', 'REST' => 'OFF', '' => '',
        ];
        $rowNumber = 2;
        foreach ($grid['rows'] as $row) {
            $values = [$row['employee_code'], $row['full_name']];
            foreach ($grid['days'] as $day) {
                $status = $row['cells'][$day['date']]['status'] ?? '';
                $values[] = $symbols[$status] ?? $status;
            }
            array_push(
                $values,
                $row['totals']['payable_days'],
                $row['totals']['late_minutes'],
                $row['totals']['early_leave_minutes'],
                $row['totals']['absent_days'],
                $row['totals']['leave_days'],
                $row['totals']['overtime_hours'],
            );
            $sheet->fromArray($values, null, 'A'.$rowNumber++);
        }

        $sheet->freezePane('C2');
        $sheet->getStyle('A1:'.$sheet->getHighestColumn().'1')->getFont()->setBold(true);
        $sheet->getColumnDimension('A')->setAutoSize(true);
        $sheet->getColumnDimension('B')->setWidth(28);

        return $spreadsheet;
    }
}
