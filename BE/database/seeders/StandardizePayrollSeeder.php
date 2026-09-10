<?php

namespace Database\Seeders;

use App\Services\PayrollRunService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Bù dữ liệu lương demo theo đúng nguồn chấm công, không ghi đè kỳ đã khóa.
 * Kỳ quá khứ được tính rồi đánh dấu PAID; tháng hiện tại luôn để OPEN.
 */
class StandardizePayrollSeeder extends Seeder
{
    private const LOCKED_STATUSES = ['CLOSED', 'LOCKED', 'PAID', 'ĐÃ_ĐÓNG', 'DA_DONG', 'ĐÃ_TRẢ', 'DA_TRA'];

    public function run(PayrollRunService $payroll): void
    {
        $this->syncLegacyInputs();

        $today = CarbonImmutable::parse(env('HRM_SEED_PAYROLL_END', CarbonImmutable::now()->toDateString()));

        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            $legalEntityIds = DB::table('legal_entities')->where('tenant_id', $tenantId)
                ->orderBy('id')->pluck('id');

            foreach ($legalEntityIds as $legalEntityId) {

            $employees = DB::table('employees')
                ->where('tenant_id', $tenantId)
                ->where('legal_entity_id', $legalEntityId)
                ->whereIn('status', ['ACTIVE', 'PROBATION'])
                ->orderBy('id')
                ->get(['id', 'hire_date', 'profile'])
                ->reject(fn ($employee) => ! empty($this->decode($employee->profile)['system_account']));

            if ($employees->isEmpty()) {
                continue;
            }

            $firstAttendance = DB::table('attendances')
                ->where('tenant_id', $tenantId)
                ->where('legal_entity_id', $legalEntityId)
                ->whereNotNull('work_date')
                ->min('work_date');

            if (! $firstAttendance) {
                continue;
            }

            $start = CarbonImmutable::parse($firstAttendance)->startOfMonth();
            $end = $today->startOfMonth();
            $createdPeriods = 0;
            $calculatedPeriods = 0;

            TenantContext::set((int) $tenantId, (int) $legalEntityId);

            try {
                $this->seedComponents((int) $tenantId);
                $this->seedPieceRates((int) $tenantId, (int) $legalEntityId, $employees, $start, $end, $today);

                for ($month = $start; $month->lte($end); $month = $month->addMonth()) {
                    $code = 'P-'.$month->format('Y-m');
                    $period = DB::table('salary_periods')
                        ->where('tenant_id', $tenantId)
                        ->where('legal_entity_id', $legalEntityId)
                        ->where('period_code', $code)
                        ->first();

                    $created = false;
                    if (! $period) {
                        $periodId = DB::table('salary_periods')->insertGetId([
                            'period_code' => $code,
                            'period_name' => 'Kỳ lương tháng '.$month->format('m/Y'),
                            'period_type' => 'MONTHLY',
                            'start_date' => $month->toDateString(),
                            'end_date' => $month->endOfMonth()->toDateString(),
                            'status' => 'OPEN',
                            'meta' => json_encode(['source' => 'standardized-payroll-seed'], JSON_UNESCAPED_UNICODE),
                            'tenant_id' => $tenantId,
                            'legal_entity_id' => $legalEntityId,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        $period = DB::table('salary_periods')->find($periodId);
                        $created = true;
                        $createdPeriods++;
                    }

                    $status = strtoupper((string) $period->status);
                    $ran = false;
                    if (! in_array($status, self::LOCKED_STATUSES, true)
                        && ($created || $this->needsEngine((int) $period->id, $employees->count()))) {
                        $payroll->run((int) $period->id);
                        $ran = true;
                        $calculatedPeriods++;
                    }

                    if ($ran && $month->lt($today->startOfMonth())) {
                        DB::table('salary_periods')->where('id', $period->id)->update([
                            'status' => 'PAID',
                            'updated_at' => now(),
                        ]);
                        DB::table('salary_details')->where('period_id', $period->id)->update([
                            'transfer_status' => 'PAID',
                            'updated_at' => now(),
                        ]);
                    }
                }
            } finally {
                TenantContext::clear();
            }

            $this->command?->info(
                "Tenant {$tenantId}/pháp nhân {$legalEntityId}: tạo {$createdPeriods} kỳ, tính {$calculatedPeriods} kỳ lương cho {$employees->count()} NV."
            );
            }
        }
    }

    private function needsEngine(int $periodId, int $employeeCount): bool
    {
        $details = DB::table('salary_details')->where('period_id', $periodId)->get(['id', 'meta']);
        if ($details->count() < $employeeCount) {
            return true;
        }

        $withBreakdowns = DB::table('salary_breakdowns')
            ->whereIn('salary_detail_id', $details->pluck('id'))
            ->distinct()
            ->pluck('salary_detail_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true]);

        return $details->contains(function ($detail) use ($withBreakdowns): bool {
            $meta = $this->decode($detail->meta);

            return ($meta['engine'] ?? null) !== 'vn-payroll-v1'
                || ! isset($withBreakdowns[(int) $detail->id]);
        });
    }

    private function seedComponents(int $tenantId): void
    {
        $components = [
            ['BASE', 'Lương cơ bản', 'earning', 'basic', true],
            ['ALLOWANCE', 'Phụ cấp', 'earning', 'allowance', true],
            ['OVERTIME', 'Tăng ca', 'earning', 'bonus', true],
            ['PIECE_RATE', 'Công khoán sản phẩm', 'earning', 'bonus', true],
            ['INS_BHXH', 'Bảo hiểm xã hội', 'deduction', 'insurance', false],
            ['INS_BHYT', 'Bảo hiểm y tế', 'deduction', 'insurance', false],
            ['INS_BHTN', 'Bảo hiểm thất nghiệp', 'deduction', 'insurance', false],
            ['PIT', 'Thuế thu nhập cá nhân', 'deduction', 'tax', false],
            ['FIXED_DEDUCTION', 'Khấu trừ cố định', 'deduction', 'other', false],
        ];

        foreach ($components as [$code, $name, $type, $category, $taxable]) {
            if (DB::table('salary_components')->where('tenant_id', $tenantId)->where('code', $code)->exists()) {
                continue;
            }

            DB::table('salary_components')->insert([
                'tenant_id' => $tenantId,
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'category' => $category,
                'is_taxable' => DB::raw($taxable ? 'true' : 'false'),
                'is_active' => DB::raw('true'),
                'meta' => json_encode(['source' => 'standardized-payroll-seed']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function seedPieceRates(
        int $tenantId,
        int $legalEntityId,
        Collection $employees,
        CarbonImmutable $start,
        CarbonImmutable $end,
        CarbonImmutable $today,
    ): void {
        $workers = $employees->take(2)->values();
        $products = ['Xử lý hồ sơ số hóa', 'Kiểm tra dữ liệu'];
        $monthIndex = 0;

        for ($month = $start; $month->lte($end); $month = $month->addMonth(), $monthIndex++) {
            $day = $month->isSameMonth($today) ? min(10, $today->day) : 10;
            $workDate = $month->day($day)->toDateString();

            foreach ($workers as $index => $employee) {
                if ($employee->hire_date && CarbonImmutable::parse($employee->hire_date)->gt($workDate)) {
                    continue;
                }

                $product = $products[$index % count($products)];
                if (DB::table('piece_rate_entries')
                    ->where('tenant_id', $tenantId)
                    ->where('employee_id', $employee->id)
                    ->where('work_date', $workDate)
                    ->where('product_name', $product)
                    ->exists()) {
                    continue;
                }

                $quantity = 18 + (($monthIndex + $index * 3) % 9);
                $rate = 25000 + $index * 5000;
                DB::table('piece_rate_entries')->insert([
                    'tenant_id' => $tenantId,
                    'legal_entity_id' => $legalEntityId,
                    'employee_id' => $employee->id,
                    'work_date' => $workDate,
                    'product_name' => $product,
                    'quantity' => $quantity,
                    'unit_rate' => $rate,
                    'amount' => $quantity * $rate,
                    'meta' => json_encode(['source' => 'standardized-payroll-seed']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /** Khôi phục các cột nghiệp vụ legacy đang nằm trong meta JSON. */
    private function syncLegacyInputs(): void
    {
        $leaves = DB::table('leave_requests')
            ->whereNotNull('meta')
            ->where(fn ($query) => $query->whereNull('start_date')->orWhereNull('end_date')->orWhereNull('total_days'))
            ->get(['id', 'start_date', 'end_date', 'total_days', 'meta']);

        foreach ($leaves as $leave) {
            $meta = $this->decode($leave->meta);
            $updates = [];
            if (! $leave->start_date && $this->isDate($meta['from_date'] ?? null)) {
                $updates['start_date'] = $meta['from_date'];
            }
            if (! $leave->end_date && $this->isDate($meta['to_date'] ?? null)) {
                $updates['end_date'] = $meta['to_date'];
            }
            if ($leave->total_days === null && is_numeric($meta['number_of_days'] ?? null)) {
                $updates['total_days'] = (float) $meta['number_of_days'];
            }
            if ($updates) {
                DB::table('leave_requests')->where('id', $leave->id)->update($updates + ['updated_at' => now()]);
            }
        }

        $overtimeRows = DB::table('overtime_requests')
            ->whereNotNull('meta')
            ->where(fn ($query) => $query->whereNull('work_date')->orWhereNull('total_hours'))
            ->get(['id', 'work_date', 'start_time', 'end_time', 'total_hours', 'meta']);

        foreach ($overtimeRows as $overtime) {
            $meta = $this->decode($overtime->meta);
            $date = $overtime->work_date ?: ($meta['overtime_date'] ?? null);
            $updates = [];
            if (! $overtime->work_date && $this->isDate($date)) {
                $updates['work_date'] = $date;
            }
            if ($overtime->total_hours === null && $this->isDate($date) && $overtime->start_time && $overtime->end_time) {
                $start = CarbonImmutable::parse("{$date} {$overtime->start_time}");
                $end = CarbonImmutable::parse("{$date} {$overtime->end_time}");
                if ($end->lte($start)) {
                    $end = $end->addDay();
                }
                $updates['total_hours'] = max(0, $start->diffInMinutes($end) / 60 - (float) ($meta['break_time'] ?? 0));
            }
            if ($updates) {
                DB::table('overtime_requests')->where('id', $overtime->id)->update($updates + ['updated_at' => now()]);
            }
        }
    }

    private function isDate(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }

    private function decode(mixed $value): array
    {
        if (! $value) {
            return [];
        }

        $decoded = is_string($value) ? json_decode($value, true) : (array) $value;

        return is_array($decoded) ? $decoded : [];
    }
}
