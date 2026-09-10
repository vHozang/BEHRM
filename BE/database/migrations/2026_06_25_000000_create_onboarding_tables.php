<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Light onboarding / offboarding checklists.
 *
 * A checklist is an instance attached to one employee (type ONBOARDING when a
 * new hire joins, OFFBOARDING when someone leaves). It owns a flat list of
 * tasks that HR ticks off; when every task is done the checklist is COMPLETED.
 *
 * Tenant-scoped only (stamped via the BelongsToTenant trait) — no
 * legal_entity_id, mirroring how the holidays/leave-config tables are scoped.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('onboarding_checklists')) {
            Schema::create('onboarding_checklists', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('tenant_id')->index();
                $table->unsignedBigInteger('employee_id')->index();
                $table->string('type')->default('ONBOARDING'); // ONBOARDING | OFFBOARDING
                $table->string('status')->default('IN_PROGRESS'); // IN_PROGRESS | COMPLETED | CANCELLED
                $table->date('start_date')->nullable(); // ngày vào / ngày làm việc cuối
                $table->text('notes')->nullable();
                $table->jsonb('meta')->nullable();
                $table->timestamps();

                $table->foreign('tenant_id')->references('id')->on('tenants');
            });
        }

        if (! Schema::hasTable('onboarding_tasks')) {
            Schema::create('onboarding_tasks', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('tenant_id')->index();
                $table->unsignedBigInteger('checklist_id')->index();
                $table->string('title');
                $table->text('description')->nullable();
                $table->boolean('is_done')->default(false);
                $table->timestampTz('done_at')->nullable();
                $table->date('due_date')->nullable();
                $table->integer('sort_order')->default(0);
                $table->jsonb('meta')->nullable();
                $table->timestamps();

                $table->foreign('tenant_id')->references('id')->on('tenants');
                $table->foreign('checklist_id')->references('id')->on('onboarding_checklists')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_tasks');
        Schema::dropIfExists('onboarding_checklists');
    }
};
