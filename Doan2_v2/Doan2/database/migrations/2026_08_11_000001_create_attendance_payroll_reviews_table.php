<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_payroll_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('legal_entity_id')->nullable()->constrained('legal_entities')->restrictOnDelete();
            $table->foreignId('attendance_id')->unique()->constrained('attendances')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->date('work_date');
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('early_leave_minutes')->default(0);
            $table->unsignedSmallInteger('default_percent')->default(0);
            $table->unsignedSmallInteger('approved_percent')->nullable();
            $table->string('status', 20)->default('PENDING');
            $table->text('decision_note')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('employees')->restrictOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampTz('stale_at')->nullable();
            $table->timestampTz('applied_at')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'employee_id', 'work_date'], 'attendance_payroll_reviews_employee_day_unique');
            $table->index(['tenant_id', 'status', 'work_date'], 'attendance_payroll_reviews_status_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_payroll_reviews');
    }
};
