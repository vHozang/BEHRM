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

    'auth' => [
        'refresh_cookie' => env('HRM_REFRESH_COOKIE', 'hrm_refresh'),
        'refresh_days' => (int) env('HRM_REFRESH_DAYS', 30),
        'refresh_rotation_grace_seconds' => (int) env('HRM_REFRESH_ROTATION_GRACE_SECONDS', 5),
        'refresh_cookie_secure' => env('HRM_REFRESH_COOKIE_SECURE'),
        'trusted_origins' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('HRM_AUTH_TRUSTED_ORIGINS', ''))
        ))),
    ],

    // Tenant-wide display preferences consumed by authenticated web clients.
    'display' => [
        'money_group_separator' => env('HRM_MONEY_GROUP_SEPARATOR', '.'),
    ],

    // Public WebSocket coordinates returned to authenticated web clients.
    // The broadcasting connection itself uses REVERB_INTERNAL_* in Docker.
    'realtime' => [
        'host' => env('REVERB_PUBLIC_HOST', env('REVERB_HOST')),
        'port' => (int) env('REVERB_PUBLIC_PORT', env('REVERB_PORT', 443)),
        'scheme' => env('REVERB_PUBLIC_SCHEME', env('REVERB_SCHEME', 'https')),
    ],

    // Chuỗi cấp duyệt đơn từ (duyệt nhiều cấp). Mặc định 1 cấp (HR) = như cũ.
    // Tenant đặt nhiều cấp qua Settings, vd ['MANAGER','DEPT_HEAD','HR'].
    'approval' => [
        'leave_chain' => ['HR'],
        'overtime_chain' => ['HR'],
        'adjustment_chain' => ['HR'],
    ],

    // Token cho endpoint nội bộ (máy chấm công bridge, job nền). Đọc qua config()
    // để hoạt động cả khi đã config:cache (env() trả null khi cache).
    'internal_service_token' => env('INTERNAL_SERVICE_TOKEN'),

    // Shared internal-token bridges are accepted only when pinned to a tenant.
    'internal_attendance_tenant_id' => env('INTERNAL_ATTENDANCE_TENANT_ID'),

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
        //
        // Luật Thuế TNCN số 109/2025/QH15 (hiệu lực 01/01/2026): biểu lũy tiến
        // rút từ 7 xuống 5 bậc — 10/30/60/100 triệu/tháng, thuế suất 5-35%.
        'pit_brackets' => [
            ['up' => 10_000_000,  'rate' => 0.05],
            ['up' => 30_000_000,  'rate' => 0.10],
            ['up' => 60_000_000,  'rate' => 0.20],
            ['up' => 100_000_000, 'rate' => 0.30],
            ['up' => null,        'rate' => 0.35],
        ],

        // Family-circumstance deductions (giảm trừ gia cảnh), monthly.
        // Nghị quyết 110/2025/UBTVQH15 (áp dụng từ kỳ tính thuế 2026): tăng
        // giảm trừ bản thân 11tr→15,5tr, người phụ thuộc 4,4tr→6,2tr.
        'personal_deduction' => 15_500_000,   // for the taxpayer
        'dependent_deduction' => 6_200_000,    // per registered dependent

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
        // 'base_salary' = mức tham chiếu đóng BHXH (Luật BHXH 2024): từ
        // 01/07/2026 = 2.530.000 (trước đó = lương cơ sở 2.340.000).
        // Trần đóng BHXH/BHYT = 20× = 50,6 triệu/tháng.
        'base_salary' => 2_530_000,        // mức tham chiếu BHXH (01/07/2026)
        // Lương tối thiểu vùng I — Nghị định 293/2025/NĐ-CP (01/01/2026).
        'region_min_wage' => 5_310_000,     // vùng I (BHTN cap = 20× = 106,2tr)

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

        // Miễn thuế TNCN phần PHỤ TRỘI của tiền làm thêm giờ / làm đêm (phần
        // trả cao hơn đơn giá giờ thường — TT 111/2013 Đ.3, nhấn lại trong đợt
        // luật hiệu lực 01/07/2026). true = chỉ phần 100% chịu thuế.
        'ot_premium_tax_exempt' => env('HRM_PAYROLL_OT_EXEMPT', true),

        // Ngân sách quỹ lương tháng TOÀN CÔNG TY (VNĐ) — mốc so sánh với quỹ
        // lương thực tế (tổng lương NV). 0 = chưa đặt, không hiển thị cảnh báo.
        'monthly_budget' => env('HRM_PAYROLL_MONTHLY_BUDGET', 0),

        // Phụ trội làm ĐÊM (Đ.98 BLLĐ: ít nhất +30% đơn giá giờ) — cộng thêm
        // cho mỗi giờ đêm trong đơn OT (meta.night_hours), ngoài hệ số ngày.
        'night_ot_premium' => env('HRM_PAYROLL_NIGHT_PREMIUM', 0.3),

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
        // Sinh con THỨ HAI: 7 tháng = 210 ngày (luật hiệu lực 01/07/2026).
        'maternity_days_second_child' => 210,

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
        // Overview uses a versioned short-lived cache. Production should set
        // CACHE_STORE=redis; tests keep using the configured array store.
        'overview_cache_store' => env('HRM_ATT_OVERVIEW_CACHE_STORE', env('CACHE_STORE', 'database')),
        // Employee-page grid cache. Attendance writes invalidate by version;
        // the TTL covers other inputs such as approved leave and shift changes.
        'timesheet_cache_seconds' => (int) env('HRM_ATT_TIMESHEET_CACHE_SECONDS', 60),
        // Giờ làm chuẩn / ngày (Đ.105: ≤ 8h/ngày, ≤ 48h/tuần).
        'standard_hours_per_day' => 8,
        // Số ngày làm chuẩn / tuần (nhiều DN 6; khuyến khích ≤ 5.5).
        'standard_days_per_week' => 6,
        // Ngày nghỉ hằng tuần: 0=CN .. 6=T7 (Đ.111: ≥ 1 ngày/tuần).
        'weekly_rest_weekday' => 0,
        // Dung sai đi trễ (phút) trước khi tính LATE.
        'late_grace_minutes' => 15,
        // A violation starts at exactly this many minutes.
        'early_leave_grace_minutes' => 15,
        // Legal-safe default: HR must explicitly approve any non-zero deduction.
        'violation_default_percent' => 0,
        // Bridge giữ punch mới trong khoảng này trước khi tự động tải lên HRM.
        // HR có thể dùng "Đồng bộ ngay" để bỏ qua thời gian chờ cho một lượt.
        'device_upload_delay_minutes' => env('HRM_ATT_DEVICE_UPLOAD_DELAY_MINUTES', 15),
        // Chỉ dùng cho thiết bị/import cũ không gửi trạng thái Check-In/Out:
        // punch thứ hai quá gần punch đầu được coi là quét lặp, không phải giờ ra.
        'device_auto_checkout_min_minutes' => env('HRM_ATT_DEVICE_AUTO_CHECKOUT_MIN_MINUTES', 60),
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
