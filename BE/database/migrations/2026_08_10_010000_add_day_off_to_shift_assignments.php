<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shift_assignments')) {
            return;
        }

        Schema::table('shift_assignments', function (Blueprint $table): void {
            if (! Schema::hasColumn('shift_assignments', 'is_day_off')) {
                $table->boolean('is_day_off')->default(false)->after('shift_type_id');
            }

            if (! Schema::hasIndex('shift_assignments', 'shift_assignments_lookup_idx')) {
                $table->index(
                    ['tenant_id', 'employee_id', 'effective_date', 'expiry_date'],
                    'shift_assignments_lookup_idx'
                );
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('shift_assignments')) {
            return;
        }

        Schema::table('shift_assignments', function (Blueprint $table): void {
            if (Schema::hasIndex('shift_assignments', 'shift_assignments_lookup_idx')) {
                $table->dropIndex('shift_assignments_lookup_idx');
            }
            if (Schema::hasColumn('shift_assignments', 'is_day_off')) {
                $table->dropColumn('is_day_off');
            }
        });
    }
};
