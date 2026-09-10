<?php

namespace App\Services;

use App\Support\TenantContext;
use App\Support\TimePolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ShiftResolver
{
    private const GENERATED_SOURCES = ['rotation', 'roster-gen', 'excel-import'];

    /**
     * Load every assignment that can affect the requested range in one query.
     *
     * @return array<int, array<int, object>> employee_id => assignment rows
     */
    public function rowsForRange(
        array $employeeIds,
        string $startDate,
        string $endDate,
        ?int $tenantId = null
    ): array {
        $employeeIds = array_values(array_unique(array_map('intval', $employeeIds)));
        if ($employeeIds === []) {
            return [];
        }

        $tenantId ??= TenantContext::id();

        return DB::table('shift_assignments as sa')
            ->leftJoin('shift_types as st', 'st.id', '=', 'sa.shift_type_id')
            ->whereIn('sa.employee_id', $employeeIds)
            ->whereDate('sa.effective_date', '<=', $endDate)
            ->where(function ($query) use ($startDate): void {
                $query->whereNull('sa.expiry_date')->orWhereDate('sa.expiry_date', '>=', $startDate);
            })
            ->where(function ($query): void {
                $query->whereNull('sa.status')->orWhere('sa.status', '!=', 'INACTIVE');
            })
            ->when($tenantId !== null, fn ($query) => $query->where('sa.tenant_id', $tenantId))
            ->get([
                'sa.*',
                'st.shift_code',
                'st.shift_name',
                'st.start_time',
                'st.end_time',
                'st.meta as shift_meta',
            ])
            ->groupBy(fn ($row) => (int) $row->employee_id)
            ->map(fn (Collection $rows) => $rows->values()->all())
            ->all();
    }

    public function resolve(int $employeeId, string $date, ?int $tenantId = null): ?object
    {
        $rows = $this->rowsForRange([$employeeId], $date, $date, $tenantId);

        return $this->resolveFromRows($rows[$employeeId] ?? [], $date);
    }

    /** @param array<int, object> $rows */
    public function resolveFromRows(array $rows, string $date): ?object
    {
        $date = CarbonImmutable::parse($date)->toDateString();
        $candidates = array_values(array_filter($rows, function ($row) use ($date): bool {
            $effective = $this->dateString($row->effective_date ?? null);
            $expiry = $this->dateString($row->expiry_date ?? null);

            return $effective !== null
                && $effective <= $date
                && ($expiry === null || $expiry >= $date)
                && strtoupper((string) ($row->status ?? 'ACTIVE')) !== 'INACTIVE';
        }));

        if ($candidates === []) {
            return null;
        }

        usort($candidates, function ($left, $right): int {
            $specificity = $this->specificityDays($left) <=> $this->specificityDays($right);
            if ($specificity !== 0) {
                return $specificity;
            }

            $source = $this->sourceRank($right) <=> $this->sourceRank($left);
            if ($source !== 0) {
                return $source;
            }

            $effective = strcmp(
                $this->dateString($right->effective_date ?? null) ?? '',
                $this->dateString($left->effective_date ?? null) ?? ''
            );
            if ($effective !== 0) {
                return $effective;
            }

            return ((int) ($right->id ?? 0)) <=> ((int) ($left->id ?? 0));
        });

        return $candidates[0];
    }

    /**
     * Return a normalized UI/API cell. A configured weekly rest day is shown as
     * OFF without destroying the underlying weekly rotation assignment.
     */
    public function cellForDate(?object $assignment, string $date): array
    {
        if (! $assignment) {
            return [
                'assignment_id' => null,
                'shift_type_id' => null,
                'shift_code' => 'OFF',
                'shift_name' => 'Chưa xếp ca',
                'start_time' => null,
                'end_time' => null,
                'color_code' => null,
                'is_day_off' => true,
                'is_rest_day' => false,
                'source' => 'unassigned',
                'is_manual' => false,
            ];
        }

        $source = $this->assignmentSource($assignment);
        $explicitOff = $this->truthy($assignment->is_day_off ?? false);
        $restDay = ! $explicitOff && ! $this->isAssignmentWorkday($assignment, $date);
        $isOff = $explicitOff || $restDay || empty($assignment->shift_type_id);

        $shiftMeta = $this->decodeMeta($assignment->shift_meta ?? null);

        return [
            'assignment_id' => isset($assignment->id) ? (int) $assignment->id : null,
            'shift_type_id' => $isOff ? null : (int) $assignment->shift_type_id,
            'shift_code' => $isOff ? 'OFF' : (string) ($assignment->shift_code ?? ''),
            'shift_name' => $isOff
                ? ($restDay ? 'Ngày nghỉ theo lịch' : 'Nghỉ')
                : (string) ($assignment->shift_name ?? $assignment->shift_code ?? ''),
            'start_time' => $isOff ? null : ($assignment->start_time ?? null),
            'end_time' => $isOff ? null : ($assignment->end_time ?? null),
            'color_code' => $isOff ? null : ($shiftMeta['color_code'] ?? null),
            'is_day_off' => $isOff,
            'is_rest_day' => $restDay,
            'source' => $restDay ? 'rest-day' : $source,
            'is_manual' => $this->isManualAssignment($assignment),
            'effective_date' => $this->dateString($assignment->effective_date ?? null),
            'expiry_date' => $this->dateString($assignment->expiry_date ?? null),
        ];
    }

    public function isAssignmentWorkday(object $assignment, string $date): bool
    {
        if ($this->truthy($assignment->is_day_off ?? false)) {
            return false;
        }

        $meta = $this->decodeMeta($assignment->shift_meta ?? null);
        $weekdays = $meta['work_weekdays'] ?? null;
        if (is_array($weekdays) && $weekdays !== []) {
            $isoDay = CarbonImmutable::parse($date)->dayOfWeekIso;
            $normalized = array_map('intval', $weekdays);

            return in_array($isoDay, $normalized, true);
        }

        return ! TimePolicy::isRestDay(CarbonImmutable::parse($date));
    }

    /**
     * Determine the employee's rotation group from the preceding week. Manual
     * one-day exceptions do not change the group unless they dominate the week.
     *
     * @param array<int, object> $rows
     * @return array{code:?string,error:?string,counts:array<string,int>}
     */
    public function rotationBase(array $rows, string $startMonday): array
    {
        $weekStart = CarbonImmutable::parse($startMonday)->subWeek();
        $counts = ['CA1' => 0, 'CA2' => 0, 'CA3' => 0];

        for ($offset = 0; $offset < 7; $offset++) {
            $date = $weekStart->addDays($offset)->toDateString();
            $assignment = $this->resolveFromRows($rows, $date);
            if (! $assignment || ! $this->isAssignmentWorkday($assignment, $date)) {
                continue;
            }

            $code = strtoupper(trim((string) ($assignment->shift_code ?? '')));
            if (array_key_exists($code, $counts)) {
                $counts[$code]++;
            }
        }

        $max = max($counts);
        if ($max === 0) {
            return ['code' => null, 'error' => 'Không xác định được ca CA1/CA2/CA3 trong tuần liền trước', 'counts' => $counts];
        }

        $winners = array_keys(array_filter($counts, fn ($count) => $count === $max));
        if (count($winners) !== 1) {
            return ['code' => null, 'error' => 'Ca gốc không nhất quán trong tuần liền trước', 'counts' => $counts];
        }

        return ['code' => $winners[0], 'error' => null, 'counts' => $counts];
    }

    public function assignmentSource(object $assignment): string
    {
        $meta = $this->decodeMeta($assignment->meta ?? null);
        $source = strtolower(trim((string) ($meta['source'] ?? '')));
        if ($source !== '') {
            return $source;
        }

        return empty($assignment->expiry_date) ? 'standing' : 'manual';
    }

    public function isGeneratedSource(string $source): bool
    {
        return in_array(strtolower($source), self::GENERATED_SOURCES, true);
    }

    public function isManualSource(string $source): bool
    {
        return ! $this->isGeneratedSource($source)
            && ! in_array(strtolower($source), ['standing', 'rest-day', 'unassigned'], true);
    }

    public function isManualAssignment(object $assignment): bool
    {
        $source = $this->assignmentSource($assignment);
        if (in_array($source, ['manual', 'shift-swap', 'swap'], true)) {
            return true;
        }
        if (! $this->isManualSource($source)) {
            return false;
        }

        // Seeded/fixed assignments often carry a source marker but have no end
        // date. They are the base schedule, not a manual exception to preserve.
        return ! empty($assignment->expiry_date);
    }

    public function decodeMeta(mixed $meta): array
    {
        if (is_array($meta)) {
            return $meta;
        }
        if (is_object($meta)) {
            return (array) $meta;
        }
        if (is_string($meta) && $meta !== '') {
            $decoded = json_decode($meta, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function specificityDays(object $assignment): int
    {
        $effective = $this->dateString($assignment->effective_date ?? null);
        $expiry = $this->dateString($assignment->expiry_date ?? null);
        if (! $effective || ! $expiry) {
            return PHP_INT_MAX;
        }

        return (int) CarbonImmutable::parse($effective)->diffInDays(CarbonImmutable::parse($expiry));
    }

    private function sourceRank(object $assignment): int
    {
        return match ($this->assignmentSource($assignment)) {
            'shift-swap', 'swap' => 60,
            'manual' => 50,
            'excel-import' => 40,
            'rotation', 'roster-gen' => 20,
            default => 10,
        };
    }

    private function dateString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value)->toDateString();
    }

    private function truthy(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true', 'TRUE'], true);
    }
}
