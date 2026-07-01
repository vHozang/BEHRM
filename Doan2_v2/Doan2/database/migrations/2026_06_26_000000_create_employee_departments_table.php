<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Secondary (matrix) department memberships.
 *
 * An employee's PRIMARY department stays on employees.department_id (source of
 * truth for payroll/primary reporting — untouched). This pivot holds ADDITIONAL
 * departments an employee also belongs to (kiêm nhiệm / cross-functional /
 * matrix), so "1 nhân viên thuộc nhiều phòng ban" is supported without changing
 * the existing single-department logic.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employee_departments')) {
            return;
        }

        Schema::create('employee_departments', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('employee_id')->index();
            $table->unsignedBigInteger('department_id')->index();
            $table->string('role_in_dept')->nullable(); // optional: vai trò trong phòng (kiêm nhiệm)
            $table->timestamps();

            $table->unique(['employee_id', 'department_id']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_departments');
    }
};
