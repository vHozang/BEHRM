<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bổ sung cờ is_default + nguồn (source) cho bảng contract_templates đã tồn tại
 * (bảng legacy dùng template_name/content/is_active). Dùng để chọn mẫu mặc định
 * khi render/gửi ký, và phân biệt mẫu hệ thống vs mẫu DN tự upload.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('contract_templates')) {
            return;
        }

        Schema::table('contract_templates', function (Blueprint $table): void {
            if (! Schema::hasColumn('contract_templates', 'is_default')) {
                $table->boolean('is_default')->default(false);
            }
            if (! Schema::hasColumn('contract_templates', 'source')) {
                $table->string('source')->default('system');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('contract_templates')) {
            return;
        }
        Schema::table('contract_templates', function (Blueprint $table): void {
            if (Schema::hasColumn('contract_templates', 'is_default')) {
                $table->dropColumn('is_default');
            }
            if (Schema::hasColumn('contract_templates', 'source')) {
                $table->dropColumn('source');
            }
        });
    }
};
