<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Chuẩn hoá TOÀN BỘ dữ liệu nhân viên cho nhất quán + đúng thực tế:
 *
 *  1) NGÀY: mỗi nhân viên có ĐÚNG 1 hợp đồng đang hiệu lực, loại "không xác định
 *     thời hạn", bắt đầu = ngày vào làm, không ngày kết thúc (đúng thực tế NV
 *     thâm niên). Các hợp đồng thừa → chuyển HẾT_HIỆU_LỰC. probation_end = vào
 *     làm + 60 ngày.
 *
 *  2) GIA ĐÌNH (một nguồn dữ liệu duy nhất, dựng lại từ giới tính + hôn nhân):
 *     - MARRIED: đúng 1 vợ/chồng (ngược giới tính NV) + 1 con (cùng họ NV).
 *       Liên hệ khẩn cấp = chính người vợ/chồng đó (TRÙNG KHỚP tên).
 *     - SINGLE: không vợ/con; 1 mẹ phụ thuộc; liên hệ khẩn cấp = mẹ.
 *     - Tên hợp giới tính, ngày sinh hợp lý theo tuổi NV.
 *
 * Idempotent. Dùng DB:: + json_encode(JSON_UNESCAPED_UNICODE) → tiếng Việt đúng.
 */
class FixEmployeeDataConsistencySeeder extends Seeder
{
    private array $male = ['Nguyễn Văn Hùng', 'Trần Văn Minh', 'Lê Văn Dũng', 'Phạm Văn Sơn', 'Hoàng Văn Nam', 'Vũ Văn Thành', 'Đặng Văn Bình', 'Bùi Văn Khôi'];
    private array $female = ['Trần Thị Lan', 'Lê Thị Hoa', 'Phạm Thị Thu', 'Nguyễn Thị Mai', 'Vũ Thị Hà', 'Đỗ Thị Hương', 'Ngô Thị Cúc', 'Đinh Thị Hồng'];
    private array $childGiven = ['An', 'Bình', 'Chi', 'Dũng', 'Hà', 'Khang', 'Linh', 'Minh', 'Nam', 'Phúc'];

    public function run(): void
    {
        $hdld01 = DB::table('contract_types')->where('contract_type_code', 'HDLD01')->value('id')
            ?? DB::table('contract_types')->min('id');

        foreach (DB::table('employees')->orderBy('id')->get() as $e) {
            if (empty($e->full_name) || stripos($e->full_name, 'System Administrator') !== false) {
                continue;
            }

            $id = (int) $e->id;
            $surname = explode(' ', trim($e->full_name))[0];
            $isMale = in_array(strtoupper((string) $e->gender), ['NAM', 'MALE'], true);
            $profile = $this->decode($e->profile);
            $married = ($profile['marital_status'] ?? '') === 'MARRIED';
            $birthYear = $e->date_of_birth ? (int) substr((string) $e->date_of_birth, 0, 4) : 1990;
            $hireDate = $e->hire_date ?: '2020-01-01';

            // ── 2) Dựng lại người phụ thuộc + liên hệ khẩn cấp ──
            DB::table('dependents')->where('employee_id', $id)->delete();

            $rows = [];
            if ($married) {
                $spouseName = $isMale ? $this->female[$id % 8] : $this->male[$id % 8];
                $spouseRel = $isMale ? 'Vợ' : 'Chồng';
                $spouseYear = max(1970, min(2002, $birthYear + ($isMale ? 2 : -2)));
                $childName = $surname . ($id % 2 === 0 ? ' Thị ' : ' Văn ') . $this->childGiven[$id % 10];
                $childYear = min(2018, max(2008, $birthYear + 27));

                $rows[] = ['full_name' => $spouseName, 'relationship' => $spouseRel, 'dob' => sprintf('%d-08-15', $spouseYear), 'ded' => 0];
                $rows[] = ['full_name' => $childName, 'relationship' => 'Con', 'dob' => sprintf('%d-05-10', $childYear), 'ded' => 100];

                $emName = $spouseName;
                $emRel = $spouseRel;
            } else {
                // SINGLE: 1 mẹ phụ thuộc (cùng họ NV), không vợ/con.
                $motherName = $surname . ' Thị ' . ['Lan', 'Hoa', 'Mai', 'Hồng', 'Cúc'][$id % 5];
                $motherYear = max(1950, $birthYear - 26);
                $rows[] = ['full_name' => $motherName, 'relationship' => 'Mẹ', 'dob' => sprintf('%d-03-20', $motherYear), 'ded' => 100];

                $emName = $motherName;
                $emRel = 'Mẹ';
            }

            foreach ($rows as $r) {
                DB::table('dependents')->insert([
                    'employee_id' => $id,
                    'tenant_id' => $e->tenant_id,
                    'full_name' => $r['full_name'],
                    'relationship' => $r['relationship'],
                    'date_of_birth' => $r['dob'],
                    'deduction_percent' => $r['ded'],
                    'start_date' => $hireDate,
                    'status' => 'ACTIVE',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Liên hệ khẩn cấp = vợ/chồng (married) hoặc mẹ (single) → khớp người phụ thuộc.
            $profile['emergency_contact_name'] = $emName;
            $profile['emergency_contact_relationship'] = $emRel;
            $profile['emergency_contact_phone'] = '09' . str_pad((string) (($id * 7654321 + 11) % 100000000), 8, '0', STR_PAD_LEFT);
            $profile['probation_end_date'] = Carbon::parse($hireDate)->addDays(60)->toDateString();

            DB::table('employees')->where('id', $id)->update([
                'profile' => json_encode($profile, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);

            // ── 1) Hợp đồng: đúng 1 HĐ hiệu lực, vô thời hạn, từ ngày vào làm ──
            $contracts = DB::table('contracts')->where('employee_id', $id)->orderBy('id')->get();
            if ($contracts->isEmpty()) {
                DB::table('contracts')->insert([
                    'tenant_id' => $e->tenant_id,
                    'legal_entity_id' => $e->legal_entity_id,
                    'employee_id' => $id,
                    'contract_type_id' => $hdld01,
                    'department_id' => $e->department_id,
                    'position_id' => $e->position_id,
                    'contract_number' => sprintf('HĐ/%s/%03d', substr((string) $hireDate, 0, 4), $id),
                    'status' => 'CÓ_HIỆU_LỰC',
                    'start_date' => $hireDate,
                    'end_date' => null,
                    'meta' => json_encode(['basic_salary' => 12000000 + ($id % 10) * 1000000, 'allowances' => 1500000, 'effective_date' => $hireDate], JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $canonical = $contracts->first();
                DB::table('contracts')->where('id', $canonical->id)->update([
                    'contract_type_id' => $hdld01,
                    'start_date' => $hireDate,
                    'end_date' => null,
                    'status' => 'CÓ_HIỆU_LỰC',
                    'department_id' => $e->department_id,
                    'position_id' => $e->position_id,
                    'updated_at' => now(),
                ]);
                $others = $contracts->slice(1)->pluck('id')->all();
                if (! empty($others)) {
                    DB::table('contracts')->whereIn('id', $others)->update(['status' => 'HẾT_HIỆU_LỰC', 'updated_at' => now()]);
                }
            }

            $this->command?->info("Chuẩn hoá #{$id} {$e->full_name} (" . ($married ? 'married' : 'single') . ')');
        }
    }

    private function decode($p): array
    {
        if (! $p) {
            return [];
        }
        $d = is_string($p) ? json_decode($p, true) : (array) $p;

        return is_array($d) ? $d : [];
    }
}
