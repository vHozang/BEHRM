<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: Thêm cột JSONB `skills` vào bảng `employees` và tạo GIN Index.
 *
 * Tại sao JSONB thay vì JSON?
 *  - JSONB lưu dữ liệu ở dạng binary (đã parse), nhanh hơn khi truy vấn.
 *  - JSON lưu dạng text thuần, nhanh hơn khi INSERT nhưng chậm khi query.
 *  - JSONB hỗ trợ GIN index, cho phép tìm kiếm trong cấu trúc JSON cực nhanh.
 *
 * GIN Index (Generalized Inverted Index) là gì?
 *  - Phù hợp cho dữ liệu composite: JSONB, ARRAY, full-text search.
 *  - Lưu trữ mapping từ mỗi "element" trong JSON → list các row chứa nó.
 *  - Cho phép các operator @>, ?, ?|, ?& trên JSONB hoạt động với O(log n).
 *  - Nhược điểm: INSERT/UPDATE chậm hơn B-Tree, phù hợp cho read-heavy data.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function getConnection(): string
    {
        return config('database.default');
    }

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            Schema::table('employees', function (Blueprint $table): void {
                if (! Schema::hasColumn('employees', 'skills')) {
                    $table->json('skills')->default('[]');
                }
                if (! Schema::hasColumn('employees', 'manager_id')) {
                    $table->foreignId('manager_id')->nullable()->constrained('employees')->nullOnDelete();
                }
                if (! Schema::hasColumn('employees', 'base_salary')) {
                    $table->decimal('base_salary', 15, 2)->nullable();
                }
            });

            return;
        }

        // ── Bước 1: Thêm cột JSONB `skills` vào bảng employees ──────────────
        // Cột `skills` lưu danh sách kỹ năng dạng structured JSON, ví dụ:
        // [
        //   {"name": "PHP", "level": "senior", "years": 5},
        //   {"name": "PostgreSQL", "level": "mid", "years": 3}
        // ]
        DB::statement("
            ALTER TABLE employees
                ADD COLUMN IF NOT EXISTS skills JSONB DEFAULT '[]'::JSONB NOT NULL
        ");

        // ── Bước 2: Thêm các Database Constraints còn thiếu ──────────────────

        // CHECK constraint: base_salary phải dương (được lưu trong cột profile JSONB,
        // nhưng cho các hợp đồng thì salary nằm trong bảng contracts/salary_details.
        // Ở đây ta thêm constraint cho bảng employees nếu có cột relevant.
        // Constraint được đặt tên rõ ràng để dễ DROP sau này.

        // Đảm bảo employee_code không NULL khi nhân viên đã được cấp mã
        // (dùng CHECK để enforce business rule tại tầng database)
        DB::statement("
            ALTER TABLE employees
                ADD CONSTRAINT chk_employee_code_format
                CHECK (employee_code IS NULL OR employee_code ~ '^[A-Z]{2,4}[0-9]{4,8}$')
        ");

        // CHECK constraint: gender chỉ nhận các giá trị hợp lệ
        DB::statement("
            ALTER TABLE employees
                ADD CONSTRAINT chk_employee_gender
                CHECK (gender IS NULL OR gender IN ('MALE', 'FEMALE', 'OTHER', 'NAM', 'NỮ'))
        ");

        // CHECK constraint: status hợp lệ
        DB::statement("
            ALTER TABLE employees
                ADD CONSTRAINT chk_employee_status
                CHECK (status IN ('ACTIVE', 'INACTIVE', 'ON_LEAVE', 'TERMINATED', 'PROBATION'))
        ");

        // CHECK constraint: hire_date không được ở trong tương lai quá xa
        DB::statement("
            ALTER TABLE employees
                ADD CONSTRAINT chk_employee_hire_date
                CHECK (hire_date IS NULL OR hire_date <= CURRENT_DATE + INTERVAL '7 days')
        ");

        // CHECK constraint: skills phải là JSON array (không phải object hay primitive)
        // jsonb_typeof() là hàm đặc thù PostgreSQL kiểm tra kiểu dữ liệu JSON
        DB::statement("
            ALTER TABLE employees
                ADD CONSTRAINT chk_skills_is_array
                CHECK (jsonb_typeof(skills) = 'array')
        ");

        // ── Bước 3: Tạo GIN Index cho cột JSONB `skills` ────────────────────
        // jsonb_path_ops: operator class tối ưu cho @> (containment queries)
        // Chỉ hỗ trợ @> operator nhưng nhỏ hơn default GIN ~3x
        DB::statement('
            CREATE INDEX IF NOT EXISTS idx_employees_skills_gin
                ON employees USING GIN (skills jsonb_path_ops)
        ');

        // B-Tree cho emergency_contact (chỉ tìm trên text chính xác)
        // jsonb_extract_path_text() sẽ chuyển GIN lookup -> B-Tree lookup (nhanh hơn cho exact match)
        DB::statement("
            CREATE INDEX IF NOT EXISTS idx_employees_emergency_phone
                ON employees ((profile->'emergency_contact'->>'phone_number'))
        ");

        // ── Bước 4: Thêm thêm cột `manager_id` để xây dựng org chart tree ──
        // Self-referential FK: nhân viên -> Line Manager (cùng bảng employees)
        // Đây là cơ sở cho truy vấn CTE đệ quy sau này
        DB::statement('
            ALTER TABLE employees
                ADD COLUMN IF NOT EXISTS manager_id BIGINT REFERENCES employees(id) ON DELETE SET NULL
        ');

        // Index cho manager_id để đi ngược cây (ai quản lý ai)
        DB::statement('
            CREATE INDEX IF NOT EXISTS idx_employees_manager_id
                ON employees (manager_id)
                WHERE manager_id IS NOT NULL
        ');

        // ── Bước 5: Thêm cột `base_salary` vào employees ────────────────────
        // (Một số hệ thống lưu mức lương cơ bản trực tiếp trên employee)
        DB::statement('
            ALTER TABLE employees
                ADD COLUMN IF NOT EXISTS base_salary NUMERIC(15, 2)
        ');

        // CHECK constraint: base_salary phải dương nếu được khai báo
        DB::statement('
            ALTER TABLE employees
                ADD CONSTRAINT chk_employee_base_salary_positive
                CHECK (base_salary IS NULL OR base_salary > 0)
        ');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            Schema::table('employees', function (Blueprint $table): void {
                $table->dropColumn(['skills', 'manager_id', 'base_salary']);
            });

            return;
        }

        // Xóa các constraint trước khi xóa cột
        DB::statement('ALTER TABLE employees DROP CONSTRAINT IF EXISTS chk_employee_base_salary_positive');
        DB::statement('ALTER TABLE employees DROP CONSTRAINT IF EXISTS chk_skills_is_array');
        DB::statement('ALTER TABLE employees DROP CONSTRAINT IF EXISTS chk_employee_hire_date');
        DB::statement('ALTER TABLE employees DROP CONSTRAINT IF EXISTS chk_employee_status');
        DB::statement('ALTER TABLE employees DROP CONSTRAINT IF EXISTS chk_employee_gender');
        DB::statement('ALTER TABLE employees DROP CONSTRAINT IF EXISTS chk_employee_code_format');

        // Xóa các index
        DB::statement('DROP INDEX IF EXISTS idx_employees_manager_id');
        DB::statement('DROP INDEX IF EXISTS idx_employees_emergency_phone');
        DB::statement('DROP INDEX IF EXISTS idx_employees_skills_gin');

        // Xóa các cột
        DB::statement('ALTER TABLE employees DROP COLUMN IF EXISTS base_salary');
        DB::statement('ALTER TABLE employees DROP COLUMN IF EXISTS manager_id');
        DB::statement('ALTER TABLE employees DROP COLUMN IF EXISTS skills');
    }
};
