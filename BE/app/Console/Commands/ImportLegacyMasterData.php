<?php

namespace App\Console\Commands;

class ImportLegacyMasterData extends BaseLegacyImportCommand
{
    protected $signature = 'legacy:import-master-data';

    protected $description = 'Import legacy HRM master data from the temporary MySQL database.';

    protected array $tables = [
        'nationalities', 'banks', 'departments', 'positions',
        'qualification_types', 'certificate_types', 'document_types',
        'asset_categories', 'suppliers', 'asset_locations',
        'insurance_types', 'roles', 'permissions', 'role_permissions',
    ];
}
