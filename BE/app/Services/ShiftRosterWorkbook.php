<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\NamedRange;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Protection;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

class ShiftRosterWorkbook
{
    public const TEMPLATE_VERSION = 'shift-roster-v1';

    /**
     * @param array<int, object|array> $employees
     * @param array<int, object|array> $shifts
     * @return array{path:string,filename:string,snapshot_hash:string}
     */
    public function create(
        object $department,
        string $weekStart,
        array $employees,
        array $shifts,
        int $tenantId
    ): array {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()
            ->setCreator('DevTapCode HRM')
            ->setTitle('Mẫu xếp ca '.($department->department_code ?? ''))
            ->setDescription('Mẫu xếp ca theo phòng ban, mã nhân viên được khóa để hạn chế sai sót.');

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Xep_Ca');
        $sheet->mergeCells('A1:J1');
        $sheet->setCellValue('A1', 'BẢNG XẾP CA - '.($department->department_name ?? ''));
        $sheet->setCellValue('A2', 'Tuần bắt đầu:');
        $sheet->setCellValue('B2', $weekStart);

        $headers = ['STT', 'Mã nhân viên', 'Họ và tên', 'Thứ 2', 'Thứ 3', 'Thứ 4', 'Thứ 5', 'Thứ 6', 'Thứ 7', 'Chủ nhật'];
        foreach ($headers as $index => $header) {
            $sheet->setCellValue([$index + 1, 4], $header);
        }

        $snapshotHash = $this->employeeSnapshotHash($employees);
        $startRow = 5;
        foreach (array_values($employees) as $index => $employee) {
            $employee = (object) $employee;
            $row = $startRow + $index;
            $sheet->setCellValue("A{$row}", $index + 1);
            $sheet->setCellValue("B{$row}", (string) $employee->employee_code);
            $sheet->setCellValue("C{$row}", (string) $employee->full_name);
        }

        $lastEmployeeRow = max($startRow, $startRow + count($employees) - 1);
        $footerRow = $lastEmployeeRow + 2;
        $sheet->mergeCells("A{$footerRow}:J{$footerRow}");
        $sheet->setCellValue(
            "A{$footerRow}",
            'Quy ước: CA1 = Ca 1 | CA2 = Ca 2 | CA3 = Ca 3 | HC = Hành chính | OFF = Nghỉ. Có thể dùng mã ca đang hoạt động.'
        );

        $metaSheet = new Worksheet($spreadsheet, '_HRM_META');
        $spreadsheet->addSheet($metaSheet);
        $metadata = [
            ['template_version', self::TEMPLATE_VERSION],
            ['tenant_id', $tenantId],
            ['department_id', (int) $department->id],
            ['department_code', (string) ($department->department_code ?? '')],
            ['week_start', $weekStart],
            ['generated_at', now()->toIso8601String()],
            ['employee_count', count($employees)],
            ['employee_snapshot_hash', $snapshotHash],
        ];
        foreach ($metadata as $index => [$key, $value]) {
            $metaSheet->setCellValue('A'.($index + 1), $key);
            $metaSheet->setCellValue('B'.($index + 1), $value);
        }

        $metaSheet->fromArray(['employee_id', 'employee_code', 'full_name', 'department_id', 'status'], null, 'A10');
        foreach (array_values($employees) as $index => $employee) {
            $employee = (object) $employee;
            $metaSheet->fromArray([
                (int) $employee->id,
                (string) $employee->employee_code,
                (string) $employee->full_name,
                (int) $employee->department_id,
                strtoupper((string) ($employee->status ?? 'ACTIVE')),
            ], null, 'A'.(11 + $index));
        }

        $shiftCodes = collect($shifts)
            ->map(fn ($shift) => strtoupper((string) ((object) $shift)->shift_code))
            ->filter()
            ->push('OFF')
            ->unique()
            ->sort()
            ->values()
            ->all();
        $metaSheet->setCellValue('F1', 'shift_codes');
        foreach ($shiftCodes as $index => $code) {
            $metaSheet->setCellValue('F'.($index + 2), $code);
        }
        $shiftListEnd = max(2, count($shiftCodes) + 1);
        $spreadsheet->addNamedRange(new NamedRange('ShiftCodes', $metaSheet, "\$F\$2:\$F\${$shiftListEnd}"));

        $validation = new DataValidation;
        $validation->setType(DataValidation::TYPE_LIST)
            ->setErrorStyle(DataValidation::STYLE_STOP)
            ->setAllowBlank(false)
            ->setShowDropDown(true)
            ->setShowErrorMessage(true)
            ->setErrorTitle('Mã ca không hợp lệ')
            ->setError('Hãy chọn mã ca trong danh sách hoặc OFF.')
            ->setFormula1('=ShiftCodes');

        if ($employees !== []) {
            foreach (range($startRow, $lastEmployeeRow) as $row) {
                foreach (range('D', 'J') as $column) {
                    $sheet->getCell("{$column}{$row}")->setDataValidation(clone $validation);
                }
            }
        }

        $this->styleSheet($sheet, $lastEmployeeRow, $footerRow);
        if ($employees !== []) {
            $sheet->getStyle("A{$startRow}:C{$lastEmployeeRow}")
                ->getProtection()->setLocked(Protection::PROTECTION_PROTECTED);
        }
        $sheet->getStyle('B2')->getProtection()->setLocked(Protection::PROTECTION_UNPROTECTED);
        if ($employees !== []) {
            $sheet->getStyle("D{$startRow}:J{$lastEmployeeRow}")
                ->getProtection()->setLocked(Protection::PROTECTION_UNPROTECTED);
        }
        $sheet->getProtection()->setPassword('devtapcode-hrm')->setSheet(true);
        $metaSheet->setSheetState(Worksheet::SHEETSTATE_VERYHIDDEN);

        $safeDepartmentCode = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($department->department_code ?? 'PhongBan'));
        $filename = "Mau_Xep_Ca_{$safeDepartmentCode}_{$weekStart}.xlsx";
        $path = tempnam(sys_get_temp_dir(), 'hrm-shift-roster-');
        if ($path === false) {
            throw new \RuntimeException('Không thể tạo file mẫu tạm thời');
        }

        (new XlsxWriter($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return ['path' => $path, 'filename' => $filename, 'snapshot_hash' => $snapshotHash];
    }

    /**
     * @return array{meta:array<string,mixed>,employees:array<int,array<string,mixed>>,rows:array<int,array<string,mixed>>}
     */
    public function parse(string $path): array
    {
        $reader = new XlsxReader;
        $reader->setReadDataOnly(false);
        $spreadsheet = $reader->load($path);

        $sheet = $spreadsheet->getSheetByName('Xep_Ca');
        $metaSheet = $spreadsheet->getSheetByName('_HRM_META');
        if (! $sheet || ! $metaSheet) {
            throw new \InvalidArgumentException('File không phải mẫu xếp ca được tải từ hệ thống');
        }

        $meta = [];
        for ($row = 1; $row <= 8; $row++) {
            $key = trim((string) $metaSheet->getCell("A{$row}")->getValue());
            if ($key !== '') {
                $meta[$key] = $metaSheet->getCell("B{$row}")->getValue();
            }
        }

        if (($meta['template_version'] ?? null) !== self::TEMPLATE_VERSION) {
            throw new \InvalidArgumentException('Phiên bản file mẫu không còn được hỗ trợ');
        }

        $meta['week_start'] = $this->parseWeekStartCell($sheet->getCell('B2')->getValue());

        $employeeCount = (int) ($meta['employee_count'] ?? 0);
        if ($employeeCount < 1 || $employeeCount > 2000) {
            throw new \InvalidArgumentException('Danh sách nhân viên trong file không hợp lệ');
        }

        $employees = [];
        for ($index = 0; $index < $employeeCount; $index++) {
            $row = 11 + $index;
            $employees[] = [
                'id' => (int) $metaSheet->getCell("A{$row}")->getValue(),
                'employee_code' => strtoupper(trim((string) $metaSheet->getCell("B{$row}")->getValue())),
                'full_name' => trim((string) $metaSheet->getCell("C{$row}")->getValue()),
                'department_id' => (int) $metaSheet->getCell("D{$row}")->getValue(),
                'status' => strtoupper(trim((string) $metaSheet->getCell("E{$row}")->getValue())),
            ];
        }

        $rows = [];
        $dayColumns = ['D', 'E', 'F', 'G', 'H', 'I', 'J'];
        for ($index = 0; $index < $employeeCount; $index++) {
            $rowNumber = 5 + $index;
            $days = [];
            foreach ($dayColumns as $column) {
                $value = $sheet->getCell("{$column}{$rowNumber}")->getValue();
                if (is_string($value) && str_starts_with(ltrim($value), '=')) {
                    throw new \InvalidArgumentException("Dòng {$rowNumber} chứa công thức; chỉ được nhập mã ca");
                }
                $days[] = strtoupper(trim((string) $value));
            }

            $rows[] = [
                'row' => $rowNumber,
                'employee_code' => strtoupper(trim((string) $sheet->getCell("B{$rowNumber}")->getValue())),
                'entered_name' => trim((string) $sheet->getCell("C{$rowNumber}")->getValue()),
                'days' => $days,
            ];
        }

        // Any employee code below the protected roster means a row was inserted manually.
        for ($row = 5 + $employeeCount; $row <= $sheet->getHighestDataRow(); $row++) {
            if (trim((string) $sheet->getCell("B{$row}")->getValue()) !== '') {
                throw new \InvalidArgumentException("File có thêm nhân viên ngoài danh sách tại dòng {$row}");
            }
        }

        $spreadsheet->disconnectWorksheets();

        return ['meta' => $meta, 'employees' => $employees, 'rows' => $rows];
    }

    /** @param array<int, object|array> $employees */
    public function employeeSnapshotHash(array $employees): string
    {
        $snapshot = collect($employees)
            ->map(function ($employee): array {
                $employee = (object) $employee;

                return [
                    'id' => (int) $employee->id,
                    'employee_code' => strtoupper((string) $employee->employee_code),
                    'full_name' => (string) $employee->full_name,
                    'department_id' => (int) $employee->department_id,
                    'status' => strtoupper((string) ($employee->status ?? 'ACTIVE')),
                ];
            })
            ->sortBy('employee_code')
            ->values()
            ->all();

        return hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function styleSheet(Worksheet $sheet, int $lastEmployeeRow, int $footerRow): void
    {
        $sheet->getStyle('A1:J1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0F766E']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(28);

        $sheet->getStyle('A4:J4')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '155E75']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);

        $sheet->getStyle("A4:J{$lastEmployeeRow}")->applyFromArray([
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CBD5E1']],
            ],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getStyle("A5:B{$lastEmployeeRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("D5:J{$lastEmployeeRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("A{$footerRow}:J{$footerRow}")->applyFromArray([
            'font' => ['italic' => true, 'color' => ['rgb' => '475569']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F1F5F9']],
        ]);

        $sheet->getColumnDimension('A')->setWidth(8);
        $sheet->getColumnDimension('B')->setWidth(18);
        $sheet->getColumnDimension('C')->setWidth(30);
        foreach (range('D', 'J') as $column) {
            $sheet->getColumnDimension($column)->setWidth(14);
        }
        $sheet->freezePane('D5');
        $sheet->setAutoFilter("A4:J{$lastEmployeeRow}");
    }

    private function parseWeekStartCell(mixed $value): string
    {
        if (is_numeric($value)) {
            return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
        }

        $value = trim((string) $value);
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            if ($date && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }

        throw new \InvalidArgumentException('Ô B2 phải là ngày Thứ Hai theo định dạng dd/mm/yyyy hoặc yyyy-mm-dd');
    }
}
