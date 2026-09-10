<?php

namespace App\Services;

use App\Support\AuditLogger;
use App\Support\TenantContext;
use App\Support\VietnameseLunarConverter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Generates and seeds the statutory Vietnamese public holidays for a year.
 *
 * Statutory basis: Bộ luật Lao động 2019, Điều 112 — 11 paid public-holiday
 * days/year:
 *   - Tết Dương lịch (01/01)                              — 1 ngày
 *   - Tết Nguyên đán                                      — 5 ngày
 *   - Giỗ Tổ Hùng Vương (10/3 âm lịch)                    — 1 ngày
 *   - Ngày Giải phóng miền Nam (30/04)                    — 1 ngày
 *   - Quốc tế Lao động (01/05)                            — 1 ngày
 *   - Quốc khánh (02/09 + 01 ngày liền kề)                — 2 ngày
 *
 * Fixed-solar dates are deterministic; the two lunar-anchored holidays (Tết,
 * Giỗ Tổ) are computed via {@see VietnameseLunarConverter} so no hand-kept
 * lookup table is needed. The default Tết block is the 5 days mùng 1±, and the
 * default Quốc khánh adjacent day is 01/09 — both are the most common official
 * arrangement and remain fully editable afterwards via the normal CRUD UI.
 */
class VietnamHolidayService
{
    /**
     * Build the statutory holiday rows for a calendar year (no DB writes).
     *
     * @return array<int, array{holiday_code:string,holiday_name:string,holiday_date:string,holiday_type:string,is_recurring:bool,description:string}>
     */
    public function statutoryHolidays(int $year): array
    {
        $rows = [];

        $rows[] = $this->row('TET_DUONG_LICH', 'Tết Dương lịch', "$year-01-01", 'NATIONAL', true, 'Nghỉ Tết Dương lịch (Điều 112 BLLĐ)');

        // Tết Nguyên đán — 5 ngày: 1 ngày trước mùng 1 → hết mùng 4.
        [$ty, $tm, $td] = VietnameseLunarConverter::lunarToSolar(1, 1, $year);
        if ($ty > 0) {
            $tet1 = CarbonImmutable::create($ty, $tm, $td);
            $tetStart = $tet1->subDay(); // giao thừa (30/29 Tết)
            for ($i = 0; $i < 5; $i++) {
                $d = $tetStart->addDays($i);
                $rows[] = $this->row(
                    'TET_NGUYEN_DAN_'.($i + 1),
                    'Tết Nguyên đán',
                    $d->toDateString(),
                    'NATIONAL',
                    false,
                    'Nghỉ Tết Nguyên đán (Điều 112 BLLĐ)'
                );
            }
        }

        // Giỗ Tổ Hùng Vương — 10 tháng 3 âm lịch.
        [$gy, $gm, $gd] = VietnameseLunarConverter::lunarToSolar(10, 3, $year);
        if ($gy > 0) {
            $rows[] = $this->row(
                'GIO_TO_HUNG_VUONG',
                'Giỗ Tổ Hùng Vương',
                CarbonImmutable::create($gy, $gm, $gd)->toDateString(),
                'NATIONAL',
                false,
                'Giỗ Tổ Hùng Vương 10/3 âm lịch (Điều 112 BLLĐ)'
            );
        }

        $rows[] = $this->row('GIAI_PHONG_MIEN_NAM', 'Ngày Giải phóng miền Nam', "$year-04-30", 'NATIONAL', true, 'Ngày Giải phóng miền Nam, thống nhất đất nước');
        $rows[] = $this->row('QUOC_TE_LAO_DONG', 'Ngày Quốc tế Lao động', "$year-05-01", 'NATIONAL', true, 'Ngày Quốc tế Lao động');
        $rows[] = $this->row('QUOC_KHANH_1', 'Quốc khánh', "$year-09-01", 'NATIONAL', true, 'Quốc khánh — ngày liền kề (Điều 112 BLLĐ)');
        $rows[] = $this->row('QUOC_KHANH_2', 'Quốc khánh', "$year-09-02", 'NATIONAL', true, 'Quốc khánh 02/09');

        return $rows;
    }

    /**
     * Seed the statutory holidays for a tenant + year, idempotently.
     *
     * Any date already present for the tenant (whether seeded earlier or added
     * by hand) is left untouched and reported as "skipped" — re-running is safe.
     *
     * @return array{year:int,created:array<int,array<string,mixed>>,skipped:array<int,string>}
     */
    public function seed(int $tenantId, int $year): array
    {
        $candidates = $this->statutoryHolidays($year);

        $existing = DB::table('holidays')
            ->where('tenant_id', $tenantId)
            ->whereBetween('holiday_date', ["$year-01-01", "$year-12-31"])
            ->pluck('holiday_date')
            ->map(fn ($d) => CarbonImmutable::parse($d)->toDateString())
            ->all();
        $existing = array_flip($existing);

        $created = [];
        $skipped = [];
        $now = now();

        foreach ($candidates as $row) {
            if (isset($existing[$row['holiday_date']])) {
                $skipped[] = $row['holiday_date'];

                continue;
            }

            $payload = TenantContext::stamp([
                'holiday_code' => $row['holiday_code'],
                'holiday_name' => $row['holiday_name'],
                'holiday_date' => $row['holiday_date'],
                'holiday_type' => $row['holiday_type'],
                'is_recurring' => $row['is_recurring'] ? DB::raw('true') : DB::raw('false'),
                'description' => $row['description'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $id = DB::table('holidays')->insertGetId($payload);
            $afterRow = DB::table('holidays')->where('id', $id)->first();
            AuditLogger::log('create', 'holidays', $id, null, $afterRow ? (array) $afterRow : null);

            // Guard against duplicates within the same run (e.g. Tết block edges).
            $existing[$row['holiday_date']] = true;
            $created[] = (array) $afterRow;
        }

        return ['year' => $year, 'created' => $created, 'skipped' => $skipped];
    }

    /**
     * @return array{holiday_code:string,holiday_name:string,holiday_date:string,holiday_type:string,is_recurring:bool,description:string}
     */
    private function row(string $code, string $name, string $date, string $type, bool $recurring, string $description): array
    {
        return [
            'holiday_code' => $code,
            'holiday_name' => $name,
            'holiday_date' => CarbonImmutable::parse($date)->toDateString(),
            'holiday_type' => $type,
            'is_recurring' => $recurring,
            'description' => $description,
        ];
    }
}
