<?php

namespace App\Console\Commands;

class ImportLegacyPayroll extends BaseLegacyImportCommand
{
    protected $signature = 'legacy:import-payroll';

    protected $description = 'Import legacy payroll records.';

    protected array $tables = [
        'insurance_claims', 'allowances', 'deductions', 'employee_allowances',
        'employee_deductions', 'salary_periods', 'salary_attendance_summary',
        'salary_details', 'salary_breakdowns', 'payroll_adjustments',
    ];
}
