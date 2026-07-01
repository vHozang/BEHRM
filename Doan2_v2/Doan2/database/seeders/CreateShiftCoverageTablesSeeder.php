<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHỦ CA KHI VẮNG ĐỘT XUẤT (shift coverage / điều người tăng ca).
 *
 * `shift_coverage_requests` = một slot ca bị hổng (có người vắng/nghỉ/no-show
 * hoặc cần thêm người) trong 1 ngày, cần được phủ đủ `hours_needed` giờ.
 * `shift_coverage_offers` = chuỗi GIAO CA: mỗi dòng là một người được mời ở lại
 * tăng ca phủ một khúc giờ (start_time→end_time). Khi NV nhận lời, hệ thống tạo
 * một đơn tăng ca (overtime_requests) tương ứng và link lại. Idempotent.
 */
class CreateShiftCoverageTablesSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('shift_coverage_requests')) {
            Schema::create('shift_coverage_requests', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->unsignedBigInteger('legal_entity_id')->nullable();
                $t->date('work_date');
                $t->unsignedBigInteger('shift_type_id');
                $t->unsignedBigInteger('absent_employee_id')->nullable(); // ai vắng (nếu có)
                $t->string('reason')->nullable();      // sick / leave / no_show / extra_demand
                $t->decimal('hours_needed', 6, 2)->default(8);
                $t->decimal('hours_covered', 6, 2)->default(0);
                // OPEN | PARTIALLY_COVERED | COVERED | CANCELLED
                $t->string('status')->default('OPEN');
                $t->unsignedBigInteger('created_by')->nullable();
                $t->text('notes')->nullable();
                $t->jsonb('meta')->nullable();
                $t->timestamps();

                $t->index(['tenant_id', 'work_date']);
                $t->index('status');
            });
            $this->command?->info('Đã tạo bảng shift_coverage_requests.');
        } else {
            $this->command?->info('shift_coverage_requests đã tồn tại — bỏ qua.');
        }

        if (! Schema::hasTable('shift_coverage_offers')) {
            Schema::create('shift_coverage_offers', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->unsignedBigInteger('legal_entity_id')->nullable();
                $t->unsignedBigInteger('coverage_request_id');
                $t->unsignedBigInteger('employee_id');       // người được mời phủ ca
                $t->time('start_time')->nullable();
                $t->time('end_time')->nullable();
                $t->decimal('hours', 6, 2)->default(0);
                // OFFERED | ACCEPTED | DECLINED | CANCELLED
                $t->string('status')->default('OFFERED');
                $t->unsignedBigInteger('overtime_request_id')->nullable();
                $t->jsonb('meta')->nullable();
                $t->timestamps();

                $t->index(['tenant_id', 'employee_id']);
                $t->index('coverage_request_id');
                $t->index('status');
            });
            $this->command?->info('Đã tạo bảng shift_coverage_offers.');
        } else {
            $this->command?->info('shift_coverage_offers đã tồn tại — bỏ qua.');
        }
    }
}
