<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('api_tokens', 'family_id')) {
            Schema::table('api_tokens', function (Blueprint $table): void {
                $table->uuid('family_id')->nullable()->index();
            });
        }

        if (! Schema::hasTable('api_refresh_tokens')) {
            Schema::create('api_refresh_tokens', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->unsignedBigInteger('employee_id')->index();
                $table->uuid('family_id')->index();
                $table->string('token_hash', 64)->unique();
                $table->unsignedBigInteger('replaced_by_id')->nullable()->index();
                $table->timestampTz('expires_at')->index();
                $table->timestampTz('rotated_at')->nullable();
                $table->timestampTz('revoked_at')->nullable()->index();
                $table->timestampTz('last_used_at')->nullable();
                $table->string('created_ip', 64)->nullable();
                $table->string('user_agent', 512)->nullable();
                $table->timestampsTz();

                $table->foreign('tenant_id')->references('id')->on('tenants');
                $table->foreign('employee_id')->references('id')->on('employees');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('api_refresh_tokens');

        if (Schema::hasColumn('api_tokens', 'family_id')) {
            Schema::table('api_tokens', function (Blueprint $table): void {
                $table->dropColumn('family_id');
            });
        }
    }
};
