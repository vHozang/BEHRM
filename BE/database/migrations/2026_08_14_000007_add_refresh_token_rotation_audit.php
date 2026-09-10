<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_refresh_tokens', function (Blueprint $table): void {
            if (! Schema::hasColumn('api_refresh_tokens', 'rotation_number')) {
                $table->unsignedInteger('rotation_number')->default(0)->index();
            }
            if (! Schema::hasColumn('api_refresh_tokens', 'reuse_detected_at')) {
                $table->timestampTz('reuse_detected_at')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('api_refresh_tokens', function (Blueprint $table): void {
            if (Schema::hasColumn('api_refresh_tokens', 'reuse_detected_at')) {
                $table->dropColumn('reuse_detected_at');
            }
            if (Schema::hasColumn('api_refresh_tokens', 'rotation_number')) {
                $table->dropColumn('rotation_number');
            }
        });
    }
};
