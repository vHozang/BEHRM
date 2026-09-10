<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Sửa các điểm dữ liệu demo vô lý do seeder trước tạo ra + đảm bảo mọi nhân viên
 * đều có hợp đồng:
 *  1) Liên hệ khẩn cấp: tên và quan hệ phải khớp giới tính (vd không để "Trần Thị
 *     Lan" mà quan hệ là "Chồng"); quan hệ vợ/chồng phải khớp giới tính NV.
 *  2) Người phụ thuộc vợ/chồng: quan hệ phải ngược giới tính NV (NV nam → "Vợ").
 *  3) Tạo hợp đồng ACTIVE cho nhân viên chưa có hợp đồng.
 *
 * Dùng DB:: trực tiếp (CLI, bỏ qua tenant scope). An toàn chạy lại nhiều lần.
 */
class FixDemoDataConsistencySeeder extends Seeder
{
    private array $maleNames = ['Nguyễn Văn Hùng', 'Trần Văn Minh', 'Lê Văn Dũng', 'Phạm Văn Sơn', 'Hoàng Văn Nam', 'Vũ Văn Thành'];
    private array $femaleNames = ['Trần Thị Lan', 'Lê Thị Hoa', 'Phạm Thị Thu', 'Nguyễn Thị Mai', 'Vũ Thị Hà', 'Đỗ Thị Hương'];

    public function run(): void
    {
        $employees = DB::table('employees')->orderBy('id')->get();

        foreach ($employees as $e) {
            if (empty($e->full_name) || stripos($e->full_name, 'System Administrator') !== false) {
                continue;
            }
            $id = (int) $e->id;
            $isMale = strtoupper((string) $e->gender) === 'NAM' || strtoupper((string) $e->gender) === 'MALE';
            $profile = $this->decode($e->profile);
            $married = ($profile['marital_status'] ?? '') === 'MARRIED';

            // ── 1) Liên hệ khẩn cấp: chọn quan hệ + tên khớp giới tính ──
            $pick = $id % 5;
            if ($pick === 4) {
                if ($married) {
                    $rel = $isMale ? 'Vợ' : 'Chồng';
                    $nameIsMale = ! $isMale; // vợ/chồng ngược giới tính NV
                } else {
                    $rel = 'Mẹ';
                    $nameIsMale = false;
                }
            } else {
                [$rel, $g] = [['Bố', 'M'], ['Mẹ', 'F'], ['Anh trai', 'M'], ['Chị gái', 'F']][$pick];
                $nameIsMale = $g === 'M';
            }
            $profile['emergency_contact_relationship'] = $rel;
            $profile['emergency_contact_name'] = $nameIsMale
                ? $this->maleNames[$id % 6]
                : $this->femaleNames[$id % 6];

            DB::table('employees')->where('id', $id)->update([
                'profile' => json_encode($profile, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);

            // ── 2) Người phụ thuộc vợ/chồng phải khớp giới tính NV ──
            $correctSpouseRel = $isMale ? 'Vợ' : 'Chồng';
            $spouseRows = DB::table('dependents')
                ->where('employee_id', $id)
                ->whereIn('relationship', ['Vợ', 'Chồng'])
                ->get();
            foreach ($spouseRows as $sp) {
                if ($sp->relationship !== $correctSpouseRel) {
                    DB::table('dependents')->where('id', $sp->id)->update([
                        'relationship' => $correctSpouseRel,
                        'full_name' => $isMale ? $this->femaleNames[$id % 6] : $this->maleNames[$id % 6],
                        'updated_at' => now(),
                    ]);
                    $this->command?->warn("Fixed spouse dependent for #{$id}: -> {$correctSpouseRel}");
                }
            }
        }

        // ── 3) Tạo hợp đồng ACTIVE cho nhân viên chưa có hợp đồng ──
        $defaultTypeId = DB::table('contract_types')->where('contract_type_code', 'HDLD01')->value('id')
            ?? DB::table('contract_types')->min('id');

        $withContract = DB::table('contracts')->pluck('employee_id')->unique()->all();
        $missing = DB::table('employees')
            ->whereNotIn('id', $withContract ?: [0])
            ->where('status', '!=', 'TERMINATED')
            ->get();

        $seq = (int) (DB::table('contracts')->count()) + 1;
        foreach ($missing as $e) {
            if (empty($e->full_name) || stripos($e->full_name, 'System Administrator') !== false) {
                continue;
            }
            $start = $e->hire_date ?: '2023-01-01';
            DB::table('contracts')->insert([
                'tenant_id' => $e->tenant_id,
                'legal_entity_id' => $e->legal_entity_id,
                'employee_id' => $e->id,
                'contract_type_id' => $defaultTypeId,
                'department_id' => $e->department_id,
                'position_id' => $e->position_id,
                'contract_number' => sprintf('HĐ/2024/%03d', $seq),
                'status' => 'CÓ_HIỆU_LỰC',
                'start_date' => $start,
                'end_date' => null, // không xác định thời hạn
                'meta' => json_encode([
                    'basic_salary' => 12000000 + ($e->id % 10) * 1000000,
                    'allowances' => 1500000,
                    'effective_date' => $start,
                    'notes' => 'Hợp đồng khởi tạo tự động (demo)',
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->command?->info("Created contract {$seq} for #{$e->id} {$e->full_name}");
            $seq++;
        }

        $this->shiftStaleDemoDates();
        $this->refreshDemoContent();
    }

    /**
     * 5) Nội dung demo "cụt" (news "Nội dung chi tiết...", policy 3 dòng) → thay bằng
     * văn bản doanh nghiệp thật. Idempotent: chỉ ghi đè khi content còn là bản cụt
     * (kết thúc "..." hoặc quá ngắn) — nội dung admin tự sửa sau này không bị đè.
     */
    private function refreshDemoContent(): void
    {
        $year = (int) now()->format('Y');

        $news = [
            'Thông báo lịch nghỉ lễ 30/4 và 1/5' => "Kính gửi toàn thể CBNV,\n\nCông ty thông báo lịch nghỉ Lễ Giải phóng miền Nam 30/4 và Quốc tế Lao động 1/5 như sau:\n\n• Thời gian nghỉ: từ thứ Tư 30/4 đến hết Chủ nhật 04/5 (hoán đổi ngày làm việc theo thông báo của Bộ LĐ-TB&XH).\n• Ngày làm việc trở lại: thứ Hai 05/5.\n• CBNV được hưởng nguyên lương các ngày lễ theo Điều 112 Bộ luật Lao động.\n• Bộ phận sản xuất có đơn hàng gấp đăng ký làm thêm với Trưởng phân xưởng trước ngày 25/4; lương làm thêm ngày lễ tính 300% theo Điều 98.\n• Bảo vệ và kỹ thuật trực theo lịch phân công riêng.\n\nĐề nghị các phòng ban sắp xếp công việc, khoá công việc dở dang và bàn giao trước kỳ nghỉ.\n\nPhòng Hành chính - Nhân sự",
            'Tổ chức team building tại Vũng Tàu' => "Công ty tổ chức chương trình Team Building {$year} với chủ đề \"Một đội ngũ - Một hành trình\":\n\n• Thời gian: 2 ngày 1 đêm, thứ Bảy - Chủ nhật tuần thứ 3 tháng 8.\n• Địa điểm: Khu du lịch Hồ Tràm, Vũng Tàu.\n• Đối tượng: toàn thể CBNV chính thức và thử việc; người thân đi kèm đăng ký thêm (chi phí ưu đãi 50%).\n• Chi phí: công ty đài thọ 100% cho CBNV (xe đưa đón, khách sạn, ăn uống, gala dinner).\n• Đăng ký: trên hệ thống HRM mục Hỗ trợ nội bộ hoặc với HR phòng ban, hạn chót 05/8.\n\nChương trình gồm hoạt động team building bãi biển, gala trao giải Nhân viên xuất sắc 6 tháng đầu năm và bốc thăm trúng thưởng.\n\nBan tổ chức",
            'Cập nhật chính sách làm việc từ xa' => "Từ ngày 01 tháng tới, chính sách làm việc từ xa (WFH) phiên bản 1.2 chính thức áp dụng:\n\n• Khối văn phòng được đăng ký tối đa 2 ngày WFH/tuần (không áp dụng khối sản xuất, kho, QC).\n• Đăng ký trên hệ thống HRM (Nghỉ phép → loại \"Làm việc từ xa\") trước 17h00 ngày hôm trước; quản lý trực tiếp phê duyệt.\n• Ngày WFH tính công bình thường; NV phải online trong giờ làm việc, tham gia đầy đủ cuộc họp và cập nhật kết quả cuối ngày.\n• Không WFH vào ngày có lịch họp toàn công ty, đào tạo bắt buộc hoặc kiểm kê.\n\nChi tiết xem văn bản \"Chính sách làm việc linh hoạt\" tại mục Chính sách công ty.\n\nPhòng Hành chính - Nhân sự",
            'Thông báo tuyển dụng thực tập sinh' => "Công ty khởi động chương trình Thực tập sinh {$year} đợt 2:\n\n• Vị trí: Kỹ thuật sản xuất (4), Kế toán (2), Hành chính - Nhân sự (2), IT (2).\n• Thời gian thực tập: 3 tháng, hỗ trợ 4.000.000đ/tháng + phụ cấp ăn trưa; ký HĐ thử việc sau khi đạt đánh giá.\n• Đối tượng: sinh viên năm cuối các trường ĐH/CĐ khối kỹ thuật, kinh tế.\n• Hồ sơ: CV gửi về tuyendung@codedengu.vn trước cuối tháng này.\n\nCBNV giới thiệu ứng viên trúng tuyển và hoàn thành thực tập được thưởng giới thiệu 1.000.000đ/ứng viên theo chính sách tuyển dụng nội bộ.\n\nPhòng Hành chính - Nhân sự",
            "Giải bóng đá nội bộ năm {$year}" => "Giải bóng đá nội bộ CODEDENGU CUP {$year} chính thức khởi tranh:\n\n• Thể thức: bóng đá 5 người, vòng bảng + loại trực tiếp.\n• Đội tham dự: mỗi khối/phân xưởng đăng ký 1 đội (8-10 cầu thủ, khuyến khích có nữ).\n• Thời gian: các chiều thứ Bảy trong tháng 8, sân bóng Khu công nghiệp.\n• Giải thưởng: Vô địch 5.000.000đ; Á quân 3.000.000đ; Ba 2.000.000đ; Vua phá lưới và Thủ môn xuất sắc 500.000đ/giải.\n• Đăng ký danh sách với Công đoàn trước 01/8.\n\nCông ty tài trợ toàn bộ chi phí sân bãi, nước uống và trang phục thi đấu cho các đội.\n\nBan Chấp hành Công đoàn",
            'Cập nhật quy định về trang phục' => "Nhằm xây dựng hình ảnh chuyên nghiệp, Công ty cập nhật quy định trang phục áp dụng từ tháng tới:\n\n• Khối văn phòng: thứ Hai - thứ Năm mặc business casual (sơ mi/polo, quần âu/chân váy công sở); thứ Sáu trang phục tự do lịch sự.\n• Khối sản xuất, kho, QC: bắt buộc mặc đồng phục bảo hộ và giày bảo hộ trong toàn bộ giờ làm việc tại xưởng (NĐ 44/2016 về ATVSLĐ); không đeo trang sức khi vận hành máy.\n• Ngày tiếp khách hàng/đối tác: trang phục formal.\n• Thẻ nhân viên đeo trong suốt giờ làm việc tại mọi khu vực.\n\nQuản lý trực tiếp nhắc nhở lần đầu; vi phạm lặp lại xử lý theo Nội quy lao động.\n\nPhòng Hành chính - Nhân sự",
        ];

        $updated = 0;
        foreach ($news as $title => $content) {
            $updated += DB::table('news')->where('title', $title)
                ->where(fn ($q) => $q->where('content', 'like', '%...')->orWhereRaw('length(content) < 120'))
                ->update(['content' => $content, 'updated_at' => now()]);
        }

        $policies = [
            'POL-HR-001' => "NỘI QUY LAO ĐỘNG\n\nI. MỤC ĐÍCH VÀ PHẠM VI\nÁp dụng cho toàn thể người lao động làm việc theo hợp đồng lao động tại Công ty, ban hành căn cứ Bộ luật Lao động 2019 và đã đăng ký với cơ quan quản lý lao động.\n\nII. THỜI GIỜ LÀM VIỆC - NGHỈ NGƠI\n1. Khối văn phòng: 08h00-17h00 thứ Hai đến thứ Sáu, nghỉ trưa 12h00-13h00.\n2. Khối sản xuất: theo ca (Ca 1: 06h00-14h00, Ca 2: 14h00-22h00, Ca 3: 22h00-06h00), tuần làm việc 6 ngày, nghỉ Chủ nhật.\n3. Chấm công bằng máy chấm công/hệ thống HRM khi vào và ra. Đi muộn/về sớm phải báo quản lý trực tiếp.\n4. Nghỉ phép năm 12 ngày (+1 ngày/5 năm thâm niên theo Điều 114); đăng ký trên hệ thống trước ít nhất 3 ngày, trừ trường hợp đột xuất.\n\nIII. TÁC PHONG VÀ TRẬT TỰ\n1. Đeo thẻ nhân viên, mặc trang phục theo quy định; giữ gìn vệ sinh chung.\n2. Không sử dụng rượu bia, chất kích thích trong giờ làm việc; không hút thuốc ngoài khu vực quy định.\n3. Khối sản xuất tuân thủ tuyệt đối quy trình vận hành và ATVSLĐ; sử dụng đầy đủ phương tiện bảo hộ được cấp.\n\nIV. BẢO MẬT\nKhông tiết lộ tiền lương của người khác, dữ liệu khách hàng, công nghệ và bí mật kinh doanh; nghĩa vụ bảo mật duy trì cả sau khi nghỉ việc.\n\nV. KỶ LUẬT LAO ĐỘNG (Điều 124 BLLĐ)\nKhiển trách → Kéo dài thời hạn nâng lương không quá 6 tháng/Cách chức → Sa thải. Việc xử lý theo đúng trình tự luật định, có mặt người lao động và đại diện công đoàn.\n\nVI. HIỆU LỰC\nNội quy có hiệu lực kể từ ngày ban hành; mọi nhân viên có trách nhiệm đọc và ký xác nhận trên hệ thống HRM.",
            'POL-IT-001' => "CHÍNH SÁCH AN TOÀN THÔNG TIN\n\nI. MỤC ĐÍCH\nBảo vệ dữ liệu công ty, dữ liệu cá nhân người lao động (tuân thủ Nghị định 13/2023/NĐ-CP về bảo vệ dữ liệu cá nhân) và hệ thống nội bộ.\n\nII. TÀI KHOẢN VÀ MẬT KHẨU\n1. Mật khẩu tối thiểu 8 ký tự, không dùng chung tài khoản, không chia sẻ mật khẩu dưới mọi hình thức.\n2. Khóa màn hình khi rời chỗ; đăng xuất hệ thống HRM khi dùng máy chung tại xưởng.\n3. Nghỉ việc: tài khoản bị vô hiệu ngay trong ngày làm việc cuối.\n\nIII. THIẾT BỊ VÀ DỮ LIỆU\n1. Thiết bị công ty cấp chỉ dùng cho công việc; cài phần mềm ngoài danh mục phải được IT duyệt.\n2. Không sao chép dữ liệu nhân sự, lương, khách hàng ra thiết bị cá nhân/cloud cá nhân.\n3. Dữ liệu lương và hồ sơ nhân sự chỉ truy cập theo đúng phân quyền trên hệ thống; mọi truy cập đều được ghi log.\n\nIV. SỰ CỐ\nPhát hiện email lừa đảo, mất thiết bị, lộ dữ liệu: báo ngay IT qua Hỗ trợ nội bộ (ưu tiên Cao) trong vòng 24 giờ.\n\nV. HIỆU LỰC\nVi phạm xử lý theo Nội quy lao động; trường hợp gây thiệt hại phải bồi thường theo quy định pháp luật.",
            'POL-WF-001' => "CHÍNH SÁCH LÀM VIỆC LINH HOẠT\n\nI. ĐỐI TƯỢNG\nÁp dụng cho khối văn phòng đã qua thử việc. Không áp dụng cho vị trí vận hành máy, kho, QC và các vị trí yêu cầu có mặt tại hiện trường.\n\nII. HÌNH THỨC\n1. Làm việc từ xa (WFH): tối đa 2 ngày/tuần, đăng ký trên hệ thống trước 17h00 hôm trước, quản lý trực tiếp duyệt.\n2. Giờ linh hoạt: được đến muộn/về sớm tối đa 60 phút so với giờ chuẩn nếu đảm bảo đủ 8 giờ làm việc và được quản lý đồng ý.\n\nIII. TRÁCH NHIỆM KHI LÀM TỪ XA\n1. Online trong giờ làm việc, phản hồi trong 30 phút; tham gia đầy đủ các cuộc họp.\n2. Đảm bảo an toàn thông tin theo Chính sách ATTT (không làm việc qua wifi công cộng không bảo mật).\n3. Kết quả công việc đánh giá theo đầu ra; quản lý có quyền tạm dừng WFH nếu hiệu suất không đạt.\n\nIV. HIỆU LỰC\nNgày WFH được duyệt tính công như ngày làm việc bình thường, không trừ phép năm.",
            'POL-EXP-001' => "QUY CHẾ CÔNG TÁC PHÍ\n\nI. PHẠM VI\nÁp dụng cho CBNV được cử đi công tác trong nước theo quyết định của quản lý từ cấp Trưởng phòng.\n\nII. ĐỊNH MỨC\n1. Phụ cấp lưu trú: 200.000đ/ngày (nội tỉnh), 300.000đ/ngày (ngoại tỉnh).\n2. Khách sạn: tối đa 900.000đ/đêm tại Hà Nội, TP.HCM, Đà Nẵng; 700.000đ/đêm tỉnh khác (hóa đơn VAT).\n3. Di chuyển: máy bay hạng phổ thông (chuyến công tác >500km, đặt trước ≥5 ngày), tàu/xe khách theo giá vé thực tế; xe cá nhân 5.000đ/km.\n\nIII. QUY TRÌNH\n1. Trước chuyến đi: tạo Yêu cầu công tác trên hệ thống, quản lý phê duyệt.\n2. Sau chuyến đi tối đa 7 ngày làm việc: nộp bảng kê kèm chứng từ gốc (hóa đơn, vé, boarding pass) cho Kế toán.\n3. Kế toán thanh toán trong 7 ngày làm việc kể từ khi hồ sơ hợp lệ; khoản không có chứng từ hợp lệ sẽ bị loại.\n\nIV. TẠM ỨNG\nĐược tạm ứng tối đa 70% dự toán; hoàn ứng cùng hồ sơ thanh toán.",
        ];

        foreach ($policies as $code => $content) {
            $updated += DB::table('policies')->where('policy_code', $code)
                ->whereRaw('length(content) < 400')
                ->update(['content' => $content, 'updated_at' => now()]);
        }

        $this->command?->info("Đã chuẩn hoá nội dung {$updated} bản tin/chính sách demo.");

        // 6) Catalog phụ cấp kiểu nhà máy (đối chiếu phiếu lương thật ADMS/Aureole):
        // cờ is_insurable theo TT10/2020 (kỹ năng/thâm niên/chức vụ đóng BH; chuyên
        // cần/đi lại/ăn ca không), meta.in_ot_base = tính vào đơn giá giờ tăng ca.
        $catalog = [
            ['PC-KN', 'Phụ cấp kỹ năng', true, true, true],
            ['PC-CC', 'Phụ cấp chuyên cần', true, false, false],
            ['PC-NO', 'Phụ cấp nhà ở', true, false, false],
            ['PC-NC', 'Phụ cấp nuôi con nhỏ', true, false, false],
        ];
        foreach ($catalog as [$code, $name, $taxable, $insurable, $otBase]) {
            DB::table('allowances')->updateOrInsert(
                ['tenant_id' => 1, 'allowance_code' => $code],
                ['allowance_name' => $name, 'allowance_type' => 'FIXED', 'calculation_method' => 'MONTHLY',
                    'is_taxable' => DB::raw($taxable ? 'true' : 'false'),
                    'is_insurable' => DB::raw($insurable ? 'true' : 'false'),
                    'status' => 'true', 'meta' => json_encode(['in_ot_base' => $otBase]),
                    'updated_at' => now(), 'created_at' => now()],
            );
        }
        // Đi lại (PC-XM) tính vào đơn giá OT như ADMS.
        DB::table('allowances')->where('tenant_id', 1)->where('allowance_code', 'PC-XM')
            ->update(['meta' => json_encode(['in_ot_base' => true]), 'updated_at' => now()]);
    }

    /**
     * 4) Dữ liệu demo legacy mang mốc năm cũ (đơn nghỉ 3/2024, tin "bóng đá 2024"…)
     * làm demo mất uy tín — dời về năm hiện tại. Đơn PENDING phải ở TƯƠNG LAI
     * (đơn chờ duyệt mà ngày nghỉ đã qua 2 năm là vô lý). Chạy lại an toàn:
     * điều kiện lọc theo "năm cũ" nên lần 2 không còn gì để dời.
     */
    private function shiftStaleDemoDates(): void
    {
        $year = (int) now()->format('Y');
        $today = now()->toDateString();

        // Đơn nghỉ năm cũ → cộng đủ số năm để rơi vào năm hiện tại (giữ ngày/tháng).
        $stale = DB::table('leave_requests')->whereRaw('EXTRACT(YEAR FROM start_date) < ?', [$year])->get();
        foreach ($stale as $lr) {
            $diff = $year - (int) date('Y', strtotime($lr->start_date));
            DB::table('leave_requests')->where('id', $lr->id)->update([
                'start_date' => date('Y-m-d', strtotime("+{$diff} years", strtotime($lr->start_date))),
                'end_date' => date('Y-m-d', strtotime("+{$diff} years", strtotime($lr->end_date))),
                'updated_at' => now(),
            ]);
        }

        // Đơn PENDING mà ngày bắt đầu đã qua → dời sang tuần tới (giữ số ngày nghỉ).
        $pastPending = DB::table('leave_requests')
            ->whereIn('status', ['PENDING', 'CHỜ_DUYỆT'])->where('start_date', '<', $today)->get();
        foreach ($pastPending as $lr) {
            $len = max(0, (strtotime($lr->end_date) - strtotime($lr->start_date)) / 86400);
            $newStart = date('Y-m-d', strtotime('+7 days'));
            DB::table('leave_requests')->where('id', $lr->id)->update([
                'start_date' => $newStart,
                'end_date' => date('Y-m-d', strtotime("+{$len} days", strtotime($newStart))),
                'updated_at' => now(),
            ]);
        }

        // Tin tức / thông báo còn nhắc năm cũ trong chữ → thay bằng năm hiện tại.
        foreach ([2023, 2024, 2025] as $old) {
            DB::table('news')->whereRaw('title LIKE ? OR content LIKE ?', ["%{$old}%", "%{$old}%"])
                ->update([
                    'title' => DB::raw("replace(title, '{$old}', '{$year}')"),
                    'content' => DB::raw("replace(content, '{$old}', '{$year}')"),
                    'updated_at' => now(),
                ]);
            DB::table('notifications')->whereRaw('message LIKE ?', ["%/{$old}%"])
                ->update([
                    'message' => DB::raw("replace(message, '/{$old}', '/{$year}')"),
                    'updated_at' => now(),
                ]);
        }

        $this->command?->info('Đã dời '.$stale->count().' đơn nghỉ năm cũ + '.$pastPending->count().' đơn PENDING quá hạn về mốc hiện tại.');
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
