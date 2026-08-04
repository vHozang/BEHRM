<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AutoRecruitEndpointResolver;
use App\Support\AccessControl;
use App\Support\HrmConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Tenant business-rule settings (Cấu hình nghiệp vụ).
 *
 * Exposes a curated catalog of configurable parameters (contract, tax, social
 * insurance, leave, onboarding) — each resolved via HrmConfig (tenant override
 * → statutory default). Admins edit them from the UI; saving writes per-tenant
 * overrides into system_configs. Nothing here is hard-coded in components/services
 * anymore — the VN statutory values in config/hrm.php are only the fallback.
 */
class SettingsController extends Controller
{
    /** Editable catalog: group → items (key, label, type). */
    private const CATALOG = [
        'contract' => [
            'label' => 'Hợp đồng & Thử việc',
            'items' => [
                ['key' => 'contract.probation_max_days', 'label' => 'Số ngày thử việc tối đa (Điều 25 BLLĐ)', 'type' => 'int'],
                ['key' => 'contract.max_fixed_term', 'label' => 'Số lần HĐ xác định thời hạn tối đa (Điều 20)', 'type' => 'int'],
                ['key' => 'contract.expiry_alert_days', 'label' => 'Cảnh báo hết hạn trước (ngày)', 'type' => 'int'],
            ],
        ],
        'tax' => [
            'label' => 'Thuế TNCN & Giảm trừ',
            'items' => [
                ['key' => 'payroll.personal_deduction', 'label' => 'Giảm trừ bản thân (VNĐ/tháng)', 'type' => 'int'],
                ['key' => 'payroll.dependent_deduction', 'label' => 'Giảm trừ người phụ thuộc (VNĐ/tháng)', 'type' => 'int'],
                ['key' => 'payroll.pit_brackets', 'label' => 'Biểu thuế lũy tiến (nâng cao, JSON)', 'type' => 'json'],
            ],
        ],
        'insurance' => [
            'label' => 'BHXH & Lương',
            'items' => [
                ['key' => 'payroll.base_salary', 'label' => 'Lương cơ sở (VNĐ)', 'type' => 'int'],
                ['key' => 'payroll.region_min_wage', 'label' => 'Lương tối thiểu vùng (VNĐ)', 'type' => 'int'],
                ['key' => 'payroll.overtime_multiplier', 'label' => 'Hệ số tăng ca (×)', 'type' => 'float'],
                ['key' => 'payroll.ot_premium_tax_exempt', 'label' => 'Miễn thuế TNCN phần phụ trội tăng ca/đêm', 'type' => 'bool'],
                ['key' => 'payroll.night_ot_premium', 'label' => 'Phụ trội giờ làm đêm (0.3 = +30%)', 'type' => 'float'],
                ['key' => 'payroll.monthly_budget', 'label' => 'Ngân sách quỹ lương tháng toàn công ty (VNĐ, 0 = chưa đặt)', 'type' => 'int'],
                ['key' => 'payroll.prorate_by_attendance', 'label' => 'Trừ lương theo ngày công vắng (prorate)', 'type' => 'bool'],
                ['key' => 'payroll.standard_hours_per_day', 'label' => 'Giờ làm chuẩn / ngày', 'type' => 'int'],
                ['key' => 'payroll.insurance', 'label' => 'Tỷ lệ trích BH (nâng cao, JSON)', 'type' => 'json'],
            ],
        ],
        'leave' => [
            'label' => 'Nghỉ phép',
            'items' => [
                ['key' => 'leave.annual_base_days', 'label' => 'Số ngày phép năm cơ bản (Điều 113)', 'type' => 'int'],
                ['key' => 'leave.seniority_bonus_per_years', 'label' => 'Thâm niên: +1 ngày mỗi (năm) (Điều 114)', 'type' => 'int'],
                ['key' => 'leave.prorate_first_year', 'label' => 'Tính phép theo tỷ lệ cho năm đầu', 'type' => 'bool'],
                ['key' => 'leave.marriage_self_days', 'label' => 'Nghỉ cưới (bản thân) — ngày/lần (Đ.115.1.a)', 'type' => 'int'],
                ['key' => 'leave.marriage_child_days', 'label' => 'Nghỉ con kết hôn — ngày/lần (Đ.115.1.b)', 'type' => 'int'],
                ['key' => 'leave.bereavement_days', 'label' => 'Nghỉ tang chế — ngày/lần (Đ.115.1.c)', 'type' => 'int'],
                ['key' => 'leave.maternity_days', 'label' => 'Nghỉ thai sản — ngày (Đ.139 / BHXH)', 'type' => 'int'],
                ['key' => 'leave.maternity_days_second_child', 'label' => 'Thai sản sinh con thứ 2 — ngày (luật 01/07/2026)', 'type' => 'int'],
                ['key' => 'leave.unpaid_personal_days', 'label' => 'Nghỉ việc riêng không lương — ngày/lần (Đ.115.2)', 'type' => 'int'],
                // Carryover: LUẬT không quy định trần/hạn (Đ.113.4 chỉ cho thỏa thuận
                // gộp ≤3 năm/lần) — đây là CHÍNH SÁCH nội quy từng công ty → config.
                ['key' => 'leave.carryover_max_days', 'label' => 'Phép gộp: số ngày chuyển tối đa (0 = không giới hạn)', 'type' => 'int'],
                ['key' => 'leave.carryover_deadline', 'label' => 'Phép gộp: hạn dùng phép năm cũ (MM-DD, vd 03-31)', 'type' => 'string'],
                // Phiếu lương custom theo công ty (render ở FE, không ảnh hưởng cách tính).
                ['key' => 'payslip.title', 'label' => 'Phiếu lương: tiêu đề (mặc định "PHIẾU LƯƠNG THÁNG")', 'type' => 'string'],
                ['key' => 'payslip.footer', 'label' => 'Phiếu lương: lời cảm ơn cuối phiếu', 'type' => 'string'],
                ['key' => 'payslip.show_allowance_detail', 'label' => 'Phiếu lương: liệt kê từng phụ cấp theo tên', 'type' => 'bool'],
                ['key' => 'payslip.show_ot_detail', 'label' => 'Phiếu lương: tách tăng ca thường/CN/lễ/đêm', 'type' => 'bool'],
                ['key' => 'payslip.show_insurance_base', 'label' => 'Phiếu lương: hiện nền đóng BHXH', 'type' => 'bool'],
                ['key' => 'payslip.show_relief', 'label' => 'Phiếu lương: hiện giảm trừ gia cảnh', 'type' => 'bool'],
                ['key' => 'payslip.show_work_days', 'label' => 'Phiếu lương: hiện số ngày công', 'type' => 'bool'],
                ['key' => 'payslip.show_employer_cost', 'label' => 'Phiếu lương: hiện chi phí DN đóng (17.5/3/1%)', 'type' => 'bool'],
            ],
        ],
        'approval' => [
            'label' => 'Duyệt nhiều cấp (đơn từ)',
            'items' => [
                ['key' => 'approval.leave_chain', 'label' => 'Cấp duyệt đơn nghỉ phép (mỗi dòng 1 cấp, vd MANAGER / DEPT_HEAD / HR)', 'type' => 'list'],
                ['key' => 'approval.overtime_chain', 'label' => 'Cấp duyệt đơn tăng ca', 'type' => 'list'],
                ['key' => 'approval.adjustment_chain', 'label' => 'Cấp duyệt đơn điều chỉnh công', 'type' => 'list'],
            ],
        ],
        'time' => [
            'label' => 'Công & Tăng ca (luật VN)',
            'items' => [
                ['key' => 'attendance.standard_hours_per_day', 'label' => 'Giờ làm chuẩn / ngày (Đ.105)', 'type' => 'int'],
                ['key' => 'attendance.standard_days_per_week', 'label' => 'Số ngày làm chuẩn / tuần', 'type' => 'int'],
                ['key' => 'attendance.weekly_rest_weekday', 'label' => 'Ngày nghỉ hằng tuần (0=CN..6=T7)', 'type' => 'int'],
                ['key' => 'attendance.late_grace_minutes', 'label' => 'Dung sai đi trễ (phút)', 'type' => 'int'],
                ['key' => 'attendance.device_upload_delay_minutes', 'label' => 'Thời gian chờ tự động tải dữ liệu máy chấm công (phút)', 'type' => 'int', 'min' => 1, 'max' => 1440],
                ['key' => 'attendance.half_day_hours', 'label' => 'Ngưỡng nửa công (giờ)', 'type' => 'float'],
                ['key' => 'overtime.daily_max_hours', 'label' => 'OT tối đa / ngày (Đ.107: ≤4h)', 'type' => 'float'],
                ['key' => 'overtime.monthly_max_hours', 'label' => 'OT tối đa / tháng (≤40h)', 'type' => 'float'],
                ['key' => 'overtime.yearly_max_hours', 'label' => 'OT tối đa / năm (≤200h)', 'type' => 'float'],
                ['key' => 'overtime.multiplier_weekday', 'label' => 'Hệ số OT ngày thường (Đ.98: 150%)', 'type' => 'float'],
                ['key' => 'overtime.multiplier_weekend', 'label' => 'Hệ số OT ngày nghỉ tuần (200%)', 'type' => 'float'],
                ['key' => 'overtime.multiplier_holiday', 'label' => 'Hệ số OT ngày lễ/Tết (300%)', 'type' => 'float'],
                ['key' => 'overtime.multiplier_saturday', 'label' => 'Hệ số OT Thứ Bảy (0 = theo luật)', 'type' => 'float'],
                ['key' => 'overtime.multiplier_sunday', 'label' => 'Hệ số OT Chủ nhật (0 = theo luật)', 'type' => 'float'],
                ['key' => 'overtime.night_premium', 'label' => 'Phụ cấp làm đêm (+30%)', 'type' => 'float'],
                ['key' => 'overtime.night_ot_extra', 'label' => 'OT ban đêm cộng thêm (+20%)', 'type' => 'float'],
                ['key' => 'overtime.night_start', 'label' => 'Giờ bắt đầu ca đêm (HH:MM)', 'type' => 'text'],
                ['key' => 'overtime.night_end', 'label' => 'Giờ kết thúc ca đêm (HH:MM)', 'type' => 'text'],
                ['key' => 'attendance.enforce_mode', 'label' => 'Chống gian lận: chế độ (off/flag/block)', 'type' => 'text'],
                ['key' => 'attendance.ip_allowlist', 'label' => 'Dải IP/CIDR mạng văn phòng (CSV)', 'type' => 'text'],
                ['key' => 'attendance.geofence_lat', 'label' => 'Geofence: vĩ độ văn phòng', 'type' => 'text'],
                ['key' => 'attendance.geofence_lng', 'label' => 'Geofence: kinh độ văn phòng', 'type' => 'text'],
                ['key' => 'attendance.geofence_radius_m', 'label' => 'Geofence: bán kính cho phép (m)', 'type' => 'int'],
                ['key' => 'attendance.allow_wfh_bypass', 'label' => 'Ca WFH được bỏ qua IP/vị trí', 'type' => 'bool'],
                ['key' => 'attendance.auto_coverage_on_leave', 'label' => 'Tự tạo yêu cầu phủ ca khi duyệt nghỉ', 'type' => 'bool'],
                ['key' => 'overtime.comp_off_enabled', 'label' => 'Cho phép quy đổi OT → nghỉ bù', 'type' => 'bool'],
                ['key' => 'overtime.comp_off_rate', 'label' => 'Hệ số quy đổi nghỉ bù (1.0 = 8h→1 ngày)', 'type' => 'float'],
            ],
        ],
        'onboarding' => [
            'label' => 'Checklist Hội nhập / Nghỉ việc',
            'items' => [
                ['key' => 'onboarding.tasks_onboarding', 'label' => 'Checklist hội nhập (mỗi dòng 1 việc)', 'type' => 'list'],
                ['key' => 'onboarding.tasks_offboarding', 'label' => 'Checklist nghỉ việc (mỗi dòng 1 việc)', 'type' => 'list'],
            ],
        ],
        'employee' => [
            'label' => 'Hồ sơ nhân viên (trường tùy biến)',
            'items' => [
                ['key' => 'employee.custom_fields', 'label' => 'Trường tùy biến — JSON mảng [{"key","label"}]', 'type' => 'json'],
            ],
        ],
        'system' => [
            'label' => 'Bật / tắt phân hệ',
            'items' => [
                ['key' => 'modules.enabled', 'label' => 'Phân hệ công ty sử dụng', 'type' => 'modules'],
            ],
        ],
    ];

    /** GET /settings/catalog — groups + current effective values for the tenant. */
    public function catalog(): JsonResponse
    {
        // Toggleable modules (settings is always on — excluded from the toggle list).
        $moduleOptions = [];
        foreach (AccessControl::MODULES as $key => $label) {
            if ($key === 'settings') {
                continue;
            }
            $moduleOptions[] = ['key' => $key, 'label' => $label];
        }

        $groups = [];
        foreach (self::CATALOG as $groupKey => $group) {
            $items = array_map(function ($item) use ($moduleOptions) {
                if ($item['type'] === 'modules') {
                    return array_merge($item, [
                        'value' => array_values(array_filter(AccessControl::enabledModules(), fn ($m) => $m !== 'settings')),
                        'options' => $moduleOptions,
                    ]);
                }

                return array_merge($item, ['value' => HrmConfig::get($item['key'])]);
            }, $group['items']);
            $groups[] = ['key' => $groupKey, 'label' => $group['label'], 'items' => $items];
        }

        return response()->json(['status' => 200, 'message' => 'Settings catalog', 'data' => ['groups' => $groups]]);
    }

    /** POST /settings/save — upsert tenant overrides. Body: { items: [{key,value}] }. */
    public function save(Request $request): JsonResponse
    {
        $types = $this->keyTypes();
        $items = $request->input('items', []);
        if (! is_array($items)) {
            return $this->validationError(['items' => ['Định dạng không hợp lệ']]);
        }

        $saved = [];
        foreach ($items as $item) {
            $key = $item['key'] ?? null;
            if (! $key || ! isset($types[$key])) {
                continue; // ignore unknown keys (whitelist)
            }
            $value = $this->cast($item['value'] ?? null, $types[$key]);
            if ($value === null) {
                continue;
            }
            if ($key === 'attendance.device_upload_delay_minutes' && ($value < 1 || $value > 1440)) {
                return $this->validationError([
                    $key => ['Thời gian tải dữ liệu phải từ 1 đến 1440 phút'],
                ]);
            }
            HrmConfig::set($key, $value);
            $saved[] = $key;
        }

        return response()->json(['status' => 200, 'message' => 'Đã lưu cấu hình', 'data' => ['saved' => $saved]]);
    }

    /** GET /settings/integrations/autorecruit/health — admin-only connectivity check. */
    public function autoRecruitHealth(): JsonResponse
    {
        $urls = app(AutoRecruitEndpointResolver::class)->urls();
        $checks = [];
        $available = false;
        $selectedUrl = null;

        foreach ($urls as $url) {
            $startedAt = microtime(true);
            try {
                $response = Http::connectTimeout(min((int) config('services.autorecruit.connect_timeout', 5), 5))
                    ->timeout(8)
                    ->acceptJson()
                    ->get($url.'/health');
                $healthy = $response->successful();
                $checks[] = [
                    'url' => $url,
                    'healthy' => $healthy,
                    'status' => $response->status(),
                    'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                ];
                $available = $available || $healthy;
                if ($healthy && $selectedUrl === null) {
                    $selectedUrl = $url;
                }
            } catch (\Throwable $exception) {
                $checks[] = [
                    'url' => $url,
                    'healthy' => false,
                    'status' => null,
                    'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    'error' => 'Không thể kết nối',
                ];
            }
        }

        return response()->json([
            'status' => $available ? 200 : 503,
            'message' => $available ? 'Resume backend đang hoạt động' : 'Resume backend không khả dụng',
            'data' => [
                'available' => $available,
                'selected_url' => $selectedUrl,
                'checks' => $checks,
            ],
        ], $available ? 200 : 503);
    }

    /** @return array<string,string> key => type */
    private function keyTypes(): array
    {
        $map = [];
        foreach (self::CATALOG as $group) {
            foreach ($group['items'] as $item) {
                $map[$item['key']] = $item['type'];
            }
        }

        return $map;
    }

    private function cast(mixed $value, string $type): mixed
    {
        return match ($type) {
            'int' => is_numeric($value) ? (int) $value : null,
            'float' => is_numeric($value) ? (float) $value : null,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'list' => is_array($value) ? array_values(array_filter(array_map(fn ($x) => trim((string) $x), $value), fn ($x) => $x !== '')) : null,
            'modules' => is_array($value) ? array_values(array_filter($value, fn ($m) => isset(AccessControl::MODULES[$m]) && $m !== 'settings')) : null,
            'json' => is_array($value) ? $value : (is_string($value) ? json_decode($value, true) : null),
            default => is_scalar($value) ? (string) $value : null,
        };
    }

    private function validationError(array $errors): JsonResponse
    {
        return response()->json(['status' => 422, 'message' => 'Dữ liệu không hợp lệ', 'data' => ['errors' => $errors]], 422);
    }
}
