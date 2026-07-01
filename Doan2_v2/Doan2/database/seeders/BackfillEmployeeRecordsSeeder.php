<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Bù & chuẩn hoá các bản ghi phụ của nhân viên cho đủ + đúng thực tế:
 *  1) Số hợp đồng khớp NĂM bắt đầu (vd HĐ/2015/001, không còn 2024 cho HĐ 2012).
 *  2) Bằng cấp (qualifications): mỗi NV ≥ 1 (bằng cao nhất).
 *  3) Chứng chỉ (certificates): mỗi NV ≥ 1.
 *  4) Lịch sử công tác (employment_histories): mỗi NV ≥ 1 (đang giữ chức).
 *  5) Lương (salary_details): mỗi NV có đủ các kỳ lương hiện có.
 * Idempotent: chỉ thêm khi còn thiếu. DB:: + JSON_UNESCAPED_UNICODE.
 */
class BackfillEmployeeRecordsSeeder extends Seeder
{
    private array $schools = ['Đại học Bách Khoa Hà Nội', 'Đại học Kinh tế Quốc dân', 'Đại học Quốc gia TP.HCM', 'Đại học Ngoại thương', 'Học viện Tài chính', 'Đại học Công nghệ'];
    private array $majors = ['Quản trị kinh doanh', 'Công nghệ thông tin', 'Kế toán', 'Quản trị nhân lực', 'Tài chính - Ngân hàng', 'Kinh tế'];
    private array $grades = ['Khá', 'Giỏi', 'Xuất sắc', 'Trung bình khá'];
    private array $certs = [
        ['Chứng chỉ Tiếng Anh TOEIC', 'IIG Việt Nam'],
        ['Chứng chỉ Tin học IC3', 'Certiport'],
        ['Chứng chỉ An toàn lao động', 'Sở LĐ-TB&XH'],
        ['Chứng chỉ Kế toán trưởng', 'Bộ Tài chính'],
        ['Chứng chỉ Quản lý dự án PMP', 'PMI'],
    ];

    public function run(): void
    {
        // ── 1) Số hợp đồng khớp năm bắt đầu ──
        foreach (DB::table('contracts')->get() as $c) {
            $year = $c->start_date ? substr((string) $c->start_date, 0, 4) : date('Y');
            $want = sprintf('HĐ/%s/%03d', $year, $c->id);
            if ($c->contract_number !== $want) {
                DB::table('contracts')->where('id', $c->id)->update(['contract_number' => $want, 'updated_at' => now()]);
            }
        }

        $periods = DB::table('salary_periods')->orderBy('id')->get();

        foreach (DB::table('employees')->orderBy('id')->get() as $e) {
            if (empty($e->full_name) || stripos($e->full_name, 'System Administrator') !== false) {
                continue;
            }
            $id = (int) $e->id;
            $profile = $this->decode($e->profile);
            $birthYear = $e->date_of_birth ? (int) substr((string) $e->date_of_birth, 0, 4) : 1990;
            $eduLevel = $profile['education_level'] ?? 'Đại học';
            $hire = $e->hire_date ?: '2020-01-01';

            // ── 2) Bằng cấp ──
            if (DB::table('qualifications')->where('employee_id', $id)->count() === 0) {
                $gradYear = min((int) date('Y'), $birthYear + 22);
                DB::table('qualifications')->insert([
                    'employee_id' => $id,
                    'tenant_id' => $e->tenant_id,
                    'qualification_name' => $eduLevel . ' ' . $this->majors[$id % 6],
                    'major' => $this->majors[$id % 6],
                    'school_name' => $this->schools[$id % 6],
                    'graduation_year' => $gradYear,
                    'graduation_grade' => $this->grades[$id % 4],
                    'issued_date' => sprintf('%d-06-15', $gradYear),
                    'issued_by' => $this->schools[$id % 6],
                    'qualification_number' => 'BC' . str_pad((string) $id, 5, '0', STR_PAD_LEFT),
                    'is_highest' => DB::raw('true'),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // ── 3) Chứng chỉ ──
            if (DB::table('certificates')->where('employee_id', $id)->count() === 0) {
                [$cname, $cby] = $this->certs[$id % 5];
                $cyear = min((int) date('Y'), $birthYear + 24);
                DB::table('certificates')->insert([
                    'employee_id' => $id,
                    'tenant_id' => $e->tenant_id,
                    'certificate_name' => $cname,
                    'issued_by' => $cby,
                    'issued_date' => sprintf('%d-09-20', $cyear),
                    'expiry_date' => sprintf('%d-09-20', $cyear + 5),
                    'certificate_number' => 'CC' . str_pad((string) $id, 5, '0', STR_PAD_LEFT),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // ── 4) Lịch sử công tác (đang giữ chức) ──
            if (DB::table('employment_histories')->where('employee_id', $id)->count() === 0) {
                DB::table('employment_histories')->insert([
                    'employee_id' => $id,
                    'tenant_id' => $e->tenant_id,
                    'department_id' => $e->department_id,
                    'position_id' => $e->position_id,
                    'start_date' => $hire,
                    'end_date' => null,
                    'is_current' => DB::raw('true'),
                    'decision_number' => 'QĐ' . str_pad((string) $id, 4, '0', STR_PAD_LEFT) . '/TD',
                    'decision_date' => $hire,
                    'notes' => 'Tuyển dụng và bổ nhiệm vào vị trí công tác',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // ── 5) Lương theo từng kỳ ──
            $contract = DB::table('contracts')->where('employee_id', $id)->where('status', 'CÓ_HIỆU_LỰC')->orderBy('id')->first();
            $cmeta = $contract ? $this->decode($contract->meta) : [];
            $basic = (float) ($cmeta['basic_salary'] ?? (12000000 + ($id % 10) * 1000000));
            $allow = (float) ($cmeta['allowances'] ?? 1500000);
            $gross = $basic + $allow;
            $net = round($gross * 0.87, 0); // ước tính sau BHXH + thuế TNCN

            foreach ($periods as $p) {
                $exists = DB::table('salary_details')->where('employee_id', $id)->where('period_id', $p->id)->exists();
                if (! $exists) {
                    DB::table('salary_details')->insert([
                        'employee_id' => $id,
                        'tenant_id' => $e->tenant_id,
                        'legal_entity_id' => $e->legal_entity_id,
                        'period_id' => $p->id,
                        'contract_id' => $contract->id ?? null,
                        'gross_salary' => $gross,
                        'net_salary' => $net,
                        'transfer_status' => $p->status === 'PAID' ? 'PAID' : 'PENDING',
                        'meta' => json_encode(['basic_salary' => $basic, 'allowances' => $allow], JSON_UNESCAPED_UNICODE),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            $this->command?->info("Bù hồ sơ #{$id} {$e->full_name}");
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
