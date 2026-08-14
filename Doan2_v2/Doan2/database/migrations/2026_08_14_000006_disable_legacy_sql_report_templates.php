<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('report_templates') || ! Schema::hasColumn('report_templates', 'sql_query')) {
            return;
        }

        DB::table('report_templates')
            ->whereNotNull('sql_query')
            ->whereRaw("trim(sql_query) <> ''")
            ->update(['status' => 'LEGACY_DISABLED', 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Legacy SQL templates stay disabled deliberately; rollback must not re-enable executable SQL.
    }
};
