<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class LegacyDataSqlSeeder extends Seeder
{
    private array $nextIds = [];

    private array $columns = [];

    private array $touchedTables = [];

    public function run(): void
    {
        $path = database_path('legacy/data.sql');

        if (! is_file($path)) {
            $this->command?->warn("Legacy data file not found: {$path}");

            return;
        }

        foreach ($this->splitStatements(file_get_contents($path)) as $statement) {
            $statement = trim($statement);

            if ($statement === '' || str_starts_with(strtoupper($statement), 'USE ')) {
                continue;
            }

            if ($this->applyAutoIncrementStatement($statement)) {
                continue;
            }

            $this->applyInsertStatement($statement);
        }

        $this->syncEmployeeOrgAssignments();
        $this->hashEmployeePasswords();
        $this->syncPostgresSequences();
    }

    private function syncEmployeeOrgAssignments(): void
    {
        if (! Schema::hasTable('employees')) {
            return;
        }

        if (Schema::hasTable('employment_histories')) {
            DB::statement(<<<'SQL'
                UPDATE employees e
                SET department_id = h.department_id,
                    position_id = h.position_id,
                    updated_at = NOW()
                FROM employment_histories h
                WHERE h.employee_id = e.id
                  AND h.is_current = TRUE
                  AND h.department_id IS NOT NULL
                  AND h.position_id IS NOT NULL
            SQL);
        }

        if (Schema::hasTable('contracts')) {
            DB::statement(<<<'SQL'
                UPDATE employees e
                SET department_id = c.department_id,
                    position_id = c.position_id,
                    updated_at = NOW()
                FROM contracts c
                WHERE c.employee_id = e.id
                  AND e.department_id IS NULL
                  AND e.position_id IS NULL
                  AND c.department_id IS NOT NULL
                  AND c.position_id IS NOT NULL
                  AND (c.status IS NULL OR c.status IN ('CÓ_HIỆU_LỰC', 'ACTIVE', 'ĐANG_HIỆU_LỰC'))
            SQL);
        }

        if (Schema::hasTable('employee_roles')) {
            DB::statement(<<<'SQL'
                UPDATE employees e
                SET department_id = er.department_id,
                    updated_at = NOW()
                FROM employee_roles er
                WHERE er.employee_id = e.id
                  AND e.department_id IS NULL
                  AND er.department_id IS NOT NULL
                  AND er.is_active = TRUE
            SQL);
        }
    }

    private function hashEmployeePasswords(): void
    {
        if (! Schema::hasTable('employees')) {
            return;
        }

        DB::table('employees')
            ->whereNotNull('employee_code')
            ->where(function ($query): void {
                $query->whereNull('password_hash')->orWhere('password_hash', '');
            })
            ->orderBy('id')
            ->chunkById(200, function ($employees): void {
                foreach ($employees as $employee) {
                    DB::table('employees')->where('id', $employee->id)->update([
                        'password_hash' => Hash::make($employee->employee_code),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    private function applyAutoIncrementStatement(string $statement): bool
    {
        if (! preg_match('/^ALTER\s+TABLE\s+`?([a-zA-Z0-9_]+)`?\s+AUTO_INCREMENT\s*=\s*(\d+)/i', $statement, $matches)) {
            return false;
        }

        $this->nextIds[$matches[1]] = (int) $matches[2];

        return true;
    }

    private function applyInsertStatement(string $statement): void
    {
        if (! preg_match('/^INSERT\s+INTO\s+`?([a-zA-Z0-9_]+)`?\s*\((.*?)\)\s*VALUES\s*(.*)$/is', $statement, $matches)) {
            return;
        }

        $table = $matches[1];

        if (! Schema::hasTable($table)) {
            $this->command?->warn("Skipping missing table from data.sql: {$table}");

            return;
        }

        $tableColumns = $this->columns[$table] ??= Schema::getColumnListing($table);
        $sourceColumns = array_map(
            static fn (string $column): string => trim($column, " \t\n\r\0\x0B`"),
            explode(',', preg_replace('/\s+/', '', $matches[2])),
        );

        foreach ($this->splitTuples($matches[3]) as $tuple) {
            $values = $this->splitValues($tuple);
            $legacyId = $this->nextIds[$table] ?? 1;
            $this->nextIds[$table] = $legacyId + 1;

            $record = [
                'id' => $legacyId,
                'legacy_id' => $legacyId,
            ];
            $meta = [];

            foreach ($sourceColumns as $index => $column) {
                $value = $this->normalizeValue($values[$index] ?? null);

                if (in_array($column, ['id', 'legacy_id'], true)) {
                    continue;
                }

                if (in_array($column, $tableColumns, true)) {
                    if ($table === 'employees' && $column === 'status') {
                        $value = match ($value) {
                            'ĐANG_LÀM_VIỆC' => 'ACTIVE',
                            'ĐÃ_NGHỈ_VIỆC' => 'TERMINATED',
                            'THỬ_VIỆC' => 'PROBATION',
                            'NGHỈ_THAI_SẢN', 'NGHỈ_KHÔNG_LƯƠNG' => 'ON_LEAVE',
                            default => $value,
                        };
                    }
                    $record[$column] = $value;
                } else {
                    $meta[$column] = $value;
                }
            }

            if ($meta !== [] && in_array('meta', $tableColumns, true)) {
                $record['meta'] = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            $now = now();

            if (in_array('created_at', $tableColumns, true) && ! array_key_exists('created_at', $record)) {
                $record['created_at'] = $now;
            }

            if (in_array('updated_at', $tableColumns, true) && ! array_key_exists('updated_at', $record)) {
                $record['updated_at'] = $now;
            }

            if (in_array('tenant_id', $tableColumns, true) && ! array_key_exists('tenant_id', $record)) {
                $record['tenant_id'] = 1;
            }
            if (in_array('legal_entity_id', $tableColumns, true) && ! array_key_exists('legal_entity_id', $record)) {
                $record['legal_entity_id'] = 1;
            }

            $record = array_intersect_key($record, array_flip($tableColumns));

            DB::table($table)->updateOrInsert(['id' => $legacyId], $record);
            $this->touchedTables[$table] = true;
        }
    }

    private function syncPostgresSequences(): void
    {
        foreach (array_keys($this->touchedTables) as $table) {
            DB::statement(
                "SELECT setval(pg_get_serial_sequence(?, 'id'), COALESCE((SELECT MAX(id) FROM {$table}), 1), true)",
                [$table],
            );
        }
    }

    private function splitStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $quote = null;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($quote === null && $char === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $length : $end + 1;

                continue;
            }

            if ($quote === null && $char === '-' && $next === '-') {
                $end = strpos($sql, "\n", $i + 2);
                $i = $end === false ? $length : $end;

                continue;
            }

            if (($char === "'" || $char === '"') && ($i === 0 || $sql[$i - 1] !== '\\')) {
                $quote = $quote === $char ? null : ($quote ?? $char);
            }

            if ($char === ';' && $quote === null) {
                $statements[] = $buffer;
                $buffer = '';

                continue;
            }

            $buffer .= $char;
        }

        if (trim($buffer) !== '') {
            $statements[] = $buffer;
        }

        return $statements;
    }

    private function splitTuples(string $valuesSql): array
    {
        $tuples = [];
        $buffer = '';
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
                    $buffer = '';

                    continue;
                }
            }

            if ($quote === null && $char === ')') {
                $depth--;

                if ($depth === 0) {
                    $tuples[] = $buffer;
                    $buffer = '';

                    continue;
                }
            }

            if ($depth > 0) {
                $buffer .= $char;
            }
        }

        return $tuples;
    }

    private function splitValues(string $tuple): array
    {
        $values = [];
        $buffer = '';
        $quote = null;

        for ($i = 0, $length = strlen($tuple); $i < $length; $i++) {
            $char = $tuple[$i];

            if (($char === "'" || $char === '"') && ($i === 0 || $tuple[$i - 1] !== '\\')) {
                $quote = $quote === $char ? null : ($quote ?? $char);
            }

            if ($char === ',' && $quote === null) {
                $values[] = $buffer;
                $buffer = '';

                continue;
            }

            $buffer .= $char;
        }

        $values[] = $buffer;

        return $values;
    }

    private function normalizeValue(?string $raw): mixed
    {
        if ($raw === null) {
            return null;
        }

        $value = trim($raw);
        $upper = strtoupper($value);

        if ($upper === 'NULL') {
            return null;
        }

        if ($upper === 'TRUE') {
            return 'true';
        }

        if ($upper === 'FALSE') {
            return 'false';
        }

        if (preg_match('/^-?\d+$/', $value)) {
            return (int) $value;
        }

        if (preg_match('/^-?\d+\.\d+$/', $value)) {
            return (float) $value;
        }

        if (
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
            || (str_starts_with($value, '"') && str_ends_with($value, '"'))
        ) {
            $value = substr($value, 1, -1);
        }

        return str_replace(["\\'", '\\"', '\\\\'], ["'", '"', '\\'], $value);
    }
}
