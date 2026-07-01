<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable audit trail of data changes (create|update|delete) across the HRM.
 *
 * Rows are tenant-stamped from TenantContext when available; console / platform
 * actions may leave tenant_id null. No updated_at — audit rows never change.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('audit_logs')) {
            return;
        }

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('actor_employee_id')->nullable();
            $table->string('action'); // create | update | delete
            $table->string('table_name')->index();
            $table->unsignedBigInteger('record_id')->nullable()->index();
            $table->jsonb('changes')->nullable(); // { before:{...}, after:{...} }
            $table->string('ip_address')->nullable();
            $table->timestampTz('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
