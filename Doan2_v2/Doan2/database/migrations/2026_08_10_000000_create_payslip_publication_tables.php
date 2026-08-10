<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payslip_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('legal_entity_id')->constrained('legal_entities')->restrictOnDelete();
            $table->foreignId('salary_period_id')->constrained('salary_periods')->restrictOnDelete();
            $table->foreignId('salary_detail_id')->unique()->constrained('salary_details')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->string('storage_path')->nullable();
            $table->string('filename')->nullable();
            $table->string('mime_type')->default('application/pdf');
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->string('generation_status')->default('PENDING')->index();
            $table->string('email_status')->default('PENDING')->index();
            $table->string('recipient_email')->nullable();
            $table->string('recipient_source')->nullable();
            $table->unsignedInteger('send_attempts')->default(0);
            $table->foreignId('published_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestampTz('generated_at')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('last_attempted_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('meta')->nullable();
            $table->timestampsTz();

            $table->index(['salary_period_id', 'generation_status']);
            $table->index(['employee_id', 'published_at']);
        });

        Schema::create('payslip_publication_issues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('legal_entity_id')->constrained('legal_entities')->restrictOnDelete();
            $table->foreignId('salary_period_id')->constrained('salary_periods')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('salary_detail_id')->nullable()->constrained('salary_details')->nullOnDelete();
            $table->foreignId('payslip_document_id')->nullable()->constrained('payslip_documents')->nullOnDelete();
            $table->string('issue_type')->index();
            $table->string('issue_code')->index();
            $table->text('message');
            $table->text('resolution_hint')->nullable();
            $table->string('status')->default('OPEN')->index();
            $table->foreignId('acknowledged_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestampTz('acknowledged_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['salary_period_id', 'employee_id', 'issue_type', 'issue_code'],
                'payslip_issue_period_employee_type_code_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslip_publication_issues');
        Schema::dropIfExists('payslip_documents');
    }
};
