<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default tenant / legal entity
    |--------------------------------------------------------------------------
    |
    | Fallback identifiers used when no tenant has been resolved from the
    | authenticated employee (e.g. unauthenticated public routes such as the
    | recruitment application or forgot-password flow). These point at the
    | seeded "Default Company" (tenant id=1, legal_entity id=1).
    |
    */

    'default_tenant_id' => env('HRM_DEFAULT_TENANT_ID', 1),

    'default_legal_entity_id' => env('HRM_DEFAULT_LEGAL_ENTITY_ID', 1),

    // Chuỗi cấp duyệt đơn từ (duyệt nhiều cấp). Mặc định 1 cấp (HR) = như cũ.
    // Tenant đặt nhiều cấp qua Settings, vd ['MANAGER','DEPT_HEAD','HR'].
    'approval' => [
        'leave_chain' => ['HR'],
        'overtime_chain' => ['HR'],
        'adjustment_chain' => ['HR'],
    ],

    // Token cho endpoint nội bộ (máy chấm công bridge, job nền). Đọc qua config()
    // để hoạt động cả khi đã config:cache (env() trả null khi cache).
    'internal_service_token' => env('INTERNAL_SERVICE_TOKEN', 'replace_with_internal_service_token'),

    /*
    |--------------------------------------------------------------------------
    | Payroll engine (Vietnam ruleset, tunable / international-ready)
    |--------------------------------------------------------------------------
    |
    | All statutory numbers live here as CONFIG so a different country / year
    | only needs a config swap, not a code change. Amounts are in the payroll
    | currency's major unit (VND). The 2026 Vietnam values are the defaults.
    |
    */

    'payroll' => [
        // Currency the engine reports in (informational; no FX conversion done).
        'currency' => env('HRM_PAYROLL_CURRENCY', 'VND'),

        // Monthly progressive personal income tax (PIT) brackets.
        // Each bracket: up = upper bound of the bracket (null = open-ended),
        // rate = marginal rate applied to the slice within the bracket.
        // Slices are taxed marginally (Vietnam Luật Thuế TNCN).
        'pit_brackets' => [
            ['up' => 5_000_000,  'rate' => 0.05],
            ['up' => 10_000_000, 'rate' => 0.10],
            ['up' => 18_000_000, 'rate' => 0.15],
            ['up' => 32_000_000, 'rate' => 0.20],
            ['up' => 52_000_000, 'rate' => 0.25],
            ['up' => 80_000_000, 'rate' => 0.30],
            ['up' => null,       'rate' => 0.35],
        ],

        // Family-circumstance deductions (giảm trừ gia cảnh), monthly.
        'personal_deduction' => 11_000_000,   // for the taxpayer
        'dependent_deduction' => 4_400_000,    // per registered dependent

        // Compulsory social-insurance rates (tỷ lệ trích bảo hiểm bắt buộc).
        'insurance' => [
            'employee' => [
                'bhxh' => 0.08,   // social insurance
                'bhyt' => 0.015,  // health insurance
                'bhtn' => 0.01,   // unemployment insurance
            ],
            'employer' => [
                'bhxh' => 0.175,
                'bhyt' => 0.03,
                'bhtn' => 0.01,
            ],
        ],

        // Statutory wage references used for the insurance contribution caps.
        'base_salary' => 2_340_000,        // lương cơ sở
        'region_min_wage' => 4_960_000,     // lương tối thiểu vùng (default vùng I)

        // Contribution-base caps, expressed as a multiplier of the references.
        // BHXH + BHYT capped at 20x base_salary; BHTN capped at 20x region min wage.
        'caps' => [
            'bhxh_bhyt_multiplier' => 20,   // -> 46,800,000
            'bhtn_multiplier' => 20,        // -> 99,200,000
        ],

        // When true (and no per-component taxability catalog is populated),
        // employee allowances are treated as taxable income. Set false to make
        // allowances a non-taxable passthrough by default.
        'allowances_taxable_by_default' => env('HRM_PAYROLL_ALLOWANCES_TAXABLE', true),

        // Standard working hours per day (used to derive an hourly rate from the
        // monthly base for overtime pay). Vietnam standard is 8h/day.
        'standard_hours_per_day' => env('HRM_PAYROLL_STD_HOURS_PER_DAY', 8),

        // Overtime pay multiplier applied to the hourly rate (Bộ luật Lao động
        // Art.98: weekday OT ≥150%). Tunable per tenant/policy.
        'overtime_multiplier' => env('HRM_PAYROLL_OT_MULTIPLIER', 1.5),

        // When true, base pay is prorated by actual_working_days / standard_days
        // (or by unpaid_leave_days) from the attendance summary.
        'prorate_by_attendance' => env('HRM_PAYROLL_PRORATE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Leave policy (Vietnam labour-law ruleset)
    |--------------------------------------------------------------------------
    */

    'leave' => [
        // When true, an employee hired mid-year earns annual leave proportional
        // to the months worked in that year (Bộ luật Lao động Art.113: leave
        // accrues by months worked when service in the year is < 12 months).
        // The seniority bonus (Art.114) is NOT prorated — it only applies once
        // the employee has completed the relevant full years of service.
        'prorate_first_year' => env('HRM_LEAVE_PRORATE_FIRST_YEAR', true),

        // Base annual leave days (Art.113: 12 working days for normal conditions).
        'annual_base_days' => 12,

        // Seniority bonus: +1 day per N full years of service with one employer
        // (Art.114). N = 5 by default.
        'seniority_bonus_per_years' => 5,

        // Nghỉ việc riêng HƯỞNG NGUYÊN LƯƠNG (Đ.115.1) — số ngày/lần (per-event,
        // không trừ vào phép năm). Báo trước cho người sử dụng lao động.
        'marriage_self_days' => 3,    // a) Kết hôn
        'marriage_child_days' => 1,   // b) Con đẻ/con nuôi kết hôn
        'bereavement_days' => 3,      // c) Tang: cha/mẹ (đẻ + vợ/chồng), vợ/chồng, con

        // Nghỉ thai sản (Đ.139 + Luật BHXH) — 6 tháng = 180 ngày (per-event, BHXH chi trả).
        'maternity_days' => 180,

        // Nghỉ việc riêng KHÔNG hưởng lương tối thiểu theo luật (Đ.115.2):
        // ông bà/anh chị em ruột mất; cha mẹ/anh chị em ruột kết hôn → 01 ngày.
        'unpaid_personal_days' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Attendance policy (Vietnam labour-law ruleset)
    |--------------------------------------------------------------------------
    */

    'attendance' => [
        // Giờ làm chuẩn / ngày (Đ.105: ≤ 8h/ngày, ≤ 48h/tuần).
        'standard_hours_per_day' => 8,
        // Số ngày làm chuẩn / tuần (nhiều DN 6; khuyến khích ≤ 5.5).
        'standard_days_per_week' => 6,
        // Ngày nghỉ hằng tuần: 0=CN .. 6=T7 (Đ.111: ≥ 1 ngày/tuần).
        'weekly_rest_weekday' => 0,
        // Dung sai đi trễ (phút) trước khi tính LATE.
        'late_grace_minutes' => 5,
        // Ngưỡng giờ để tính nửa công.
        'half_day_hours' => 4,

        // ── Chống gian lận chấm công (xác minh IP / vị trí / thiết bị) ──
        // Chế độ áp dụng: off (tắt) | flag (đánh dấu "cần xem xét") | block (chặn).
        'enforce_mode' => env('HRM_ATT_ENFORCE', 'flag'),
        // Dải IP/CIDR mạng văn phòng (CSV). Rỗng = không kiểm tra IP.
        'ip_allowlist' => env('HRM_ATT_IP_ALLOWLIST', ''),
        // Geofence: toạ độ văn phòng + bán kính (mét). Rỗng = không kiểm tra vị trí.
        'geofence_lat' => env('HRM_ATT_GEO_LAT', ''),
        'geofence_lng' => env('HRM_ATT_GEO_LNG', ''),
        'geofence_radius_m' => env('HRM_ATT_GEO_RADIUS', 200),
        // Ca cho phép WFH (shift.allow_wfh) thì bỏ qua kiểm tra IP/vị trí.
        'allow_wfh_bypass' => env('HRM_ATT_WFH_BYPASS', true),
        // Tự sinh "yêu cầu phủ ca" khi duyệt đơn nghỉ (cho NV có ca trong ngày
        // nghỉ) → QL điều người tăng ca phủ. Tắt nếu DN văn phòng không cần.
        'auto_coverage_on_leave' => env('HRM_AUTO_COVERAGE_ON_LEAVE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Overtime policy (Vietnam labour-law ruleset — Đ.98, Đ.106-107)
    |--------------------------------------------------------------------------
    */

    'overtime' => [
        // Giới hạn (Đ.107): ≤ 50% giờ chuẩn/ngày → 4h; ≤ 40h/tháng; ≤ 200h/năm
        // (300h/năm cho một số ngành nghề đặc thù).
        'daily_max_hours' => 4,
        'monthly_max_hours' => 40,
        'yearly_max_hours' => 200,
        'yearly_max_hours_special' => 300,
        // Hệ số tiền lương làm thêm giờ (Đ.98.1) so với giờ làm bình thường.
        'multiplier_weekday' => 1.5,   // 150% ngày thường
        'multiplier_weekend' => 2.0,   // 200% ngày nghỉ hằng tuần
        'multiplier_holiday' => 3.0,   // 300% ngày lễ/tết, nghỉ có lương
        // Phụ cấp làm việc vào ban đêm (Đ.98.2): +30%; làm thêm vào ban đêm
        // còn được cộng thêm 20% (Đ.98.3).
        'night_premium' => 0.30,
        'night_ot_extra' => 0.20,
        // Khung giờ ban đêm (Đ.106): 22:00 → 06:00.
        'night_start' => '22:00',
        'night_end' => '06:00',

        // Quy đổi tăng ca → NGHỈ BÙ (comp-off): số ngày bù = giờ OT × rate / giờ
        // chuẩn/ngày. rate=1.0 nghĩa 8h OT = 1 ngày bù (có thể đặt 1.5/2.0 nếu DN
        // muốn bù theo hệ số). OT đã quy đổi sẽ KHÔNG trả tiền (tránh trùng).
        'comp_off_enabled' => env('HRM_OT_COMP_OFF', true),
        'comp_off_rate' => env('HRM_OT_COMP_OFF_RATE', 1.0),

        // Hệ số OT riêng cho Thứ Bảy / Chủ nhật (0 = dùng mức theo luật: nghỉ
        // hằng tuần 200%, ngày thường 150%). Đặt vd T7=2.0, CN=3.0 nếu DN trả cao.
        'multiplier_saturday' => env('HRM_OT_MULT_SAT', 0),
        'multiplier_sunday' => env('HRM_OT_MULT_SUN', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Contract policy (Vietnam labour-law ruleset)
    |--------------------------------------------------------------------------
    |
    | Defaults for contract lifecycle rules. Tenant overrides live in
    | system_configs and are resolved via App\Support\HrmConfig.
    |
    */

    'contract' => [
        // Probation max length (Điều 25 BLLĐ): caution threshold in days. 60 is
        // the common cap (trình độ CĐ trở lên); 180 only for enterprise managers.
        'probation_max_days' => 60,

        // Max consecutive fixed-term contracts before an indefinite one is
        // required (Điều 20 BLLĐ: 2). Warn from this count.
        'max_fixed_term' => 2,

        // How many days before end_date to start flagging a contract as expiring.
        'expiry_alert_days' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Onboarding / offboarding default checklists
    |--------------------------------------------------------------------------
    |
    | Default task lists per type. Tenant overrides (system_configs key
    | onboarding.tasks_onboarding / onboarding.tasks_offboarding) replace these.
    |
    */

    'onboarding' => [
        'tasks_onboarding' => [
            'Ký hợp đồng lao động',
            'Nộp hồ sơ cá nhân (CCCD, bằng cấp, sơ yếu lý lịch)',
            'Đăng ký mã số thuế cá nhân / người phụ thuộc',
            'Đăng ký tham gia BHXH, BHYT, BHTN',
            'Mở tài khoản ngân hàng nhận lương',
            'Cấp tài khoản email và tài khoản hệ thống',
            'Bàn giao thiết bị, công cụ làm việc',
            'Đào tạo hội nhập, phổ biến nội quy công ty',
        ],
        'tasks_offboarding' => [
            'Tiếp nhận đơn/quyết định thôi việc',
            'Bàn giao công việc cho người kế nhiệm',
            'Thu hồi thiết bị, tài sản công ty',
            'Thu hồi tài khoản email/hệ thống',
            'Chốt ngày công và phép còn lại',
            'Tính lương kỳ cuối + trợ cấp thôi việc (nếu có)',
            'Chốt sổ BHXH, trả sổ cho nhân viên',
            'Xác nhận hoàn tất thủ tục nghỉ việc',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI HR assistant (Claude / Anthropic Messages API)
    |--------------------------------------------------------------------------
    |
    | Powers the in-app "Hỏi HR AI" assistant. The API key is read from the
    | environment ONLY — never hardcode it. When the key is empty (or the
    | feature is disabled) the assistant degrades gracefully and the controller
    | returns a friendly "not configured" message instead of calling the API.
    |
    */

    'ai' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('HRM_AI_MODEL', 'claude-opus-4-8'),
        'max_tokens' => (int) env('HRM_AI_MAX_TOKENS', 1024),
        'effort' => env('HRM_AI_EFFORT', 'low'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        'enabled' => filter_var(env('HRM_AI_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    ],
];
