<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_change_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('legal_entity_id')->nullable();
            $table->unsignedBigInteger('attendance_id');
            $table->unsignedBigInteger('employee_id');
            $table->date('work_date')->nullable();
            $table->string('change_type', 40);
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['tenant_id', 'id'], 'attendance_changes_tenant_cursor_index');
            $table->index(['tenant_id', 'employee_id', 'id'], 'attendance_changes_employee_cursor_index');
            $table->index('created_at', 'attendance_changes_created_at_index');
        });

        Schema::create('attendance_timesheet_exports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('legal_entity_id')->nullable();
            $table->unsignedBigInteger('requested_by');
            $table->string('month', 7);
            $table->string('format', 8)->default('xlsx');
            $table->jsonb('filters')->nullable();
            $table->string('status', 20)->default('PENDING');
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->text('error')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'requested_by', 'created_at'], 'timesheet_exports_owner_index');
            $table->index(['status', 'expires_at'], 'timesheet_exports_cleanup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_timesheet_exports');
        Schema::dropIfExists('attendance_change_events');
    }
};
