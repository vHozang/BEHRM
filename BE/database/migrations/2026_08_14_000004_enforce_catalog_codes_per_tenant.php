<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, string> */
    private array $catalogs = [
        'asset_categories' => 'category_code',
        'asset_locations' => 'location_code',
        'suppliers' => 'supplier_code',
        'service_categories' => 'category_code',
        'request_types' => 'request_type_code',
        'news_categories' => 'category_code',
        'document_types' => 'document_type_code',
        'qualification_types' => 'qualification_type_code',
        'insurance_types' => 'insurance_type_code',
        'allowances' => 'allowance_code',
        'deductions' => 'deduction_code',
        'salary_components' => 'code',
        'banks' => 'bank_code',
        'nationalities' => 'nationality_code',
    ];

    public function up(): void
    {
        foreach (['banks', 'nationalities'] as $table) {
            if (! Schema::hasColumn($table, 'tenant_id')) {
                Schema::table($table, function (Blueprint $blueprint): void {
                    $blueprint->unsignedBigInteger('tenant_id')->default(1)->index();
                });
            }
        }

        $conflicts = [];
        foreach ($this->catalogs as $table => $column) {
            $duplicates = DB::table($table)
                ->selectRaw("tenant_id, lower(trim({$column})) as normalized_code, count(*) as total")
                ->whereNotNull($column)
                ->whereRaw("trim({$column}) <> ''")
                ->groupBy('tenant_id', DB::raw("lower(trim({$column}))"))
                ->havingRaw('count(*) > 1')
                ->get();
            foreach ($duplicates as $duplicate) {
                $conflicts[] = [
                    $table,
                    $column,
                    $duplicate->tenant_id,
                    $duplicate->normalized_code,
                    $duplicate->total,
                ];
            }
        }

        if ($conflicts !== []) {
            $path = $this->writeConflictReport($conflicts);
            throw new RuntimeException("Catalog code migration stopped; inspect {$path}");
        }

        foreach ($this->catalogs as $table => $column) {
            $index = $this->indexName($table);
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("CREATE UNIQUE INDEX {$index} ON {$table} (tenant_id, lower(trim({$column})))");
            } else {
                Schema::table($table, function (Blueprint $blueprint) use ($column, $index): void {
                    $blueprint->unique(['tenant_id', $column], $index);
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->catalogs as $table => $column) {
            $index = $this->indexName($table);
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("DROP INDEX IF EXISTS {$index}");
            } else {
                Schema::table($table, function (Blueprint $blueprint) use ($index): void {
                    $blueprint->dropUnique($index);
                });
            }
        }

        foreach (['banks', 'nationalities'] as $table) {
            if (Schema::hasColumn($table, 'tenant_id')) {
                Schema::table($table, function (Blueprint $blueprint): void {
                    $blueprint->dropColumn('tenant_id');
                });
            }
        }
    }

    private function indexName(string $table): string
    {
        return 'cat_'.substr(hash('sha1', $table), 0, 10).'_tenant_code_uq';
    }

    /** @param array<int, array<int, mixed>> $rows */
    private function writeConflictReport(array $rows): string
    {
        $directory = storage_path('app/private/migration-reports');
        File::ensureDirectoryExists($directory);
        $path = $directory.'/catalog-code-conflicts-'.now()->format('Ymd-His').'.csv';
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Cannot write catalog conflict report');
        }
        fputcsv($handle, ['table', 'column', 'tenant_id', 'normalized_code', 'count']);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);

        return $path;
    }
};
