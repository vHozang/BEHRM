<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mẫu hợp đồng (contract templates).
 *
 * Mỗi tenant có thể có nhiều mẫu: mẫu chuẩn theo luật VN ship sẵn (tạo từ
 * DEFAULT trong code) HOẶC mẫu doanh nghiệp tự upload/dán vào. Nội dung là HTML
 * chứa các placeholder {{key}} (vd {{ho_ten}}, {{muc_luong}}) — khi tạo/gửi ký
 * hệ thống trộn dữ liệu hợp đồng + nhân viên vào để render & in.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('contract_templates')) {
            return;
        }

        Schema::create('contract_templates', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('name');
            $table->longText('content');            // HTML có {{placeholder}}
            $table->boolean('is_default')->default(false);
            $table->string('source')->default('system'); // system | uploaded
            $table->jsonb('meta')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
        });
    }

    public function down(): void
    {
        // No-op: bảng contract_templates đã tồn tại từ trước migration này
        // (migration up() đã bỏ qua việc tạo), nên KHÔNG drop để tránh xóa nhầm
        // bảng legacy. Cột bổ sung do migration 000003 quản lý rollback riêng.
    }
};
