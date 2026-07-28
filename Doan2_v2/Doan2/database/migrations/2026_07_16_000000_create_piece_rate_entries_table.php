<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('piece_rate_entries')) {
            return;
        }

        Schema::create('piece_rate_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('legal_entity_id')->nullable()->constrained('legal_entities')->nullOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->date('work_date');
            $table->string('product_name');
            $table->decimal('quantity', 15, 2)->default(0);
            $table->decimal('unit_rate', 15, 2)->default(0);
            $table->decimal('amount', 15, 2)->default(0);
            $table->jsonb('meta')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'employee_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('piece_rate_entries');
    }
};
