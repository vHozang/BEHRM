<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bảng CÔNG KHOÁN theo sản phẩm (piece-rate) cho công nhân: lương = số lượng ×
 * đơn giá. Mỗi dòng = 1 lần ghi nhận sản lượng của 1 nhân viên trong 1 ngày.
 * Payroll cộng tổng amount của kỳ vào thu nhập. Idempotent.
 */
class CreatePieceRateTableSeeder extends Seeder
{
    public function run(): void
    {
        if (Schema::hasTable('piece_rate_entries')) {
            $this->command?->info('piece_rate_entries đã tồn tại — bỏ qua.');

            return;
        }

        Schema::create('piece_rate_entries', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('legal_entity_id')->nullable();
            $t->unsignedBigInteger('employee_id');
            $t->date('work_date');
            $t->string('product_name');          // tên sản phẩm/công đoạn
            $t->decimal('quantity', 15, 2)->default(0);
            $t->decimal('unit_rate', 15, 2)->default(0); // đơn giá/sản phẩm (VND)
            $t->decimal('amount', 15, 2)->default(0);    // = quantity × unit_rate
            $t->jsonb('meta')->nullable();
            $t->timestamps();

            $t->index(['tenant_id', 'employee_id']);
            $t->index('work_date');
        });

        $this->command?->info('Đã tạo bảng piece_rate_entries.');
    }
}
