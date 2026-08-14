<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('salary_periods')
            ->selectRaw('tenant_id, legal_entity_id, period_code, count(*) as total')
            ->groupBy('tenant_id', 'legal_entity_id', 'period_code')
            ->havingRaw('count(*) > 1')
            ->limit(10)
            ->get();
        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException('Duplicate salary period codes must be resolved before migration: '.$duplicates->toJson());
        }

        $rangeDuplicates = DB::table('salary_periods')
            ->selectRaw('tenant_id, legal_entity_id, period_type, start_date, end_date, count(*) as total')
            ->groupBy('tenant_id', 'legal_entity_id', 'period_type', 'start_date', 'end_date')
            ->havingRaw('count(*) > 1')
            ->limit(10)
            ->get();
        if ($rangeDuplicates->isNotEmpty()) {
            throw new RuntimeException('Duplicate salary period ranges must be resolved before migration: '.$rangeDuplicates->toJson());
        }

        Schema::table('salary_periods', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'legal_entity_id', 'period_code'], 'salary_periods_tenant_entity_code_unique');
            $table->unique(
                ['tenant_id', 'legal_entity_id', 'period_type', 'start_date', 'end_date'],
                'salary_periods_tenant_entity_range_unique'
            );
        });

        Schema::table('payroll_adjustments', function (Blueprint $table): void {
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('submitted_by')->nullable()->index();
            $table->unsignedBigInteger('approved_by')->nullable()->index();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('applied_at')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->text('note')->nullable();
            $table->text('rejection_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payroll_adjustments', function (Blueprint $table): void {
            $table->dropColumn([
                'created_by', 'submitted_by', 'approved_by', 'submitted_at',
                'approved_at', 'applied_at', 'rejected_at', 'note', 'rejection_reason',
            ]);
        });
        Schema::table('salary_periods', function (Blueprint $table): void {
            $table->dropUnique('salary_periods_tenant_entity_code_unique');
            $table->dropUnique('salary_periods_tenant_entity_range_unique');
        });
    }
};
