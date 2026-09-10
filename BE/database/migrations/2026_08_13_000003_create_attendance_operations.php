<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('legal_entity_id')->nullable();
            $table->unsignedBigInteger('requested_by');
            $table->string('type', 32);
            $table->string('status', 20)->default('PENDING');
            $table->json('filters')->nullable();
            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('processed_items')->default(0);
            $table->unsignedInteger('succeeded_items')->default(0);
            $table->unsignedInteger('failed_items')->default(0);
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'requested_by', 'created_at'], 'attendance_operations_owner_index');
            $table->index(['status', 'created_at'], 'attendance_operations_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_operations');
    }
};
