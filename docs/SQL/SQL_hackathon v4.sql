/* ===================================================== */
/* DATABASE: HRM_SYSTEM                                   */
/* Phiên bản: 3.0 (Nâng cấp Full - Tích hợp tất cả modules) */
/* Mô tả: Hệ thống quản lý nhân sự HRM đạt chuẩn 3NF      */
/* ===================================================== */
CREATE DATABASE IF NOT EXISTS HRM_SYSTEM CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE HRM_SYSTEM;
/* ===================================================== */
/* 1. MODULE QUẢN LÝ NHÂN SỰ & HỒ SƠ (NÂNG CẤP)          */
/* ===================================================== */
/* Bảng quốc tịch */
CREATE TABLE nationalities (
    nationality_id INT AUTO_INCREMENT PRIMARY KEY,
    nationality_code VARCHAR(10) UNIQUE NOT NULL,
    nationality_name VARCHAR(100) NOT NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng ngân hàng */
CREATE TABLE banks (
    bank_id INT AUTO_INCREMENT PRIMARY KEY,
    bank_code VARCHAR(20) UNIQUE NOT NULL,
    bank_name VARCHAR(100) NOT NULL,
    swift_code VARCHAR(20),
    status BOOLEAN DEFAULT TRUE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng nhân viên (nâng cấp với phép thâm niên) */
CREATE TABLE employees (
    employee_id INT AUTO_INCREMENT PRIMARY KEY,
    employee_code VARCHAR(20) UNIQUE NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    date_of_birth DATE,
    gender ENUM('NAM', 'NỮ', 'KHÁC'),
    place_of_birth VARCHAR(200),
    ethnicity VARCHAR(50),
    religion VARCHAR(50),
    marital_status ENUM('ĐỘC_THÂN', 'ĐÃ_KẾT_HÔN', 'LY_HÔN', 'GÓA'),
    phone_number VARCHAR(20),
    personal_email VARCHAR(100),
    company_email VARCHAR(100) UNIQUE,
    permanent_address TEXT,
    current_address TEXT,
    nationality_id INT,
    avatar_url VARCHAR(500),
    bank_account VARCHAR(30),
    bank_id INT,
    bank_branch VARCHAR(200),
    emergency_contact_name VARCHAR(100),
    emergency_contact_phone VARCHAR(20),
    emergency_contact_relation VARCHAR(50),
    status ENUM(
        'ĐANG_LÀM_VIỆC',
        'ĐÃ_NGHỈ_VIỆC',
        'THỬ_VIỆC',
        'NGHỈ_THAI_SẢN',
        'TẠM_HOÃN_CÔNG_TÁC'
    ) DEFAULT 'ĐANG_LÀM_VIỆC',
    hire_date DATE,
    seniority_start_date DATE,
    base_leave_days DECIMAL(5, 2) DEFAULT 12.00,
    last_seniority_calc DATE,
    resignation_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    updated_by INT,
    INDEX idx_employee_code (employee_code),
    INDEX idx_employee_status (status),
    INDEX idx_hire_date (hire_date)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng phòng ban */
CREATE TABLE departments (
    department_id INT AUTO_INCREMENT PRIMARY KEY,
    department_code VARCHAR(20) UNIQUE NOT NULL,
    department_name VARCHAR(100) NOT NULL,
    parent_department_id INT,
    manager_id INT,
    description TEXT,
    status BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng chức vụ */
CREATE TABLE positions (
    position_id INT AUTO_INCREMENT PRIMARY KEY,
    position_code VARCHAR(20) UNIQUE NOT NULL,
    position_name VARCHAR(100) NOT NULL,
    job_description TEXT,
    requirements TEXT,
    salary_range_min DECIMAL(15, 2),
    salary_range_max DECIMAL(15, 2),
    status BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng loại hợp đồng */
CREATE TABLE contract_types (
    contract_type_id INT AUTO_INCREMENT PRIMARY KEY,
    contract_type_code VARCHAR(20) UNIQUE NOT NULL,
    contract_type_name VARCHAR(100) NOT NULL,
    description TEXT,
    is_probation BOOLEAN DEFAULT FALSE,
    max_duration_months INT,
    status BOOLEAN DEFAULT TRUE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng mẫu hợp đồng */
CREATE TABLE contract_templates (
    template_id INT AUTO_INCREMENT PRIMARY KEY,
    template_code VARCHAR(20) UNIQUE NOT NULL,
    template_name VARCHAR(200) NOT NULL,
    contract_type_id INT NOT NULL,
    content TEXT NOT NULL,
    version VARCHAR(20),
    is_active BOOLEAN DEFAULT TRUE,
    file_url VARCHAR(500),
    effective_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng hợp đồng lao động */
CREATE TABLE contracts (
    contract_id INT AUTO_INCREMENT PRIMARY KEY,
    contract_code VARCHAR(20) UNIQUE NOT NULL,
    employee_id INT NOT NULL,
    contract_type_id INT NOT NULL,
    contract_number VARCHAR(50) UNIQUE,
    sign_date DATE NOT NULL,
    effective_date DATE NOT NULL,
    expiry_date DATE,
    position_id INT,
    department_id INT,
    basic_salary DECIMAL(15, 2) NOT NULL,
    gross_salary DECIMAL(15, 2) NOT NULL,
    net_salary DECIMAL(15, 2),
    work_location VARCHAR(200),
    job_title VARCHAR(100),
    content TEXT,
    file_url VARCHAR(500),
    signed_file_url VARCHAR(500),
    contract_template_id INT,
    status ENUM(
        'CÓ_HIỆU_LỰC',
        'HẾT_HẠN',
        'ĐÃ_CHẤM_DỨT',
        'CHỜ_HIỆU_LỰC'
    ) DEFAULT 'CÓ_HIỆU_LỰC',
    is_renewed BOOLEAN DEFAULT FALSE,
    renewed_from_contract_id INT,
    termination_reason TEXT,
    termination_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    updated_by INT,
    INDEX idx_contract_employee (employee_id),
    INDEX idx_contract_status (status),
    INDEX idx_contract_expiry (expiry_date)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng lịch sử ký kết hợp đồng */
CREATE TABLE contract_histories (
    history_id INT AUTO_INCREMENT PRIMARY KEY,
    contract_id INT NOT NULL,
    action ENUM('TẠO', 'GIA_HẠN', 'CHẤM_DỨT', 'CẬP_NHẬT', 'KÝ') NOT NULL,
    action_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    action_by INT NOT NULL,
    previous_value TEXT,
    new_value TEXT,
    notes TEXT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng loại bằng cấp */
CREATE TABLE qualification_types (
    qualification_type_id INT AUTO_INCREMENT PRIMARY KEY,
    qualification_type_code VARCHAR(20) UNIQUE NOT NULL,
    qualification_type_name VARCHAR(100) NOT NULL,
    description TEXT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng bằng cấp */
CREATE TABLE qualifications (
    qualification_id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    qualification_type_id INT NOT NULL,
    qualification_name VARCHAR(200) NOT NULL,
    major VARCHAR(200),
    school_name VARCHAR(200),
    graduation_year INT,
    graduation_grade VARCHAR(20),
    issued_date DATE,
    issued_by VARCHAR(200),
    qualification_number VARCHAR(50),
    file_url VARCHAR(500),
    is_highest BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_employee_qualification (employee_id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng loại chứng chỉ */
CREATE TABLE certificate_types (
    certificate_type_id INT AUTO_INCREMENT PRIMARY KEY,
    certificate_type_code VARCHAR(20) UNIQUE NOT NULL,
    certificate_type_name VARCHAR(100) NOT NULL,
    description TEXT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng chứng chỉ */
CREATE TABLE certificates (
    certificate_id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    certificate_type_id INT,
    certificate_name VARCHAR(200) NOT NULL,
    issued_by VARCHAR(200),
    issued_date DATE,
    expiry_date DATE,
    certificate_number VARCHAR(50),
    score DECIMAL(5, 2),
    file_url VARCHAR(500),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_certificate_expiry (expiry_date)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng loại giấy tờ */
CREATE TABLE document_types (
    document_type_id INT AUTO_INCREMENT PRIMARY KEY,
    document_type_code VARCHAR(20) UNIQUE NOT NULL,
    document_type_name VARCHAR(100) NOT NULL,
    description TEXT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng CMND/CCCD */
CREATE TABLE identity_documents (
    identity_id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    document_type_id INT NOT NULL,
    document_number VARCHAR(20) NOT NULL,
    full_name VARCHAR(100),
    date_of_birth DATE,
    issue_date DATE,
    issue_place VARCHAR(200),
    expiry_date DATE,
    front_image_url VARCHAR(500),
    back_image_url VARCHAR(500),
    has_chip BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_identity_number (document_number)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng thông tin BHXH */
CREATE TABLE social_insurance_info (
    insurance_info_id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    social_insurance_number VARCHAR(20) UNIQUE NOT NULL,
    health_insurance_number VARCHAR(20) UNIQUE NOT NULL,
    tax_code VARCHAR(20) UNIQUE,
    issue_date DATE,
    issue_place VARCHAR(200),
    status ENUM('ACTIVE', 'SUSPENDED', 'TERMINATED') DEFAULT 'ACTIVE',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_employee_insurance (employee_id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng người phụ thuộc (giảm trừ gia cảnh) */
CREATE TABLE dependents (
    dependent_id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    relationship VARCHAR(50) NOT NULL,
    date_of_birth DATE,
    id_card_number VARCHAR(20),
    tax_code VARCHAR(20),
    deduction_percent DECIMAL(5, 2) DEFAULT 100.00,
    start_date DATE,
    end_date DATE,
    status BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng lịch sử công tác */
CREATE TABLE employment_histories (
    history_id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    department_id INT NOT NULL,
    position_id INT NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE,
    is_current BOOLEAN DEFAULT FALSE,
    decision_number VARCHAR(50),
    decision_date DATE,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* ===================================================== */
/* 2. MODULE QUẢN LÝ TÀI SẢN                             */
/* ===================================================== */
/* Bảng danh mục tài sản */
CREATE TABLE asset_categories (
    category_id INT AUTO_INCREMENT PRIMARY KEY,
    category_code VARCHAR(20) UNIQUE NOT NULL,
    category_name VARCHAR(100) NOT NULL,
    description TEXT,
    depreciation_method ENUM(
        'ĐƯỜNG_THẲNG',
        'SỐ_DƯ_GIẢM_DẦN',
        'THEO_SẢN_LƯỢNG'
    ) DEFAULT 'ĐƯỜNG_THẲNG',
    depreciation_years INT,
    status BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng nhà cung cấp */
CREATE TABLE suppliers (
    supplier_id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_code VARCHAR(20) UNIQUE NOT NULL,
    supplier_name VARCHAR(200) NOT NULL,
    tax_code VARCHAR(20),
    address TEXT,
    phone VARCHAR(20),
    email VARCHAR(100),
    contact_person VARCHAR(100),
    status BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng vị trí tài sản */
CREATE TABLE asset_locations (
    location_id INT AUTO_INCREMENT PRIMARY KEY,
    location_code VARCHAR(20) UNIQUE NOT NULL,
    location_name VARCHAR(200) NOT NULL,
    address TEXT,
    department_id INT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng tài sản */
CREATE TABLE assets (
    asset_id INT AUTO_INCREMENT PRIMARY KEY,
    asset_code VARCHAR(20) UNIQUE NOT NULL,
    asset_name VARCHAR(200) NOT NULL,
    category_id INT,
    supplier_id INT,
    serial_number VARCHAR(50) UNIQUE,
    inventory_number VARCHAR(50) UNIQUE,
    brand VARCHAR(100),
    model VARCHAR(100),
    color VARCHAR(30),
    purchase_date DATE,
    purchase_price DECIMAL(15, 2),
    original_price DECIMAL(15, 2),
    current_value DECIMAL(15, 2),
    warranty_expiry DATE,
    location_id INT,
    status ENUM(
        'CÓ_SẴN',
        'ĐÃ_CẤP_PHÁT',
        'ĐANG_BẢO_TRÌ',
        'HỎNG',
        'THANH_LÝ'
    ) DEFAULT 'CÓ_SẴN',
    condition_note TEXT,
    specifications TEXT,
    image_url VARCHAR(500),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_asset_status (status)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng cấp phát tài sản */
CREATE TABLE asset_assignments (
    assignment_id INT AUTO_INCREMENT PRIMARY KEY,
    asset_id INT NOT NULL,
    employee_id INT NOT NULL,
    assigned_by INT NOT NULL,
    assigned_date DATETIME NOT NULL,
    expected_return_date DATE,
    actual_return_date DATETIME,
    assignment_notes TEXT,
    condition_before TEXT,
    condition_after TEXT,
    status ENUM('ĐANG_SỬ_DỤNG', 'ĐÃ_TRẢ', 'MẤT', 'HỎNG') DEFAULT 'ĐANG_SỬ_DỤNG',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_asset_assignment (asset_id, employee_id, status)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng báo cáo mất/hỏng tài sản */
CREATE TABLE asset_incidents (
    incident_id INT AUTO_INCREMENT PRIMARY KEY,
    assignment_id INT,
    asset_id INT NOT NULL,
    reported_by INT NOT NULL,
    incident_date DATETIME NOT NULL,
    incident_type ENUM('MẤT', 'HỎNG', 'TRỤC_TRẶC') NOT NULL,
    description TEXT NOT NULL,
    damage_level ENUM('NHẸ', 'TRUNG_BÌNH', 'NẶNG', 'MẤT_HOÀN_TOÀN'),
    estimated_cost DECIMAL(15, 2),
    resolution_notes TEXT,
    resolved_by INT,
    resolved_date DATETIME,
    status ENUM('ĐÃ_BÁO_CÁO', 'ĐANG_XỬ_LÝ', 'ĐÃ_XỬ_LÝ', 'ĐÓNG') DEFAULT 'ĐÃ_BÁO_CÁO',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng bảo dưỡng tài sản */
CREATE TABLE asset_maintenance (
    maintenance_id INT AUTO_INCREMENT PRIMARY KEY,
    asset_id INT NOT NULL,
    maintenance_date DATE NOT NULL,
    maintenance_type ENUM('ĐỊNH_KỲ', 'SỬA_CHỮA', 'BẢO_HÀNH') NOT NULL,
    description TEXT,
    cost DECIMAL(15, 2),
    performed_by VARCHAR(200),
    next_maintenance_date DATE,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* ===================================================== */
/* 3. MODULE QUẢN LÝ ĐƠN TỪ & PHÊ DUYỆT (LINH HOẠT)     */
/* ===================================================== */
/* Bảng vai trò phê duyệt */
CREATE TABLE approval_roles (
    role_id INT AUTO_INCREMENT PRIMARY KEY,
    role_code VARCHAR(50) UNIQUE NOT NULL,
    role_name VARCHAR(100) NOT NULL,
    description TEXT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng loại đơn từ */
CREATE TABLE request_types (
    request_type_id INT AUTO_INCREMENT PRIMARY KEY,
    request_type_code VARCHAR(50) UNIQUE NOT NULL,
    request_type_name VARCHAR(200) NOT NULL,
    category ENUM(
        'NGHỈ_PHÉP',
        'GỘP_PHÉP',
        'TĂNG_CA',
        'ĐIỀU_CHỈNH_CÔNG',
        'CÔNG_TÁC',
        'TẠM_ỨNG_LƯƠNG',
        'THANH_TOÁN',
        'KỶ_LUẬT',
        'ĐI_MUỘN_VỀ_SỚM',
        'SUẤT_ĂN',
        'KHÁC'
    ) NOT NULL,
    requires_approval BOOLEAN DEFAULT TRUE,
    approval_flow_id INT,
    form_template TEXT,
    description TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng quy trình phê duyệt */
CREATE TABLE approval_flows (
    approval_flow_id INT AUTO_INCREMENT PRIMARY KEY,
    flow_name VARCHAR(200) NOT NULL,
    request_type_id INT NOT NULL,
    description TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng các bước trong quy trình phê duyệt */
CREATE TABLE approval_steps (
    step_id INT AUTO_INCREMENT PRIMARY KEY,
    approval_flow_id INT NOT NULL,
    step_order INT NOT NULL,
    step_name VARCHAR(200) NOT NULL,
    approver_role_id INT,
    approver_user_id INT,
    can_reject BOOLEAN DEFAULT TRUE,
    can_add_comment BOOLEAN DEFAULT TRUE,
    days_to_approve INT DEFAULT 3,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng đơn từ tổng hợp */
CREATE TABLE requests (
    request_id INT AUTO_INCREMENT PRIMARY KEY,
    request_code VARCHAR(50) UNIQUE NOT NULL,
    request_type_id INT NOT NULL,
    requester_id INT NOT NULL,
    request_date DATE NOT NULL,
    from_date DATETIME,
    to_date DATETIME,
    duration DECIMAL(15, 2),
    reason TEXT,
    status ENUM(
        'NHÁP',
        'CHỜ_DUYỆT',
        'ĐANG_XỬ_LÝ',
        'ĐÃ_DUYỆT',
        'TỪ_CHỐI',
        'ĐÃ_HỦY',
        'HOÀN_THÀNH'
    ) DEFAULT 'NHÁP',
    current_step_id INT,
    is_urgent BOOLEAN DEFAULT FALSE,
    attachments TEXT,
    notes TEXT,
    completed_date DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    updated_by INT,
    INDEX idx_request_status (status),
    INDEX idx_request_date (request_date)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng lịch sử phê duyệt */
CREATE TABLE approval_histories (
    approval_id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    step_id INT,
    approver_id INT NOT NULL,
    action ENUM(
        'GỬI',
        'DUYỆT',
        'TỪ_CHỐI',
        'TRẢ_VỀ',
        'CHUYỂN_TIẾP'
    ) NOT NULL,
    comment TEXT,
    action_date DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng file đính kèm của đơn từ */
CREATE TABLE request_attachments (
    attachment_id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_size INT,
    file_type VARCHAR(100),
    uploaded_by INT NOT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* ===================================================== */
/* 4. MODULE QUẢN LÝ LOẠI NGHỈ PHÉP & THÂM NIÊN          */
/* ===================================================== */
/* Bảng loại nghỉ phép */
CREATE TABLE leave_types (
    leave_type_id INT AUTO_INCREMENT PRIMARY KEY,
    leave_type_code VARCHAR(20) UNIQUE NOT NULL,
    leave_type_name VARCHAR(100) NOT NULL,
    category ENUM(
        'ANNUAL',
        'SICK',
        'MATERNITY',
        'PATERNITY',
        'UNPAID',
        'COMPENSATORY',
        'MARRIAGE',
        'FUNERAL',
        'OTHER'
    ) NOT NULL,
    is_paid BOOLEAN DEFAULT TRUE,
    is_social_insurance BOOLEAN DEFAULT FALSE,
    payment_source ENUM('COMPANY', 'SOCIAL_INSURANCE', 'BOTH') DEFAULT 'COMPANY',
    max_days_per_year INT DEFAULT 12,
    min_days_per_request DECIMAL(5, 2) DEFAULT 0.5,
    max_days_per_request INT DEFAULT 30,
    requires_document BOOLEAN DEFAULT FALSE,
    document_required VARCHAR(200),
    can_carry_forward BOOLEAN DEFAULT FALSE,
    carry_forward_limit INT DEFAULT 0,
    carry_forward_expiry_months INT DEFAULT 3,
    seniority_applicable BOOLEAN DEFAULT FALSE,
    description TEXT,
    status BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_leave_type_code (leave_type_code)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng ngày lễ */
CREATE TABLE holidays (
    holiday_id INT AUTO_INCREMENT PRIMARY KEY,
    holiday_name VARCHAR(200) NOT NULL,
    holiday_date DATE NOT NULL,
    holiday_type ENUM(
        'NEW_YEAR',
        'LUNAR_NEW_YEAR',
        'HUNG_KINGS',
        'LIBERATION_DAY',
        'LABOR_DAY',
        'NATIONAL_DAY',
        'OTHER'
    ) DEFAULT 'OTHER',
    is_recurring BOOLEAN DEFAULT FALSE,
    year INT,
    paid_holiday BOOLEAN DEFAULT TRUE,
    salary_multiplier DECIMAL(4, 2) DEFAULT 3.00,
    allowance_amount DECIMAL(15, 2) DEFAULT 0.00,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_holiday_date (holiday_date, year)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng quỹ phép năm (nâng cấp với phép thâm niên) */
CREATE TABLE leave_balances (
    balance_id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    leave_type_id INT NOT NULL,
    year INT NOT NULL,
    base_leave DECIMAL(5, 2),
    seniority_bonus DECIMAL(5, 2) DEFAULT 0.00,
    total_days DECIMAL(5, 2) NOT NULL,
    carried_over_days DECIMAL(5, 2) DEFAULT 0.00,
    carried_over_source VARCHAR(255),
    used_days DECIMAL(5, 2) DEFAULT 0.00,
    pending_days DECIMAL(5, 2) DEFAULT 0.00,
    remaining_days DECIMAL(5, 2) GENERATED ALWAYS AS (
        total_days + carried_over_days - used_days - pending_days
    ) STORED,
    carry_over_expiry_date DATE,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_employee_leave_year (employee_id, leave_type_id, year),
    INDEX idx_leave_balance (employee_id, year)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng lịch sử tính phép thâm niên */
CREATE TABLE seniority_leave_history (
    history_id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    calculation_date DATE NOT NULL,
    years_of_service INT NOT NULL,
    base_leave DECIMAL(5, 2) NOT NULL,
    seniority_bonus INT NOT NULL,
    total_leave DECIMAL(5, 2) NOT NULL,
    effective_year INT NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT,
    INDEX idx_seniority_employee (employee_id, effective_year)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng cấu hình nghỉ gộp theo phòng ban */
CREATE TABLE leave_advancement_config (
    config_id INT AUTO_INCREMENT PRIMARY KEY,
    department_id INT,
    position_id INT,
    is_enabled BOOLEAN DEFAULT FALSE,
    max_accumulation_years INT DEFAULT 3,
    requires_approval BOOLEAN DEFAULT TRUE,
    approval_flow_id INT,
    effective_date DATE NOT NULL,
    expiry_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng theo dõi phép tồn theo năm */
CREATE TABLE leave_carryover_tracking (
    tracking_id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    source_year INT NOT NULL,
    target_year INT NOT NULL,
    original_days DECIMAL(5, 2) NOT NULL,
    carried_over_days DECIMAL(5, 2) DEFAULT 0.00,
    used_days DECIMAL(5, 2) DEFAULT 0.00,
    expired_days DECIMAL(5, 2) DEFAULT 0.00,
    expiry_date DATE NOT NULL,
    status ENUM('CÒN_HẠN', 'ĐÃ_HẾT_HẠN', 'ĐÃ_DÙNG_HẾT') DEFAULT 'CÒN_HẠN',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_employee_year_source (employee_id, source_year, target_year),
    INDEX idx_carryover_expiry (expiry_date)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng chi tiết nghỉ phép (kết nối với requests) */
CREATE TABLE leave_requests (
    leave_request_id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL UNIQUE,
    leave_type_id INT NOT NULL,
    employee_id INT NOT NULL,
    from_date DATE NOT NULL,
    to_date DATE NOT NULL,
    from_session ENUM('SÁNG', 'CHIỀU', 'CẢ_NGÀY') DEFAULT 'CẢ_NGÀY',
    to_session ENUM('SÁNG', 'CHIỀU', 'CẢ_NGÀY') DEFAULT 'CẢ_NGÀY',
    number_of_days DECIMAL(5, 2) NOT NULL,
    leave_used_type ENUM('BASE', 'SENIORITY', 'CARRIED_OVER', 'ADVANCED') DEFAULT 'BASE',
    base_days_used DECIMAL(5, 2) DEFAULT 0.00,
    seniority_days_used DECIMAL(5, 2) DEFAULT 0.00,
    carried_over_days_used DECIMAL(5, 2) DEFAULT 0.00,
    paid_days DECIMAL(5, 2) DEFAULT 0.00,
    unpaid_days DECIMAL(5, 2) DEFAULT 0.00,
    substitute_employee_id INT,
    handover_notes TEXT,
    contact_phone VARCHAR(20),
    emergency_contact VARCHAR(100),
    doctor_note_url VARCHAR(500),
    attachment_url VARCHAR(500),
    insurance_claim_id INT,
    certificate_file VARCHAR(500),
    certificate_number VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_leave_requests_dates (from_date, to_date)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng yêu cầu gộp phép */
CREATE TABLE leave_advancement_requests (
    advancement_id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL UNIQUE,
    employee_id INT NOT NULL,
    year_from INT NOT NULL,
    year_to INT NOT NULL,
    accumulated_days DECIMAL(5, 2) NOT NULL,
    days_to_transfer DECIMAL(5, 2) NOT NULL,
    remaining_after_transfer DECIMAL(5, 2),
    approved_by_manager INT,
    approved_by_manager_date DATETIME,
    approved_by_hr INT,
    approved_by_hr_date DATETIME,
    manager_notes TEXT,
    hr_notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng biến động phép */
CREATE TABLE leave_transactions (
    transaction_id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    leave_type_id INT NOT NULL,
    transaction_date DATE NOT NULL,
    transaction_type ENUM(
        'CẤP_PHÉP',
        'SỬ_DỤNG',
        'HOÀN_PHÉP',
        'CHUYỂN_NĂM',
        'ĐIỀU_CHỈNH',
        'HẾT_HẠN'
    ) NOT NULL,
    quantity DECIMAL(5, 2) NOT NULL,
    before_balance DECIMAL(5, 2) NOT NULL,
    after_balance DECIMAL(5, 2) NOT NULL,
    reference_id INT,
    reference_type VARCHAR(50),
    reason TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_leave_transaction_employee (employee_id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* ===================================================== */
/* 5. MODULE QUẢN LÝ CHẤM CÔNG & XẾP CA                   */
/* ===================================================== */
/* Bảng loại ca làm việc */
CREATE TABLE shift_types (
    shift_type_id INT AUTO_INCREMENT PRIMARY KEY,
    shift_code VARCHAR(20) UNIQUE NOT NULL,
    shift_name VARCHAR(100) NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    break_start TIME,
    break_end TIME,
    working_hours DECIMAL(5, 2),
    is_night_shift BOOLEAN DEFAULT FALSE,
    allow_overtime BOOLEAN DEFAULT TRUE,
    allow_wfh BOOLEAN DEFAULT FALSE,
    coefficient DECIMAL(3, 2) DEFAULT 1.00,
    color_code VARCHAR(7),
    description TEXT,
    status BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng lịch làm việc mẫu */
CREATE TABLE shift_schedules (
    schedule_id INT AUTO_INCREMENT PRIMARY KEY,
    schedule_code VARCHAR(20) UNIQUE NOT NULL,
    schedule_name VARCHAR(100) NOT NULL,
    department_id INT,
    effective_from DATE NOT NULL,
    effective_to DATE,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng chi tiết lịch làm việc */
CREATE TABLE shift_schedule_details (
    detail_id INT AUTO_INCREMENT PRIMARY KEY,
    schedule_id INT NOT NULL,
    day_of_week TINYINT,
    shift_type_id INT,
    is_holiday BOOLEAN DEFAULT FALSE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng đăng ký ca làm việc */
CREATE TABLE shift_assignments (
    assignment_id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    shift_type_id INT NOT NULL,
    effective_date DATE NOT NULL,
    expiry_date DATE,
    is_permanent BOOLEAN DEFAULT FALSE,
    assigned_by INT NOT NULL,
    notes TEXT,
    status ENUM('HIỆU_LỰC', 'HẾT_HIỆU_LỰC', 'CHỜ_DUYỆT') DEFAULT 'HIỆU_LỰC',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_employee_shift (employee_id, effective_date)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng đăng ký đổi ca */
CREATE TABLE shift_swaps (
    swap_id INT AUTO_INCREMENT PRIMARY KEY,
    requester_id INT NOT NULL,
    target_employee_id INT NOT NULL,
    shift_date DATE NOT NULL,
    original_shift_id INT NOT NULL,
    requested_shift_id INT NOT NULL,
    swap_reason TEXT,
    approver_id INT,
    approval_status ENUM('CHỜ_DUYỆT', 'ĐÃ_DUYỆT', 'TỪ_CHỐI', 'ĐÃ_HỦY') DEFAULT 'CHỜ_DUYỆT',
    approval_date DATETIME,
    approval_notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng chấm công */
CREATE TABLE attendances (
    attendance_id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    attendance_date DATE NOT NULL,
    shift_type_id INT,
    check_in_time DATETIME,
    check_out_time DATETIME,
    check_in_method ENUM('MÁY_QUÉT', 'MOBILE', 'MANUAL'),
    check_out_method ENUM('MÁY_QUÉT', 'MOBILE', 'MANUAL'),
    check_in_latitude DECIMAL(10, 8),
    check_in_longitude DECIMAL(11, 8),
    check_out_latitude DECIMAL(10, 8),
    check_out_longitude DECIMAL(11, 8),
    work_type ENUM(
        'VĂN_PHÒNG',
        'LÀM_TỪ_XA',
        'CÔNG_TÁC',
        'ĐI_CÔNG_TÁC'
    ) DEFAULT 'VĂN_PHÒNG',
    actual_working_hours DECIMAL(5, 2),
    overtime_hours DECIMAL(5, 2),
    late_minutes INT DEFAULT 0,
    early_leave_minutes INT DEFAULT 0,
    is_holiday BOOLEAN DEFAULT FALSE,
    is_overtime BOOLEAN DEFAULT FALSE,
    status ENUM(
        'CHỜ_DUYỆT',
        'ĐÃ_DUYỆT',
        'TỪ_CHỐI',
        'NHẬP_THỦ_CÔNG'
    ) DEFAULT 'CHỜ_DUYỆT',
    notes TEXT,
    approved_by INT,
    approved_date DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_attendance (employee_id, attendance_date),
    INDEX idx_attendance_date (attendance_date),
    INDEX idx_attendance_status (status)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng yêu cầu làm thêm giờ */
CREATE TABLE overtime_requests (
    overtime_id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    employee_id INT NOT NULL,
    overtime_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    break_time INT DEFAULT 0,
    total_hours DECIMAL(5, 2) GENERATED ALWAYS AS (
        HOUR(TIMEDIFF(end_time, start_time)) + (MINUTE(TIMEDIFF(end_time, start_time)) / 60) - (break_time / 60)
    ) STORED,
    reason TEXT,
    approved_by INT,
    approved_date DATETIME,
    status ENUM('CHỜ_DUYỆT', 'ĐÃ_DUYỆT', 'TỪ_CHỐI') DEFAULT 'CHỜ_DUYỆT',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* ===================================================== */
/* 6. MODULE QUẢN LÝ LƯƠNG & BẢO HIỂM                     */
/* ===================================================== */
/* Bảng loại bảo hiểm */
CREATE TABLE insurance_types (
    insurance_type_id INT AUTO_INCREMENT PRIMARY KEY,
    insurance_code VARCHAR(20) UNIQUE NOT NULL,
    insurance_name VARCHAR(100) NOT NULL,
    payment_rate DECIMAL(5, 2),
    is_company_paid BOOLEAN DEFAULT FALSE,
    is_social_insurance BOOLEAN DEFAULT FALSE,
    description TEXT,
    status BOOLEAN DEFAULT TRUE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng yêu cầu thanh toán bảo hiểm */
CREATE TABLE insurance_claims (
    claim_id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    request_id INT NOT NULL,
    insurance_type_id INT NOT NULL,
    claim_code VARCHAR(50) UNIQUE NOT NULL,
    leave_request_id INT,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    total_days INT NOT NULL,
    daily_rate DECIMAL(15, 2),
    total_amount DECIMAL(15, 2),
    payment_source ENUM('DOANH_NGHIỆP', 'BHXH', 'BẢO_HIỂM_TƯ_NHÂN') NOT NULL,
    certificate_number VARCHAR(50),
    certificate_file_url VARCHAR(500),
    certificate_uploaded_date DATETIME,
    certificate_verified_by INT,
    certificate_verified_date DATETIME,
    bank_account VARCHAR(30),
    bank_id INT,
    payment_date DATE,
    payment_status ENUM(
        'CHỜ_XỬ_LÝ',
        'ĐANG_XỬ_LÝ',
        'ĐÃ_THANH_TOÁN',
        'TỪ_CHỐI'
    ) DEFAULT 'CHỜ_XỬ_LÝ',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_claim_status (payment_status),
    INDEX idx_claim_dates (start_date, end_date)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng phụ cấp */
CREATE TABLE allowances (
    allowance_id INT AUTO_INCREMENT PRIMARY KEY,
    allowance_code VARCHAR(20) UNIQUE NOT NULL,
    allowance_name VARCHAR(100) NOT NULL,
    allowance_type ENUM(
        'FIXED',
        'PERCENTAGE_OF_SALARY',
        'PERCENTAGE_OF_BASIC'
    ) NOT NULL,
    calculation_method ENUM('MONTHLY', 'DAILY', 'HOURLY', 'ONE_TIME') DEFAULT 'MONTHLY',
    is_taxable BOOLEAN DEFAULT TRUE,
    is_insurable BOOLEAN DEFAULT TRUE,
    description TEXT,
    status BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng khấu trừ */
CREATE TABLE deductions (
    deduction_id INT AUTO_INCREMENT PRIMARY KEY,
    deduction_code VARCHAR(20) UNIQUE NOT NULL,
    deduction_name VARCHAR(100) NOT NULL,
    deduction_type ENUM(
        'FIXED',
        'PERCENTAGE_OF_SALARY',
        'PERCENTAGE_OF_BASIC'
    ) NOT NULL,
    is_mandatory BOOLEAN DEFAULT FALSE,
    description TEXT,
    status BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng phụ cấp theo nhân viên */
CREATE TABLE employee_allowances (
    employee_allowance_id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    allowance_id INT NOT NULL,
    amount DECIMAL(15, 2),
    percentage DECIMAL(5, 2),
    effective_date DATE NOT NULL,
    expiry_date DATE,
    is_active BOOLEAN DEFAULT TRUE,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng khấu trừ theo nhân viên */
CREATE TABLE employee_deductions (
    employee_deduction_id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    deduction_id INT NOT NULL,
    amount DECIMAL(15, 2),
    percentage DECIMAL(5, 2),
    effective_date DATE NOT NULL,
    expiry_date DATE,
    is_active BOOLEAN DEFAULT TRUE,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng kỳ lương */
CREATE TABLE salary_periods (
    period_id INT AUTO_INCREMENT PRIMARY KEY,
    period_code VARCHAR(20) UNIQUE NOT NULL,
    period_name VARCHAR(100) NOT NULL,
    period_type ENUM('MONTHLY', 'BIWEEKLY', 'WEEKLY') DEFAULT 'MONTHLY',
    year INT NOT NULL,
    month INT,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    payment_date DATE,
    standard_working_days INT DEFAULT 26,
    status ENUM(
        'OPEN',
        'CALCULATING',
        'REVIEWING',
        'APPROVED',
        'PAID',
        'CLOSED'
    ) DEFAULT 'OPEN',
    closed_by INT,
    closed_date DATETIME,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng tổng hợp ngày công */
CREATE TABLE salary_attendance_summary (
    summary_id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    period_id INT NOT NULL,
    standard_days INT NOT NULL,
    actual_working_days INT DEFAULT 0,
    total_shifts DECIMAL(6, 2) DEFAULT 0.00,
    admin_shifts DECIMAL(6, 2) DEFAULT 0.00,
    shift_1_count DECIMAL(6, 2) DEFAULT 0.00,
    shift_2_count DECIMAL(6, 2) DEFAULT 0.00,
    shift_3_count DECIMAL(6, 2) DEFAULT 0.00,
    holiday_shifts DECIMAL(6, 2) DEFAULT 0.00,
    paid_leave_days DECIMAL(5, 2) DEFAULT 0.00,
    unpaid_leave_days DECIMAL(5, 2) DEFAULT 0.00,
    holiday_days INT DEFAULT 0,
    overtime_hours DECIMAL(5, 2) DEFAULT 0.00,
    shift_pay DECIMAL(15, 2) DEFAULT 0.00,
    holiday_pay DECIMAL(15, 2) DEFAULT 0.00,
    late_minutes INT DEFAULT 0,
    early_leave_minutes INT DEFAULT 0,
    total_paid_days DECIMAL(5, 2) GENERATED ALWAYS AS (actual_working_days + paid_leave_days) STORED,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_employee_period (employee_id, period_id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng bảng lương chi tiết */
CREATE TABLE salary_details (
    salary_detail_id INT AUTO_INCREMENT PRIMARY KEY,
    period_id INT NOT NULL,
    employee_id INT NOT NULL,
    contract_id INT,
    basic_salary DECIMAL(15, 2) NOT NULL,
    gross_salary DECIMAL(15, 2) NOT NULL,
    net_salary DECIMAL(15, 2) NOT NULL,
    shift_pay DECIMAL(15, 2) DEFAULT 0.00,
    holiday_pay DECIMAL(15, 2) DEFAULT 0.00,
    total_allowances DECIMAL(15, 2) DEFAULT 0.00,
    total_deductions DECIMAL(15, 2) DEFAULT 0.00,
    overtime_pay DECIMAL(15, 2) DEFAULT 0.00,
    leave_pay DECIMAL(15, 2) DEFAULT 0.00,
    bonus DECIMAL(15, 2) DEFAULT 0.00,
    penalty DECIMAL(15, 2) DEFAULT 0.00,
    social_insurance_employee DECIMAL(15, 2) DEFAULT 0.00,
    social_insurance_company DECIMAL(15, 2) DEFAULT 0.00,
    health_insurance_employee DECIMAL(15, 2) DEFAULT 0.00,
    health_insurance_company DECIMAL(15, 2) DEFAULT 0.00,
    unemployment_insurance_employee DECIMAL(15, 2) DEFAULT 0.00,
    unemployment_insurance_company DECIMAL(15, 2) DEFAULT 0.00,
    personal_income_tax DECIMAL(15, 2) DEFAULT 0.00,
    advance_payment DECIMAL(15, 2) DEFAULT 0.00,
    final_amount DECIMAL(15, 2) GENERATED ALWAYS AS (
        shift_pay + holiday_pay + total_allowances + overtime_pay + leave_pay + bonus - total_deductions - social_insurance_employee - health_insurance_employee - unemployment_insurance_employee - personal_income_tax - advance_payment - penalty
    ) STORED,
    bank_account VARCHAR(30),
    bank_name VARCHAR(100),
    transfer_status ENUM('PENDING', 'TRANSFERRED', 'FAILED') DEFAULT 'PENDING',
    transfer_date DATETIME,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_period_employee (period_id, employee_id),
    INDEX idx_salary_period (period_id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng chi tiết các khoản trong bảng lương */
CREATE TABLE salary_breakdowns (
    breakdown_id INT AUTO_INCREMENT PRIMARY KEY,
    salary_detail_id INT NOT NULL,
    item_type ENUM(
        'ALLOWANCE',
        'DEDUCTION',
        'BONUS',
        'PENALTY',
        'OVERTIME',
        'LEAVE',
        'OTHER'
    ) NOT NULL,
    item_id INT,
    item_name VARCHAR(200) NOT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    is_taxable BOOLEAN DEFAULT FALSE,
    is_insurable BOOLEAN DEFAULT FALSE,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* ===================================================== */
/* 7. MODULE TRUYỀN THÔNG NỘI BỘ                          */
/* ===================================================== */
/* Bảng danh mục tin tức */
CREATE TABLE news_categories (
    category_id INT AUTO_INCREMENT PRIMARY KEY,
    category_code VARCHAR(20) UNIQUE NOT NULL,
    category_name VARCHAR(100) NOT NULL,
    description TEXT,
    status BOOLEAN DEFAULT TRUE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng tin tức */
CREATE TABLE news (
    news_id INT AUTO_INCREMENT PRIMARY KEY,
    news_code VARCHAR(50) UNIQUE NOT NULL,
    category_id INT,
    title VARCHAR(500) NOT NULL,
    summary TEXT,
    content LONGTEXT,
    priority ENUM('THẤP', 'TRUNG_BÌNH', 'CAO', 'KHẨN_CẤP') DEFAULT 'TRUNG_BÌNH',
    is_important BOOLEAN DEFAULT FALSE,
    is_pinned BOOLEAN DEFAULT FALSE,
    published_date DATETIME,
    expiry_date DATETIME,
    published_by INT,
    department_id INT,
    position_id INT,
    attachment_url VARCHAR(500),
    image_url VARCHAR(500),
    view_count INT DEFAULT 0,
    status ENUM('NHÁP', 'ĐÃ_XUẤT_BẢN', 'LƯU_TRỮ') DEFAULT 'NHÁP',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    updated_by INT,
    INDEX idx_news_published (published_date)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng xác nhận đã đọc tin */
CREATE TABLE news_reads (
    read_id INT AUTO_INCREMENT PRIMARY KEY,
    news_id INT NOT NULL,
    employee_id INT NOT NULL,
    read_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45),
    device_info VARCHAR(500),
    UNIQUE KEY unique_news_employee (news_id, employee_id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng quy định công ty */
CREATE TABLE policies (
    policy_id INT AUTO_INCREMENT PRIMARY KEY,
    policy_code VARCHAR(50) UNIQUE NOT NULL,
    policy_name VARCHAR(200) NOT NULL,
    policy_type ENUM('QUY_DINH', 'QUY_TRINH', 'HUONG_DAN', 'MAU_BIEU') NOT NULL,
    content LONGTEXT,
    version VARCHAR(20),
    effective_date DATE,
    expiry_date DATE,
    department_id INT,
    file_url VARCHAR(500),
    is_required_acknowledgment BOOLEAN DEFAULT FALSE,
    status ENUM('NHÁP', 'HIỆU_LỰC', 'HẾT_HIỆU_LỰC') DEFAULT 'NHÁP',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    approved_by INT,
    approved_date DATETIME
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng xác nhận đã đọc quy định */
CREATE TABLE policy_acknowledgments (
    acknowledgment_id INT AUTO_INCREMENT PRIMARY KEY,
    policy_id INT NOT NULL,
    employee_id INT NOT NULL,
    acknowledged_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45),
    UNIQUE KEY unique_policy_employee (policy_id, employee_id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* ===================================================== */
/* 8. MODULE THÔNG BÁO & DASHBOARD                        */
/* ===================================================== */
/* Bảng cấu hình thông báo */
CREATE TABLE notification_configs (
    config_id INT AUTO_INCREMENT PRIMARY KEY,
    notification_type ENUM(
        'SENIORITY_ALERT',
        'LEAVE_EXPIRY',
        'CLAIM_STATUS',
        'APPROVAL_REMINDER',
        'LEAVE_BALANCE_LOW',
        'CONTRACT_EXPIRY',
        'BIRTHDAY',
        'HOLIDAY'
    ) NOT NULL,
    is_enabled BOOLEAN DEFAULT TRUE,
    send_email BOOLEAN DEFAULT TRUE,
    send_in_app BOOLEAN DEFAULT TRUE,
    days_before_trigger INT DEFAULT 30,
    recipients ENUM('EMPLOYEE', 'MANAGER', 'HR', 'ALL') DEFAULT 'EMPLOYEE',
    email_template TEXT,
    in_app_template TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng thông báo */
CREATE TABLE notifications (
    notification_id INT AUTO_INCREMENT PRIMARY KEY,
    notification_type VARCHAR(50) NOT NULL,
    title VARCHAR(200) NOT NULL,
    content TEXT NOT NULL,
    sender_id INT,
    receiver_id INT,
    department_id INT,
    is_read BOOLEAN DEFAULT FALSE,
    read_date DATETIME,
    priority ENUM('THẤP', 'TRUNG_BÌNH', 'CAO') DEFAULT 'TRUNG_BÌNH',
    reference_type ENUM(
        'SENIORITY',
        'LEAVE_REQUEST',
        'INSURANCE_CLAIM',
        'LEAVE_ADVANCEMENT',
        'CONTRACT',
        'ATTENDANCE',
        'NEWS',
        'POLICY'
    ),
    reference_id INT,
    action_url VARCHAR(500),
    expires_at DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notifications_receiver (receiver_id, is_read),
    INDEX idx_notification_expiry (expires_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng theo dõi dashboard */
CREATE TABLE dashboard_views (
    view_id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    view_date DATE NOT NULL,
    view_type ENUM('EMPLOYEE', 'MANAGER', 'HR') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* ===================================================== */
/* 9. MODULE PHÂN QUYỀN & BÁO CÁO                         */
/* ===================================================== */
/* Bảng vai trò */
CREATE TABLE roles (
    role_id INT AUTO_INCREMENT PRIMARY KEY,
    role_code VARCHAR(50) UNIQUE NOT NULL,
    role_name VARCHAR(100) NOT NULL,
    description TEXT,
    is_system_role BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng quyền */
CREATE TABLE permissions (
    permission_id INT AUTO_INCREMENT PRIMARY KEY,
    permission_code VARCHAR(100) UNIQUE NOT NULL,
    permission_name VARCHAR(200) NOT NULL,
    module VARCHAR(50),
    description TEXT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng phân quyền theo vai trò */
CREATE TABLE role_permissions (
    role_permission_id INT AUTO_INCREMENT PRIMARY KEY,
    role_id INT NOT NULL,
    permission_id INT NOT NULL,
    can_access BOOLEAN DEFAULT TRUE,
    can_create BOOLEAN DEFAULT FALSE,
    can_edit BOOLEAN DEFAULT FALSE,
    can_delete BOOLEAN DEFAULT FALSE,
    can_approve BOOLEAN DEFAULT FALSE,
    can_export BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_role_permission (role_id, permission_id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng phân vai trò cho nhân viên */
CREATE TABLE employee_roles (
    employee_role_id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    role_id INT NOT NULL,
    department_id INT,
    effective_date DATE NOT NULL,
    expiry_date DATE,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_employee_role (employee_id, role_id, department_id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng báo cáo mẫu */
CREATE TABLE report_templates (
    template_id INT AUTO_INCREMENT PRIMARY KEY,
    template_code VARCHAR(50) UNIQUE NOT NULL,
    template_name VARCHAR(200) NOT NULL,
    report_type ENUM(
        'NHAN_SU',
        'LUONG',
        'CHAM_CONG',
        'NGHI_PHEP',
        'TAI_SAN',
        'TONG_HOP'
    ) NOT NULL,
    sql_query TEXT,
    columns_config TEXT,
    filters_config TEXT,
    chart_config TEXT,
    created_by INT,
    is_public BOOLEAN DEFAULT FALSE,
    status BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* Bảng lịch sử báo cáo */
CREATE TABLE report_histories (
    history_id INT AUTO_INCREMENT PRIMARY KEY,
    report_code VARCHAR(50) NOT NULL,
    report_name VARCHAR(200) NOT NULL,
    executed_by INT NOT NULL,
    executed_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    parameters TEXT,
    result_summary TEXT,
    file_url VARCHAR(500),
    execution_time INT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* ===================================================== */
/* 10. BẢNG CẤU HÌNH HỆ THỐNG                             */
/* ===================================================== */
CREATE TABLE system_configs (
    config_id INT AUTO_INCREMENT PRIMARY KEY,
    config_key VARCHAR(100) UNIQUE NOT NULL,
    config_value TEXT,
    config_type ENUM('TEXT', 'NUMBER', 'BOOLEAN', 'JSON', 'FILE') DEFAULT 'TEXT',
    description TEXT,
    module VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
/* ===================================================== */
/* 11. THÊM CÁC RÀNG BUỘC FOREIGN KEY                    */
/* ===================================================== */
/* employees foreign keys */
ALTER TABLE employees
ADD CONSTRAINT fk_employees_nationality FOREIGN KEY (nationality_id) REFERENCES nationalities(nationality_id),
    ADD CONSTRAINT fk_employees_bank FOREIGN KEY (bank_id) REFERENCES banks(bank_id),
    ADD CONSTRAINT fk_employees_created_by FOREIGN KEY (created_by) REFERENCES employees(employee_id),
    ADD CONSTRAINT fk_employees_updated_by FOREIGN KEY (updated_by) REFERENCES employees(employee_id);
/* departments foreign keys */
ALTER TABLE departments
ADD CONSTRAINT fk_departments_parent FOREIGN KEY (parent_department_id) REFERENCES departments(department_id),
    ADD CONSTRAINT fk_departments_manager FOREIGN KEY (manager_id) REFERENCES employees(employee_id);
/* contract_templates foreign keys */
ALTER TABLE contract_templates
ADD CONSTRAINT fk_contract_templates_type FOREIGN KEY (contract_type_id) REFERENCES contract_types(contract_type_id),
    ADD CONSTRAINT fk_contract_templates_created_by FOREIGN KEY (created_by) REFERENCES employees(employee_id);
/* contracts foreign keys */
ALTER TABLE contracts
ADD CONSTRAINT fk_contracts_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id),
    ADD CONSTRAINT fk_contracts_type FOREIGN KEY (contract_type_id) REFERENCES contract_types(contract_type_id),
    ADD CONSTRAINT fk_contracts_position FOREIGN KEY (position_id) REFERENCES positions(position_id),
    ADD CONSTRAINT fk_contracts_department FOREIGN KEY (department_id) REFERENCES departments(department_id),
    ADD CONSTRAINT fk_contracts_renewed_from FOREIGN KEY (renewed_from_contract_id) REFERENCES contracts(contract_id),
    ADD CONSTRAINT fk_contracts_template FOREIGN KEY (contract_template_id) REFERENCES contract_templates(template_id),
    ADD CONSTRAINT fk_contracts_created_by FOREIGN KEY (created_by) REFERENCES employees(employee_id),
    ADD CONSTRAINT fk_contracts_updated_by FOREIGN KEY (updated_by) REFERENCES employees(employee_id);
/* contract_histories foreign keys */
ALTER TABLE contract_histories
ADD CONSTRAINT fk_contract_histories_contract FOREIGN KEY (contract_id) REFERENCES contracts(contract_id),
    ADD CONSTRAINT fk_contract_histories_action_by FOREIGN KEY (action_by) REFERENCES employees(employee_id);
/* qualifications foreign keys */
ALTER TABLE qualifications
ADD CONSTRAINT fk_qualifications_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id),
    ADD CONSTRAINT fk_qualifications_type FOREIGN KEY (qualification_type_id) REFERENCES qualification_types(qualification_type_id);
/* certificates foreign keys */
ALTER TABLE certificates
ADD CONSTRAINT fk_certificates_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id),
    ADD CONSTRAINT fk_certificates_type FOREIGN KEY (certificate_type_id) REFERENCES certificate_types(certificate_type_id);
/* identity_documents foreign keys */
ALTER TABLE identity_documents
ADD CONSTRAINT fk_identity_documents_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id),
    ADD CONSTRAINT fk_identity_documents_type FOREIGN KEY (document_type_id) REFERENCES document_types(document_type_id);
/* social_insurance_info foreign keys */
ALTER TABLE social_insurance_info
ADD CONSTRAINT fk_social_insurance_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id);
/* dependents foreign keys */
ALTER TABLE dependents
ADD CONSTRAINT fk_dependents_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id);
/* employment_histories foreign keys */
ALTER TABLE employment_histories
ADD CONSTRAINT fk_employment_histories_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id),
    ADD CONSTRAINT fk_employment_histories_department FOREIGN KEY (department_id) REFERENCES departments(department_id),
    ADD CONSTRAINT fk_employment_histories_position FOREIGN KEY (position_id) REFERENCES positions(position_id);
/* assets foreign keys */
ALTER TABLE assets
ADD CONSTRAINT fk_assets_category FOREIGN KEY (category_id) REFERENCES asset_categories(category_id),
    ADD CONSTRAINT fk_assets_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id),
    ADD CONSTRAINT fk_assets_location FOREIGN KEY (location_id) REFERENCES asset_locations(location_id);
/* asset_locations foreign keys */
ALTER TABLE asset_locations
ADD CONSTRAINT fk_asset_locations_department FOREIGN KEY (department_id) REFERENCES departments(department_id);
/* asset_assignments foreign keys */
ALTER TABLE asset_assignments
ADD CONSTRAINT fk_asset_assignments_asset FOREIGN KEY (asset_id) REFERENCES assets(asset_id),
    ADD CONSTRAINT fk_asset_assignments_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id),
    ADD CONSTRAINT fk_asset_assignments_assigned_by FOREIGN KEY (assigned_by) REFERENCES employees(employee_id);
/* asset_incidents foreign keys */
ALTER TABLE asset_incidents
ADD CONSTRAINT fk_asset_incidents_asset FOREIGN KEY (asset_id) REFERENCES assets(asset_id),
    ADD CONSTRAINT fk_asset_incidents_assignment FOREIGN KEY (assignment_id) REFERENCES asset_assignments(assignment_id),
    ADD CONSTRAINT fk_asset_incidents_reported_by FOREIGN KEY (reported_by) REFERENCES employees(employee_id),
    ADD CONSTRAINT fk_asset_incidents_resolved_by FOREIGN KEY (resolved_by) REFERENCES employees(employee_id);
/* asset_maintenance foreign keys */
ALTER TABLE asset_maintenance
ADD CONSTRAINT fk_asset_maintenance_asset FOREIGN KEY (asset_id) REFERENCES assets(asset_id);
/* request_types foreign keys */
ALTER TABLE request_types
ADD CONSTRAINT fk_request_types_flow FOREIGN KEY (approval_flow_id) REFERENCES approval_flows(approval_flow_id);
/* approval_flows foreign keys */
ALTER TABLE approval_flows
ADD CONSTRAINT fk_approval_flows_type FOREIGN KEY (request_type_id) REFERENCES request_types(request_type_id);
/* approval_steps foreign keys */
ALTER TABLE approval_steps
ADD CONSTRAINT fk_approval_steps_flow FOREIGN KEY (approval_flow_id) REFERENCES approval_flows(approval_flow_id),
    ADD CONSTRAINT fk_approval_steps_role FOREIGN KEY (approver_role_id) REFERENCES approval_roles(role_id),
    ADD CONSTRAINT fk_approval_steps_user FOREIGN KEY (approver_user_id) REFERENCES employees(employee_id);
/* requests foreign keys */
ALTER TABLE requests
ADD CONSTRAINT fk_requests_type FOREIGN KEY (request_type_id) REFERENCES request_types(request_type_id),
    ADD CONSTRAINT fk_requests_requester FOREIGN KEY (requester_id) REFERENCES employees(employee_id),
    ADD CONSTRAINT fk_requests_step FOREIGN KEY (current_step_id) REFERENCES approval_steps(step_id),
    ADD CONSTRAINT fk_requests_created_by FOREIGN KEY (created_by) REFERENCES employees(employee_id),
    ADD CONSTRAINT fk_requests_updated_by FOREIGN KEY (updated_by) REFERENCES employees(employee_id);
/* approval_histories foreign keys */
ALTER TABLE approval_histories
ADD CONSTRAINT fk_approval_histories_request FOREIGN KEY (request_id) REFERENCES requests(request_id),
    ADD CONSTRAINT fk_approval_histories_step FOREIGN KEY (step_id) REFERENCES approval_steps(step_id),
    ADD CONSTRAINT fk_approval_histories_approver FOREIGN KEY (approver_id) REFERENCES employees(employee_id);
/* request_attachments foreign keys */
ALTER TABLE request_attachments
ADD CONSTRAINT fk_request_attachments_request FOREIGN KEY (request_id) REFERENCES requests(request_id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_request_attachments_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES employees(employee_id);
/* leave_balances foreign keys */
ALTER TABLE leave_balances
ADD CONSTRAINT fk_leave_balances_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id),
    ADD CONSTRAINT fk_leave_balances_type FOREIGN KEY (leave_type_id) REFERENCES leave_types(leave_type_id);
/* seniority_leave_history foreign keys */
ALTER TABLE seniority_leave_history
ADD CONSTRAINT fk_seniority_history_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id),
    ADD CONSTRAINT fk_seniority_history_created_by FOREIGN KEY (created_by) REFERENCES employees(employee_id);
/* leave_advancement_config foreign keys */
ALTER TABLE leave_advancement_config
ADD CONSTRAINT fk_advancement_config_department FOREIGN KEY (department_id) REFERENCES departments(department_id),
    ADD CONSTRAINT fk_advancement_config_position FOREIGN KEY (position_id) REFERENCES positions(position_id),
    ADD CONSTRAINT fk_advancement_config_flow FOREIGN KEY (approval_flow_id) REFERENCES approval_flows(approval_flow_id),
    ADD CONSTRAINT fk_advancement_config_created_by FOREIGN KEY (created_by) REFERENCES employees(employee_id),
    ADD CONSTRAINT fk_advancement_config_updated_by FOREIGN KEY (updated_by) REFERENCES employees(employee_id);
/* leave_carryover_tracking foreign keys */
ALTER TABLE leave_carryover_tracking
ADD CONSTRAINT fk_carryover_tracking_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id);
/* leave_requests foreign keys */
ALTER TABLE leave_requests
ADD CONSTRAINT fk_leave_requests_request FOREIGN KEY (request_id) REFERENCES requests(request_id),
    ADD CONSTRAINT fk_leave_requests_type FOREIGN KEY (leave_type_id) REFERENCES leave_types(leave_type_id),
    ADD CONSTRAINT fk_leave_requests_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id),
    ADD CONSTRAINT fk_leave_requests_substitute FOREIGN KEY (substitute_employee_id) REFERENCES employees(employee_id),
    ADD CONSTRAINT fk_leave_requests_claim FOREIGN KEY (insurance_claim_id) REFERENCES insurance_claims(claim_id);
/* leave_advancement_requests foreign keys */
ALTER TABLE leave_advancement_requests
ADD CONSTRAINT fk_advancement_requests_request FOREIGN KEY (request_id) REFERENCES requests(request_id),
    ADD CONSTRAINT fk_advancement_requests_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id),
    ADD CONSTRAINT fk_advancement_requests_manager FOREIGN KEY (approved_by_manager) REFERENCES employees(employee_id),
    ADD CONSTRAINT fk_advancement_requests_hr FOREIGN KEY (approved_by_hr) REFERENCES employees(employee_id);
/* leave_transactions foreign keys */
ALTER TABLE leave_transactions
ADD CONSTRAINT fk_leave_transactions_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id),
    ADD CONSTRAINT fk_leave_transactions_type FOREIGN KEY (leave_type_id) REFERENCES leave_types(leave_type_id),
    ADD CONSTRAINT fk_leave_transactions_created_by FOREIGN KEY (created_by) REFERENCES employees(employee_id);
/* shift_schedules foreign keys */
ALTER TABLE shift_schedules
ADD CONSTRAINT fk_shift_schedules_department FOREIGN KEY (department_id) REFERENCES departments(department_id);
/* shift_schedule_details foreign keys */
ALTER TABLE shift_schedule_details
ADD CONSTRAINT fk_shift_schedule_details_schedule FOREIGN KEY (schedule_id) REFERENCES shift_schedules(schedule_id),
    ADD CONSTRAINT fk_shift_schedule_details_shift FOREIGN KEY (shift_type_id) REFERENCES shift_types(shift_type_id);
/* shift_assignments foreign keys */
ALTER TABLE shift_assignments
ADD CONSTRAINT fk_shift_assignments_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id),
    ADD CONSTRAINT fk_shift_assignments_shift FOREIGN KEY (shift_type_id) REFERENCES shift_types(shift_type_id),
    ADD CONSTRAINT fk_shift_assignments_assigned_by FOREIGN KEY (assigned_by) REFERENCES employees(employee_id);
/* shift_swaps foreign keys */
ALTER TABLE shift_swaps
ADD CONSTRAINT fk_shift_swaps_requester FOREIGN KEY (requester_id) REFERENCES employees(employee_id),
    ADD CONSTRAINT fk_shift_swaps_target FOREIGN KEY (target_employee_id) REFERENCES employees(employee_id),
    ADD CONSTRAINT fk_shift_swaps_original_shift FOREIGN KEY (original_shift_id) REFERENCES shift_types(shift_type_id),
    ADD CONSTRAINT fk_shift_swaps_requested_shift FOREIGN KEY (requested_shift_id) REFERENCES shift_types(shift_type_id),
    ADD CONSTRAINT fk_shift_swaps_approver FOREIGN KEY (approver_id) REFERENCES employees(employee_id);
/* attendances foreign keys */
ALTER TABLE attendances
ADD CONSTRAINT fk_attendances_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id),
    ADD CONSTRAINT fk_attendances_shift FOREIGN KEY (shift_type_id) REFERENCES shift_types(shift_type_id),
    ADD CONSTRAINT fk_attendances_approved_by FOREIGN KEY (approved_by) REFERENCES employees(employee_id);
/* overtime_requests foreign keys */
ALTER TABLE overtime_requests
ADD CONSTRAINT fk_overtime_requests_request FOREIGN KEY (request_id) REFERENCES requests(request_id),
    ADD CONSTRAINT fk_overtime_requests_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id),
    ADD CONSTRAINT fk_overtime_requests_approved_by FOREIGN KEY (approved_by) REFERENCES employees(employee_id);
/* insurance_claims foreign keys */
ALTER TABLE insurance_claims
ADD CONSTRAINT fk_insurance_claims_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id),
    ADD CONSTRAINT fk_insurance_claims_request FOREIGN KEY (request_id) REFERENCES requests(request_id),
    ADD CONSTRAINT fk_insurance_claims_type FOREIGN KEY (insurance_type_id) REFERENCES insurance_types(insurance_type_id),
    ADD CONSTRAINT fk_insurance_claims_leave_request FOREIGN KEY (leave_request_id) REFERENCES leave_requests(leave_request_id),
    ADD CONSTRAINT fk_insurance_claims_verified_by FOREIGN KEY (certificate_verified_by) REFERENCES employees(employee_id),
    ADD CONSTRAINT fk_insurance_claims_bank FOREIGN KEY (bank_id) REFERENCES banks(bank_id);
/* employee_allowances foreign keys */
ALTER TABLE employee_allowances
ADD CONSTRAINT fk_employee_allowances_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id),
    ADD CONSTRAINT fk_employee_allowances_allowance FOREIGN KEY (allowance_id) REFERENCES allowances(allowance_id);
/* employee_deductions foreign keys */
ALTER TABLE employee_deductions
ADD CONSTRAINT fk_employee_deductions_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id),
    ADD CONSTRAINT fk_employee_deductions_deduction FOREIGN KEY (deduction_id) REFERENCES deductions(deduction_id);
/* salary_periods foreign keys */
ALTER TABLE salary_periods
ADD CONSTRAINT fk_salary_periods_closed_by FOREIGN KEY (closed_by) REFERENCES employees(employee_id);
/* salary_attendance_summary foreign keys */
ALTER TABLE salary_attendance_summary
ADD CONSTRAINT fk_salary_attendance_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id),
    ADD CONSTRAINT fk_salary_attendance_period FOREIGN KEY (period_id) REFERENCES salary_periods(period_id);
/* salary_details foreign keys */
ALTER TABLE salary_details
ADD CONSTRAINT fk_salary_details_period FOREIGN KEY (period_id) REFERENCES salary_periods(period_id),
    ADD CONSTRAINT fk_salary_details_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id),
    ADD CONSTRAINT fk_salary_details_contract FOREIGN KEY (contract_id) REFERENCES contracts(contract_id);
/* salary_breakdowns foreign keys */
ALTER TABLE salary_breakdowns
ADD CONSTRAINT fk_salary_breakdowns_detail FOREIGN KEY (salary_detail_id) REFERENCES salary_details(salary_detail_id);
/* news foreign keys */
ALTER TABLE news
ADD CONSTRAINT fk_news_category FOREIGN KEY (category_id) REFERENCES news_categories(category_id),
    ADD CONSTRAINT fk_news_published_by FOREIGN KEY (published_by) REFERENCES employees(employee_id),
    ADD CONSTRAINT fk_news_department FOREIGN KEY (department_id) REFERENCES departments(department_id),
    ADD CONSTRAINT fk_news_position FOREIGN KEY (position_id) REFERENCES positions(position_id),
    ADD CONSTRAINT fk_news_created_by FOREIGN KEY (created_by) REFERENCES employees(employee_id),
    ADD CONSTRAINT fk_news_updated_by FOREIGN KEY (updated_by) REFERENCES employees(employee_id);
/* news_reads foreign keys */
ALTER TABLE news_reads
ADD CONSTRAINT fk_news_reads_news FOREIGN KEY (news_id) REFERENCES news(news_id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_news_reads_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id);
/* policies foreign keys */
ALTER TABLE policies
ADD CONSTRAINT fk_policies_department FOREIGN KEY (department_id) REFERENCES departments(department_id),
    ADD CONSTRAINT fk_policies_created_by FOREIGN KEY (created_by) REFERENCES employees(employee_id),
    ADD CONSTRAINT fk_policies_approved_by FOREIGN KEY (approved_by) REFERENCES employees(employee_id);
/* policy_acknowledgments foreign keys */
ALTER TABLE policy_acknowledgments
ADD CONSTRAINT fk_policy_acknowledgments_policy FOREIGN KEY (policy_id) REFERENCES policies(policy_id),
    ADD CONSTRAINT fk_policy_acknowledgments_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id);
/* notifications foreign keys */
ALTER TABLE notifications
ADD CONSTRAINT fk_notifications_sender FOREIGN KEY (sender_id) REFERENCES employees(employee_id),
    ADD CONSTRAINT fk_notifications_receiver FOREIGN KEY (receiver_id) REFERENCES employees(employee_id),
    ADD CONSTRAINT fk_notifications_department FOREIGN KEY (department_id) REFERENCES departments(department_id);
/* dashboard_views foreign keys */
ALTER TABLE dashboard_views
ADD CONSTRAINT fk_dashboard_views_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id);
/* role_permissions foreign keys */
ALTER TABLE role_permissions
ADD CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles(role_id),
    ADD CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions(permission_id);
/* employee_roles foreign keys */
ALTER TABLE employee_roles
ADD CONSTRAINT fk_employee_roles_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id),
    ADD CONSTRAINT fk_employee_roles_role FOREIGN KEY (role_id) REFERENCES roles(role_id),
    ADD CONSTRAINT fk_employee_roles_department FOREIGN KEY (department_id) REFERENCES departments(department_id);
/* report_templates foreign keys */
ALTER TABLE report_templates
ADD CONSTRAINT fk_report_templates_created_by FOREIGN KEY (created_by) REFERENCES employees(employee_id);
/* report_histories foreign keys */
ALTER TABLE report_histories
ADD CONSTRAINT fk_report_histories_executed_by FOREIGN KEY (executed_by) REFERENCES employees(employee_id);
/* ===================================================== */
/* 12. STORED PROCEDURES & EVENTS                         */
/* ===================================================== */
DELIMITER $$

CREATE PROCEDURE calculate_seniority_leave()
BEGIN
    DECLARE done INT DEFAULT FALSE;
    DECLARE emp_id INT;
    DECLARE emp_seniority_start DATE;
    DECLARE emp_base_leave DECIMAL(5, 2);
    DECLARE years_service INT;
    DECLARE seniority_bonus INT;
    DECLARE current_year INT;

    DECLARE emp_cursor CURSOR FOR
    SELECT employee_id,
        COALESCE(seniority_start_date, hire_date) AS start_date,
        base_leave_days
    FROM employees
    WHERE status IN ('ĐANG_LÀM_VIỆC', 'THỬ_VIỆC');

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;

    SET current_year = YEAR(CURDATE());
    OPEN emp_cursor;

    read_loop: LOOP
        FETCH emp_cursor INTO emp_id,
        emp_seniority_start,
        emp_base_leave;

        IF done THEN
            LEAVE read_loop;
        END IF;

        SET years_service = TIMESTAMPDIFF(YEAR, emp_seniority_start, CURDATE());
        SET seniority_bonus = FLOOR(years_service / 5);

        INSERT INTO seniority_leave_history (
                employee_id,
                calculation_date,
                years_of_service,
                base_leave,
                seniority_bonus,
                total_leave,
                effective_year
            )
        VALUES (
                emp_id,
                CURDATE(),
                years_service,
                emp_base_leave,
                seniority_bonus,
                emp_base_leave + seniority_bonus,
                current_year
            );

        UPDATE employees
        SET last_seniority_calc = CURDATE()
        WHERE employee_id = emp_id;

        INSERT INTO leave_balances (
                employee_id,
                leave_type_id,
                year,
                base_leave,
                seniority_bonus,
                total_days
            )
        SELECT emp_id,
            lt.leave_type_id,
            current_year,
            emp_base_leave,
            seniority_bonus,
            emp_base_leave + seniority_bonus
        FROM leave_types lt
        WHERE lt.seniority_applicable = TRUE
            AND lt.status = TRUE
        ON DUPLICATE KEY UPDATE
            base_leave = VALUES(base_leave),
            seniority_bonus = VALUES(seniority_bonus),
            total_days = VALUES(total_days);

        IF (years_service + 1) % 5 = 0 THEN
            INSERT INTO notifications (
                    notification_type,
                    title,
                    content,
                    receiver_id,
                    priority,
                    reference_type,
                    reference_id
                )
            VALUES (
                    'SENIORITY_ALERT',
                    'Sắp được cộng phép thâm niên',
                    CONCAT(
                        'Bạn sắp tròn ',
                        years_service + 1,
                        ' năm làm việc. Bạn sẽ được cộng thêm 1 ngày phép thâm niên.'
                    ),
                    emp_id,
                    'CAO',
                    'SENIORITY',
                    emp_id
                );
        END IF;
    END LOOP;

    CLOSE emp_cursor;

    UPDATE leave_carryover_tracking
    SET status = 'ĐÃ_HẾT_HẠN',
        expired_days = carried_over_days - used_days
    WHERE expiry_date < CURDATE()
        AND status = 'CÒN_HẠN';
END $$

CREATE EVENT IF NOT EXISTS monthly_seniority_calculation
ON SCHEDULE EVERY 1 MONTH
STARTS TIMESTAMP(CURRENT_DATE + INTERVAL 1 DAY, '00:00:00')
DO
    CALL calculate_seniority_leave() $$

CREATE EVENT IF NOT EXISTS daily_leave_expiry_check
ON SCHEDULE EVERY 1 DAY
STARTS TIMESTAMP(CURRENT_DATE + INTERVAL 1 DAY, '08:00:00')
DO
BEGIN
    INSERT INTO notifications (
            notification_type,
            title,
            content,
            receiver_id,
            reference_type,
            reference_id
        )
    SELECT 'LEAVE_EXPIRY',
        'Phép gộp sắp hết hạn',
        CONCAT(
            'Bạn còn ',
            (lct.carried_over_days - lct.used_days),
            ' ngày phép gộp từ năm ',
            lct.source_year,
            ' sẽ hết hạn vào ngày ',
            lct.expiry_date
        ),
        lct.employee_id,
        'LEAVE_ADVANCEMENT',
        lct.tracking_id
    FROM leave_carryover_tracking lct
    WHERE lct.status = 'CÒN_HẠN'
        AND lct.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 15 DAY)
        AND (lct.carried_over_days - lct.used_days) > 0
        AND NOT EXISTS (
            SELECT 1
            FROM notifications n
            WHERE n.reference_id = lct.tracking_id
                AND n.notification_type = 'LEAVE_EXPIRY'
                AND n.created_at > DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        );
END $$

DELIMITER ;
/* ===================================================== */
/* 13. VIEWS CHO DASHBOARD                                */
/* ===================================================== */
CREATE VIEW employee_leave_dashboard AS
SELECT e.employee_id,
    e.full_name,
    e.employee_code,
    e.hire_date,
    e.base_leave_days,
    COALESCE(lb.total_days, e.base_leave_days) AS total_entitled,
    COALESCE(lb.base_leave, e.base_leave_days) AS base_leave,
    COALESCE(lb.seniority_bonus, 0) AS seniority_bonus,
    COALESCE(lb.carried_over_days, 0) AS carried_over,
    COALESCE(lb.used_days, 0) AS used,
    COALESCE(lb.pending_days, 0) AS pending,
    COALESCE(lb.remaining_days, e.base_leave_days) AS remaining,
    lb.year,
    JSON_OBJECT(
        'used',
        COALESCE(lb.used_days, 0),
        'pending',
        COALESCE(lb.pending_days, 0),
        'remaining',
        COALESCE(lb.remaining_days, e.base_leave_days),
        'seniority',
        COALESCE(lb.seniority_bonus, 0),
        'carried_over',
        COALESCE(lb.carried_over_days, 0)
    ) AS chart_data,
    CASE
        WHEN (
            TIMESTAMPDIFF(
                YEAR,
                COALESCE(e.seniority_start_date, e.hire_date),
                CURDATE()
            ) + 1
        ) % 5 = 0 THEN CONCAT(
            'Sắp được cộng 1 ngày phép thâm niên vào ',
            DATE_ADD(
                COALESCE(e.seniority_start_date, e.hire_date),
                INTERVAL (
                    FLOOR(
                        TIMESTAMPDIFF(
                            YEAR,
                            COALESCE(e.seniority_start_date, e.hire_date),
                            CURDATE()
                        ) / 5
                    ) + 1
                ) * 5 YEAR
            )
        )
        ELSE NULL
    END AS upcoming_seniority,
    (
        SELECT SUM(carried_over_days - used_days)
        FROM leave_carryover_tracking lct
        WHERE lct.employee_id = e.employee_id
            AND lct.status = 'CÒN_HẠN'
    ) AS total_valid_carried_over
FROM employees e
    LEFT JOIN leave_balances lb ON e.employee_id = lb.employee_id
    AND lb.year = YEAR(CURDATE());
CREATE VIEW department_leave_report AS
SELECT d.department_id,
    d.department_code,
    d.department_name,
    COUNT(DISTINCT e.employee_id) AS total_employees,
    SUM(lb.total_days) AS total_entitled,
    SUM(lb.seniority_bonus) AS total_seniority,
    SUM(lb.carried_over_days) AS total_carried_over,
    SUM(lb.used_days) AS total_used,
    SUM(lb.remaining_days) AS total_remaining,
    AVG(lb.remaining_days) AS avg_remaining_per_employee
FROM departments d
    LEFT JOIN employment_histories eh ON d.department_id = eh.department_id
    AND eh.is_current = TRUE
    LEFT JOIN employees e ON eh.employee_id = e.employee_id
    LEFT JOIN leave_balances lb ON e.employee_id = lb.employee_id
    AND lb.year = YEAR(CURDATE())
WHERE d.status = TRUE
GROUP BY d.department_id,
    d.department_code,
    d.department_name;
/* ===================================================== */
/* 14. QUY TẮC LƯƠNG THEO CA (KHÔNG DÙNG TRIGGER)        */
/* ===================================================== */
/* Chuẩn hóa ngày tính công:
   - Ca đêm (22:00-06:00) tính vào ngày bắt đầu ca.
   - Nếu có check_in_time thì lấy DATE(check_in_time).
   - Nếu nhập tay không có check_in_time thì lấy attendance_date - 1 ngày cho ca đêm. */
CREATE VIEW vw_attendance_payroll_basis AS
SELECT b.attendance_id,
    b.employee_id,
    b.shift_type_id,
    b.shift_code,
    b.shift_name,
    b.shift_coefficient,
    b.is_night_shift,
    b.payroll_work_date,
    b.attendance_date,
    b.check_in_time,
    b.check_out_time,
    b.actual_working_hours,
    b.overtime_hours,
    b.late_minutes,
    b.early_leave_minutes,
    h.holiday_id,
    h.holiday_name,
    h.holiday_type,
    COALESCE(h.salary_multiplier, 1.00) AS holiday_multiplier,
    COALESCE(h.allowance_amount, 0.00) AS holiday_allowance
FROM (
        SELECT a.attendance_id,
            a.employee_id,
            a.shift_type_id,
            st.shift_code,
            st.shift_name,
            COALESCE(st.coefficient, 1.00) AS shift_coefficient,
            COALESCE(st.is_night_shift, FALSE) AS is_night_shift,
            CASE
                WHEN COALESCE(st.is_night_shift, FALSE) = TRUE THEN COALESCE(DATE(a.check_in_time), DATE_SUB(a.attendance_date, INTERVAL 1 DAY))
                ELSE COALESCE(DATE(a.check_in_time), a.attendance_date)
            END AS payroll_work_date,
            a.attendance_date,
            a.check_in_time,
            a.check_out_time,
            COALESCE(a.actual_working_hours, 0.00) AS actual_working_hours,
            COALESCE(a.overtime_hours, 0.00) AS overtime_hours,
            COALESCE(a.late_minutes, 0) AS late_minutes,
            COALESCE(a.early_leave_minutes, 0) AS early_leave_minutes
        FROM attendances a
            LEFT JOIN shift_types st ON st.shift_type_id = a.shift_type_id
        WHERE a.status = 'ĐÃ_DUYỆT'
    ) b
    LEFT JOIN holidays h ON (
        (
            h.is_recurring = FALSE
            AND h.holiday_date = b.payroll_work_date
            AND (h.year IS NULL OR h.year = YEAR(b.payroll_work_date))
        )
        OR (
            h.is_recurring = TRUE
            AND DATE_FORMAT(h.holiday_date, '%m-%d') = DATE_FORMAT(b.payroll_work_date, '%m-%d')
            AND (h.year IS NULL OR h.year = YEAR(b.payroll_work_date))
        )
    );
/* Tổng hợp ca làm + tiền ca theo kỳ lương */
CREATE VIEW vw_shift_payroll_summary AS
SELECT sp.period_id,
    ab.employee_id,
    COUNT(*) AS total_shifts,
    SUM(CASE WHEN ab.shift_code = 'HC' THEN 1 ELSE 0 END) AS admin_shifts,
    SUM(CASE WHEN ab.shift_code = 'CA1' THEN 1 ELSE 0 END) AS shift_1_count,
    SUM(CASE WHEN ab.shift_code = 'CA2' THEN 1 ELSE 0 END) AS shift_2_count,
    SUM(CASE WHEN ab.shift_code = 'CA3' THEN 1 ELSE 0 END) AS shift_3_count,
    COUNT(DISTINCT ab.payroll_work_date) AS actual_working_days,
    SUM(CASE WHEN ab.holiday_id IS NOT NULL THEN 1 ELSE 0 END) AS holiday_shifts,
    COALESCE(lc.contract_id, NULL) AS contract_id,
    COALESCE(lc.basic_salary, 0.00) AS contract_basic_salary,
    COALESCE(lc.gross_salary, 0.00) AS contract_gross_salary,
    COALESCE(sp.standard_working_days, 26) AS standard_working_days,
    ROUND(
        (COALESCE(lc.gross_salary, 0.00) / NULLIF(COALESCE(sp.standard_working_days, 26), 0)) * SUM(ab.shift_coefficient),
        2
    ) AS shift_pay,
    ROUND(
        (COALESCE(lc.gross_salary, 0.00) / NULLIF(COALESCE(sp.standard_working_days, 26), 0)) * SUM(
            ab.shift_coefficient * (GREATEST(ab.holiday_multiplier, 1.00) - 1.00)
        ) + SUM(ab.holiday_allowance),
        2
    ) AS holiday_pay,
    ROUND(SUM(ab.overtime_hours), 2) AS overtime_hours,
    SUM(ab.late_minutes) AS late_minutes,
    SUM(ab.early_leave_minutes) AS early_leave_minutes
FROM salary_periods sp
    JOIN vw_attendance_payroll_basis ab ON ab.payroll_work_date BETWEEN sp.start_date AND sp.end_date
    LEFT JOIN (
        SELECT c.contract_id,
            c.employee_id,
            c.basic_salary,
            c.gross_salary
        FROM contracts c
            JOIN (
                SELECT employee_id,
                    MAX(contract_id) AS latest_contract_id
                FROM contracts
                WHERE status = 'CÓ_HIỆU_LỰC'
                GROUP BY employee_id
            ) lc2 ON lc2.latest_contract_id = c.contract_id
    ) lc ON lc.employee_id = ab.employee_id
GROUP BY sp.period_id,
    ab.employee_id,
    lc.contract_id,
    lc.basic_salary,
    lc.gross_salary,
    sp.standard_working_days;
/* Không dùng trigger tính lương, chỉ tính khi gọi procedure này */
DELIMITER $$
CREATE PROCEDURE sp_calculate_salary_by_shifts(IN p_period_id INT)
BEGIN
    DECLARE v_period_exists INT DEFAULT 0;
    SELECT COUNT(*)
    INTO v_period_exists
    FROM salary_periods
    WHERE period_id = p_period_id;
    IF v_period_exists = 0 THEN SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Salary period not found';
    END IF;
    INSERT INTO salary_attendance_summary (
            employee_id,
            period_id,
            standard_days,
            actual_working_days,
            total_shifts,
            admin_shifts,
            shift_1_count,
            shift_2_count,
            shift_3_count,
            holiday_shifts,
            holiday_days,
            overtime_hours,
            shift_pay,
            holiday_pay,
            late_minutes,
            early_leave_minutes
        )
    SELECT s.employee_id,
        s.period_id,
        s.standard_working_days,
        s.actual_working_days,
        s.total_shifts,
        s.admin_shifts,
        s.shift_1_count,
        s.shift_2_count,
        s.shift_3_count,
        s.holiday_shifts,
        s.holiday_shifts,
        s.overtime_hours,
        s.shift_pay,
        s.holiday_pay,
        s.late_minutes,
        s.early_leave_minutes
    FROM vw_shift_payroll_summary s
    WHERE s.period_id = p_period_id ON DUPLICATE KEY
    UPDATE standard_days =
    VALUES(standard_days),
        actual_working_days =
    VALUES(actual_working_days),
        total_shifts =
    VALUES(total_shifts),
        admin_shifts =
    VALUES(admin_shifts),
        shift_1_count =
    VALUES(shift_1_count),
        shift_2_count =
    VALUES(shift_2_count),
        shift_3_count =
    VALUES(shift_3_count),
        holiday_shifts =
    VALUES(holiday_shifts),
        holiday_days =
    VALUES(holiday_days),
        overtime_hours =
    VALUES(overtime_hours),
        shift_pay =
    VALUES(shift_pay),
        holiday_pay =
    VALUES(holiday_pay),
        late_minutes =
    VALUES(late_minutes),
        early_leave_minutes =
    VALUES(early_leave_minutes);
    INSERT INTO salary_details (
            period_id,
            employee_id,
            contract_id,
            basic_salary,
            gross_salary,
            net_salary,
            shift_pay,
            holiday_pay,
            overtime_pay,
            total_allowances,
            total_deductions,
            bonus,
            penalty,
            social_insurance_employee,
            health_insurance_employee,
            unemployment_insurance_employee,
            personal_income_tax,
            advance_payment,
            transfer_status,
            notes
        )
    SELECT s.period_id,
        s.employee_id,
        s.contract_id,
        s.contract_basic_salary,
        s.contract_gross_salary,
        (s.shift_pay + s.holiday_pay),
        s.shift_pay,
        s.holiday_pay,
        0.00,
        0.00,
        0.00,
        0.00,
        0.00,
        0.00,
        0.00,
        0.00,
        0.00,
        0.00,
        'PENDING',
        'Tính theo số ca làm việc (manual, không dùng trigger)'
    FROM vw_shift_payroll_summary s
    WHERE s.period_id = p_period_id ON DUPLICATE KEY
    UPDATE contract_id =
    VALUES(contract_id),
        basic_salary =
    VALUES(basic_salary),
        gross_salary =
    VALUES(gross_salary),
        net_salary =
    VALUES(net_salary),
        shift_pay =
    VALUES(shift_pay),
        holiday_pay =
    VALUES(holiday_pay),
        updated_at = CURRENT_TIMESTAMP,
        notes = 'Cập nhật theo số ca làm việc (manual, không dùng trigger)';
END $$
DELIMITER ;
/* ===================================================== */
/* 15. DỮ LIỆU MẪU                                        */
/* ===================================================== */
/* Dữ liệu seed đã tách riêng ở data.sql để tránh trùng khi import theo 2 file. */
/* ===================================================== */
/* KẾT THÚC SCRIPT                                        */
/* ===================================================== */

/* ===================================================== */
/* 16. APPENDED MIGRATIONS (BACKEND UPDATES)             */
/* ===================================================== */

/* 16.1 payroll_adjustments */
CREATE TABLE IF NOT EXISTS payroll_adjustments (
    adjustment_id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    description VARCHAR(255) NOT NULL,
    apply_month CHAR(7) NOT NULL,
    status TINYINT(1) NOT NULL DEFAULT 0,
    paid_salary_detail_id INT NULL,
    paid_period_id INT NULL,
    paid_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_adjustment_lookup (employee_id, apply_month, status),
    INDEX idx_adjustment_paid_detail (paid_salary_detail_id),
    INDEX idx_adjustment_paid_period (paid_period_id),
    CONSTRAINT fk_adjustment_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id),
    CONSTRAINT fk_adjustment_salary_detail FOREIGN KEY (paid_salary_detail_id) REFERENCES salary_details(salary_detail_id) ON DELETE SET NULL,
    CONSTRAINT fk_adjustment_salary_period FOREIGN KEY (paid_period_id) REFERENCES salary_periods(period_id) ON DELETE SET NULL,
    CONSTRAINT chk_adjustment_status CHECK (status IN (0, 1)),
    CONSTRAINT chk_adjustment_apply_month CHECK (apply_month REGEXP '^[0-9]{4}-(0[1-9]|1[0-2])$')
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

/* 16.2 employees.password_hash */
SET @column_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'employees'
      AND COLUMN_NAME = 'password_hash'
);

SET @ddl := IF(
    @column_exists = 0,
    'ALTER TABLE employees ADD COLUMN password_hash VARCHAR(255) NULL AFTER company_email',
    'SELECT ''password_hash already exists'' AS message'
);

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

/* 16.3 contract_change_logs */
CREATE TABLE IF NOT EXISTS contract_change_logs (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    contract_id INT NULL,
    contract_no VARCHAR(50) NOT NULL,
    employee_name VARCHAR(120) NOT NULL,
    action_type VARCHAR(30) NOT NULL,
    content VARCHAR(255) NOT NULL,
    icon VARCHAR(30) NOT NULL DEFAULT 'edit',
    bg_class VARCHAR(50) NOT NULL DEFAULT 'bg-slate-500',
    notes TEXT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_contract_change_logs_created_at (created_at),
    INDEX idx_contract_change_logs_contract_no (contract_no),
    INDEX idx_contract_change_logs_action_type (action_type)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

/* 16.4 positions.position_group + positions.position_level */
SET @position_group_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'positions'
      AND COLUMN_NAME = 'position_group'
);

SET @ddl := IF(
    @position_group_exists = 0,
    'ALTER TABLE positions ADD COLUMN position_group VARCHAR(100) NULL AFTER position_name',
    'SELECT ''position_group already exists'' AS message'
);

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @position_level_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'positions'
      AND COLUMN_NAME = 'position_level'
);

SET @ddl := IF(
    @position_level_exists = 0,
    'ALTER TABLE positions ADD COLUMN position_level VARCHAR(50) NULL AFTER position_group',
    'SELECT ''position_level already exists'' AS message'
);

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

/* 16.5 recruitment module (3NF) */
CREATE TABLE IF NOT EXISTS recruitment_positions (
    recruitment_position_id INT AUTO_INCREMENT PRIMARY KEY,
    position_code VARCHAR(30) NOT NULL,
    position_name VARCHAR(150) NOT NULL,
    department_id INT NULL,
    employment_type ENUM('FULL_TIME', 'PART_TIME', 'CONTRACT', 'INTERN') NOT NULL DEFAULT 'FULL_TIME',
    vacancy_count INT NOT NULL DEFAULT 1,
    description TEXT NULL,
    status ENUM('OPEN', 'CLOSED', 'ON_HOLD') NOT NULL DEFAULT 'OPEN',
    opened_at DATE NULL,
    closed_at DATE NULL,
    created_by INT NULL,
    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_recruitment_position_code (position_code),
    INDEX idx_recruitment_position_status (status),
    INDEX idx_recruitment_position_department (department_id),
    CONSTRAINT fk_recruitment_position_department FOREIGN KEY (department_id) REFERENCES departments(department_id) ON DELETE SET NULL,
    CONSTRAINT fk_recruitment_position_created_by FOREIGN KEY (created_by) REFERENCES employees(employee_id) ON DELETE SET NULL,
    CONSTRAINT fk_recruitment_position_updated_by FOREIGN KEY (updated_by) REFERENCES employees(employee_id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recruitment_candidates (
    candidate_id INT AUTO_INCREMENT PRIMARY KEY,
    candidate_code VARCHAR(30) NOT NULL,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(120) NULL,
    phone_number VARCHAR(30) NULL,
    recruitment_position_id INT NOT NULL,
    cv_url VARCHAR(500) NULL,
    source_channel VARCHAR(100) NULL,
    ai_score DECIMAL(5, 2) NULL,
    application_status ENUM('NEW', 'SCREENING', 'INTERVIEWING', 'PASSED', 'REJECTED', 'HIRED') NOT NULL DEFAULT 'NEW',
    applied_at DATE NOT NULL,
    notes TEXT NULL,
    created_by INT NULL,
    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_candidate_code (candidate_code),
    INDEX idx_candidate_status (application_status),
    INDEX idx_candidate_position (recruitment_position_id),
    CONSTRAINT fk_candidate_position FOREIGN KEY (recruitment_position_id) REFERENCES recruitment_positions(recruitment_position_id),
    CONSTRAINT fk_candidate_created_by FOREIGN KEY (created_by) REFERENCES employees(employee_id) ON DELETE SET NULL,
    CONSTRAINT fk_candidate_updated_by FOREIGN KEY (updated_by) REFERENCES employees(employee_id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS interview_schedules (
    interview_id INT AUTO_INCREMENT PRIMARY KEY,
    candidate_id INT NOT NULL,
    interviewer_id INT NULL,
    interview_date DATE NOT NULL,
    interview_time TIME NOT NULL,
    interview_mode ENUM('ONLINE', 'OFFLINE') NOT NULL DEFAULT 'ONLINE',
    meeting_link VARCHAR(500) NULL,
    location VARCHAR(255) NULL,
    status ENUM('SCHEDULED', 'COMPLETED', 'CANCELED') NOT NULL DEFAULT 'SCHEDULED',
    result ENUM('PASS', 'FAIL', 'PENDING') NOT NULL DEFAULT 'PENDING',
    evaluation_notes TEXT NULL,
    created_by INT NULL,
    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_interview_candidate (candidate_id),
    INDEX idx_interview_status (status),
    INDEX idx_interview_interviewer (interviewer_id),
    CONSTRAINT fk_interview_candidate FOREIGN KEY (candidate_id) REFERENCES recruitment_candidates(candidate_id) ON DELETE CASCADE,
    CONSTRAINT fk_interview_interviewer FOREIGN KEY (interviewer_id) REFERENCES employees(employee_id) ON DELETE SET NULL,
    CONSTRAINT fk_interview_created_by FOREIGN KEY (created_by) REFERENCES employees(employee_id) ON DELETE SET NULL,
    CONSTRAINT fk_interview_updated_by FOREIGN KEY (updated_by) REFERENCES employees(employee_id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

/* 16.6 internal service ticket module (3NF) */
CREATE TABLE IF NOT EXISTS service_categories (
    category_id INT AUTO_INCREMENT PRIMARY KEY,
    category_code VARCHAR(30) NOT NULL,
    category_name VARCHAR(120) NOT NULL,
    description TEXT NULL,
    status BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_service_category_code (category_code)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_tickets (
    ticket_id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_code VARCHAR(30) NOT NULL,
    requester_id INT NOT NULL,
    category_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NULL,
    priority ENUM('LOW', 'MEDIUM', 'HIGH', 'URGENT') NOT NULL DEFAULT 'MEDIUM',
    status ENUM('OPEN', 'IN_PROGRESS', 'RESOLVED', 'CLOSED') NOT NULL DEFAULT 'OPEN',
    assigned_to INT NULL,
    resolved_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_service_ticket_code (ticket_code),
    INDEX idx_service_ticket_requester (requester_id),
    INDEX idx_service_ticket_status (status),
    INDEX idx_service_ticket_assignee (assigned_to),
    INDEX idx_service_ticket_category (category_id),
    CONSTRAINT fk_service_ticket_requester FOREIGN KEY (requester_id) REFERENCES employees(employee_id),
    CONSTRAINT fk_service_ticket_category FOREIGN KEY (category_id) REFERENCES service_categories(category_id),
    CONSTRAINT fk_service_ticket_assignee FOREIGN KEY (assigned_to) REFERENCES employees(employee_id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_ticket_updates (
    update_id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    action_type ENUM('COMMENT', 'STATUS_CHANGE', 'ASSIGNMENT', 'SYSTEM') NOT NULL DEFAULT 'COMMENT',
    old_status ENUM('OPEN', 'IN_PROGRESS', 'RESOLVED', 'CLOSED') NULL,
    new_status ENUM('OPEN', 'IN_PROGRESS', 'RESOLVED', 'CLOSED') NULL,
    content TEXT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_service_ticket_update_ticket (ticket_id),
    INDEX idx_service_ticket_update_created (created_at),
    CONSTRAINT fk_service_ticket_update_ticket FOREIGN KEY (ticket_id) REFERENCES service_tickets(ticket_id) ON DELETE CASCADE,
    CONSTRAINT fk_service_ticket_update_created_by FOREIGN KEY (created_by) REFERENCES employees(employee_id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

/* 16.7 seed defaults cho service_categories + notification_configs */
INSERT INTO service_categories (category_code, category_name, description, status)
VALUES
    ('IT_SUPPORT', 'Hỗ trợ IT', 'Lỗi máy tính, phần mềm, email công ty', TRUE),
    ('FACILITY', 'Cơ sở vật chất', 'Điện, nước, điều hòa, bàn ghế', TRUE),
    ('HR_SUPPORT', 'Hỗ trợ nhân sự', 'Giấy tờ, xác nhận, thủ tục nội bộ', TRUE)
ON DUPLICATE KEY UPDATE
    category_name = VALUES(category_name),
    description = VALUES(description),
    status = VALUES(status);

INSERT INTO notification_configs (
    notification_type,
    is_enabled,
    send_email,
    send_in_app,
    days_before_trigger,
    recipients,
    email_template,
    in_app_template
)
SELECT 'SENIORITY_ALERT', 1, 1, 1, 30, 'HR', 'Thông báo thâm niên', 'Thông báo thâm niên'
WHERE NOT EXISTS (
    SELECT 1 FROM notification_configs WHERE notification_type = 'SENIORITY_ALERT'
);

INSERT INTO notification_configs (
    notification_type,
    is_enabled,
    send_email,
    send_in_app,
    days_before_trigger,
    recipients,
    email_template,
    in_app_template
)
SELECT 'LEAVE_EXPIRY', 1, 1, 1, 7, 'EMPLOYEE', 'Thông báo phép sắp hết hạn', 'Thông báo phép sắp hết hạn'
WHERE NOT EXISTS (
    SELECT 1 FROM notification_configs WHERE notification_type = 'LEAVE_EXPIRY'
);

INSERT INTO notification_configs (
    notification_type,
    is_enabled,
    send_email,
    send_in_app,
    days_before_trigger,
    recipients,
    email_template,
    in_app_template
)
SELECT 'CONTRACT_EXPIRY', 1, 1, 1, 30, 'HR', 'Thông báo hợp đồng sắp hết hạn', 'Thông báo hợp đồng sắp hết hạn'
WHERE NOT EXISTS (
    SELECT 1 FROM notification_configs WHERE notification_type = 'CONTRACT_EXPIRY'
);
