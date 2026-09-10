<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Backfill "chân dung 360" cho nhân viên hiện có bằng dữ liệu VN hợp lý (demo).
 *
 * Idempotent: chỉ điền các trường còn trống trong employees.profile, và chỉ tạo
 * người phụ thuộc nếu nhân viên chưa có. Dùng DB:: trực tiếp để bỏ qua global
 * tenant scope khi chạy ở CLI (seeder không có ngữ cảnh đăng nhập).
 */
class BackfillEmployeeProfilesSeeder extends Seeder
{
    public function run(): void
    {
        $banks = ['Vietcombank', 'Techcombank', 'BIDV', 'VietinBank', 'MB Bank', 'ACB', 'VPBank', 'Agribank', 'Sacombank', 'TPBank'];
        $provinces = ['Hà Nội', 'TP. Hồ Chí Minh', 'Đà Nẵng', 'Hải Phòng', 'Cần Thơ', 'Bình Dương', 'Đồng Nai', 'Khánh Hòa', 'Nghệ An', 'Thanh Hóa', 'Quảng Ninh', 'Bắc Ninh'];
        $education = ['Đại học', 'Thạc sĩ', 'Cao đẳng', 'Trung cấp'];
        $streets = ['Lê Lợi', 'Nguyễn Huệ', 'Trần Hưng Đạo', 'Hai Bà Trưng', 'Lý Thường Kiệt', 'Phan Đình Phùng', 'Nguyễn Trãi', 'Hoàng Văn Thụ'];
        $relationships = ['Vợ', 'Chồng', 'Bố', 'Mẹ', 'Anh trai', 'Chị gái'];

        $employees = DB::table('employees')->orderBy('id')->get();

        foreach ($employees as $e) {
            // Bỏ qua tài khoản kỹ thuật (System Administrator) — không phải nhân sự thật.
            if (empty($e->full_name) || stripos($e->full_name, 'System Administrator') !== false) {
                continue;
            }

            $id = (int) $e->id;
            $profile = $this->decode($e->profile);

            $province = $provinces[$id % count($provinces)];
            $street = $streets[$id % count($streets)];
            $married = ($id % 3 !== 0); // ~2/3 đã kết hôn
            $birthYear = $e->date_of_birth ? (int) substr((string) $e->date_of_birth, 0, 4) : 1990;

            $defaults = [
                'ethnicity' => 'Kinh',
                'religion' => 'Không',
                'nationality_name' => 'Việt Nam',
                'marital_status' => $married ? 'MARRIED' : 'SINGLE',
                'education_level' => $education[$id % count($education)],
                'hometown' => $province,
                'permanent_address' => sprintf('Số %d, đường %s, %s', ($id * 7) % 200 + 1, $street, $province),
                'address' => sprintf('Số %d, đường %s, %s', ($id * 13) % 200 + 1, $street, $province),
                'id_number' => $this->numericString(12, $id * 1000003 + 79),
                'id_issue_date' => sprintf('2021-%02d-15', ($id % 12) + 1),
                'id_issue_place' => 'Cục Cảnh sát QLHC về TTXH',
                'tax_number' => $this->numericString(10, $id * 7919 + 8),
                'insurance_number' => $this->numericString(10, $id * 104729 + 1),
                'bank_name' => $banks[$id % count($banks)],
                'bank_account' => $this->numericString(12, $id * 31337 + 5),
                'personal_email' => 'nhanvien' . $id . '@gmail.com',
                'personal_phone' => $e->phone_number ?: $this->numericString(10, $id, '09'),
                'emergency_contact_name' => $this->emergencyName($married, $id),
                'emergency_contact_relationship' => $relationships[$id % count($relationships)],
                'emergency_contact_phone' => $this->numericString(10, $id * 17 + 3, '09'),
            ];

            if ($e->hire_date) {
                $defaults['probation_end_date'] = Carbon::parse($e->hire_date)->addDays(60)->toDateString();
            }

            // Chỉ điền key còn trống — không ghi đè dữ liệu đã có.
            foreach ($defaults as $k => $v) {
                if (! isset($profile[$k]) || $profile[$k] === '' || $profile[$k] === null) {
                    $profile[$k] = $v;
                }
            }

            DB::table('employees')->where('id', $id)->update([
                'profile' => json_encode($profile, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);

            // Người phụ thuộc (demo) — chỉ tạo nếu chưa có.
            $hasDeps = DB::table('dependents')->where('employee_id', $id)->exists();
            if (! $hasDeps) {
                $deps = [];
                if ($married) {
                    $deps[] = [
                        'full_name' => ($id % 2 === 0 ? 'Nguyễn Văn ' : 'Trần Thị ') . chr(65 + ($id % 26)),
                        'relationship' => $id % 2 === 0 ? 'Chồng' : 'Vợ',
                        'date_of_birth' => sprintf('%d-0%d-12', $birthYear + 1, ($id % 8) + 1),
                        'deduction_percent' => 0,
                    ];
                    $deps[] = [
                        'full_name' => ($id % 2 === 0 ? 'Nguyễn ' : 'Trần ') . 'Bé ' . chr(65 + (($id + 3) % 26)),
                        'relationship' => 'Con',
                        'date_of_birth' => sprintf('%d-0%d-20', $birthYear + 22, ($id % 8) + 1),
                        'deduction_percent' => 100, // người phụ thuộc giảm trừ gia cảnh
                    ];
                }
                foreach ($deps as $d) {
                    DB::table('dependents')->insert([
                        'employee_id' => $id,
                        'tenant_id' => $e->tenant_id,
                        'full_name' => $d['full_name'],
                        'relationship' => $d['relationship'],
                        'date_of_birth' => $d['date_of_birth'],
                        'tax_code' => $this->numericString(10, $id * 991 + strlen($d['full_name'])),
                        'deduction_percent' => $d['deduction_percent'],
                        'start_date' => $e->hire_date ?: '2023-01-01',
                        'status' => 'ACTIVE',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            $this->command?->info("Backfilled employee #{$id} {$e->full_name}");
        }
    }

    private function decode($profile): array
    {
        if (! $profile) {
            return [];
        }
        $decoded = is_string($profile) ? json_decode($profile, true) : (array) $profile;

        return is_array($decoded) ? $decoded : [];
    }

    /** Chuỗi số deterministic độ dài $len, có thể có tiền tố. */
    private function numericString(int $len, int $seed, string $prefix = ''): string
    {
        $s = (string) abs($seed * 2654435761);
        $s = str_pad($s, $len, '0', STR_PAD_LEFT);
        $body = substr($s, 0, max(0, $len - strlen($prefix)));

        return $prefix . $body;
    }

    private function emergencyName(bool $married, int $id): string
    {
        $ho = ['Nguyễn', 'Trần', 'Lê', 'Phạm', 'Hoàng', 'Vũ'][$id % 6];
        $ten = ['Văn Hùng', 'Thị Lan', 'Văn Minh', 'Thị Thu', 'Văn Dũng', 'Thị Hà'][$id % 6];

        return "{$ho} {$ten}";
    }
}
