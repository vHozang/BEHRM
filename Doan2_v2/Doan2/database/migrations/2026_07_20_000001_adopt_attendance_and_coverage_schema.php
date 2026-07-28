<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $attendanceExists = Schema::hasTable('attendance_devices');
        if (! $attendanceExists) {
            Schema::create('attendance_devices', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
                $table->foreignId('legal_entity_id')->nullable()->constrained('legal_entities')->restrictOnDelete();
                $table->string('name');
                $table->string('brand')->default('zkteco');
                $table->string('protocol')->default('zk_pull');
                $table->string('device_token', 64)->unique();
                $table->string('status')->default('ACTIVE');
                $table->string('location')->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->jsonb('meta')->nullable();
                $table->timestamps();
                $table->index('status');
            });
        }

        $requestsExist = Schema::hasTable('shift_coverage_requests');
        if (! $requestsExist) {
            Schema::create('shift_coverage_requests', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
                $table->foreignId('legal_entity_id')->nullable()->constrained('legal_entities')->restrictOnDelete();
                $table->date('work_date');
                $table->foreignId('shift_type_id')->constrained('shift_types')->restrictOnDelete();
                $table->foreignId('absent_employee_id')->nullable()->constrained('employees')->restrictOnDelete();
                $table->string('reason')->nullable();
                $table->decimal('hours_needed', 6, 2)->default(8);
                $table->decimal('hours_covered', 6, 2)->default(0);
                $table->string('status')->default('OPEN');
                $table->foreignId('created_by')->nullable()->constrained('employees')->restrictOnDelete();
                $table->text('notes')->nullable();
                $table->jsonb('meta')->nullable();
                $table->timestamps();
                $table->index(['tenant_id', 'work_date']);
                $table->index('status');
            });
        }

        $offersExist = Schema::hasTable('shift_coverage_offers');
        if (! $offersExist) {
            Schema::create('shift_coverage_offers', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
                $table->foreignId('legal_entity_id')->nullable()->constrained('legal_entities')->restrictOnDelete();
                $table->foreignId('coverage_request_id')->constrained('shift_coverage_requests')->restrictOnDelete();
                $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
                $table->time('start_time')->nullable();
                $table->time('end_time')->nullable();
                $table->decimal('hours', 6, 2)->default(0);
                $table->string('status')->default('OFFERED');
                $table->foreignId('overtime_request_id')->nullable()->constrained('overtime_requests')->restrictOnDelete();
                $table->jsonb('meta')->nullable();
                $table->timestamps();
                $table->index(['tenant_id', 'employee_id']);
                $table->index('status');
            });
        }

        if ($attendanceExists) {
            Schema::table('attendance_devices', function (Blueprint $table): void {
                $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
                $table->foreign('legal_entity_id')->references('id')->on('legal_entities')->restrictOnDelete();
            });
        }
        if ($requestsExist) {
            Schema::table('shift_coverage_requests', function (Blueprint $table): void {
                $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
                $table->foreign('legal_entity_id')->references('id')->on('legal_entities')->restrictOnDelete();
                $table->foreign('shift_type_id')->references('id')->on('shift_types')->restrictOnDelete();
                $table->foreign('absent_employee_id')->references('id')->on('employees')->restrictOnDelete();
                $table->foreign('created_by')->references('id')->on('employees')->restrictOnDelete();
            });
        }
        if ($offersExist) {
            Schema::table('shift_coverage_offers', function (Blueprint $table): void {
                $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
                $table->foreign('legal_entity_id')->references('id')->on('legal_entities')->restrictOnDelete();
                $table->foreign('coverage_request_id')->references('id')->on('shift_coverage_requests')->restrictOnDelete();
                $table->foreign('employee_id')->references('id')->on('employees')->restrictOnDelete();
                $table->foreign('overtime_request_id')->references('id')->on('overtime_requests')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        // ponytail: adoption is intentionally irreversible; rollback must never drop existing attendance history.
    }
};
