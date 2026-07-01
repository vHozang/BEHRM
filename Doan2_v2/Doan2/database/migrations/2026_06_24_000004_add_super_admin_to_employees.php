<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a boolean `is_super_admin` flag to employees to separate
 * platform (cross-tenant) super-admins from regular tenant-scoped users.
 *
 * Also flags the seed/test account as super-admin so platform endpoints
 * can be exercised immediately after the migration runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('employees', 'is_super_admin')) {
            Schema::table('employees', function (Blueprint $table): void {
                $table->boolean('is_super_admin')->notNull()->default(false);
            });
        }

        DB::table('employees')
            ->where('company_email', 'an.nguyen@company.com')
            ->update(['is_super_admin' => DB::raw('true')]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('employees', 'is_super_admin')) {
            Schema::table('employees', function (Blueprint $table): void {
                $table->dropColumn('is_super_admin');
            });
        }
    }
};
