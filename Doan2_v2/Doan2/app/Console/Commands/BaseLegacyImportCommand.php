<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

abstract class BaseLegacyImportCommand extends Command
{
    protected array $tables = [];

    public function handle(): int
    {
        $this->info('Starting legacy import: '.$this->getName());

        foreach ($this->tables as $source => $destination) {
            if (is_int($source)) {
                $source = $destination;
            }

            $this->importTable($source, $destination);
        }

        $this->info('Legacy import finished.');

        return self::SUCCESS;
    }

    protected function importTable(string $source, string $destination): void
    {
        if (! Schema::hasTable($destination)) {
            $this->warn("Destination table missing: {$destination}");

            return;
        }

        if (! Schema::connection('mysql_legacy')->hasTable($source)) {
            $this->warn("Legacy table missing: {$source}");

            return;
        }

        $destinationColumns = collect(Schema::getColumnListing($destination));
        $sourceColumns = collect(Schema::connection('mysql_legacy')->getColumnListing($source));
        $legacyIdColumn = $sourceColumns->first(fn (string $column) => str_ends_with($column, '_id')) ?? 'id';
        $copyColumns = $sourceColumns->intersect($destinationColumns)->reject(fn (string $column) => in_array($column, ['id', 'created_at', 'updated_at'], true));
        $count = 0;

        DB::connection('mysql_legacy')
            ->table($source)
            ->orderBy($legacyIdColumn)
            ->chunk(500, function ($rows) use ($destination, $legacyIdColumn, $copyColumns, &$count): void {
                foreach ($rows as $row) {
                    $record = ['legacy_id' => $row->{$legacyIdColumn} ?? null];

                    foreach ($copyColumns as $column) {
                        $record[$column] = $row->{$column};
                    }

                    $record['created_at'] = $row->created_at ?? now();
                    $record['updated_at'] = $row->updated_at ?? now();

                    DB::table($destination)->updateOrInsert(
                        ['legacy_id' => $record['legacy_id']],
                        $record
                    );

                    $count++;
                }
            });

        $this->line("Imported {$count} rows: {$source} -> {$destination}");
    }
}
