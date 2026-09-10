<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class VerifyLegacyImport extends Command
{
    protected $signature = 'legacy:verify';

    protected $description = 'Compare legacy MySQL row counts with PostgreSQL destination row counts.';

    public function handle(): int
    {
        $tables = [
            'nationalities', 'banks', 'departments', 'positions', 'employees',
            'contract_types', 'contract_templates', 'contracts', 'contract_histories',
            'qualification_types', 'qualifications', 'certificate_types', 'certificates',
            'document_types', 'identity_documents', 'social_insurance_info', 'dependents',
            'employment_histories', 'request_types', 'requests', 'approval_histories',
            'leave_balances', 'leave_requests', 'leave_transactions', 'shift_schedules',
            'shift_schedule_details', 'shift_assignments', 'attendances', 'overtime_requests',
            'insurance_claims', 'allowances', 'deductions', 'employee_allowances',
            'employee_deductions', 'salary_attendance_summary', 'salary_details',
            'roles', 'permissions', 'employee_roles', 'report_templates', 'news',
            'notifications', 'system_configs',
        ];

        foreach ($tables as $table) {
            $destination = Schema::hasTable($table) ? DB::table($table)->count() : null;
            $source = $this->legacyCount($table);

            $this->line(sprintf('%-32s legacy=%s postgres=%s', $table, $source ?? 'n/a', $destination ?? 'n/a'));
        }

        return self::SUCCESS;
    }

    private function legacyCount(string $table): ?int
    {
        try {
            if (Schema::connection('mysql_legacy')->hasTable($table)) {
                return DB::connection('mysql_legacy')->table($table)->count();
            }
        } catch (QueryException) {
            return $this->countRowsInLegacyDataSql($table);
        }

        return $this->countRowsInLegacyDataSql($table);
    }

    private function countRowsInLegacyDataSql(string $table): ?int
    {
        $path = database_path('legacy/data.sql');

        if (! is_file($path)) {
            return null;
        }

        $sql = file_get_contents($path);
        $count = 0;

        if (! preg_match_all('/INSERT\s+INTO\s+`?'.preg_quote($table, '/').'`?\s*\(.*?\)\s*VALUES\s*(.*?);/is', $sql, $matches)) {
            return null;
        }

        foreach ($matches[1] as $valuesSql) {
            $count += $this->countTopLevelTuples($valuesSql);
        }

        return $count;
    }

    private function countTopLevelTuples(string $valuesSql): int
    {
        $count = 0;
        $depth = 0;
        $quote = null;

        for ($i = 0, $length = strlen($valuesSql); $i < $length; $i++) {
            $char = $valuesSql[$i];

            if (($char === "'" || $char === '"') && ($i === 0 || $valuesSql[$i - 1] !== '\\')) {
                $quote = $quote === $char ? null : ($quote ?? $char);
            }

            if ($quote === null && $char === '(') {
                $depth++;

                if ($depth === 1) {
                    $count++;
                }
            }

            if ($quote === null && $char === ')') {
                $depth--;
            }
        }

        return $count;
    }
}
