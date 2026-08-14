<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('leave_advancement_requests', 'leave_type_id')) {
            Schema::table('leave_advancement_requests', function (Blueprint $table): void {
                $table->unsignedBigInteger('leave_type_id')->nullable()->index();
            });
        }

        $duplicates = DB::table('role_permissions')
            ->selectRaw('tenant_id, role_id, permission_id, count(*) as total')
            ->groupBy('tenant_id', 'role_id', 'permission_id')
            ->havingRaw('count(*) > 1')
            ->exists();
        if ($duplicates) {
            throw new RuntimeException('Duplicate role_permissions detected; clean the duplicates before migration');
        }
        Schema::table('role_permissions', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'role_id', 'permission_id'], 'role_permissions_tenant_role_permission_uq');
        });
    }

    public function down(): void
    {
        Schema::table('role_permissions', function (Blueprint $table): void {
            $table->dropUnique('role_permissions_tenant_role_permission_uq');
        });
        if (Schema::hasColumn('leave_advancement_requests', 'leave_type_id')) {
            Schema::table('leave_advancement_requests', function (Blueprint $table): void {
                $table->dropColumn('leave_type_id');
            });
        }
    }
};
