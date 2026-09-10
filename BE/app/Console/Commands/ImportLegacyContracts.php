<?php

namespace App\Console\Commands;

class ImportLegacyContracts extends BaseLegacyImportCommand
{
    protected $signature = 'legacy:import-contracts';

    protected $description = 'Import legacy contract records.';

    protected array $tables = ['contract_types', 'contract_templates', 'contracts', 'contract_histories', 'contract_change_logs'];
}
