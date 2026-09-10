<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Profile change requests (đơn đề nghị thay đổi thông tin cá nhân).
 *
 * Nghiệp vụ: khi onboarding, nhân viên cung cấp hồ sơ/sơ yếu lý lịch và HR nhập
 * các thông tin nhạy cảm (CCCD, MST, tài khoản ngân hàng, BHXH...). Sau đó các
 * trường này KHÓA với nhân viên — muốn đổi phải làm đơn để HR/quản trị duyệt,
 * tránh tự ý sửa gây sai lệch dữ liệu khi hệ thống đang vận hành.
 *
 * Mỗi đơn chứa một tập thay đổi (changes) dạng {field_key: {label, old, new}}.
 * Khi DUYỆT, các giá trị mới được ghi vào hồ sơ nhân viên (employees.profile
 * hoặc cột tương ứng). Tenant + legal-entity scoped (stamp qua trait).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('profile_change_requests')) {
            return;
        }

        Schema::create('profile_change_requests', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('legal_entity_id')->index();
            $table->unsignedBigInteger('employee_id')->index();
            $table->jsonb('changes');                 // {field_key: {label, old, new}}
            $table->text('reason')->nullable();
            $table->string('status')->default('PENDING'); // PENDING/APPROVED/REJECTED/CANCELLED
            $table->unsignedBigInteger('approver_id')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_comment')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('legal_entity_id')->references('id')->on('legal_entities');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_change_requests');
    }
};
