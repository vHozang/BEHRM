<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance regularization requests (đơn điều chỉnh công).
 *
 * An employee asks to correct a work-day's check-in/out times or status.
 * On approval the target attendances row is created/updated and late/early
 * minutes recomputed. Tenant + legal-entity scoped (stamped via traits).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('attendance_adjustment_requests')) {
            return;
        }

        Schema::create('attendance_adjustment_requests', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('legal_entity_id')->index();
            $table->unsignedBigInteger('employee_id')->index();
            $table->unsignedBigInteger('attendance_id')->nullable(); // target row, may not exist yet
            $table->date('work_date');
            $table->time('requested_check_in_time')->nullable();
            $table->time('requested_check_out_time')->nullable();
            $table->string('requested_status')->nullable();
            $table->text('reason')->nullable();
            $table->string('status')->default('PENDING');
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
        Schema::dropIfExists('attendance_adjustment_requests');
    }
};
