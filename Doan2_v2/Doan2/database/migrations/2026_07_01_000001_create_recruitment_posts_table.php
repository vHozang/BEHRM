<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_posts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('recruitment_position_id')->nullable()->index();
            $table->string('slug')->unique();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->text('content')->nullable();
            $table->jsonb('benefits')->nullable();
            $table->jsonb('requirements')->nullable();
            $table->string('location')->nullable();
            $table->string('salary_range')->nullable();
            $table->string('employment_type')->nullable();
            $table->date('deadline')->nullable();
            $table->string('status')->default('DRAFT'); // DRAFT, PUBLISHED, CLOSED, ARCHIVED
            $table->timestampTz('published_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestampsTz();

            $table->index('tenant_id');
            $table->index('status');
            $table->index(['status', 'deadline']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_posts');
    }
};
