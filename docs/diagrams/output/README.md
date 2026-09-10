# Bộ sơ đồ HRM System

Tài liệu được dựng theo cấu trúc của file Word mẫu `nhóm 1 (4).docx`, nhưng nội dung lấy từ source và database PostgreSQL hiện tại của dự án `BEHRM`.

1. `01-use-case.svg`: Sơ đồ Use Case.
2. `02-main-tables.svg`: Các bảng chính cho ERD, nhóm theo module.
3. `03-erd-relations.svg`: Sơ đồ quan hệ ERD mức khái niệm.
4. `04-detailed-erd.svg`: Sơ đồ chi tiết thực thể với trường PK/FK quan trọng.

Các bảng framework (`cache`, `sessions`, `jobs`, `failed_jobs`...) và các partition con của `attendance_logs` không được đưa vào sơ đồ nghiệp vụ.
