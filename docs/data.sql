/* ===================================================== */
/* DỮ LIỆU ẢO CHO HỆ THỐNG HRM - PHIÊN BẢN 3.0          */
/* Phù hợp với cấu trúc database đã sửa                   */
/* ===================================================== */
USE HRM_SYSTEM;
/* ===================================================== */
/* 1. nationalities - Quốc tịch                          */
/* ===================================================== */
INSERT INTO nationalities (nationality_code, nationality_name)
VALUES ('VN', 'Việt Nam'),
    ('US', 'Hoa Kỳ'),
    ('JP', 'Nhật Bản'),
    ('KR', 'Hàn Quốc'),
    ('CN', 'Trung Quốc'),
    ('FR', 'Pháp'),
    ('UK', 'Anh'),
    ('DE', 'Đức'),
    ('CA', 'Canada'),
    ('AU', 'Úc');
/* ===================================================== */
/* 2. banks - Ngân hàng                                   */
/* ===================================================== */
INSERT INTO banks (bank_code, bank_name, swift_code, status)
VALUES (
        'VCB',
        'Ngân hàng TMCP Ngoại thương Việt Nam',
        'VCBVVNVX',
        TRUE
    ),
    (
        'CTG',
        'Ngân hàng TMCP Công thương Việt Nam',
        'ICBVVNVX',
        TRUE
    ),
    (
        'BIDV',
        'Ngân hàng TMCP Đầu tư và Phát triển Việt Nam',
        'BIDVVNVX',
        TRUE
    ),
    (
        'AGB',
        'Ngân hàng Nông nghiệp và Phát triển Nông thôn',
        'VBAAVNVX',
        TRUE
    ),
    (
        'TCB',
        'Ngân hàng TMCP Kỹ thương Việt Nam',
        'TCBVVNVX',
        TRUE
    ),
    (
        'MBB',
        'Ngân hàng TMCP Quân đội',
        'MSCBVVVX',
        TRUE
    ),
    ('ACB', 'Ngân hàng TMCP Á Châu', 'ASCBVNVX', TRUE),
    (
        'VPB',
        'Ngân hàng TMCP Việt Nam Thịnh Vượng',
        'VPBKVNVX',
        TRUE
    ),
    (
        'HDB',
        'Ngân hàng TMCP Phát triển TP HCM',
        'HDBCVNVX',
        TRUE
    ),
    (
        'SHB',
        'Ngân hàng TMCP Sài Gòn - Hà Nội',
        'SHBAVNVX',
        TRUE
    );
/* ===================================================== */
/* 3. positions - Chức vụ (cần tạo trước departments)     */
/* ===================================================== */
INSERT INTO positions (
        position_code,
        position_name,
        job_description,
        requirements,
        salary_range_min,
        salary_range_max,
        status
    )
VALUES (
        'GD',
        'Giám đốc',
        'Điều hành toàn bộ công ty',
        'Kinh nghiệm 10 năm quản lý',
        50000000,
        100000000,
        TRUE
    ),
    (
        'PGD',
        'Phó Giám đốc',
        'Hỗ trợ giám đốc điều hành',
        'Kinh nghiệm 8 năm quản lý',
        40000000,
        80000000,
        TRUE
    ),
    (
        'TP',
        'Trưởng phòng',
        'Quản lý phòng ban',
        'Kinh nghiệm 5 năm',
        25000000,
        50000000,
        TRUE
    ),
    (
        'PP',
        'Phó phòng',
        'Hỗ trợ trưởng phòng',
        'Kinh nghiệm 3 năm',
        18000000,
        35000000,
        TRUE
    ),
    (
        'CV',
        'Chuyên viên',
        'Thực hiện nghiệp vụ chuyên môn',
        'Tốt nghiệp đại học',
        12000000,
        25000000,
        TRUE
    ),
    (
        'NV',
        'Nhân viên',
        'Thực hiện công việc được giao',
        'Kinh nghiệm 1 năm',
        8000000,
        15000000,
        TRUE
    ),
    (
        'TV',
        'Thực tập viên',
        'Học việc',
        'Sinh viên năm cuối',
        3000000,
        5000000,
        TRUE
    );
/* ===================================================== */
/* 4. departments - Phòng ban (cần positions trước)       */
/* ===================================================== */
INSERT INTO departments (
        department_code,
        department_name,
        parent_department_id,
        manager_id,
        description,
        status
    )
VALUES (
        'HCNS',
        'Hành chính Nhân sự',
        NULL,
        NULL,
        'Quản lý nhân sự và hành chính',
        TRUE
    ),
    (
        'KT',
        'Kế toán',
        NULL,
        NULL,
        'Quản lý tài chính kế toán',
        TRUE
    ),
    (
        'KD',
        'Kinh doanh',
        NULL,
        NULL,
        'Phát triển kinh doanh và bán hàng',
        TRUE
    ),
    (
        'IT',
        'Công nghệ thông tin',
        NULL,
        NULL,
        'Phát triển phần mềm và hệ thống',
        TRUE
    ),
    (
        'MKT',
        'Marketing',
        NULL,
        NULL,
        'Quảng bá thương hiệu và sản phẩm',
        TRUE
    ),
    (
        'CSKH',
        'Chăm sóc khách hàng',
        NULL,
        NULL,
        'Hỗ trợ và chăm sóc khách hàng',
        TRUE
    ),
    (
        'KHO',
        'Quản lý kho',
        NULL,
        NULL,
        'Quản lý hàng hóa và vật tư',
        TRUE
    ),
    (
        'SX',
        'Sản xuất',
        NULL,
        NULL,
        'Điều hành sản xuất',
        TRUE
    ),
    (
        'PHA',
        'Pháp chế',
        NULL,
        NULL,
        'Tư vấn pháp lý và hợp đồng',
        TRUE
    ),
    (
        'QLDA',
        'Quản lý dự án',
        NULL,
        NULL,
        'Quản lý các dự án',
        TRUE
    );
/* ===================================================== */
/* 5. employees - Nhân viên (20 người)                    */
/* ===================================================== */
INSERT INTO employees (
        employee_code,
        full_name,
        date_of_birth,
        gender,
        place_of_birth,
        ethnicity,
        religion,
        marital_status,
        phone_number,
        personal_email,
        company_email,
        permanent_address,
        current_address,
        nationality_id,
        avatar_url,
        bank_account,
        bank_id,
        bank_branch,
        emergency_contact_name,
        emergency_contact_phone,
        emergency_contact_relation,
        status,
        hire_date,
        seniority_start_date,
        base_leave_days
    )
VALUES (
        'NV0001',
        'Nguyễn Văn An',
        '1990-05-15',
        'NAM',
        'Hà Nội',
        'Kinh',
        'Không',
        'ĐÃ_KẾT_HÔN',
        '0912345678',
        'an.nguyen@gmail.com',
        'an.nguyen@company.com',
        'Số 1, Đường A, Q.1, TP.HCM',
        'Số 10, Đường B, Q.2, TP.HCM',
        1,
        NULL,
        '123456789',
        1,
        'Chi nhánh Hà Nội',
        'Trần Thị Bình',
        '0987654321',
        'Vợ',
        'ĐANG_LÀM_VIỆC',
        '2015-06-01',
        '2015-06-01',
        12.00
    ),
    (
        'NV0002',
        'Trần Thị Mai',
        '1992-08-20',
        'NỮ',
        'Hải Phòng',
        'Kinh',
        'Phật giáo',
        'ĐỘC_THÂN',
        '0923456789',
        'mai.tran@gmail.com',
        'mai.tran@company.com',
        'Số 2, Đường C, Hải Phòng',
        'Số 20, Đường D, Q.3, TP.HCM',
        1,
        NULL,
        '234567891',
        2,
        'Chi nhánh Hải Phòng',
        'Nguyễn Văn Hùng',
        '0976543210',
        'Bố',
        'ĐANG_LÀM_VIỆC',
        '2016-03-15',
        '2016-03-15',
        12.00
    ),
    (
        'NV0003',
        'Lê Văn Cường',
        '1988-11-10',
        'NAM',
        'Đà Nẵng',
        'Kinh',
        'Thiên chúa giáo',
        'ĐÃ_KẾT_HÔN',
        '0934567891',
        'cuong.le@gmail.com',
        'cuong.le@company.com',
        'Số 3, Đường E, Đà Nẵng',
        'Số 30, Đường F, Q.4, TP.HCM',
        1,
        NULL,
        '345678912',
        3,
        'Chi nhánh Đà Nẵng',
        'Phạm Thị Lan',
        '0965432109',
        'Vợ',
        'ĐANG_LÀM_VIỆC',
        '2014-09-01',
        '2014-09-01',
        12.00
    ),
    (
        'NV0004',
        'Phạm Thị Hương',
        '1991-02-28',
        'NỮ',
        'Cần Thơ',
        'Kinh',
        'Không',
        'ĐÃ_KẾT_HÔN',
        '0945678901',
        'huong.pham@gmail.com',
        'huong.pham@company.com',
        'Số 4, Đường G, Cần Thơ',
        'Số 40, Đường H, Q.5, TP.HCM',
        1,
        NULL,
        '456789123',
        4,
        'Chi nhánh Cần Thơ',
        'Lê Văn Hải',
        '0954321098',
        'Chồng',
        'ĐANG_LÀM_VIỆC',
        '2015-11-01',
        '2015-11-01',
        12.00
    ),
    (
        'NV0005',
        'Hoàng Văn Đức',
        '1989-07-12',
        'NAM',
        'Huế',
        'Kinh',
        'Phật giáo',
        'ĐÃ_KẾT_HÔN',
        '0956789012',
        'duc.hoang@gmail.com',
        'duc.hoang@company.com',
        'Số 5, Đường I, Huế',
        'Số 50, Đường K, Q.6, TP.HCM',
        1,
        NULL,
        '567891234',
        5,
        'Chi nhánh Huế',
        'Hoàng Thị Hoa',
        '0943210987',
        'Mẹ',
        'ĐANG_LÀM_VIỆC',
        '2013-04-01',
        '2013-04-01',
        12.00
    ),
    (
        'NV0006',
        'Đặng Thị Ngọc',
        '1993-12-05',
        'NỮ',
        'Nha Trang',
        'Kinh',
        'Không',
        'ĐÃ_KẾT_HÔN',
        '0967890123',
        'ngoc.dang@gmail.com',
        'ngoc.dang@company.com',
        'Số 6, Đường L, Nha Trang',
        'Số 60, Đường M, Q.7, TP.HCM',
        1,
        NULL,
        '678912345',
        6,
        'Chi nhánh Nha Trang',
        'Đặng Văn Thành',
        '0932109876',
        'Bố',
        'ĐANG_LÀM_VIỆC',
        '2016-07-01',
        '2016-07-01',
        12.00
    ),
    (
        'NV0007',
        'Bùi Văn Quân',
        '1987-09-18',
        'NAM',
        'Quảng Ninh',
        'Kinh',
        'Phật giáo',
        'ĐÃ_KẾT_HÔN',
        '0978901234',
        'quan.bui@gmail.com',
        'quan.bui@company.com',
        'Số 7, Đường N, Quảng Ninh',
        'Số 70, Đường P, Q.8, TP.HCM',
        1,
        NULL,
        '789123456',
        7,
        'Chi nhánh Quảng Ninh',
        'Bùi Thị Thanh',
        '0921098765',
        'Vợ',
        'ĐANG_LÀM_VIỆC',
        '2012-10-01',
        '2012-10-01',
        12.00
    ),
    (
        'NV0008',
        'Vũ Thị Lan',
        '1994-04-22',
        'NỮ',
        'Bắc Ninh',
        'Kinh',
        'Không',
        'ĐỘC_THÂN',
        '0989012345',
        'lan.vu@gmail.com',
        'lan.vu@company.com',
        'Số 8, Đường Q, Bắc Ninh',
        'Số 80, Đường R, Q.9, TP.HCM',
        1,
        NULL,
        '891234567',
        8,
        'Chi nhánh Bắc Ninh',
        'Vũ Văn Toàn',
        '0910987654',
        'Anh trai',
        'ĐANG_LÀM_VIỆC',
        '2017-02-01',
        '2017-02-01',
        12.00
    ),
    (
        'NV0009',
        'Đỗ Văn Hải',
        '1986-06-30',
        'NAM',
        'Hà Nam',
        'Kinh',
        'Thiên chúa giáo',
        'ĐÃ_KẾT_HÔN',
        '0990123456',
        'hai.do@gmail.com',
        'hai.do@company.com',
        'Số 9, Đường S, Hà Nam',
        'Số 90, Đường T, Q.10, TP.HCM',
        1,
        NULL,
        '912345678',
        9,
        'Chi nhánh Hà Nam',
        'Đỗ Thị Hằng',
        '0909876543',
        'Vợ',
        'ĐANG_LÀM_VIỆC',
        '2011-05-01',
        '2011-05-01',
        12.00
    ),
    (
        'NV0010',
        'Lý Thị Hoa',
        '1995-10-15',
        'NỮ',
        'Lạng Sơn',
        'Tày',
        'Phật giáo',
        'ĐỘC_THÂN',
        '0901234567',
        'hoa.ly@gmail.com',
        'hoa.ly@company.com',
        'Số 10, Đường U, Lạng Sơn',
        'Số 100, Đường V, Q.11, TP.HCM',
        1,
        NULL,
        '123456780',
        10,
        'Chi nhánh Lạng Sơn',
        'Lý Văn Cường',
        '0898765432',
        'Bố',
        'ĐANG_LÀM_VIỆC',
        '2018-08-01',
        '2018-08-01',
        12.00
    );
/* ===================================================== */
/* 6. Cập nhật manager_id cho departments sau khi có employees */
/* ===================================================== */
UPDATE departments
SET manager_id = 1
WHERE department_code = 'HCNS';
UPDATE departments
SET manager_id = 3
WHERE department_code = 'KD';
UPDATE departments
SET manager_id = 5
WHERE department_code = 'IT';
UPDATE departments
SET manager_id = 9
WHERE department_code = 'HCNS';
-- Giám đốc
/* ===================================================== */
/* 7. contract_types - Loại hợp đồng                      */
/* ===================================================== */
INSERT INTO contract_types (
        contract_type_code,
        contract_type_name,
        description,
        is_probation,
        max_duration_months,
        status
    )
VALUES (
        'HDLD01',
        'Hợp đồng lao động không xác định thời hạn',
        'Hợp đồng dài hạn, không giới hạn thời gian',
        FALSE,
        NULL,
        TRUE
    ),
    (
        'HDLD02',
        'Hợp đồng lao động xác định thời hạn 12 tháng',
        'Hợp đồng có thời hạn 1 năm',
        FALSE,
        12,
        TRUE
    ),
    (
        'HDLD03',
        'Hợp đồng lao động xác định thời hạn 24 tháng',
        'Hợp đồng có thời hạn 2 năm',
        FALSE,
        24,
        TRUE
    ),
    (
        'HDTV',
        'Hợp đồng thử việc',
        'Hợp đồng thử việc 2 tháng',
        TRUE,
        2,
        TRUE
    );
/* ===================================================== */
/* 8. contract_templates - Mẫu hợp đồng                   */
/* ===================================================== */
INSERT INTO contract_templates (
        template_code,
        template_name,
        contract_type_id,
        content,
        version,
        is_active,
        file_url,
        effective_date
    )
VALUES (
        'MT_HDLD01_V1',
        'Mẫu hợp đồng không xác định thời hạn v1',
        1,
        'Nội dung mẫu hợp đồng không xác định thời hạn...',
        '1.0',
        TRUE,
        '/templates/contracts/hdld01_v1.docx',
        '2024-01-01'
    ),
    (
        'MT_HDLD02_V1',
        'Mẫu hợp đồng 12 tháng v1',
        2,
        'Nội dung mẫu hợp đồng 12 tháng...',
        '1.0',
        TRUE,
        '/templates/contracts/hdld02_v1.docx',
        '2024-01-01'
    ),
    (
        'MT_HDTV_V1',
        'Mẫu hợp đồng thử việc v1',
        4,
        'Nội dung mẫu hợp đồng thử việc 2 tháng...',
        '1.0',
        TRUE,
        '/templates/contracts/hdtv_v1.docx',
        '2024-01-01'
    );
/* ===================================================== */
/* 9. contracts - Hợp đồng lao động                       */
/* ===================================================== */
INSERT INTO contracts (
        contract_code,
        employee_id,
        contract_type_id,
        contract_number,
        sign_date,
        effective_date,
        expiry_date,
        position_id,
        department_id,
        basic_salary,
        gross_salary,
        net_salary,
        work_location,
        job_title,
        content,
        file_url,
        signed_file_url,
        contract_template_id,
        status,
        is_renewed
    )
VALUES (
        'HD0001',
        1,
        1,
        'HD/2024/001',
        '2024-01-15',
        '2024-02-01',
        NULL,
        3,
        1,
        25000000,
        35000000,
        30000000,
        'Hà Nội',
        'Trưởng phòng Nhân sự',
        'Nội dung hợp đồng...',
        '/files/contracts/hd0001.pdf',
        '/files/contracts/hd0001_signed.pdf',
        1,
        'CÓ_HIỆU_LỰC',
        FALSE
    ),
    (
        'HD0002',
        2,
        2,
        'HD/2024/002',
        '2024-01-20',
        '2024-02-01',
        '2025-01-31',
        5,
        2,
        18000000,
        25000000,
        22000000,
        'Hồ Chí Minh',
        'Kế toán viên',
        'Nội dung hợp đồng...',
        '/files/contracts/hd0002.pdf',
        '/files/contracts/hd0002_signed.pdf',
        2,
        'CÓ_HIỆU_LỰC',
        FALSE
    ),
    (
        'HD0003',
        3,
        1,
        'HD/2024/003',
        '2024-02-05',
        '2024-02-15',
        NULL,
        3,
        3,
        30000000,
        42000000,
        37000000,
        'Đà Nẵng',
        'Trưởng phòng Kinh doanh',
        'Nội dung hợp đồng...',
        '/files/contracts/hd0003.pdf',
        '/files/contracts/hd0003_signed.pdf',
        1,
        'CÓ_HIỆU_LỰC',
        FALSE
    ),
    (
        'HD0004',
        4,
        2,
        'HD/2024/004',
        '2024-02-10',
        '2024-03-01',
        '2025-02-28',
        6,
        4,
        15000000,
        20000000,
        17500000,
        'Hồ Chí Minh',
        'Lập trình viên',
        'Nội dung hợp đồng...',
        '/files/contracts/hd0004.pdf',
        '/files/contracts/hd0004_signed.pdf',
        2,
        'CÓ_HIỆU_LỰC',
        FALSE
    ),
    (
        'HD0005',
        5,
        3,
        'HD/2024/005',
        '2024-02-15',
        '2024-03-01',
        '2026-02-28',
        2,
        1,
        40000000,
        55000000,
        48000000,
        'Hà Nội',
        'Phó Giám đốc',
        'Nội dung hợp đồng...',
        '/files/contracts/hd0005.pdf',
        '/files/contracts/hd0005_signed.pdf',
        1,
        'CÓ_HIỆU_LỰC',
        FALSE
    );
/* ===================================================== */
/* 10. contract_histories - Lịch sử hợp đồng               */
/* ===================================================== */
INSERT INTO contract_histories (
        contract_id,
        action,
        action_by,
        previous_value,
        new_value,
        notes
    )
VALUES (
        1,
        'TẠO',
        1,
        NULL,
        'Tạo hợp đồng mới',
        'Hợp đồng chính thức'
    ),
    (
        2,
        'TẠO',
        1,
        NULL,
        'Tạo hợp đồng mới',
        'Hợp đồng 12 tháng'
    ),
    (
        3,
        'TẠO',
        1,
        NULL,
        'Tạo hợp đồng mới',
        'Hợp đồng chính thức'
    ),
    (
        4,
        'TẠO',
        1,
        NULL,
        'Tạo hợp đồng mới',
        'Hợp đồng 12 tháng'
    ),
    (
        5,
        'TẠO',
        1,
        NULL,
        'Tạo hợp đồng mới',
        'Hợp đồng 24 tháng'
    );
/* ===================================================== */
/* 11. qualification_types - Loại bằng cấp                */
/* ===================================================== */
INSERT INTO qualification_types (
        qualification_type_code,
        qualification_type_name,
        description
    )
VALUES ('DH', 'Đại học', 'Bằng tốt nghiệp đại học'),
    ('THS', 'Thạc sĩ', 'Bằng thạc sĩ'),
    ('CD', 'Cao đẳng', 'Bằng tốt nghiệp cao đẳng');
/* ===================================================== */
/* 12. qualifications - Bằng cấp                          */
/* ===================================================== */
INSERT INTO qualifications (
        employee_id,
        qualification_type_id,
        qualification_name,
        major,
        school_name,
        graduation_year,
        graduation_grade,
        issued_date,
        issued_by,
        qualification_number,
        file_url,
        is_highest
    )
VALUES (
        1,
        1,
        'Cử nhân Quản trị Nhân lực',
        'Quản trị nhân sự',
        'Đại học Kinh tế Quốc dân',
        2012,
        'Giỏi',
        '2012-06-15',
        'ĐH Kinh tế Quốc dân',
        'QTNL2012-001',
        NULL,
        TRUE
    ),
    (
        2,
        1,
        'Cử nhân Kế toán',
        'Kế toán doanh nghiệp',
        'Đại học Tài chính - Marketing',
        2014,
        'Khá',
        '2014-08-20',
        'ĐH Tài chính Marketing',
        'KT2014-123',
        NULL,
        TRUE
    ),
    (
        3,
        2,
        'Thạc sĩ Quản trị Kinh doanh',
        'Quản trị chiến lược',
        'Đại học Ngoại thương',
        2016,
        'Giỏi',
        '2016-03-10',
        'ĐH Ngoại thương',
        'MBA2016-045',
        NULL,
        TRUE
    ),
    (
        4,
        1,
        'Cử nhân Công nghệ thông tin',
        'Kỹ thuật phần mềm',
        'Đại học Bách khoa TP.HCM',
        2015,
        'Khá',
        '2015-09-05',
        'ĐH Bách khoa',
        'CNTT2015-789',
        NULL,
        TRUE
    ),
    (
        5,
        2,
        'Thạc sĩ Quản lý Kinh tế',
        'Quản lý dự án',
        'Đại học Kinh tế TP.HCM',
        2014,
        'Giỏi',
        '2014-12-20',
        'ĐH Kinh tế',
        'QLKT2014-234',
        NULL,
        TRUE
    );
/* ===================================================== */
/* 13. certificate_types - Loại chứng chỉ                 */
/* ===================================================== */
INSERT INTO certificate_types (
        certificate_type_code,
        certificate_type_name,
        description
    )
VALUES ('TOEIC', 'TOEIC', 'Chứng chỉ tiếng Anh TOEIC'),
    ('IELTS', 'IELTS', 'Chứng chỉ tiếng Anh IELTS'),
    ('MOS', 'MOS', 'Chứng chỉ tin học văn phòng');
/* ===================================================== */
/* 14. certificates - Chứng chỉ                          */
/* ===================================================== */
INSERT INTO certificates (
        employee_id,
        certificate_type_id,
        certificate_name,
        issued_by,
        issued_date,
        expiry_date,
        certificate_number,
        score,
        file_url
    )
VALUES (
        1,
        1,
        'TOEIC 850',
        'IIG Vietnam',
        '2023-05-10',
        '2025-05-10',
        'TOEIC850-001',
        850.00,
        NULL
    ),
    (
        2,
        3,
        'MOS Excel Expert',
        'Microsoft',
        '2023-08-15',
        '2026-08-15',
        'MOS-EX-12345',
        950.00,
        NULL
    ),
    (
        3,
        2,
        'IELTS 7.5',
        'British Council',
        '2023-02-20',
        '2025-02-20',
        'IELTS-7.5-6789',
        7.50,
        NULL
    );
/* ===================================================== */
/* 15. document_types - Loại giấy tờ                      */
/* ===================================================== */
INSERT INTO document_types (
        document_type_code,
        document_type_name,
        description
    )
VALUES ('CMND', 'Chứng minh nhân dân', 'CMND 9 số'),
    ('CCCD', 'Căn cước công dân', 'CCCD 12 số'),
    ('PASSPORT', 'Hộ chiếu', 'Passport Việt Nam');
/* ===================================================== */
/* 16. identity_documents - CMND/CCCD                     */
/* ===================================================== */
INSERT INTO identity_documents (
        employee_id,
        document_type_id,
        document_number,
        full_name,
        date_of_birth,
        issue_date,
        issue_place,
        expiry_date,
        front_image_url,
        back_image_url,
        has_chip
    )
VALUES (
        1,
        2,
        '001089012345',
        'Nguyễn Văn An',
        '1990-05-15',
        '2018-06-10',
        'Cục CSĐK Cư trú',
        '2038-06-10',
        NULL,
        NULL,
        TRUE
    ),
    (
        2,
        1,
        '023456789012',
        'Trần Thị Mai',
        '1992-08-20',
        '2015-09-15',
        'CA Hải Phòng',
        '2025-09-15',
        NULL,
        NULL,
        FALSE
    ),
    (
        3,
        2,
        '034567890123',
        'Lê Văn Cường',
        '1988-11-10',
        '2019-03-20',
        'Cục CSĐK Cư trú',
        '2039-03-20',
        NULL,
        NULL,
        TRUE
    ),
    (
        4,
        2,
        '045678901234',
        'Phạm Thị Hương',
        '1991-02-28',
        '2020-01-15',
        'Cục CSĐK Cư trú',
        '2040-01-15',
        NULL,
        NULL,
        TRUE
    ),
    (
        5,
        1,
        '056789012345',
        'Hoàng Văn Đức',
        '1989-07-12',
        '2016-05-22',
        'CA Thừa Thiên Huế',
        '2026-05-22',
        NULL,
        NULL,
        FALSE
    );
/* ===================================================== */
/* 17. social_insurance_info - Thông tin BHXH             */
/* ===================================================== */
INSERT INTO social_insurance_info (
        employee_id,
        social_insurance_number,
        health_insurance_number,
        tax_code,
        issue_date,
        issue_place,
        status
    )
VALUES (
        1,
        'BHXH-0001-1234567',
        'BHYT-0001-123456789',
        '1234567890',
        '2015-06-01',
        'BHXH Hà Nội',
        'ACTIVE'
    ),
    (
        2,
        'BHXH-0002-2345678',
        'BHYT-0002-234567890',
        '2345678901',
        '2016-03-15',
        'BHXH Hải Phòng',
        'ACTIVE'
    ),
    (
        3,
        'BHXH-0003-3456789',
        'BHYT-0003-345678901',
        '3456789012',
        '2014-09-01',
        'BHXH Đà Nẵng',
        'ACTIVE'
    ),
    (
        4,
        'BHXH-0004-4567890',
        'BHYT-0004-456789012',
        '4567890123',
        '2015-11-01',
        'BHXH Cần Thơ',
        'ACTIVE'
    ),
    (
        5,
        'BHXH-0005-5678901',
        'BHYT-0005-567890123',
        '5678901234',
        '2013-04-01',
        'BHXH Huế',
        'ACTIVE'
    );
/* ===================================================== */
/* 18. dependents - Người phụ thuộc                       */
/* ===================================================== */
INSERT INTO dependents (
        employee_id,
        full_name,
        relationship,
        date_of_birth,
        id_card_number,
        tax_code,
        deduction_percent,
        start_date,
        end_date,
        status
    )
VALUES (
        1,
        'Nguyễn Văn Hùng',
        'Con',
        '2018-03-12',
        '025678912345',
        NULL,
        100.00,
        '2018-03-12',
        NULL,
        TRUE
    ),
    (
        1,
        'Trần Thị Lan',
        'Vợ',
        '1992-06-20',
        '025678912346',
        '1234567891',
        100.00,
        '2015-12-01',
        NULL,
        TRUE
    ),
    (
        3,
        'Lê Thị Hoa',
        'Vợ',
        '1990-04-15',
        '036789012345',
        NULL,
        100.00,
        '2015-09-01',
        NULL,
        TRUE
    ),
    (
        3,
        'Lê Văn Bình',
        'Con',
        '2017-08-25',
        '036789012346',
        NULL,
        100.00,
        '2017-08-25',
        NULL,
        TRUE
    ),
    (
        5,
        'Hoàng Thị Thảo',
        'Mẹ',
        '1965-11-30',
        '047890123456',
        '5678901235',
        100.00,
        '2013-04-01',
        NULL,
        TRUE
    );
/* ===================================================== */
/* 19. employment_histories - Lịch sử công tác            */
/* ===================================================== */
INSERT INTO employment_histories (
        employee_id,
        department_id,
        position_id,
        start_date,
        end_date,
        is_current,
        decision_number,
        decision_date,
        notes
    )
VALUES (
        1,
        1,
        5,
        '2015-06-01',
        '2018-05-31',
        FALSE,
        'QĐ-2015-001',
        '2015-06-01',
        'Nhân viên nhân sự'
    ),
    (
        1,
        1,
        3,
        '2018-06-01',
        NULL,
        TRUE,
        'QĐ-2018-023',
        '2018-05-25',
        'Trưởng phòng Nhân sự'
    ),
    (
        2,
        2,
        5,
        '2016-03-15',
        '2019-02-28',
        FALSE,
        'QĐ-2016-015',
        '2016-03-10',
        'Kế toán viên'
    ),
    (
        2,
        2,
        4,
        '2019-03-01',
        NULL,
        TRUE,
        'QĐ-2019-045',
        '2019-02-20',
        'Phó phòng Kế toán'
    ),
    (
        3,
        3,
        6,
        '2014-09-01',
        '2016-08-31',
        FALSE,
        'QĐ-2014-032',
        '2014-08-25',
        'Nhân viên kinh doanh'
    ),
    (
        3,
        3,
        5,
        '2016-09-01',
        '2019-08-31',
        FALSE,
        'QĐ-2016-078',
        '2016-08-20',
        'Chuyên viên kinh doanh'
    ),
    (
        3,
        3,
        3,
        '2019-09-01',
        NULL,
        TRUE,
        'QĐ-2019-112',
        '2019-08-25',
        'Trưởng phòng Kinh doanh'
    ),
    (
        4,
        4,
        6,
        '2015-11-01',
        '2018-10-31',
        FALSE,
        'QĐ-2015-089',
        '2015-10-25',
        'Lập trình viên'
    ),
    (
        4,
        4,
        5,
        '2018-11-01',
        '2021-10-31',
        FALSE,
        'QĐ-2018-156',
        '2018-10-20',
        'Chuyên viên IT'
    ),
    (
        4,
        4,
        4,
        '2021-11-01',
        NULL,
        TRUE,
        'QĐ-2021-203',
        '2021-10-25',
        'Phó phòng IT'
    );
/* ===================================================== */
/* 20. leave_types - Loại nghỉ phép                       */
/* ===================================================== */
INSERT IGNORE INTO leave_types (
        leave_type_code,
        leave_type_name,
        category,
        is_paid,
        is_social_insurance,
        payment_source,
        max_days_per_year,
        min_days_per_request,
        max_days_per_request,
        requires_document,
        document_required,
        can_carry_forward,
        carry_forward_limit,
        carry_forward_expiry_months,
        seniority_applicable,
        description,
        status
    )
VALUES (
        'PHEP_NAM',
        'Nghỉ phép năm',
        'ANNUAL',
        TRUE,
        FALSE,
        'COMPANY',
        12,
        0.5,
        30,
        FALSE,
        NULL,
        TRUE,
        5,
        3,
        TRUE,
        'Nghỉ phép năm cơ bản',
        TRUE
    ),
    (
        'OM_DAU',
        'Nghỉ ốm đau',
        'SICK',
        TRUE,
        TRUE,
        'SOCIAL_INSURANCE',
        30,
        0.5,
        30,
        TRUE,
        'Giấy khám bệnh',
        FALSE,
        0,
        0,
        FALSE,
        'Nghỉ ốm hưởng BHXH',
        TRUE
    ),
    (
        'KHONG_LUONG',
        'Nghỉ không lương',
        'UNPAID',
        FALSE,
        FALSE,
        'COMPANY',
        30,
        0.5,
        30,
        FALSE,
        NULL,
        FALSE,
        0,
        0,
        FALSE,
        'Nghỉ không hưởng lương',
        TRUE
    );
/* ===================================================== */
/* 20A. holidays - Ngày lễ/tết Việt Nam                  */
/* ===================================================== */
INSERT IGNORE INTO holidays (
        holiday_name,
        holiday_date,
        holiday_type,
        is_recurring,
        year,
        paid_holiday,
        salary_multiplier,
        allowance_amount,
        description
    )
VALUES (
        'Tết Dương lịch',
        '2024-01-01',
        'NEW_YEAR',
        TRUE,
        2024,
        TRUE,
        3.00,
        300000,
        'Nghỉ lễ theo quy định nhà nước'
    ),
    (
        'Tết Âm lịch - Mùng 1',
        '2024-02-10',
        'LUNAR_NEW_YEAR',
        FALSE,
        2024,
        TRUE,
        3.00,
        500000,
        'Đãi ngộ tết âm lịch'
    ),
    (
        'Tết Âm lịch - Mùng 2',
        '2024-02-11',
        'LUNAR_NEW_YEAR',
        FALSE,
        2024,
        TRUE,
        3.00,
        500000,
        'Đãi ngộ tết âm lịch'
    ),
    (
        'Tết Âm lịch - Mùng 3',
        '2024-02-12',
        'LUNAR_NEW_YEAR',
        FALSE,
        2024,
        TRUE,
        3.00,
        500000,
        'Đãi ngộ tết âm lịch'
    ),
    (
        'Giỗ Tổ Hùng Vương',
        '2024-04-18',
        'HUNG_KINGS',
        FALSE,
        2024,
        TRUE,
        3.00,
        300000,
        'Ngày lễ quốc gia'
    ),
    (
        'Ngày Giải phóng miền Nam',
        '2024-04-30',
        'LIBERATION_DAY',
        TRUE,
        2024,
        TRUE,
        3.00,
        300000,
        'Lễ 30/4'
    ),
    (
        'Quốc tế Lao động',
        '2024-05-01',
        'LABOR_DAY',
        TRUE,
        2024,
        TRUE,
        3.00,
        300000,
        'Lễ 1/5'
    ),
    (
        'Quốc khánh',
        '2024-09-02',
        'NATIONAL_DAY',
        TRUE,
        2024,
        TRUE,
        3.00,
        300000,
        'Lễ Quốc khánh'
    );
/* ===================================================== */
/* 21. leave_balances - Quỹ phép năm                      */
/* ===================================================== */
INSERT INTO leave_balances (
        employee_id,
        leave_type_id,
        year,
        base_leave,
        seniority_bonus,
        total_days,
        carried_over_days,
        carried_over_source,
        used_days,
        pending_days,
        carry_over_expiry_date,
        notes
    )
VALUES (
        1,
        1,
        2024,
        12.00,
        2.00,
        14.00,
        3.00,
        '2023',
        2.0,
        0.00,
        '2024-03-31',
        'Phép năm 2024'
    ),
    (
        2,
        1,
        2024,
        12.00,
        1.00,
        13.00,
        2.00,
        '2023',
        1.0,
        0.00,
        '2024-03-31',
        'Đã dùng 1 ngày'
    ),
    (
        3,
        1,
        2024,
        12.00,
        2.00,
        14.00,
        0.00,
        NULL,
        0.0,
        0.00,
        NULL,
        'Chưa dùng'
    ),
    (
        4,
        1,
        2024,
        12.00,
        1.00,
        13.00,
        0.00,
        NULL,
        0.0,
        2.0,
        NULL,
        'Đang chờ duyệt 2 ngày'
    ),
    (
        5,
        1,
        2024,
        12.00,
        3.00,
        15.00,
        5.00,
        '2023',
        0.0,
        0.00,
        '2024-03-31',
        'Còn 20 ngày'
    );
/* ===================================================== */
/* 22. approval_roles - Vai trò phê duyệt                  */
/* ===================================================== */
INSERT INTO approval_roles (role_code, role_name, description)
VALUES (
        'QLTT',
        'Quản lý trực tiếp',
        'Quản lý trực tiếp của nhân viên'
    ),
    ('TP', 'Trưởng phòng', 'Trưởng phòng ban'),
    ('GD', 'Giám đốc', 'Giám đốc công ty'),
    ('HR', 'Nhân sự', 'Bộ phận nhân sự'),
    ('KT', 'Kế toán', 'Bộ phận kế toán');
/* ===================================================== */
/* 23. request_types - Loại đơn từ                        */
/* ===================================================== */
INSERT INTO request_types (
        request_type_code,
        request_type_name,
        category,
        requires_approval,
        description,
        is_active
    )
VALUES (
        'NP',
        'Đơn xin nghỉ phép',
        'NGHỈ_PHÉP',
        TRUE,
        'Đơn xin nghỉ phép năm',
        TRUE
    ),
    (
        'GP',
        'Đơn xin gộp phép',
        'GỘP_PHÉP',
        TRUE,
        'Đơn xin gộp phép tồn sang năm sau',
        TRUE
    ),
    (
        'TC',
        'Đơn đăng ký tăng ca',
        'TĂNG_CA',
        TRUE,
        'Đơn đăng ký làm thêm giờ',
        TRUE
    ),
    (
        'CT',
        'Đơn đăng ký công tác',
        'CÔNG_TÁC',
        TRUE,
        'Đơn đăng ký đi công tác',
        TRUE
    ),
    (
        'TUL',
        'Đơn tạm ứng lương',
        'TẠM_ỨNG_LƯƠNG',
        TRUE,
        'Đơn xin tạm ứng lương',
        TRUE
    );
/* ===================================================== */
/* 24. approval_flows - Quy trình phê duyệt                */
/* ===================================================== */
INSERT INTO approval_flows (
        flow_name,
        request_type_id,
        description,
        is_active
    )
VALUES (
        'Quy trình duyệt nghỉ phép',
        1,
        'Quy trình duyệt đơn xin nghỉ phép',
        TRUE
    ),
    (
        'Quy trình duyệt gộp phép',
        2,
        'Quy trình duyệt đơn xin gộp phép',
        TRUE
    ),
    (
        'Quy trình duyệt tăng ca',
        3,
        'Quy trình duyệt đơn đăng ký tăng ca',
        TRUE
    );
/* ===================================================== */
/* 25. approval_steps - Các bước phê duyệt                 */
/* ===================================================== */
INSERT INTO approval_steps (
        approval_flow_id,
        step_order,
        step_name,
        approver_role_id,
        approver_user_id,
        can_reject,
        can_add_comment,
        days_to_approve
    )
VALUES (
        1,
        1,
        'Quản lý trực tiếp duyệt',
        1,
        NULL,
        TRUE,
        TRUE,
        2
    ),
    (
        1,
        2,
        'Trưởng phòng duyệt',
        2,
        NULL,
        TRUE,
        TRUE,
        2
    ),
    (1, 3, 'Nhân sự xác nhận', 4, NULL, TRUE, TRUE, 1),
    (
        2,
        1,
        'Quản lý trực tiếp duyệt',
        1,
        NULL,
        TRUE,
        TRUE,
        3
    ),
    (
        2,
        2,
        'Trưởng phòng duyệt',
        2,
        NULL,
        TRUE,
        TRUE,
        2
    ),
    (2, 3, 'Nhân sự xác nhận', 4, NULL, TRUE, TRUE, 2),
    (
        3,
        1,
        'Quản lý trực tiếp duyệt',
        1,
        NULL,
        TRUE,
        TRUE,
        1
    ),
    (
        3,
        2,
        'Trưởng phòng duyệt',
        2,
        NULL,
        TRUE,
        TRUE,
        1
    );
/* ===================================================== */
/* 26. requests - Đơn từ tổng hợp                         */
/* ===================================================== */
INSERT INTO requests (
        request_code,
        request_type_id,
        requester_id,
        request_date,
        from_date,
        to_date,
        duration,
        reason,
        status,
        current_step_id,
        is_urgent,
        notes
    )
VALUES (
        'NP-2024-001',
        1,
        1,
        '2024-03-01',
        '2024-03-10 08:00:00',
        '2024-03-12 17:00:00',
        2.5,
        'Nghỉ phép đi du lịch Đà Lạt',
        'ĐÃ_DUYỆT',
        3,
        FALSE,
        'Đã được duyệt'
    ),
    (
        'NP-2024-002',
        1,
        2,
        '2024-03-02',
        '2024-03-20 08:00:00',
        '2024-03-20 17:00:00',
        1.0,
        'Nghỉ cưới em gái',
        'ĐÃ_DUYỆT',
        3,
        FALSE,
        'Đã duyệt'
    ),
    (
        'NP-2024-003',
        1,
        3,
        '2024-03-03',
        '2024-03-25 08:00:00',
        '2024-03-25 12:00:00',
        0.5,
        'Khám sức khỏe định kỳ',
        'CHỜ_DUYỆT',
        1,
        FALSE,
        'Gửi đơn'
    ),
    (
        'TC-2024-001',
        3,
        1,
        '2024-03-01',
        '2024-03-05 18:00:00',
        '2024-03-05 21:00:00',
        3.0,
        'Làm báo cáo cuối quý',
        'ĐÃ_DUYỆT',
        2,
        FALSE,
        'Đã duyệt'
    ),
    (
        'TUL-2024-001',
        5,
        1,
        '2024-02-20',
        NULL,
        NULL,
        10000000,
        'Tạm ứng tiền chữa bệnh cho mẹ',
        'ĐÃ_DUYỆT',
        3,
        TRUE,
        'Đã duyệt'
    );
/* ===================================================== */
/* 27. approval_histories - Lịch sử phê duyệt             */
/* ===================================================== */
INSERT INTO approval_histories (
        request_id,
        step_id,
        approver_id,
        action,
        comment,
        action_date
    )
VALUES (
        1,
        1,
        3,
        'DUYỆT',
        'Đồng ý cho nghỉ',
        '2024-03-01 10:30:00'
    ),
    (1, 2, 3, 'DUYỆT', 'OK', '2024-03-01 14:20:00'),
    (
        1,
        3,
        2,
        'DUYỆT',
        'Đã xác nhận',
        '2024-03-02 09:15:00'
    ),
    (
        2,
        1,
        3,
        'DUYỆT',
        'Đồng ý',
        '2024-03-02 11:00:00'
    ),
    (
        2,
        2,
        3,
        'DUYỆT',
        'Chúc em hạnh phúc',
        '2024-03-02 15:30:00'
    ),
    (2, 3, 2, 'DUYỆT', 'OK', '2024-03-03 08:45:00');
/* ===================================================== */
/* 28. leave_requests - Chi tiết nghỉ phép                */
/* ===================================================== */
INSERT INTO leave_requests (
        request_id,
        leave_type_id,
        employee_id,
        from_date,
        to_date,
        from_session,
        to_session,
        number_of_days,
        leave_used_type,
        base_days_used,
        seniority_days_used,
        carried_over_days_used,
        paid_days,
        unpaid_days,
        substitute_employee_id,
        handover_notes,
        contact_phone,
        emergency_contact,
        attachment_url
    )
VALUES (
        1,
        1,
        1,
        '2024-03-10',
        '2024-03-12',
        'CẢ_NGÀY',
        'CẢ_NGÀY',
        2.5,
        'BASE',
        2.5,
        0,
        0,
        2.5,
        0,
        4,
        'Đã bàn giao công việc cho Phạm Thị Hương',
        '0912345678',
        'Trần Thị Bình - 0987654321',
        NULL
    ),
    (
        2,
        1,
        2,
        '2024-03-20',
        '2024-03-20',
        'CẢ_NGÀY',
        'CẢ_NGÀY',
        1.0,
        'BASE',
        1.0,
        0,
        0,
        1.0,
        0,
        5,
        'Đã bàn giao cho Hoàng Văn Đức',
        '0923456789',
        'Nguyễn Văn Hùng - 0976543210',
        NULL
    ),
    (
        3,
        1,
        3,
        '2024-03-25',
        '2024-03-25',
        'SÁNG',
        'SÁNG',
        0.5,
        'BASE',
        0.5,
        0,
        0,
        0.5,
        0,
        4,
        'Đã bàn giao',
        '0934567891',
        'Phạm Thị Lan - 0965432109',
        NULL
    );
/* ===================================================== */
/* 29. leave_transactions - Biến động phép                 */
/* ===================================================== */
INSERT INTO leave_transactions (
        employee_id,
        leave_type_id,
        transaction_date,
        transaction_type,
        quantity,
        before_balance,
        after_balance,
        reference_id,
        reference_type,
        reason
    )
VALUES (
        1,
        1,
        '2024-01-01',
        'CẤP_PHÉP',
        14.0,
        0,
        14.0,
        1,
        'ANNUAL_GRANT',
        'Cấp phép năm 2024'
    ),
    (
        1,
        1,
        '2024-01-02',
        'CHUYỂN_NĂM',
        3.0,
        14.0,
        17.0,
        1,
        'CARRY_OVER',
        'Chuyển phép từ 2023'
    ),
    (
        1,
        1,
        '2024-03-10',
        'SỬ_DỤNG',
        -2.5,
        17.0,
        14.5,
        1,
        'LEAVE_REQUEST',
        'Nghỉ phép tháng 3'
    ),
    (
        2,
        1,
        '2024-01-01',
        'CẤP_PHÉP',
        13.0,
        0,
        13.0,
        2,
        'ANNUAL_GRANT',
        'Cấp phép năm 2024'
    ),
    (
        2,
        1,
        '2024-01-02',
        'CHUYỂN_NĂM',
        2.0,
        13.0,
        15.0,
        2,
        'CARRY_OVER',
        'Chuyển phép từ 2023'
    ),
    (
        2,
        1,
        '2024-03-20',
        'SỬ_DỤNG',
        -1.0,
        15.0,
        14.0,
        2,
        'LEAVE_REQUEST',
        'Nghỉ phép tháng 3'
    );
/* ===================================================== */
/* 30. shift_types - Loại ca làm việc                      */
/* ===================================================== */
INSERT INTO shift_types (
        shift_code,
        shift_name,
        start_time,
        end_time,
        break_start,
        break_end,
        working_hours,
        is_night_shift,
        allow_overtime,
        allow_wfh,
        coefficient,
        color_code,
        description,
        status
    )
VALUES (
        'HC',
        'Hành chính',
        '08:00:00',
        '17:00:00',
        '12:00:00',
        '13:00:00',
        8.0,
        FALSE,
        TRUE,
        FALSE,
        1.0,
        '#4CAF50',
        'Giờ hành chính tiêu chuẩn',
        TRUE
    ),
    (
        'CA1',
        'Ca 1',
        '06:00:00',
        '14:00:00',
        NULL,
        NULL,
        8.0,
        FALSE,
        TRUE,
        FALSE,
        1.0,
        '#2196F3',
        'Ca 1: 06h-14h',
        TRUE
    ),
    (
        'CA2',
        'Ca 2',
        '14:00:00',
        '22:00:00',
        NULL,
        NULL,
        8.0,
        FALSE,
        TRUE,
        FALSE,
        1.0,
        '#03A9F4',
        'Ca 2: 14h-22h',
        TRUE
    ),
    (
        'CA3',
        'Ca 3',
        '22:00:00',
        '06:00:00',
        NULL,
        NULL,
        8.0,
        TRUE,
        TRUE,
        FALSE,
        1.3,
        '#3F51B5',
        'Ca 3: 22h-06h, tính công vào ngày bắt đầu ca',
        TRUE
    );
/* ===================================================== */
/* 31. shift_schedules - Lịch làm việc mẫu                 */
/* ===================================================== */
INSERT INTO shift_schedules (
        schedule_code,
        schedule_name,
        department_id,
        effective_from,
        effective_to,
        is_active
    )
VALUES (
        'LICH-HC',
        'Lịch hành chính toàn công ty',
        NULL,
        '2024-01-01',
        '2024-12-31',
        TRUE
    ),
    (
        'LICH-HCNS',
        'Lịch phòng HCNS',
        1,
        '2024-01-01',
        '2024-12-31',
        TRUE
    ),
    (
        'LICH-KT',
        'Lịch phòng Kế toán',
        2,
        '2024-01-01',
        '2024-12-31',
        TRUE
    ),
    (
        'LICH-KD',
        'Lịch phòng Kinh doanh',
        3,
        '2024-01-01',
        '2024-12-31',
        TRUE
    ),
    (
        'LICH-IT',
        'Lịch phòng IT',
        4,
        '2024-01-01',
        '2024-12-31',
        TRUE
    );
/* ===================================================== */
/* 32. shift_schedule_details - Chi tiết lịch làm việc    */
/* ===================================================== */
INSERT INTO shift_schedule_details (
        schedule_id,
        day_of_week,
        shift_type_id,
        is_holiday
    )
VALUES (1, 2, 1, FALSE),
    -- Thứ 2
    (1, 3, 1, FALSE),
    -- Thứ 3
    (1, 4, 1, FALSE),
    -- Thứ 4
    (1, 5, 1, FALSE),
    -- Thứ 5
    (1, 6, 1, FALSE),
    -- Thứ 6
    (1, 7, 2, FALSE),
    -- Thứ 7 - ca sáng
    (1, 1, NULL, TRUE);
-- Chủ nhật - nghỉ
/* ===================================================== */
/* 33. shift_assignments - Đăng ký ca làm việc            */
/* ===================================================== */
INSERT INTO shift_assignments (
        employee_id,
        shift_type_id,
        effective_date,
        expiry_date,
        is_permanent,
        assigned_by,
        notes,
        status
    )
VALUES (
        1,
        1,
        '2024-01-01',
        NULL,
        TRUE,
        1,
        'Ca hành chính cố định',
        'HIỆU_LỰC'
    ),
    (
        2,
        1,
        '2024-01-01',
        NULL,
        TRUE,
        1,
        'Ca hành chính cố định',
        'HIỆU_LỰC'
    ),
    (
        3,
        1,
        '2024-01-01',
        NULL,
        TRUE,
        1,
        'Ca hành chính cố định',
        'HIỆU_LỰC'
    ),
    (
        4,
        1,
        '2024-01-01',
        NULL,
        TRUE,
        1,
        'Ca hành chính cố định',
        'HIỆU_LỰC'
    ),
    (
        5,
        1,
        '2024-01-01',
        NULL,
        TRUE,
        1,
        'Ca hành chính cố định',
        'HIỆU_LỰC'
    );
/* ===================================================== */
/* 34. attendances - Chấm công                            */
/* ===================================================== */
INSERT INTO attendances (
        employee_id,
        attendance_date,
        shift_type_id,
        check_in_time,
        check_out_time,
        check_in_method,
        check_out_method,
        work_type,
        actual_working_hours,
        overtime_hours,
        late_minutes,
        early_leave_minutes,
        is_holiday,
        is_overtime,
        status,
        notes
    )
VALUES (
        1,
        '2024-03-01',
        1,
        '2024-03-01 07:55:00',
        '2024-03-01 17:05:00',
        'MÁY_QUÉT',
        'MÁY_QUÉT',
        'VĂN_PHÒNG',
        8.2,
        0,
        0,
        5,
        FALSE,
        FALSE,
        'ĐÃ_DUYỆT',
        NULL
    ),
    (
        2,
        '2024-03-01',
        1,
        '2024-03-01 07:58:00',
        '2024-03-01 17:02:00',
        'MÁY_QUÉT',
        'MÁY_QUÉT',
        'VĂN_PHÒNG',
        8.1,
        0,
        0,
        0,
        FALSE,
        FALSE,
        'ĐÃ_DUYỆT',
        NULL
    ),
    (
        3,
        '2024-03-01',
        1,
        '2024-03-01 08:15:00',
        '2024-03-01 17:30:00',
        'MÁY_QUÉT',
        'MÁY_QUÉT',
        'VĂN_PHÒNG',
        8.2,
        0,
        15,
        0,
        FALSE,
        FALSE,
        'ĐÃ_DUYỆT',
        'Đi muộn 15 phút'
    ),
    (
        4,
        '2024-03-01',
        1,
        '2024-03-01 07:50:00',
        '2024-03-01 16:45:00',
        'MÁY_QUÉT',
        'MÁY_QUÉT',
        'VĂN_PHÒNG',
        7.9,
        0,
        0,
        15,
        FALSE,
        FALSE,
        'ĐÃ_DUYỆT',
        'Về sớm 15 phút'
    ),
    (
        5,
        '2024-03-01',
        1,
        '2024-03-01 08:30:00',
        '2024-03-01 18:00:00',
        'MÁY_QUÉT',
        'MÁY_QUÉT',
        'VĂN_PHÒNG',
        8.5,
        0.5,
        30,
        0,
        FALSE,
        TRUE,
        'ĐÃ_DUYỆT',
        'Đi muộn 30 phút, tăng ca 30 phút'
    );
/* ===================================================== */
/* 35. overtime_requests - Yêu cầu làm thêm giờ           */
/* ===================================================== */
INSERT INTO overtime_requests (
        request_id,
        employee_id,
        overtime_date,
        start_time,
        end_time,
        break_time,
        reason,
        status
    )
VALUES (
        4,
        1,
        '2024-03-05',
        '18:00:00',
        '21:00:00',
        0,
        'Làm báo cáo cuối quý',
        'ĐÃ_DUYỆT'
    );
/* ===================================================== */
/* 36. insurance_types - Loại bảo hiểm                    */
/* ===================================================== */
INSERT IGNORE INTO insurance_types (
        insurance_code,
        insurance_name,
        payment_rate,
        is_company_paid,
        is_social_insurance,
        description,
        status
    )
VALUES (
        'BHXH_OM',
        'Ốm đau',
        75.00,
        FALSE,
        TRUE,
        'Bảo hiểm xã hội ốm đau',
        TRUE
    ),
    (
        'BHXH_TS',
        'Thai sản',
        100.00,
        FALSE,
        TRUE,
        'Bảo hiểm xã hội thai sản',
        TRUE
    ),
    (
        'BHYT',
        'Bảo hiểm y tế',
        100.00,
        TRUE,
        TRUE,
        'Bảo hiểm y tế',
        TRUE
    ),
    (
        'BHTN',
        'Bảo hiểm thất nghiệp',
        60.00,
        FALSE,
        TRUE,
        'Bảo hiểm thất nghiệp',
        TRUE
    );
/* ===================================================== */
/* 37. insurance_claims - Yêu cầu thanh toán bảo hiểm     */
/* ===================================================== */
INSERT INTO insurance_claims (
        employee_id,
        request_id,
        insurance_type_id,
        claim_code,
        leave_request_id,
        start_date,
        end_date,
        total_days,
        daily_rate,
        total_amount,
        payment_source,
        certificate_number,
        certificate_file_url,
        certificate_uploaded_date,
        bank_account,
        bank_id,
        payment_status,
        notes
    )
VALUES (
        2,
        2,
        1,
        'BHXH-2024-001',
        2,
        '2024-03-20',
        '2024-03-20',
        1,
        500000,
        500000,
        'BHXH',
        'GCN-2024-001',
        NULL,
        '2024-03-21 10:30:00',
        '234567891',
        2,
        'ĐÃ_THANH_TOÁN',
        'Nghỉ cưới hưởng BHXH'
    ),
    (
        3,
        3,
        1,
        'BHXH-2024-002',
        3,
        '2024-03-25',
        '2024-03-25',
        0.5,
        450000,
        225000,
        'BHXH',
        'GCN-2024-002',
        NULL,
        '2024-03-26 09:15:00',
        '345678912',
        3,
        'CHỜ_XỬ_LÝ',
        'Khám sức khỏe hưởng BHXH'
    );
/* ===================================================== */
/* 38. allowances - Phụ cấp                               */
/* ===================================================== */
INSERT INTO allowances (
        allowance_code,
        allowance_name,
        allowance_type,
        calculation_method,
        is_taxable,
        is_insurable,
        description,
        status
    )
VALUES (
        'PC-CV',
        'Phụ cấp chức vụ',
        'FIXED',
        'MONTHLY',
        TRUE,
        TRUE,
        'Phụ cấp cho trưởng phòng, phó phòng',
        TRUE
    ),
    (
        'PC-AT',
        'Phụ cấp ăn trưa',
        'FIXED',
        'DAILY',
        FALSE,
        FALSE,
        'Tiền ăn trưa hàng ngày',
        TRUE
    ),
    (
        'PC-XM',
        'Phụ cấp xăng xe',
        'FIXED',
        'MONTHLY',
        FALSE,
        FALSE,
        'Phụ cấp đi lại',
        TRUE
    ),
    (
        'PC-DT',
        'Phụ cấp điện thoại',
        'FIXED',
        'MONTHLY',
        TRUE,
        FALSE,
        'Phụ cấp cước điện thoại',
        TRUE
    ),
    (
        'PC-TN',
        'Phụ cấp thâm niên',
        'FIXED',
        'MONTHLY',
        TRUE,
        TRUE,
        'Phụ cấp theo số năm công tác',
        TRUE
    );
/* ===================================================== */
/* 39. deductions - Khấu trừ                               */
/* ===================================================== */
INSERT INTO deductions (
        deduction_code,
        deduction_name,
        deduction_type,
        is_mandatory,
        description,
        status
    )
VALUES (
        'BHXH',
        'Bảo hiểm xã hội',
        'PERCENTAGE_OF_SALARY',
        TRUE,
        'Trích BHXH 8%',
        TRUE
    ),
    (
        'BHYT',
        'Bảo hiểm y tế',
        'PERCENTAGE_OF_SALARY',
        TRUE,
        'Trích BHYT 1.5%',
        TRUE
    ),
    (
        'BHTN',
        'Bảo hiểm thất nghiệp',
        'PERCENTAGE_OF_SALARY',
        TRUE,
        'Trích BHTN 1%',
        TRUE
    ),
    (
        'TNCN',
        'Thuế thu nhập cá nhân',
        'PERCENTAGE_OF_SALARY',
        TRUE,
        'Thuế TNCN theo luật định',
        TRUE
    ),
    (
        'TL',
        'Tạm ứng lương',
        'FIXED',
        FALSE,
        'Khấu trừ tạm ứng lương',
        TRUE
    );
/* ===================================================== */
/* 40. employee_allowances - Phụ cấp theo nhân viên       */
/* ===================================================== */
INSERT INTO employee_allowances (
        employee_id,
        allowance_id,
        amount,
        percentage,
        effective_date,
        expiry_date,
        is_active,
        notes
    )
VALUES (
        1,
        1,
        2000000,
        NULL,
        '2024-01-01',
        NULL,
        TRUE,
        'Phụ cấp trưởng phòng'
    ),
    (
        1,
        2,
        30000,
        NULL,
        '2024-01-01',
        NULL,
        TRUE,
        'Phụ cấp ăn trưa'
    ),
    (
        1,
        3,
        500000,
        NULL,
        '2024-01-01',
        NULL,
        TRUE,
        'Phụ cấp xăng xe'
    ),
    (
        2,
        2,
        30000,
        NULL,
        '2024-01-01',
        NULL,
        TRUE,
        'Phụ cấp ăn trưa'
    ),
    (
        2,
        3,
        300000,
        NULL,
        '2024-01-01',
        NULL,
        TRUE,
        'Phụ cấp xăng xe'
    ),
    (
        3,
        1,
        2500000,
        NULL,
        '2024-01-01',
        NULL,
        TRUE,
        'Phụ cấp trưởng phòng'
    ),
    (
        3,
        2,
        30000,
        NULL,
        '2024-01-01',
        NULL,
        TRUE,
        'Phụ cấp ăn trưa'
    ),
    (
        3,
        3,
        500000,
        NULL,
        '2024-01-01',
        NULL,
        TRUE,
        'Phụ cấp xăng xe'
    );
/* ===================================================== */
/* 41. employee_deductions - Khấu trừ theo nhân viên       */
/* ===================================================== */
INSERT INTO employee_deductions (
        employee_id,
        deduction_id,
        amount,
        percentage,
        effective_date,
        expiry_date,
        is_active,
        notes
    )
VALUES (
        1,
        1,
        NULL,
        8,
        '2024-01-01',
        NULL,
        TRUE,
        'BHXH 8%'
    ),
    (
        1,
        2,
        NULL,
        1.5,
        '2024-01-01',
        NULL,
        TRUE,
        'BHYT 1.5%'
    ),
    (
        1,
        3,
        NULL,
        1,
        '2024-01-01',
        NULL,
        TRUE,
        'BHTN 1%'
    ),
    (2, 1, NULL, 8, '2024-01-01', NULL, TRUE, 'BHXH'),
    (
        2,
        2,
        NULL,
        1.5,
        '2024-01-01',
        NULL,
        TRUE,
        'BHYT'
    ),
    (2, 3, NULL, 1, '2024-01-01', NULL, TRUE, 'BHTN'),
    (3, 1, NULL, 8, '2024-01-01', NULL, TRUE, 'BHXH'),
    (
        3,
        2,
        NULL,
        1.5,
        '2024-01-01',
        NULL,
        TRUE,
        'BHYT'
    ),
    (3, 3, NULL, 1, '2024-01-01', NULL, TRUE, 'BHTN');
/* ===================================================== */
/* 42. salary_periods - Kỳ lương                          */
/* ===================================================== */
INSERT INTO salary_periods (
        period_code,
        period_name,
        period_type,
        year,
        month,
        start_date,
        end_date,
        payment_date,
        standard_working_days,
        status,
        notes
    )
VALUES (
        'P-2024-01',
        'Kỳ lương tháng 1/2024',
        'MONTHLY',
        2024,
        1,
        '2024-01-01',
        '2024-01-31',
        '2024-02-05',
        26,
        'PAID',
        'Đã chi lương tháng 1'
    ),
    (
        'P-2024-02',
        'Kỳ lương tháng 2/2024',
        'MONTHLY',
        2024,
        2,
        '2024-02-01',
        '2024-02-29',
        '2024-03-05',
        24,
        'PAID',
        'Đã chi lương tháng 2'
    ),
    (
        'P-2024-03',
        'Kỳ lương tháng 3/2024',
        'MONTHLY',
        2024,
        3,
        '2024-03-01',
        '2024-03-31',
        NULL,
        26,
        'OPEN',
        'Đang tính lương'
    );
/* ===================================================== */
/* 43. salary_attendance_summary - Tổng hợp ngày công     */
/* ===================================================== */
INSERT INTO salary_attendance_summary (
        employee_id,
        period_id,
        standard_days,
        actual_working_days,
        paid_leave_days,
        unpaid_leave_days,
        holiday_days,
        overtime_hours,
        late_minutes,
        early_leave_minutes
    )
VALUES (1, 3, 26, 23, 2, 0, 1, 0, 0, 5),
    (2, 3, 26, 24, 1, 0, 1, 0, 15, 0),
    (3, 3, 26, 25, 0, 0, 1, 0, 15, 0),
    (4, 3, 26, 24, 0, 0, 2, 0, 0, 15),
    (5, 3, 26, 23, 0, 0, 3, 0.5, 30, 0);
/* ===================================================== */
/* 44. salary_details - Bảng lương chi tiết               */
/* ===================================================== */
INSERT INTO salary_details (
        period_id,
        employee_id,
        contract_id,
        basic_salary,
        gross_salary,
        net_salary,
        total_allowances,
        total_deductions,
        overtime_pay,
        leave_pay,
        bonus,
        penalty,
        social_insurance_employee,
        health_insurance_employee,
        unemployment_insurance_employee,
        personal_income_tax,
        advance_payment,
        bank_account,
        bank_name,
        transfer_status,
        notes
    )
VALUES (
        3,
        1,
        1,
        25000000,
        35000000,
        30000000,
        2800000,
        3500000,
        0,
        500000,
        2000000,
        0,
        2000000,
        375000,
        250000,
        1500000,
        0,
        '123456789',
        'Vietcombank',
        'PENDING',
        'Lương tháng 3'
    ),
    (
        3,
        2,
        2,
        18000000,
        25000000,
        22000000,
        1500000,
        2500000,
        0,
        300000,
        1000000,
        0,
        1440000,
        270000,
        180000,
        800000,
        0,
        '234567891',
        'VietinBank',
        'PENDING',
        'Lương tháng 3'
    ),
    (
        3,
        3,
        3,
        30000000,
        42000000,
        37000000,
        3500000,
        4200000,
        0,
        150000,
        3000000,
        0,
        2400000,
        450000,
        300000,
        2000000,
        0,
        '345678912',
        'BIDV',
        'PENDING',
        'Lương tháng 3'
    );
/* ===================================================== */
/* 45. roles - Vai trò                                     */
/* ===================================================== */
INSERT INTO roles (
        role_code,
        role_name,
        description,
        is_system_role
    )
VALUES (
        'ADMIN',
        'Quản trị viên',
        'Toàn quyền hệ thống',
        TRUE
    ),
    (
        'HR',
        'Nhân sự',
        'Quản lý nhân sự, tuyển dụng, đào tạo',
        TRUE
    ),
    (
        'MANAGER',
        'Quản lý',
        'Quản lý phòng ban, duyệt đơn',
        TRUE
    ),
    ('EMPLOYEE', 'Nhân viên', 'Quyền cơ bản', TRUE),
    (
        'ACCOUNTANT',
        'Kế toán',
        'Quản lý lương, thanh toán',
        TRUE
    );
/* ===================================================== */
/* 46. permissions - Quyền                                  */
/* ===================================================== */
INSERT INTO permissions (
        permission_code,
        permission_name,
        module,
        description
    )
VALUES (
        'EMP_VIEW',
        'Xem nhân viên',
        'NHAN_SU',
        'Xem danh sách và thông tin nhân viên'
    ),
    (
        'EMP_CREATE',
        'Thêm nhân viên',
        'NHAN_SU',
        'Thêm mới nhân viên'
    ),
    (
        'LEAVE_VIEW',
        'Xem đơn nghỉ phép',
        'NGHI_PHEP',
        'Xem danh sách đơn nghỉ phép'
    ),
    (
        'LEAVE_CREATE',
        'Tạo đơn nghỉ phép',
        'NGHI_PHEP',
        'Tạo đơn xin nghỉ phép'
    ),
    (
        'LEAVE_APPROVE',
        'Duyệt đơn nghỉ phép',
        'NGHI_PHEP',
        'Duyệt hoặc từ chối đơn nghỉ phép'
    ),
    (
        'SALARY_VIEW',
        'Xem bảng lương',
        'LUONG',
        'Xem bảng lương'
    ),
    (
        'ATTENDANCE_VIEW',
        'Xem chấm công',
        'CHAM_CONG',
        'Xem bảng chấm công'
    );
/* ===================================================== */
/* 47. employee_roles - Phân vai trò cho nhân viên        */
/* ===================================================== */
INSERT INTO employee_roles (
        employee_id,
        role_id,
        department_id,
        effective_date,
        expiry_date,
        is_active
    )
VALUES (1, 3, 1, '2024-01-01', NULL, TRUE),
    -- Quản lý
    (1, 4, 1, '2024-01-01', NULL, TRUE),
    -- Nhân viên
    (2, 2, 2, '2024-01-01', NULL, TRUE),
    -- HR
    (2, 4, 2, '2024-01-01', NULL, TRUE),
    (3, 3, 3, '2024-01-01', NULL, TRUE),
    -- Quản lý
    (3, 4, 3, '2024-01-01', NULL, TRUE),
    (4, 4, 4, '2024-01-01', NULL, TRUE),
    -- Nhân viên
    (5, 4, 1, '2024-01-01', NULL, TRUE);
/* ===================================================== */
/* 48. report_templates - Báo cáo mẫu                      */
/* ===================================================== */
INSERT INTO report_templates (
        template_code,
        template_name,
        report_type,
        sql_query,
        columns_config,
        filters_config,
        chart_config,
        created_by,
        is_public,
        status
    )
VALUES (
        'RP_EMP_LIST',
        'Danh sách nhân viên',
        'NHAN_SU',
        'SELECT * FROM employees',
        '{"columns":["Mã NV","Họ tên","Phòng ban","Chức vụ","Ngày vào"]}',
        '{"filters":["phong_ban","trang_thai"]}',
        NULL,
        1,
        TRUE,
        TRUE
    ),
    (
        'RP_LEAVE_SUM',
        'Tổng hợp nghỉ phép theo phòng ban',
        'NGHI_PHEP',
        'SELECT d.department_name, COUNT(*) as so_luong, SUM(lr.number_of_days) as tong_ngay FROM leave_requests lr JOIN employees e ON lr.employee_id = e.employee_id JOIN departments d ON e.department_id = d.department_id GROUP BY d.department_name',
        '{"columns":["Phòng ban","Số đơn","Tổng ngày nghỉ"]}',
        '{"filters":["tu_ngay","den_ngay"]}',
        '{"type":"pie","data":"tong_ngay"}',
        1,
        TRUE,
        TRUE
    ),
    (
        'RP_SALARY',
        'Bảng lương tháng',
        'LUONG',
        'SELECT e.full_name, e.employee_code, sd.basic_salary, sd.gross_salary, sd.net_salary, sd.transfer_status FROM salary_details sd JOIN employees e ON sd.employee_id = e.employee_id WHERE sd.period_id = ?',
        '{"columns":["Họ tên","Mã NV","Lương CB","Lương Gross","Lương Net","Trạng thái"]}',
        '{"filters":["ky_luong"]}',
        '{"type":"bar","data":"net_salary"}',
        1,
        TRUE,
        TRUE
    );
/* ===================================================== */
/* 49. news_categories - Danh mục tin tức                 */
/* ===================================================== */
INSERT INTO news_categories (
        category_code,
        category_name,
        description,
        status
    )
VALUES (
        'TT',
        'Thông báo',
        'Thông báo chung từ công ty',
        TRUE
    ),
    (
        'SK',
        'Sự kiện',
        'Sự kiện nội bộ sắp diễn ra',
        TRUE
    ),
    (
        'CT',
        'Chính sách',
        'Chính sách mới của công ty',
        TRUE
    );
/* ===================================================== */
/* 50. news - Tin tức                                      */
/* ===================================================== */
INSERT INTO news (
        news_code,
        category_id,
        title,
        summary,
        content,
        priority,
        is_important,
        is_pinned,
        published_date,
        expiry_date,
        published_by,
        department_id,
        position_id,
        view_count,
        status
    )
VALUES (
        'NEWS-2024-001',
        1,
        'Thông báo lịch nghỉ lễ 30/4 và 1/5',
        'Lịch nghỉ lễ 30/4 và 1/5 năm 2024',
        'Nội dung chi tiết về lịch nghỉ lễ...',
        'CAO',
        TRUE,
        TRUE,
        '2024-04-01 08:00:00',
        '2024-05-02 17:00:00',
        2,
        1,
        3,
        150,
        'ĐÃ_XUẤT_BẢN'
    ),
    (
        'NEWS-2024-002',
        2,
        'Tổ chức team building tại Vũng Tàu',
        'Chương trình team building toàn công ty',
        'Chi tiết chương trình team building...',
        'TRUNG_BÌNH',
        FALSE,
        FALSE,
        '2024-03-15 10:00:00',
        '2024-04-15 17:00:00',
        6,
        3,
        5,
        85,
        'ĐÃ_XUẤT_BẢN'
    ),
    (
        'NEWS-2024-003',
        3,
        'Cập nhật chính sách làm việc từ xa',
        'Chính sách WFH mới áp dụng từ tháng 4/2024',
        'Nội dung chính sách...',
        'CAO',
        TRUE,
        TRUE,
        '2024-03-20 14:00:00',
        NULL,
        2,
        1,
        3,
        210,
        'ĐÃ_XUẤT_BẢN'
    );
/* ===================================================== */
/* 51. notification_configs - Cấu hình thông báo           */
/* ===================================================== */
INSERT IGNORE INTO notification_configs (
        notification_type,
        is_enabled,
        send_email,
        send_in_app,
        days_before_trigger,
        recipients
    )
VALUES (
        'SENIORITY_ALERT',
        TRUE,
        TRUE,
        TRUE,
        30,
        'EMPLOYEE'
    ),
    ('LEAVE_EXPIRY', TRUE, TRUE, TRUE, 15, 'EMPLOYEE'),
    ('CLAIM_STATUS', TRUE, TRUE, TRUE, 0, 'EMPLOYEE'),
    (
        'APPROVAL_REMINDER',
        TRUE,
        TRUE,
        TRUE,
        2,
        'MANAGER'
    ),
    (
        'CONTRACT_EXPIRY',
        TRUE,
        TRUE,
        TRUE,
        30,
        'EMPLOYEE'
    );
/* ===================================================== */
/* 52. notifications - Thông báo                          */
/* ===================================================== */
INSERT INTO notifications (
        notification_type,
        title,
        content,
        receiver_id,
        priority,
        reference_type,
        reference_id,
        action_url,
        expires_at
    )
VALUES (
        'LEAVE_EXPIRY',
        'Phép gộp sắp hết hạn',
        'Bạn còn 3 ngày phép gộp từ năm 2023 sẽ hết hạn vào ngày 31/03/2024',
        1,
        'CAO',
        'LEAVE_ADVANCEMENT',
        1,
        '/leave/balances',
        '2024-04-01 00:00:00'
    ),
    (
        'LEAVE_EXPIRY',
        'Phép gộp sắp hết hạn',
        'Bạn còn 2 ngày phép gộp từ năm 2023 sẽ hết hạn vào ngày 31/03/2024',
        2,
        'CAO',
        'LEAVE_ADVANCEMENT',
        2,
        '/leave/balances',
        '2024-04-01 00:00:00'
    ),
    (
        'APPROVAL_REMINDER',
        'Nhắc duyệt đơn xin nghỉ phép',
        'Bạn có đơn xin nghỉ phép NP-2024-003 đang chờ duyệt',
        3,
        'CAO',
        'LEAVE_REQUEST',
        3,
        '/approval/3',
        '2024-03-10 00:00:00'
    ),
    (
        'CLAIM_STATUS',
        'Yêu cầu bảo hiểm đã được duyệt',
        'Yêu cầu BHXH-2024-001 của bạn đã được duyệt',
        2,
        'TRUNG_BÌNH',
        'INSURANCE_CLAIM',
        1,
        '/insurance/1',
        NULL
    );
/* ===================================================== */
/* 53. system_configs - Cấu hình hệ thống                  */
/* ===================================================== */
INSERT INTO system_configs (
        config_key,
        config_value,
        config_type,
        description,
        module
    )
VALUES (
        'company_name',
        'Công ty Cổ phần Đầu tư Phát triển Nhân lực ABC',
        'TEXT',
        'Tên công ty',
        'GENERAL'
    ),
    (
        'company_address',
        '123 Nguyễn Trãi, Quận 1, TP.HCM',
        'TEXT',
        'Địa chỉ công ty',
        'GENERAL'
    ),
    (
        'company_phone',
        '028 1234 5678',
        'TEXT',
        'Số điện thoại công ty',
        'GENERAL'
    ),
    (
        'base_leave_days',
        '12',
        'NUMBER',
        'Số ngày phép cơ bản mỗi năm',
        'LEAVE'
    ),
    (
        'seniority_years',
        '5',
        'NUMBER',
        'Số năm để được cộng phép thâm niên',
        'LEAVE'
    ),
    (
        'max_carryover_years',
        '3',
        'NUMBER',
        'Số năm tối đa được gộp phép',
        'LEAVE'
    ),
    (
        'working_hours_per_day',
        '8',
        'NUMBER',
        'Số giờ làm việc chuẩn mỗi ngày',
        'ATTENDANCE'
    ),
    (
        'working_days_per_month',
        '26',
        'NUMBER',
        'Số ngày công chuẩn mỗi tháng',
        'ATTENDANCE'
    ),
    (
        'social_insurance_rate',
        '8.0',
        'NUMBER',
        'Tỷ lệ BHXH người lao động đóng (%)',
        'SALARY'
    ),
    (
        'health_insurance_rate',
        '1.5',
        'NUMBER',
        'Tỷ lệ BHYT người lao động đóng (%)',
        'SALARY'
    ),
    (
        'unemployment_insurance_rate',
        '1.0',
        'NUMBER',
        'Tỷ lệ BHTN người lao động đóng (%)',
        'SALARY'
    ),
    (
        'salary_payment_day',
        '5',
        'NUMBER',
        'Ngày chi trả lương hàng tháng',
        'SALARY'
    ),
    (
        'system_version',
        '3.0',
        'TEXT',
        'Phiên bản hệ thống',
        'SYSTEM'
    );
/* ===================================================== */
/* KẾT THÚC DỮ LIỆU ẢO                                    */
/* ===================================================== */
/* ===================================================== */
/* BỔ SUNG DỮ LIỆU BẰNG LỆNH ALTER TABLE                 */
/* ===================================================== */
USE HRM_SYSTEM;
/* ===================================================== */
/* 1. Bổ sung thêm quốc tịch                              */
/* ===================================================== */
ALTER TABLE nationalities AUTO_INCREMENT = 11;
INSERT INTO nationalities (nationality_code, nationality_name)
VALUES ('SG', 'Singapore'),
    ('TH', 'Thái Lan'),
    ('MY', 'Malaysia'),
    ('ID', 'Indonesia'),
    ('PH', 'Philippines');
/* ===================================================== */
/* 2. Bổ sung thêm ngân hàng                              */
/* ===================================================== */
ALTER TABLE banks AUTO_INCREMENT = 11;
INSERT INTO banks (bank_code, bank_name, swift_code, status)
VALUES (
        'TPB',
        'Ngân hàng TMCP Tiên Phong',
        'TPBVVNVX',
        TRUE
    ),
    (
        'VIB',
        'Ngân hàng TMCP Quốc tế Việt Nam',
        'VNIBVNVX',
        TRUE
    ),
    (
        'EIB',
        'Ngân hàng TMCP Xuất Nhập khẩu Việt Nam',
        'EBVIVNVX',
        TRUE
    ),
    (
        'LPB',
        'Ngân hàng TMCP Bưu điện Liên Việt',
        'LVBKVNVX',
        TRUE
    ),
    (
        'MSB',
        'Ngân hàng TMCP Hàng Hải Việt Nam',
        'MCOBVNVX',
        TRUE
    );
/* ===================================================== */
/* 3. Bổ sung thêm chức vụ                                */
/* ===================================================== */
ALTER TABLE positions AUTO_INCREMENT = 8;
INSERT INTO positions (
        position_code,
        position_name,
        job_description,
        requirements,
        salary_range_min,
        salary_range_max,
        status
    )
VALUES (
        'KTV',
        'Kiểm toán viên',
        'Kiểm tra sổ sách kế toán',
        'Chứng chỉ kiểm toán',
        15000000,
        30000000,
        TRUE
    ),
    (
        'KTVC',
        'Kế toán trưởng',
        'Quản lý bộ phận kế toán',
        'Chứng chỉ kế toán trưởng',
        25000000,
        45000000,
        TRUE
    ),
    (
        'KTTH',
        'Kế toán tổng hợp',
        'Tổng hợp số liệu kế toán',
        'Kinh nghiệm 3 năm',
        15000000,
        25000000,
        TRUE
    ),
    (
        'NS',
        'Nhân sự',
        'Tuyển dụng và đào tạo',
        'Chuyên ngành nhân sự',
        12000000,
        22000000,
        TRUE
    ),
    (
        'HC',
        'Hành chính',
        'Quản lý văn phòng',
        'Kỹ năng hành chính',
        10000000,
        18000000,
        TRUE
    ),
    (
        'LD',
        'Lập trình viên',
        'Phát triển phần mềm',
        'Thành thạo ngôn ngữ lập trình',
        15000000,
        30000000,
        TRUE
    ),
    (
        'TL',
        'Thủ kho',
        'Quản lý xuất nhập kho',
        'Kinh nghiệm quản lý kho',
        9000000,
        15000000,
        TRUE
    ),
    (
        'BV',
        'Bảo vệ',
        'Đảm bảo an ninh',
        'Sức khỏe tốt',
        6000000,
        9000000,
        TRUE
    );
/* ===================================================== */
/* 4. Bổ sung thêm phòng ban                              */
/* ===================================================== */
ALTER TABLE departments AUTO_INCREMENT = 11;
INSERT INTO departments (
        department_code,
        department_name,
        parent_department_id,
        manager_id,
        description,
        status
    )
VALUES (
        'KMD',
        'Kinh doanh Miền Đông',
        3,
        3,
        'Kinh doanh khu vực miền Đông',
        TRUE
    ),
    (
        'KMT',
        'Kinh doanh Miền Tây',
        3,
        3,
        'Kinh doanh khu vực miền Tây',
        TRUE
    ),
    (
        'KN',
        'Kinh doanh Miền Bắc',
        3,
        3,
        'Kinh doanh khu vực miền Bắc',
        TRUE
    ),
    (
        'HC1',
        'Hành chính Hà Nội',
        1,
        2,
        'Hành chính văn phòng Hà Nội',
        TRUE
    ),
    (
        'HC2',
        'Hành chính Đà Nẵng',
        1,
        2,
        'Hành chính văn phòng Đà Nẵng',
        TRUE
    ),
    (
        'DAISO',
        'Đào tạo và ISO',
        1,
        2,
        'Đào tạo nhân viên và quản lý ISO',
        TRUE
    );
/* ===================================================== */
/* 5. Bổ sung thêm nhân viên                              */
/* ===================================================== */
ALTER TABLE employees AUTO_INCREMENT = 11;
INSERT INTO employees (
        employee_code,
        full_name,
        date_of_birth,
        gender,
        place_of_birth,
        ethnicity,
        religion,
        marital_status,
        phone_number,
        personal_email,
        company_email,
        permanent_address,
        current_address,
        nationality_id,
        avatar_url,
        bank_account,
        bank_id,
        bank_branch,
        emergency_contact_name,
        emergency_contact_phone,
        emergency_contact_relation,
        status,
        hire_date,
        seniority_start_date,
        base_leave_days
    )
VALUES (
        'NV0011',
        'Ngô Văn Tùng',
        '1985-03-08',
        'NAM',
        'Nam Định',
        'Kinh',
        'Không',
        'ĐÃ_KẾT_HÔN',
        '0912345670',
        'tung.ngo@gmail.com',
        'tung.ngo@company.com',
        'Số 11, Đường W, Nam Định',
        'Số 110, Đường X, Q.12, TP.HCM',
        1,
        NULL,
        '234567801',
        1,
        'Chi nhánh Nam Định',
        'Ngô Thị Thu',
        '0887654321',
        'Vợ',
        'ĐANG_LÀM_VIỆC',
        '2010-01-01',
        '2010-01-01',
        12.00
    ),
    (
        'NV0012',
        'Dương Thị Hồng',
        '1992-11-25',
        'NỮ',
        'Thái Bình',
        'Kinh',
        'Phật giáo',
        'ĐÃ_KẾT_HÔN',
        '0923456780',
        'hong.duong@gmail.com',
        'hong.duong@company.com',
        'Số 12, Đường Y, Thái Bình',
        'Số 120, Đường Z, Thủ Đức',
        1,
        NULL,
        '345678012',
        2,
        'Chi nhánh Thái Bình',
        'Dương Văn Hùng',
        '0876543210',
        'Bố',
        'ĐANG_LÀM_VIỆC',
        '2016-09-01',
        '2016-09-01',
        12.00
    ),
    (
        'NV0013',
        'Trịnh Văn Phúc',
        '1988-07-19',
        'NAM',
        'Thanh Hóa',
        'Kinh',
        'Thiên chúa giáo',
        'ĐỘC_THÂN',
        '0934567890',
        'phuc.trinh@gmail.com',
        'phuc.trinh@company.com',
        'Số 13, Đường AB, Thanh Hóa',
        'Số 130, Đường AC, Bình Thạnh',
        1,
        NULL,
        '456789123',
        3,
        'Chi nhánh Thanh Hóa',
        'Trịnh Thị Lan',
        '0865432109',
        'Mẹ',
        'ĐANG_LÀM_VIỆC',
        '2014-04-01',
        '2014-04-01',
        12.00
    ),
    (
        'NV0014',
        'Đinh Thị Nhung',
        '1990-09-14',
        'NỮ',
        'Nghệ An',
        'Kinh',
        'Không',
        'ĐÃ_KẾT_HÔN',
        '0945678900',
        'nhung.dinh@gmail.com',
        'nhung.dinh@company.com',
        'Số 14, Đường AD, Nghệ An',
        'Số 140, Đường AE, Gò Vấp',
        1,
        NULL,
        '567891234',
        4,
        'Chi nhánh Nghệ An',
        'Đinh Văn Nam',
        '0854321098',
        'Chồng',
        'ĐANG_LÀM_VIỆC',
        '2015-05-15',
        '2015-05-15',
        12.00
    ),
    (
        'NV0015',
        'Lương Văn Kiên',
        '1984-12-03',
        'NAM',
        'Hà Tĩnh',
        'Kinh',
        'Phật giáo',
        'ĐÃ_KẾT_HÔN',
        '0956789010',
        'kien.luong@gmail.com',
        'kien.luong@company.com',
        'Số 15, Đường AF, Hà Tĩnh',
        'Số 150, Đường AG, Tân Bình',
        1,
        NULL,
        '678912345',
        5,
        'Chi nhánh Hà Tĩnh',
        'Lương Thị Hà',
        '0843210987',
        'Vợ',
        'ĐANG_LÀM_VIỆC',
        '2009-08-10',
        '2009-08-10',
        12.00
    ),
    (
        'NV0016',
        'Mai Thị Quỳnh',
        '1993-01-27',
        'NỮ',
        'Quảng Bình',
        'Kinh',
        'Không',
        'ĐỘC_THÂN',
        '0967890120',
        'quynh.mai@gmail.com',
        'quynh.mai@company.com',
        'Số 16, Đường AH, Quảng Bình',
        'Số 160, Đường AI, Phú Nhuận',
        1,
        NULL,
        '789123456',
        6,
        'Chi nhánh Quảng Bình',
        'Mai Văn Đức',
        '0832109876',
        'Bố',
        'ĐANG_LÀM_VIỆC',
        '2017-03-20',
        '2017-03-20',
        12.00
    ),
    (
        'NV0017',
        'Hà Văn Phát',
        '1989-05-02',
        'NAM',
        'Quảng Trị',
        'Kinh',
        'Thiên chúa giáo',
        'ĐÃ_KẾT_HÔN',
        '0978901230',
        'phat.ha@gmail.com',
        'phat.ha@company.com',
        'Số 17, Đường AJ, Quảng Trị',
        'Số 170, Đường AK, Tân Phú',
        1,
        NULL,
        '891234567',
        7,
        'Chi nhánh Quảng Trị',
        'Hà Thị Yến',
        '0821098765',
        'Vợ',
        'ĐANG_LÀM_VIỆC',
        '2013-06-05',
        '2013-06-05',
        12.00
    ),
    (
        'NV0018',
        'Lâm Thị Ngọc',
        '1991-08-11',
        'NỮ',
        'Đắk Lắk',
        'Ê Đê',
        'Phật giáo',
        'ĐÃ_KẾT_HÔN',
        '0989012340',
        'ngoc.lam@gmail.com',
        'ngoc.lam@company.com',
        'Số 18, Đường AL, Đắk Lắk',
        'Số 180, Đường AM, Bình Tân',
        1,
        NULL,
        '912345678',
        8,
        'Chi nhánh Đắk Lắk',
        'Lâm Văn Sơn',
        '0810987654',
        'Chồng',
        'ĐANG_LÀM_VIỆC',
        '2015-10-12',
        '2015-10-12',
        12.00
    ),
    (
        'NV0019',
        'Tô Văn Tài',
        '1986-02-18',
        'NAM',
        'Gia Lai',
        'Kinh',
        'Không',
        'ĐÃ_KẾT_HÔN',
        '0990123450',
        'tai.to@gmail.com',
        'tai.to@company.com',
        'Số 19, Đường AN, Gia Lai',
        'Số 190, Đường AO, Nhà Bè',
        1,
        NULL,
        '123456789',
        9,
        'Chi nhánh Gia Lai',
        'Tô Thị Thảo',
        '0809876543',
        'Vợ',
        'ĐANG_LÀM_VIỆC',
        '2012-07-25',
        '2012-07-25',
        12.00
    ),
    (
        'NV0020',
        'Nguyễn Hoàng Nam',
        '1994-04-30',
        'NAM',
        'TP.HCM',
        'Kinh',
        'Phật giáo',
        'ĐỘC_THÂN',
        '0901234568',
        'nam.nguyenhoang@gmail.com',
        'nam.nh@company.com',
        'Số 20, Đường AP, Q.1, TP.HCM',
        'Số 200, Đường AQ, Q.1, TP.HCM',
        1,
        NULL,
        '234567890',
        10,
        'Chi nhánh TP.HCM',
        'Nguyễn Thị Mai',
        '0798765432',
        'Mẹ',
        'ĐANG_LÀM_VIỆC',
        '2018-01-08',
        '2018-01-08',
        12.00
    );
/* ===================================================== */
/* 6. Bổ sung thêm hợp đồng lao động                      */
/* ===================================================== */
ALTER TABLE contracts AUTO_INCREMENT = 6;
INSERT INTO contracts (
        contract_code,
        employee_id,
        contract_type_id,
        contract_number,
        sign_date,
        effective_date,
        expiry_date,
        position_id,
        department_id,
        basic_salary,
        gross_salary,
        net_salary,
        work_location,
        job_title,
        content,
        file_url,
        signed_file_url,
        contract_template_id,
        status,
        is_renewed
    )
VALUES (
        'HD0006',
        6,
        4,
        'HD/2024/006',
        '2024-03-01',
        '2024-03-15',
        '2024-05-14',
        6,
        5,
        8000000,
        8000000,
        7500000,
        'Hồ Chí Minh',
        'Nhân viên Marketing',
        'Nội dung hợp đồng thử việc...',
        '/files/contracts/hd0006.pdf',
        '/files/contracts/hd0006_signed.pdf',
        3,
        'CÓ_HIỆU_LỰC',
        FALSE
    ),
    (
        'HD0007',
        7,
        1,
        'HD/2024/007',
        '2024-03-05',
        '2024-03-15',
        NULL,
        4,
        6,
        22000000,
        30000000,
        26000000,
        'Cần Thơ',
        'Phó phòng CSKH',
        'Nội dung hợp đồng...',
        '/files/contracts/hd0007.pdf',
        '/files/contracts/hd0007_signed.pdf',
        1,
        'CÓ_HIỆU_LỰC',
        FALSE
    ),
    (
        'HD0008',
        8,
        2,
        'HD/2024/008',
        '2024-03-10',
        '2024-04-01',
        '2025-03-31',
        6,
        7,
        12000000,
        16000000,
        14000000,
        'Hồ Chí Minh',
        'Nhân viên kho',
        'Nội dung hợp đồng...',
        '/files/contracts/hd0008.pdf',
        '/files/contracts/hd0008_signed.pdf',
        2,
        'CÓ_HIỆU_LỰC',
        FALSE
    ),
    (
        'HD0009',
        9,
        1,
        'HD/2024/009',
        '2024-03-15',
        '2024-04-01',
        NULL,
        1,
        1,
        60000000,
        85000000,
        73000000,
        'Hà Nội',
        'Giám đốc',
        'Nội dung hợp đồng...',
        '/files/contracts/hd0009.pdf',
        '/files/contracts/hd0009_signed.pdf',
        1,
        'CÓ_HIỆU_LỰC',
        FALSE
    ),
    (
        'HD0010',
        10,
        4,
        'HD/2024/010',
        '2024-04-01',
        '2024-04-15',
        '2024-06-14',
        6,
        8,
        7000000,
        7000000,
        6500000,
        'Bình Dương',
        'Nhân viên sản xuất',
        'Nội dung hợp đồng thử việc...',
        '/files/contracts/hd0010.pdf',
        '/files/contracts/hd0010_signed.pdf',
        3,
        'CÓ_HIỆU_LỰC',
        FALSE
    );
/* ===================================================== */
/* 7. Bổ sung thêm bằng cấp                               */
/* ===================================================== */
ALTER TABLE qualifications AUTO_INCREMENT = 6;
INSERT INTO qualifications (
        employee_id,
        qualification_type_id,
        qualification_name,
        major,
        school_name,
        graduation_year,
        graduation_grade,
        issued_date,
        issued_by,
        qualification_number,
        file_url,
        is_highest
    )
VALUES (
        6,
        3,
        'Thạc sĩ Marketing',
        'Hành vi người tiêu dùng',
        'Đại học Kinh tế Quốc dân',
        2020,
        'Xuất sắc',
        '2020-05-15',
        'ĐH Kinh tế Quốc dân',
        'TS2020-056',
        NULL,
        TRUE
    ),
    (
        7,
        3,
        'Cao đẳng Quản trị Dịch vụ',
        'Quản lý khách sạn',
        'Cao đẳng Du lịch Sài Gòn',
        2015,
        'Giỏi',
        '2015-07-30',
        'CĐ Du lịch',
        'CDDV2015-098',
        NULL,
        TRUE
    ),
    (
        8,
        1,
        'Cử nhân Logistics',
        'Quản lý chuỗi cung ứng',
        'Đại học Giao thông Vận tải',
        2016,
        'Trung bình khá',
        '2016-11-10',
        'ĐH GTVT',
        'LOG2016-567',
        NULL,
        TRUE
    ),
    (
        9,
        2,
        'Thạc sĩ Quản lý Công nghiệp',
        'Quản lý sản xuất',
        'Đại học Bách khoa Hà Nội',
        2013,
        'Giỏi',
        '2013-04-25',
        'ĐH Bách khoa HN',
        'QLCN2013-890',
        NULL,
        TRUE
    ),
    (
        10,
        1,
        'Trung cấp Kỹ thuật',
        'Cơ khí',
        'Trường Trung cấp Kỹ thuật Bình Dương',
        2018,
        'Khá',
        '2018-08-12',
        'TCKT Bình Dương',
        'TC2018-1234',
        NULL,
        TRUE
    );
/* ===================================================== */
/* 8. Bổ sung thêm chứng chỉ                              */
/* ===================================================== */
ALTER TABLE certificates AUTO_INCREMENT = 4;
INSERT INTO certificates (
        employee_id,
        certificate_type_id,
        certificate_name,
        issued_by,
        issued_date,
        expiry_date,
        certificate_number,
        score,
        file_url
    )
VALUES (
        6,
        2,
        'IELTS 7.5',
        'British Council',
        '2023-02-20',
        '2025-02-20',
        'IELTS-7.5-6789',
        7.50,
        NULL
    ),
    (
        7,
        3,
        'MOS Excel Expert',
        'Microsoft',
        '2023-08-15',
        '2026-08-15',
        'MOS-EX-12345',
        950.00,
        NULL
    ),
    (
        8,
        1,
        'TOEIC 850',
        'IIG Vietnam',
        '2023-05-10',
        '2025-05-10',
        'TOEIC850-001',
        850.00,
        NULL
    ),
    (
        9,
        2,
        'IELTS 7.5',
        'British Council',
        '2023-02-20',
        '2025-02-20',
        'IELTS-7.5-6789',
        7.50,
        NULL
    ),
    (
        10,
        3,
        'MOS Excel Expert',
        'Microsoft',
        '2023-08-15',
        '2026-08-15',
        'MOS-EX-12345',
        950.00,
        NULL
    );
/* ===================================================== */
/* 9. Bổ sung thêm CMND/CCCD                              */
/* ===================================================== */
ALTER TABLE identity_documents AUTO_INCREMENT = 6;
INSERT INTO identity_documents (
        employee_id,
        document_type_id,
        document_number,
        full_name,
        date_of_birth,
        issue_date,
        issue_place,
        expiry_date,
        front_image_url,
        back_image_url,
        has_chip
    )
VALUES (
        6,
        2,
        '067890123456',
        'Đặng Thị Ngọc',
        '1993-12-05',
        '2021-08-30',
        'Cục CSĐK Cư trú',
        '2041-08-30',
        NULL,
        NULL,
        TRUE
    ),
    (
        7,
        1,
        '078901234567',
        'Bùi Văn Quân',
        '1987-09-18',
        '2014-11-10',
        'CA Quảng Ninh',
        '2024-11-10',
        NULL,
        NULL,
        FALSE
    ),
    (
        8,
        2,
        '089012345678',
        'Vũ Thị Lan',
        '1994-04-22',
        '2022-02-18',
        'Cục CSĐK Cư trú',
        '2042-02-18',
        NULL,
        NULL,
        TRUE
    ),
    (
        9,
        1,
        '090123456789',
        'Đỗ Văn Hải',
        '1986-06-30',
        '2013-07-25',
        'CA Hà Nam',
        '2023-07-25',
        NULL,
        NULL,
        FALSE
    ),
    (
        10,
        3,
        'C12345678',
        'Lý Thị Hoa',
        '1995-10-15',
        '2023-04-12',
        'Cục Quản lý Xuất nhập cảnh',
        '2033-04-12',
        NULL,
        NULL,
        FALSE
    );
/* ===================================================== */
/* 10. Bổ sung thêm thông tin BHXH                         */
/* ===================================================== */
ALTER TABLE social_insurance_info AUTO_INCREMENT = 6;
INSERT INTO social_insurance_info (
        employee_id,
        social_insurance_number,
        health_insurance_number,
        tax_code,
        issue_date,
        issue_place,
        status
    )
VALUES (
        6,
        'BHXH-0006-6789012',
        'BHYT-0006-678901234',
        '6789012345',
        '2016-07-01',
        'BHXH Khánh Hòa',
        'ACTIVE'
    ),
    (
        7,
        'BHXH-0007-7890123',
        'BHYT-0007-789012345',
        '7890123456',
        '2012-10-01',
        'BHXH Quảng Ninh',
        'ACTIVE'
    ),
    (
        8,
        'BHXH-0008-8901234',
        'BHYT-0008-890123456',
        '8901234567',
        '2017-02-01',
        'BHXH Bắc Ninh',
        'ACTIVE'
    ),
    (
        9,
        'BHXH-0009-9012345',
        'BHYT-0009-901234567',
        '9012345678',
        '2011-05-01',
        'BHXH Hà Nam',
        'ACTIVE'
    ),
    (
        10,
        'BHXH-0010-0123456',
        'BHYT-0010-012345678',
        '0123456789',
        '2018-08-01',
        'BHXH Lạng Sơn',
        'ACTIVE'
    );
/* ===================================================== */
/* 11. Bổ sung thêm người phụ thuộc                        */
/* ===================================================== */
ALTER TABLE dependents AUTO_INCREMENT = 6;
INSERT INTO dependents (
        employee_id,
        full_name,
        relationship,
        date_of_birth,
        id_card_number,
        tax_code,
        deduction_percent,
        start_date,
        end_date,
        status
    )
VALUES (
        6,
        'Đặng Văn Thành',
        'Bố',
        '1960-05-20',
        '067890123457',
        NULL,
        100.00,
        '2016-07-01',
        NULL,
        TRUE
    ),
    (
        7,
        'Bùi Thị Thanh',
        'Vợ',
        '1989-07-22',
        '078901234568',
        NULL,
        100.00,
        '2012-10-01',
        NULL,
        TRUE
    ),
    (
        7,
        'Bùi Minh Tuấn',
        'Con',
        '2016-04-10',
        '078901234569',
        NULL,
        100.00,
        '2016-04-10',
        NULL,
        TRUE
    ),
    (
        9,
        'Đỗ Thị Hà',
        'Vợ',
        '1988-12-05',
        '090123456780',
        NULL,
        100.00,
        '2011-05-01',
        NULL,
        TRUE
    ),
    (
        9,
        'Đỗ Minh Khang',
        'Con',
        '2014-09-18',
        '090123456781',
        NULL,
        100.00,
        '2014-09-18',
        NULL,
        TRUE
    );
/* ===================================================== */
/* 12. Bổ sung thêm lịch sử công tác                       */
/* ===================================================== */
ALTER TABLE employment_histories AUTO_INCREMENT = 11;
INSERT INTO employment_histories (
        employee_id,
        department_id,
        position_id,
        start_date,
        end_date,
        is_current,
        decision_number,
        decision_date,
        notes
    )
VALUES (
        6,
        5,
        6,
        '2016-07-01',
        '2018-06-30',
        FALSE,
        'QĐ-2016-045',
        '2016-07-01',
        'Nhân viên Marketing'
    ),
    (
        6,
        5,
        5,
        '2018-07-01',
        NULL,
        TRUE,
        'QĐ-2018-067',
        '2018-06-25',
        'Chuyên viên Marketing'
    ),
    (
        7,
        6,
        6,
        '2012-10-01',
        '2015-09-30',
        FALSE,
        'QĐ-2012-023',
        '2012-10-01',
        'Nhân viên CSKH'
    ),
    (
        7,
        6,
        4,
        '2015-10-01',
        '2018-09-30',
        FALSE,
        'QĐ-2015-089',
        '2015-09-20',
        'Phó phòng CSKH'
    ),
    (
        7,
        6,
        3,
        '2018-10-01',
        NULL,
        TRUE,
        'QĐ-2018-156',
        '2018-09-15',
        'Trưởng phòng CSKH'
    ),
    (
        8,
        7,
        6,
        '2017-02-01',
        '2019-01-31',
        FALSE,
        'QĐ-2017-012',
        '2017-02-01',
        'Nhân viên kho'
    ),
    (
        8,
        7,
        5,
        '2019-02-01',
        '2021-01-31',
        FALSE,
        'QĐ-2019-045',
        '2019-01-20',
        'Chuyên viên kho'
    ),
    (
        8,
        7,
        4,
        '2021-02-01',
        NULL,
        TRUE,
        'QĐ-2021-089',
        '2021-01-25',
        'Phó phòng Kho'
    ),
    (
        9,
        1,
        1,
        '2011-05-01',
        NULL,
        TRUE,
        'QĐ-2011-001',
        '2011-04-20',
        'Giám đốc'
    ),
    (
        10,
        8,
        6,
        '2018-08-01',
        NULL,
        TRUE,
        'QĐ-2018-034',
        '2018-07-20',
        'Nhân viên sản xuất'
    );
/* ===================================================== */
/* 13. Bổ sung thêm quỹ phép năm                          */
/* ===================================================== */
ALTER TABLE leave_balances AUTO_INCREMENT = 6;
INSERT INTO leave_balances (
        employee_id,
        leave_type_id,
        year,
        base_leave,
        seniority_bonus,
        total_days,
        carried_over_days,
        carried_over_source,
        used_days,
        pending_days,
        carry_over_expiry_date,
        notes
    )
VALUES (
        6,
        1,
        2024,
        12.00,
        1.00,
        13.00,
        0.00,
        NULL,
        0.0,
        0.00,
        NULL,
        'Phép năm 2024'
    ),
    (
        7,
        1,
        2024,
        12.00,
        2.00,
        14.00,
        2.00,
        '2023',
        0.0,
        0.00,
        '2024-03-31',
        'Còn 16 ngày'
    ),
    (
        8,
        1,
        2024,
        12.00,
        0.00,
        12.00,
        1.00,
        '2023',
        0.0,
        0.00,
        '2024-03-31',
        'Còn 13 ngày'
    ),
    (
        9,
        1,
        2024,
        12.00,
        3.00,
        15.00,
        8.00,
        '2023',
        0.0,
        0.00,
        '2024-03-31',
        'Còn 23 ngày'
    ),
    (
        10,
        1,
        2024,
        12.00,
        0.00,
        12.00,
        0.00,
        NULL,
        0.0,
        0.00,
        NULL,
        'Nhân viên mới'
    );
/* ===================================================== */
/* 14. Bổ sung thêm đơn từ                                 */
/* ===================================================== */
ALTER TABLE requests AUTO_INCREMENT = 6;
INSERT INTO requests (
        request_code,
        request_type_id,
        requester_id,
        request_date,
        from_date,
        to_date,
        duration,
        reason,
        status,
        current_step_id,
        is_urgent,
        notes
    )
VALUES (
        'NP-2024-004',
        1,
        6,
        '2024-03-10',
        '2024-03-25 08:00:00',
        '2024-03-26 17:00:00',
        1.5,
        'Nghỉ ốm',
        'CHỜ_DUYỆT',
        1,
        FALSE,
        'Mới gửi'
    ),
    (
        'NP-2024-005',
        1,
        7,
        '2024-03-11',
        '2024-04-01 08:00:00',
        '2024-04-03 17:00:00',
        3.0,
        'Nghỉ phép về quê',
        'CHỜ_DUYỆT',
        1,
        FALSE,
        'Đã gửi'
    ),
    (
        'TC-2024-002',
        3,
        4,
        '2024-03-12',
        '2024-03-15 18:00:00',
        '2024-03-15 21:00:00',
        3.0,
        'Xử lý sự cố hệ thống',
        'ĐÃ_DUYỆT',
        2,
        TRUE,
        'Khẩn cấp'
    ),
    (
        'CT-2024-001',
        4,
        3,
        '2024-03-13',
        '2024-03-20 08:00:00',
        '2024-03-22 17:00:00',
        3.0,
        'Công tác tại Hà Nội gặp khách hàng',
        'CHỜ_DUYỆT',
        1,
        FALSE,
        'Chờ duyệt'
    ),
    (
        'TUL-2024-002',
        5,
        5,
        '2024-03-14',
        NULL,
        NULL,
        5000000,
        'Tạm ứng đóng học phí cho con',
        'CHỜ_DUYỆT',
        1,
        FALSE,
        'Mới gửi'
    );
/* ===================================================== */
/* 15. Bổ sung thêm lịch sử phê duyệt                      */
/* ===================================================== */
ALTER TABLE approval_histories AUTO_INCREMENT = 8;
INSERT INTO approval_histories (
        request_id,
        step_id,
        approver_id,
        action,
        comment,
        action_date
    )
VALUES (3, 1, 5, 'GỬI', 'Gửi đơn', '2024-03-03 09:00:00'),
    (
        4,
        1,
        1,
        'GỬI',
        'Gửi đơn tăng ca',
        '2024-03-01 10:00:00'
    ),
    (
        4,
        1,
        5,
        'DUYỆT',
        'Đồng ý',
        '2024-03-01 14:30:00'
    ),
    (4, 2, 1, 'DUYỆT', 'OK', '2024-03-01 15:45:00'),
    (
        5,
        1,
        1,
        'GỬI',
        'Gửi đơn tạm ứng',
        '2024-02-20 09:15:00'
    ),
    (
        5,
        2,
        2,
        'DUYỆT',
        'Đồng ý',
        '2024-02-20 11:30:00'
    ),
    (
        5,
        3,
        15,
        'DUYỆT',
        'Đã chuyển khoản',
        '2024-02-21 10:00:00'
    );
/* ===================================================== */
/* 16. Bổ sung thêm chi tiết nghỉ phép                     */
/* ===================================================== */
ALTER TABLE leave_requests AUTO_INCREMENT = 4;
INSERT INTO leave_requests (
        request_id,
        leave_type_id,
        employee_id,
        from_date,
        to_date,
        from_session,
        to_session,
        number_of_days,
        leave_used_type,
        base_days_used,
        seniority_days_used,
        carried_over_days_used,
        paid_days,
        unpaid_days,
        substitute_employee_id,
        handover_notes,
        contact_phone,
        emergency_contact
    )
VALUES (
        4,
        1,
        6,
        '2024-03-25',
        '2024-03-26',
        'CẢ_NGÀY',
        'CẢ_NGÀY',
        1.5,
        'BASE',
        1.5,
        0,
        0,
        1.5,
        0,
        5,
        'Đã bàn giao công việc',
        '0967890123',
        'Đặng Văn Thành - 0932109876'
    ),
    (
        5,
        1,
        7,
        '2024-04-01',
        '2024-04-03',
        'CẢ_NGÀY',
        'CẢ_NGÀY',
        3.0,
        'BASE',
        3.0,
        0,
        0,
        3.0,
        0,
        8,
        'Đã bàn giao cho Vũ Thị Lan',
        '0978901234',
        'Bùi Thị Thanh - 0921098765'
    );
/* ===================================================== */
/* 17. Bổ sung thêm yêu cầu bảo hiểm                      */
/* ===================================================== */
ALTER TABLE insurance_claims AUTO_INCREMENT = 3;
INSERT INTO insurance_claims (
        employee_id,
        request_id,
        insurance_type_id,
        claim_code,
        leave_request_id,
        start_date,
        end_date,
        total_days,
        daily_rate,
        total_amount,
        payment_source,
        certificate_number,
        certificate_file_url,
        certificate_uploaded_date,
        bank_account,
        bank_id,
        payment_status,
        notes
    )
VALUES (
        6,
        4,
        1,
        'BHXH-2024-003',
        4,
        '2024-03-25',
        '2024-03-26',
        1.5,
        420000,
        630000,
        'BHXH',
        'GCN-2024-003',
        NULL,
        '2024-03-27 09:15:00',
        '678912345',
        6,
        'CHỜ_XỬ_LÝ',
        'Nghỉ ốm hưởng BHXH'
    ),
    (
        7,
        5,
        1,
        'BHXH-2024-004',
        5,
        '2024-04-01',
        '2024-04-03',
        3,
        400000,
        1200000,
        'BHXH',
        'GCN-2024-004',
        NULL,
        '2024-04-04 10:30:00',
        '789123456',
        7,
        'CHỜ_XỬ_LÝ',
        'Nghỉ phép hưởng BHXH'
    );
/* ===================================================== */
/* 18. Bổ sung thêm biến động phép                         */
/* ===================================================== */
ALTER TABLE leave_transactions AUTO_INCREMENT = 7;
INSERT INTO leave_transactions (
        employee_id,
        leave_type_id,
        transaction_date,
        transaction_type,
        quantity,
        before_balance,
        after_balance,
        reference_id,
        reference_type,
        reason
    )
VALUES (
        3,
        1,
        '2024-01-01',
        'CẤP_PHÉP',
        14.0,
        0,
        14.0,
        3,
        'ANNUAL_GRANT',
        'Cấp phép năm 2024'
    ),
    (
        4,
        1,
        '2024-01-01',
        'CẤP_PHÉP',
        13.0,
        0,
        13.0,
        4,
        'ANNUAL_GRANT',
        'Cấp phép năm 2024'
    ),
    (
        5,
        1,
        '2024-01-01',
        'CẤP_PHÉP',
        15.0,
        0,
        15.0,
        5,
        'ANNUAL_GRANT',
        'Cấp phép năm 2024'
    ),
    (
        5,
        1,
        '2024-01-02',
        'CHUYỂN_NĂM',
        5.0,
        15.0,
        20.0,
        5,
        'CARRY_OVER',
        'Chuyển phép từ 2023'
    ),
    (
        6,
        1,
        '2024-01-01',
        'CẤP_PHÉP',
        13.0,
        0,
        13.0,
        6,
        'ANNUAL_GRANT',
        'Cấp phép năm 2024'
    ),
    (
        7,
        1,
        '2024-01-01',
        'CẤP_PHÉP',
        14.0,
        0,
        14.0,
        7,
        'ANNUAL_GRANT',
        'Cấp phép năm 2024'
    ),
    (
        7,
        1,
        '2024-01-02',
        'CHUYỂN_NĂM',
        2.0,
        14.0,
        16.0,
        7,
        'CARRY_OVER',
        'Chuyển phép từ 2023'
    ),
    (
        8,
        1,
        '2024-01-01',
        'CẤP_PHÉP',
        12.0,
        0,
        12.0,
        8,
        'ANNUAL_GRANT',
        'Cấp phép năm 2024'
    ),
    (
        8,
        1,
        '2024-01-02',
        'CHUYỂN_NĂM',
        1.0,
        12.0,
        13.0,
        8,
        'CARRY_OVER',
        'Chuyển phép từ 2023'
    ),
    (
        9,
        1,
        '2024-01-01',
        'CẤP_PHÉP',
        15.0,
        0,
        15.0,
        9,
        'ANNUAL_GRANT',
        'Cấp phép năm 2024'
    ),
    (
        9,
        1,
        '2024-01-02',
        'CHUYỂN_NĂM',
        8.0,
        15.0,
        23.0,
        9,
        'CARRY_OVER',
        'Chuyển phép từ 2023'
    ),
    (
        10,
        1,
        '2024-01-01',
        'CẤP_PHÉP',
        12.0,
        0,
        12.0,
        10,
        'ANNUAL_GRANT',
        'Cấp phép năm 2024'
    );
/* ===================================================== */
/* 19. Bổ sung thêm đăng ký ca làm việc                    */
/* ===================================================== */
ALTER TABLE shift_assignments AUTO_INCREMENT = 6;
INSERT INTO shift_assignments (
        employee_id,
        shift_type_id,
        effective_date,
        expiry_date,
        is_permanent,
        assigned_by,
        notes,
        status
    )
VALUES (
        6,
        1,
        '2024-01-01',
        NULL,
        TRUE,
        1,
        'Ca hành chính cố định',
        'HIỆU_LỰC'
    ),
    (
        7,
        1,
        '2024-01-01',
        NULL,
        TRUE,
        1,
        'Ca hành chính cố định',
        'HIỆU_LỰC'
    ),
    (
        8,
        1,
        '2024-01-01',
        NULL,
        TRUE,
        1,
        'Ca hành chính cố định',
        'HIỆU_LỰC'
    ),
    (
        9,
        1,
        '2024-01-01',
        NULL,
        TRUE,
        1,
        'Ca hành chính cố định',
        'HIỆU_LỰC'
    ),
    (
        10,
        4,
        '2024-01-01',
        NULL,
        TRUE,
        1,
        'Ca đêm sản xuất',
        'HIỆU_LỰC'
    );
/* ===================================================== */
/* 20. Bổ sung thêm chấm công                              */
/* ===================================================== */
ALTER TABLE attendances AUTO_INCREMENT = 6;
INSERT INTO attendances (
        employee_id,
        attendance_date,
        shift_type_id,
        check_in_time,
        check_out_time,
        check_in_method,
        check_out_method,
        work_type,
        actual_working_hours,
        overtime_hours,
        late_minutes,
        early_leave_minutes,
        is_holiday,
        is_overtime,
        status,
        notes
    )
VALUES (
        6,
        '2024-03-01',
        1,
        '2024-03-01 07:59:00',
        '2024-03-01 17:01:00',
        'MÁY_QUÉT',
        'MÁY_QUÉT',
        'VĂN_PHÒNG',
        8.0,
        0,
        0,
        0,
        FALSE,
        FALSE,
        'ĐÃ_DUYỆT',
        NULL
    ),
    (
        7,
        '2024-03-01',
        1,
        '2024-03-01 08:00:00',
        '2024-03-01 17:00:00',
        'MÁY_QUÉT',
        'MÁY_QUÉT',
        'VĂN_PHÒNG',
        8.0,
        0,
        0,
        0,
        FALSE,
        FALSE,
        'ĐÃ_DUYỆT',
        NULL
    ),
    (
        8,
        '2024-03-01',
        1,
        '2024-03-01 08:05:00',
        '2024-03-01 17:10:00',
        'MÁY_QUÉT',
        'MÁY_QUÉT',
        'VĂN_PHÒNG',
        8.1,
        0,
        5,
        10,
        FALSE,
        FALSE,
        'ĐÃ_DUYỆT',
        'Đi muộn 5 phút'
    ),
    (
        9,
        '2024-03-01',
        1,
        '2024-03-01 08:30:00',
        '2024-03-01 17:30:00',
        'MÁY_QUÉT',
        'MÁY_QUÉT',
        'VĂN_PHÒNG',
        8.0,
        0,
        30,
        0,
        FALSE,
        FALSE,
        'ĐÃ_DUYỆT',
        'Đi muộn 30 phút'
    ),
    (
        10,
        '2024-03-01',
        4,
        '2024-02-29 21:55:00',
        '2024-03-01 06:05:00',
        'MÁY_QUÉT',
        'MÁY_QUÉT',
        'VĂN_PHÒNG',
        8.2,
        0,
        0,
        5,
        FALSE,
        FALSE,
        'ĐÃ_DUYỆT',
        'Ca đêm'
    );
/* ===================================================== */
/* 21. Bổ sung thêm phụ cấp theo nhân viên                 */
/* ===================================================== */
ALTER TABLE employee_allowances AUTO_INCREMENT = 9;
INSERT INTO employee_allowances (
        employee_id,
        allowance_id,
        amount,
        percentage,
        effective_date,
        expiry_date,
        is_active,
        notes
    )
VALUES (
        4,
        2,
        30000,
        NULL,
        '2024-01-01',
        NULL,
        TRUE,
        'Phụ cấp ăn trưa'
    ),
    (
        4,
        3,
        300000,
        NULL,
        '2024-01-01',
        NULL,
        TRUE,
        'Phụ cấp xăng xe'
    ),
    (
        5,
        1,
        5000000,
        NULL,
        '2024-01-01',
        NULL,
        TRUE,
        'Phụ cấp phó giám đốc'
    ),
    (
        5,
        2,
        30000,
        NULL,
        '2024-01-01',
        NULL,
        TRUE,
        'Phụ cấp ăn trưa'
    ),
    (
        5,
        3,
        800000,
        NULL,
        '2024-01-01',
        NULL,
        TRUE,
        'Phụ cấp xăng xe'
    ),
    (
        5,
        5,
        1500000,
        NULL,
        '2024-01-01',
        NULL,
        TRUE,
        'Phụ cấp thâm niên'
    ),
    (
        6,
        2,
        30000,
        NULL,
        '2024-01-01',
        NULL,
        TRUE,
        'Phụ cấp ăn trưa'
    ),
    (
        6,
        3,
        300000,
        NULL,
        '2024-01-01',
        NULL,
        TRUE,
        'Phụ cấp xăng xe'
    ),
    (
        7,
        2,
        30000,
        NULL,
        '2024-01-01',
        NULL,
        TRUE,
        'Phụ cấp ăn trưa'
    ),
    (
        7,
        3,
        400000,
        NULL,
        '2024-01-01',
        NULL,
        TRUE,
        'Phụ cấp xăng xe'
    );
/* ===================================================== */
/* 22. Bổ sung thêm khấu trừ theo nhân viên                */
/* ===================================================== */
ALTER TABLE employee_deductions AUTO_INCREMENT = 10;
INSERT INTO employee_deductions (
        employee_id,
        deduction_id,
        amount,
        percentage,
        effective_date,
        expiry_date,
        is_active,
        notes
    )
VALUES (4, 1, NULL, 8, '2024-01-01', NULL, TRUE, 'BHXH'),
    (
        4,
        2,
        NULL,
        1.5,
        '2024-01-01',
        NULL,
        TRUE,
        'BHYT'
    ),
    (4, 3, NULL, 1, '2024-01-01', NULL, TRUE, 'BHTN'),
    (5, 1, NULL, 8, '2024-01-01', NULL, TRUE, 'BHXH'),
    (
        5,
        2,
        NULL,
        1.5,
        '2024-01-01',
        NULL,
        TRUE,
        'BHYT'
    ),
    (5, 3, NULL, 1, '2024-01-01', NULL, TRUE, 'BHTN'),
    (6, 1, NULL, 8, '2024-01-01', NULL, TRUE, 'BHXH'),
    (
        6,
        2,
        NULL,
        1.5,
        '2024-01-01',
        NULL,
        TRUE,
        'BHYT'
    ),
    (6, 3, NULL, 1, '2024-01-01', NULL, TRUE, 'BHTN'),
    (7, 1, NULL, 8, '2024-01-01', NULL, TRUE, 'BHXH'),
    (
        7,
        2,
        NULL,
        1.5,
        '2024-01-01',
        NULL,
        TRUE,
        'BHYT'
    ),
    (7, 3, NULL, 1, '2024-01-01', NULL, TRUE, 'BHTN');
/* ===================================================== */
/* 23. Bổ sung thêm bảng lương chi tiết                    */
/* ===================================================== */
ALTER TABLE salary_details AUTO_INCREMENT = 4;
INSERT INTO salary_details (
        period_id,
        employee_id,
        contract_id,
        basic_salary,
        gross_salary,
        net_salary,
        total_allowances,
        total_deductions,
        overtime_pay,
        leave_pay,
        bonus,
        penalty,
        social_insurance_employee,
        health_insurance_employee,
        unemployment_insurance_employee,
        personal_income_tax,
        advance_payment,
        bank_account,
        bank_name,
        transfer_status,
        notes
    )
VALUES (
        3,
        4,
        4,
        15000000,
        20000000,
        17500000,
        600000,
        2000000,
        0,
        0,
        500000,
        0,
        1200000,
        225000,
        150000,
        500000,
        0,
        '456789123',
        'Techcombank',
        'PENDING',
        'Lương tháng 3'
    ),
    (
        3,
        5,
        5,
        40000000,
        55000000,
        48000000,
        7300000,
        5500000,
        0,
        0,
        5000000,
        0,
        3200000,
        600000,
        400000,
        3500000,
        0,
        '567891234',
        'MB Bank',
        'PENDING',
        'Lương tháng 3'
    ),
    (
        3,
        6,
        6,
        8000000,
        8000000,
        7500000,
        600000,
        800000,
        0,
        0,
        0,
        0,
        640000,
        120000,
        80000,
        0,
        0,
        '678912345',
        'ACB',
        'PENDING',
        'Lương thử việc'
    ),
    (
        3,
        7,
        7,
        22000000,
        30000000,
        26000000,
        700000,
        3000000,
        0,
        0,
        1500000,
        0,
        1760000,
        330000,
        220000,
        1200000,
        0,
        '789123456',
        'VPBank',
        'PENDING',
        'Lương tháng 3'
    );
/* ===================================================== */
/* 24. Bổ sung thêm phân vai trò cho nhân viên             */
/* ===================================================== */
ALTER TABLE employee_roles AUTO_INCREMENT = 9;
INSERT INTO employee_roles (
        employee_id,
        role_id,
        department_id,
        effective_date,
        expiry_date,
        is_active
    )
VALUES (6, 4, 5, '2024-01-01', NULL, TRUE),
    (7, 3, 6, '2024-01-01', NULL, TRUE),
    (7, 4, 6, '2024-01-01', NULL, TRUE),
    (8, 4, 7, '2024-01-01', NULL, TRUE),
    (9, 1, 1, '2024-01-01', NULL, TRUE),
    -- ADMIN
    (9, 4, 1, '2024-01-01', NULL, TRUE),
    (10, 4, 8, '2024-01-01', NULL, TRUE),
    (11, 3, 3, '2024-01-01', NULL, TRUE),
    (11, 4, 3, '2024-01-01', NULL, TRUE);
/* ===================================================== */
/* 25. Bổ sung thêm tin tức                                */
/* ===================================================== */
ALTER TABLE news AUTO_INCREMENT = 4;
INSERT INTO news (
        news_code,
        category_id,
        title,
        summary,
        content,
        priority,
        is_important,
        is_pinned,
        published_date,
        expiry_date,
        published_by,
        department_id,
        position_id,
        view_count,
        status
    )
VALUES (
        'NEWS-2024-004',
        1,
        'Thông báo tuyển dụng thực tập sinh',
        'Cơ hội cho sinh viên thực tập tại công ty',
        'Chi tiết chương trình thực tập...',
        'TRUNG_BÌNH',
        FALSE,
        FALSE,
        '2024-03-25 09:00:00',
        '2024-04-30 17:00:00',
        2,
        1,
        3,
        45,
        'ĐÃ_XUẤT_BẢN'
    ),
    (
        'NEWS-2024-005',
        2,
        'Giải bóng đá nội bộ năm 2024',
        'Đăng ký tham gia giải đấu',
        'Thể lệ và lịch thi đấu...',
        'THẤP',
        FALSE,
        FALSE,
        '2024-03-26 14:00:00',
        '2024-05-20 17:00:00',
        6,
        3,
        5,
        78,
        'ĐÃ_XUẤT_BẢN'
    ),
    (
        'NEWS-2024-006',
        3,
        'Cập nhật quy định về trang phục',
        'Quy định trang phục mới áp dụng từ tháng 5/2024',
        'Nội dung chi tiết...',
        'TRUNG_BÌNH',
        TRUE,
        FALSE,
        '2024-03-27 10:30:00',
        NULL,
        2,
        1,
        3,
        92,
        'ĐÃ_XUẤT_BẢN'
    );
/* ===================================================== */
/* 26. Bổ sung thêm thông báo                              */
/* ===================================================== */
ALTER TABLE notifications AUTO_INCREMENT = 5;
INSERT INTO notifications (
        notification_type,
        title,
        content,
        receiver_id,
        priority,
        reference_type,
        reference_id,
        action_url,
        expires_at
    )
VALUES (
        'LEAVE_EXPIRY',
        'Phép gộp sắp hết hạn',
        'Bạn còn 5 ngày phép gộp từ năm 2023 sẽ hết hạn vào ngày 31/03/2024',
        5,
        'CAO',
        'LEAVE_ADVANCEMENT',
        5,
        '/leave/balances',
        '2024-04-01 00:00:00'
    ),
    (
        'LEAVE_EXPIRY',
        'Phép gộp sắp hết hạn',
        'Bạn còn 8 ngày phép gộp từ năm 2023 sẽ hết hạn vào ngày 31/03/2024',
        9,
        'CAO',
        'LEAVE_ADVANCEMENT',
        9,
        '/leave/balances',
        '2024-04-01 00:00:00'
    ),
    (
        'APPROVAL_REMINDER',
        'Nhắc duyệt đơn xin nghỉ phép',
        'Bạn có đơn xin nghỉ phép NP-2024-004 đang chờ duyệt',
        5,
        'CAO',
        'LEAVE_REQUEST',
        4,
        '/approval/4',
        '2024-03-26 00:00:00'
    ),
    (
        'APPROVAL_REMINDER',
        'Nhắc duyệt đơn xin nghỉ phép',
        'Bạn có đơn xin nghỉ phép NP-2024-005 đang chờ duyệt',
        7,
        'CAO',
        'LEAVE_REQUEST',
        5,
        '/approval/5',
        '2024-04-02 00:00:00'
    ),
    (
        'SALARY',
        'Thông báo lương tháng 3',
        'Lương tháng 3/2024 sẽ được chi trả vào ngày 05/04/2024',
        NULL,
        'CAO',
        NULL,
        NULL,
        '/salary',
        '2024-04-05 00:00:00'
    );
/* ===================================================== */
/* 27. Bổ sung thêm báo cáo mẫu                            */
/* ===================================================== */
ALTER TABLE report_templates AUTO_INCREMENT = 4;
INSERT INTO report_templates (
        template_code,
        template_name,
        report_type,
        sql_query,
        columns_config,
        filters_config,
        chart_config,
        created_by,
        is_public,
        status
    )
VALUES (
        'RP_ATTENDANCE',
        'Báo cáo chấm công tháng',
        'CHAM_CONG',
        'SELECT e.full_name, a.attendance_date, a.check_in_time, a.check_out_time, a.actual_working_hours FROM attendances a JOIN employees e ON a.employee_id = e.employee_id WHERE MONTH(a.attendance_date) = ? AND YEAR(a.attendance_date) = ?',
        '{"columns":["Họ tên","Ngày","Giờ vào","Giờ ra","Số giờ"]}',
        '{"filters":["thang","nam"]}',
        NULL,
        1,
        TRUE,
        TRUE
    ),
    (
        'RP_DEPARTMENT',
        'Thống kê nhân sự theo phòng ban',
        'TONG_HOP',
        'SELECT d.department_name, COUNT(e.employee_id) as tong_nv, SUM(CASE WHEN e.status = "ĐANG_LÀM_VIỆC" THEN 1 ELSE 0 END) as dang_lam FROM departments d LEFT JOIN employment_histories eh ON d.department_id = eh.department_id AND eh.is_current = TRUE LEFT JOIN employees e ON eh.employee_id = e.employee_id GROUP BY d.department_id',
        '{"columns":["Phòng ban","Tổng NV","Đang làm"]}',
        '{}',
        '{"type":"pie","data":"tong_nv"}',
        1,
        TRUE,
        TRUE
    ),
    (
        'RP_LEAVE_BALANCE',
        'Số dư phép nhân viên',
        'NGHI_PHEP',
        'SELECT e.full_name, d.department_name, lb.total_days, lb.used_days, lb.remaining_days, lb.carried_over_days FROM leave_balances lb JOIN employees e ON lb.employee_id = e.employee_id JOIN departments d ON e.department_id = d.department_id WHERE lb.year = YEAR(CURDATE())',
        '{"columns":["Họ tên","Phòng ban","Tổng","Đã dùng","Còn lại","Gộp"]}',
        '{"filters":["phong_ban"]}',
        '{"type":"bar","data":"remaining_days"}',
        1,
        TRUE,
        TRUE
    );
/* ===================================================== */
/* 28. Bổ sung thêm cấu hình hệ thống                      */
/* ===================================================== */
ALTER TABLE system_configs AUTO_INCREMENT = 14;
INSERT INTO system_configs (
        config_key,
        config_value,
        config_type,
        description,
        module
    )
VALUES (
        'overtime_rate_weekday',
        '1.5',
        'NUMBER',
        'Hệ số tăng ca ngày thường',
        'ATTENDANCE'
    ),
    (
        'overtime_rate_weekend',
        '2.0',
        'NUMBER',
        'Hệ số tăng ca cuối tuần',
        'ATTENDANCE'
    ),
    (
        'overtime_rate_holiday',
        '3.0',
        'NUMBER',
        'Hệ số tăng ca ngày lễ',
        'ATTENDANCE'
    ),
    (
        'company_social_insurance_rate',
        '17.5',
        'NUMBER',
        'Tỷ lệ BHXH công ty đóng (%)',
        'SALARY'
    ),
    (
        'company_health_insurance_rate',
        '3.0',
        'NUMBER',
        'Tỷ lệ BHYT công ty đóng (%)',
        'SALARY'
    ),
    (
        'company_unemployment_insurance_rate',
        '1.0',
        'NUMBER',
        'Tỷ lệ BHTN công ty đóng (%)',
        'SALARY'
    ),
    (
        'advance_salary_limit_percent',
        '30',
        'NUMBER',
        'Giới hạn tạm ứng lương (% lương)',
        'SALARY'
    ),
    (
        'min_salary',
        '4680000',
        'NUMBER',
        'Lương tối thiểu vùng',
        'SALARY'
    ),
    (
        'allow_remote_checkin',
        'true',
        'BOOLEAN',
        'Cho phép chấm công từ xa',
        'ATTENDANCE'
    ),
    (
        'maintenance_mode',
        'false',
        'BOOLEAN',
        'Chế độ bảo trì',
        'SYSTEM'
    ),
    (
        'last_backup',
        '2024-03-07 02:00:00',
        'TEXT',
        'Lần backup cuối',
        'SYSTEM'
    ),
    (
        'admin_email',
        'admin@abchrm.vn',
        'TEXT',
        'Email quản trị',
        'SYSTEM'
    ),
    (
        'currency',
        'VND',
        'TEXT',
        'Đơn vị tiền tệ',
        'GENERAL'
    ),
    (
        'timezone',
        'Asia/Ho_Chi_Minh',
        'TEXT',
        'Múi giờ',
        'GENERAL'
    );
/* ===================================================== */
/* KẾT THÚC BỔ SUNG DỮ LIỆU                               */
/* ===================================================== */
