<?php

namespace App\Support;

use App\Models\Contract;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Trộn dữ liệu hợp đồng + nhân viên + công ty vào mẫu hợp đồng (placeholder
 * {{key}}). Cung cấp mẫu HĐLĐ chuẩn VN mặc định + danh mục placeholder.
 *
 * Lưu ý: mẫu mặc định mang tính tham khảo, cần bộ phận pháp chế rà soát trước
 * khi dùng chính thức.
 */
class ContractRenderer
{
    /** Danh mục trường trộn (key + nhãn hiển thị cho UI). */
    public static function placeholders(): array
    {
        return [
            ['key' => 'ho_ten', 'label' => 'Họ tên nhân viên'],
            ['key' => 'ma_nhan_vien', 'label' => 'Mã nhân viên'],
            ['key' => 'ngay_sinh', 'label' => 'Ngày sinh'],
            ['key' => 'gioi_tinh', 'label' => 'Giới tính'],
            ['key' => 'cccd', 'label' => 'Số CCCD/CMND'],
            ['key' => 'ngay_cap_cccd', 'label' => 'Ngày cấp CCCD'],
            ['key' => 'noi_cap_cccd', 'label' => 'Nơi cấp CCCD'],
            ['key' => 'dia_chi_thuong_tru', 'label' => 'Địa chỉ thường trú'],
            ['key' => 'sdt', 'label' => 'Số điện thoại'],
            ['key' => 'email_ca_nhan', 'label' => 'Email cá nhân'],
            ['key' => 'chuc_danh', 'label' => 'Chức danh'],
            ['key' => 'phong_ban', 'label' => 'Phòng ban'],
            ['key' => 'loai_hop_dong', 'label' => 'Loại hợp đồng'],
            ['key' => 'so_hop_dong', 'label' => 'Số hợp đồng'],
            ['key' => 'ngay_bat_dau', 'label' => 'Ngày bắt đầu hiệu lực HĐ'],
            ['key' => 'ngay_vao_lam', 'label' => 'Ngày vào làm chính thức'],
            ['key' => 'ngay_ket_thuc', 'label' => 'Ngày kết thúc'],
            ['key' => 'thoi_han', 'label' => 'Thời hạn hợp đồng'],
            ['key' => 'muc_luong', 'label' => 'Mức lương'],
            ['key' => 'phu_cap', 'label' => 'Phụ cấp'],
            ['key' => 'cong_ty', 'label' => 'Tên công ty'],
            ['key' => 'dia_chi_cong_ty', 'label' => 'Địa chỉ công ty'],
            ['key' => 'ma_so_thue_cong_ty', 'label' => 'MST công ty'],
            ['key' => 'nguoi_dai_dien', 'label' => 'Người đại diện công ty'],
            ['key' => 'chuc_vu_dai_dien', 'label' => 'Chức vụ người đại diện'],
            ['key' => 'ngay_ky', 'label' => 'Ngày ký'],
            ['key' => 'dia_diem_ky', 'label' => 'Địa điểm ký'],
        ];
    }

    /** Xây map dữ liệu trộn cho 1 hợp đồng. */
    public static function buildData(Contract $contract): array
    {
        $contract->loadMissing(['employee', 'contractType', 'department']);
        $emp = $contract->employee;
        $profile = self::decode($emp?->profile);
        $meta = is_array($contract->meta) ? $contract->meta : [];

        $entity = $contract->legal_entity_id
            ? (array) DB::table('legal_entities')->where('id', $contract->legal_entity_id)->first()
            : [];
        // Người đại diện ký Bên A + chức vụ lưu trong legal_entities.meta.
        $entityMeta = self::decode($entity['meta'] ?? null);

        $start = $contract->start_date ? Carbon::parse($contract->start_date) : null;
        $end = $contract->end_date ? Carbon::parse($contract->end_date) : null;
        $thoiHan = $end ? 'Có thời hạn đến ' . $end->format('d/m/Y') : 'Không xác định thời hạn';

        // Chức danh: ưu tiên position của hợp đồng, fallback position của NV.
        $positionId = $contract->position_id ?: $emp?->position_id;
        $chucDanh = $positionId
            ? (DB::table('positions')->where('id', $positionId)->value('position_name') ?? '')
            : '';

        return [
            'ho_ten' => $emp?->full_name ?? '',
            'ma_nhan_vien' => $emp?->employee_code ?? '',
            'ngay_sinh' => $emp?->date_of_birth ? Carbon::parse($emp->date_of_birth)->format('d/m/Y') : '',
            'gioi_tinh' => self::gender($emp?->gender),
            'cccd' => $profile['id_number'] ?? '',
            'ngay_cap_cccd' => isset($profile['id_issue_date']) ? self::date($profile['id_issue_date']) : '',
            'noi_cap_cccd' => $profile['id_issue_place'] ?? '',
            'dia_chi_thuong_tru' => $profile['permanent_address'] ?? ($profile['address'] ?? ''),
            'sdt' => $profile['personal_phone'] ?? ($emp?->phone_number ?? ''),
            'email_ca_nhan' => $profile['personal_email'] ?? '',
            'chuc_danh' => $chucDanh,
            'phong_ban' => $contract->department?->department_name ?? '',
            'loai_hop_dong' => $contract->contractType?->contract_type_name ?? '',
            'so_hop_dong' => $contract->contract_number ?? '',
            'ngay_bat_dau' => $start ? $start->format('d/m/Y') : '',
            'ngay_ket_thuc' => $end ? $end->format('d/m/Y') : '',
            'thoi_han' => $thoiHan,
            'muc_luong' => self::money($meta['basic_salary'] ?? null),
            'phu_cap' => self::money($meta['allowances'] ?? null),
            'cong_ty' => $entity['name'] ?? 'Công ty',
            'dia_chi_cong_ty' => $entity['address'] ?? '',
            'ma_so_thue_cong_ty' => $entity['tax_code'] ?? '',
            'nguoi_dai_dien' => $entityMeta['representative'] ?? '',
            'chuc_vu_dai_dien' => $entityMeta['representative_title'] ?? 'Giám đốc',
            // Ngày ký = ngày ký THẬT trên HĐ (meta.sign_date), KHÔNG phải hôm nay;
            // fallback về ngày bắt đầu rồi mới tới hôm nay.
            'ngay_ky' => isset($meta['sign_date']) && $meta['sign_date']
                ? self::date($meta['sign_date'])
                : ($start ? $start->format('d/m/Y') : now()->format('d/m/Y')),
            'ngay_vao_lam' => $start ? $start->format('d/m/Y') : '',
            'dia_diem_ky' => $entity['address'] ?? '',
        ];
    }

    /** Trộn data vào nội dung mẫu: thay {{key}} bằng giá trị. */
    public static function render(string $content, array $data): string
    {
        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', function ($m) use ($data) {
            $key = $m[1];

            return array_key_exists($key, $data) ? (string) $data[$key] : $m[0];
        }, $content);
    }

    private static function decode($p): array
    {
        if (! $p) {
            return [];
        }
        $d = is_string($p) ? json_decode($p, true) : (array) $p;

        return is_array($d) ? $d : [];
    }

    private static function gender($g): string
    {
        $g = strtoupper((string) $g);

        return match (true) {
            in_array($g, ['MALE', 'NAM', 'M'], true) => 'Nam',
            in_array($g, ['FEMALE', 'NỮ', 'F'], true) => 'Nữ',
            default => '',
        };
    }

    private static function date($d): string
    {
        try {
            return Carbon::parse($d)->format('d/m/Y');
        } catch (\Throwable $e) {
            return (string) $d;
        }
    }

    private static function money($v): string
    {
        if ($v === null || $v === '') {
            return '';
        }

        return number_format((float) $v, 0, ',', '.') . ' đ';
    }

    /** Mẫu HĐLĐ chuẩn VN mặc định (tham khảo). */
    public static function defaultTemplate(): string
    {
        return <<<'HTML'
<div style="font-family: 'Times New Roman', serif; line-height: 1.6; color: #111; max-width: 760px; margin: 0 auto; padding: 24px 36px; text-align: left;">
  <div style="text-align:center; font-weight:bold;">
    CỘNG HÒA XÃ HỘI CHỦ NGHĨA VIỆT NAM<br>
    Độc lập - Tự do - Hạnh phúc<br>
    -------------------
  </div>
  <h2 style="text-align:center; margin-top:16px;">HỢP ĐỒNG LAO ĐỘNG</h2>
  <p style="text-align:center;">Số: {{so_hop_dong}}</p>

  <p>Hôm nay, ngày {{ngay_ky}}, tại {{dia_diem_ky}}, chúng tôi gồm:</p>

  <p><b>BÊN A (NGƯỜI SỬ DỤNG LAO ĐỘNG):</b> {{cong_ty}}</p>
  <ul>
    <li>Địa chỉ: {{dia_chi_cong_ty}}</li>
    <li>Mã số thuế: {{ma_so_thue_cong_ty}}</li>
    <li>Người đại diện: {{nguoi_dai_dien}} &nbsp;&nbsp; Chức vụ: {{chuc_vu_dai_dien}}</li>
  </ul>

  <p><b>BÊN B (NGƯỜI LAO ĐỘNG):</b> {{ho_ten}}</p>
  <ul>
    <li>Mã nhân viên: {{ma_nhan_vien}} &nbsp;&nbsp; Giới tính: {{gioi_tinh}} &nbsp;&nbsp; Ngày sinh: {{ngay_sinh}}</li>
    <li>CCCD/CMND: {{cccd}} &nbsp;&nbsp; Ngày cấp: {{ngay_cap_cccd}} &nbsp;&nbsp; Nơi cấp: {{noi_cap_cccd}}</li>
    <li>Địa chỉ thường trú: {{dia_chi_thuong_tru}}</li>
    <li>Điện thoại: {{sdt}} &nbsp;&nbsp; Email: {{email_ca_nhan}}</li>
  </ul>

  <p>Hai bên thỏa thuận ký kết hợp đồng lao động và cam kết làm đúng những điều khoản sau đây:</p>

  <p><b>Điều 1. Loại hợp đồng, công việc và thời hạn</b></p>
  <ul>
    <li>Loại hợp đồng: {{loai_hop_dong}} ({{thoi_han}})</li>
    <li>Chức danh chuyên môn: {{chuc_danh}} &nbsp;&nbsp; Bộ phận: {{phong_ban}}</li>
    <li>Thời hạn hợp đồng: từ ngày {{ngay_bat_dau}} đến ngày {{ngay_ket_thuc}}</li>
  </ul>

  <p><b>Điều 2. Chế độ làm việc</b></p>
  <ul>
    <li>Thời giờ làm việc: theo quy định và nội quy lao động của công ty.</li>
    <li>Được cấp phát phương tiện làm việc theo yêu cầu công việc.</li>
  </ul>

  <p><b>Điều 3. Quyền lợi và nghĩa vụ của người lao động</b></p>
  <ul>
    <li>Mức lương cơ bản: {{muc_luong}} / tháng. Phụ cấp: {{phu_cap}}.</li>
    <li>Hình thức trả lương: chuyển khoản, kỳ trả lương theo quy định công ty.</li>
    <li>Được tham gia BHXH, BHYT, BHTN theo quy định của pháp luật.</li>
    <li>Được nghỉ lễ, phép năm và hưởng các chế độ theo Bộ luật Lao động.</li>
    <li>Hoàn thành công việc đã cam kết; chấp hành nội quy, kỷ luật lao động.</li>
  </ul>

  <p><b>Điều 4. Nghĩa vụ và quyền hạn của người sử dụng lao động</b></p>
  <ul>
    <li>Bảo đảm việc làm, trả lương đầy đủ, đúng hạn cho người lao động.</li>
    <li>Thực hiện đầy đủ các chế độ, chính sách đối với người lao động.</li>
  </ul>

  <p><b>Điều 5. Điều khoản thi hành</b></p>
  <ul>
    <li>Hợp đồng có hiệu lực kể từ ngày {{ngay_bat_dau}}.</li>
    <li>Hợp đồng được lập thành 02 bản có giá trị pháp lý như nhau, mỗi bên giữ 01 bản.</li>
  </ul>

  <table style="width:100%; margin-top:24px; text-align:center;">
    <tr>
      <td style="width:50%;"><b>NGƯỜI LAO ĐỘNG</b><br><i>(Ký, ghi rõ họ tên)</i></td>
      <td style="width:50%;"><b>NGƯỜI SỬ DỤNG LAO ĐỘNG</b><br><i>(Ký, đóng dấu)</i></td>
    </tr>
  </table>
</div>
HTML;
    }
}
